<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use ZipArchive;

class DatabaseExportService
{
    private const EXPORT_DIR = 'exports/database';

    public function exportDirectory(): string
    {
        return self::EXPORT_DIR;
    }

    public function buildBackupDownloadName(): string
    {
        return 'db_backup(' . now()->format('Y-m-d') . ').zip';
    }

    /**
     * @return array{
     *   path: string,
     *   name: string,
     *   download_name: string,
     *   size_bytes: int,
     *   generated_at: string,
     *   table_count: int,
     *   total_rows: int,
     *   preview_table: string,
     *   preview_csv: string
     * }
     */
    public function exportDatabaseZip(): array
    {
        $tables = Schema::getTableListing();
        sort($tables);

        $disk = Storage::disk('local');
        if (! $disk->exists(self::EXPORT_DIR)) {
            $disk->makeDirectory(self::EXPORT_DIR);
        }

        $timestamp = now()->format('Ymd-His');
        $filename = 'database-export-' . $timestamp . '.zip';
        $relativePath = self::EXPORT_DIR . '/' . $filename;
        $tempZipPath = $this->createTempFilePath('afhome_db_export_');

        $zip = new ZipArchive();
        $zipStatus = $zip->open($tempZipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        if ($zipStatus !== true) {
            throw new RuntimeException('Failed to create export archive.');
        }

        $tableSummaries = [];
        $previewCsv = '';
        $previewTable = '';
        $totalRows = 0;
        $tableCsvTempPaths = [];

        try {
            foreach ($tables as $table) {
                [$tableCsvPath, $rowCount, $tablePreview] = $this->buildTableCsvTempFile($table);
                $tableCsvTempPaths[] = $tableCsvPath;
                $totalRows += $rowCount;

                if ($previewCsv === '') {
                    $previewCsv = $tablePreview;
                    $previewTable = $table;
                }

                $zip->addFile($tableCsvPath, $table . '.csv');
                $tableSummaries[] = [
                    'name' => $table,
                    'row_count' => $rowCount,
                ];
            }

            $summaryCsv = $this->buildCsvFromRows($tableSummaries);
            $zip->addFromString('_summary.csv', $summaryCsv);

            $invoiceFiles = $this->collectExpenseInvoiceFiles();
            foreach ($invoiceFiles as $invoiceFile) {
                if (($invoiceFile['exists'] ?? 0) !== 1) {
                    continue;
                }

                $absolutePath = $invoiceFile['absolute_path'] ?? null;
                $zipPath = $invoiceFile['zip_path'] ?? null;
                if (! is_string($absolutePath) || ! is_string($zipPath) || $absolutePath === '' || $zipPath === '') {
                    continue;
                }

                if (is_file($absolutePath)) {
                    $zip->addFile($absolutePath, $zipPath);
                }
            }

            $invoiceFileSummary = array_map(
                static function (array $row): array {
                    return [
                        'invoice_url' => $row['invoice_url'] ?? '',
                        'public_path' => $row['public_path'] ?? '',
                        'zip_path' => $row['zip_path'] ?? '',
                        'exists' => $row['exists'] ?? 0,
                        'size_bytes' => $row['size_bytes'] ?? 0,
                        'note' => $row['note'] ?? '',
                    ];
                },
                $invoiceFiles
            );
            $zip->addFromString('_expense_invoice_files.csv', $this->buildCsvFromRows($invoiceFileSummary));

            $closeStatus = $zip->close();
            if ($closeStatus !== true) {
                @unlink($tempZipPath);
                throw new RuntimeException('Failed to finalize export archive.');
            }
        } finally {
            foreach ($tableCsvTempPaths as $tempCsvPath) {
                @unlink($tempCsvPath);
            }
        }

        $tempStream = fopen($tempZipPath, 'r');
        if (! is_resource($tempStream)) {
            @unlink($tempZipPath);
            throw new RuntimeException('Failed to finalize export archive.');
        }

        $stored = $disk->put($relativePath, $tempStream);
        fclose($tempStream);
        @unlink($tempZipPath);

        if (! $stored) {
            throw new RuntimeException('Failed to store export archive.');
        }

        $archiveSize = (int) ($disk->size($relativePath) ?? 0);

        return [
            'path' => $relativePath,
            'name' => $filename,
            'download_name' => $this->buildBackupDownloadName(),
            'size_bytes' => $archiveSize,
            'generated_at' => now()->toIso8601String(),
            'table_count' => count($tableSummaries),
            'total_rows' => $totalRows,
            'preview_table' => $previewTable,
            'preview_csv' => $previewCsv,
        ];
    }

    /**
     * @return array{0: string, 1: int, 2: string}
     */
    private function buildTableCsvTempFile(string $table): array
    {
        $columns = Schema::getColumnListing($table);
        $tempCsvPath = $this->createTempFilePath('afhome_db_table_');

        $stream = fopen($tempCsvPath, 'w');
        if (! is_resource($stream)) {
            @unlink($tempCsvPath);
            throw new RuntimeException('Failed to initialize table export stream.');
        }

        $rowCount = 0;

        if (empty($columns)) {
            fputcsv($stream, ['message']);
            fputcsv($stream, ['No rows']);
        } else {
            fputcsv($stream, $columns);

            foreach (DB::table($table)->select($columns)->cursor() as $row) {
                $line = [];
                $rowData = (array) $row;
                foreach ($columns as $column) {
                    $line[] = $this->normalizeCsvValue($rowData[$column] ?? null);
                }
                fputcsv($stream, $line);
                $rowCount++;
            }
        }

        fclose($stream);

        $preview = file_get_contents($tempCsvPath, false, null, 0, 50000);
        $previewCsv = is_string($preview) ? $preview : '';

        return [$tempCsvPath, $rowCount, $previewCsv];
    }

    private function createTempFilePath(string $prefix): string
    {
        $tempDir = $this->resolveTempDirectoryPath();
        $basePath = tempnam($tempDir, $prefix);
        if ($basePath === false) {
            throw new RuntimeException('Failed to initialize temporary export file.');
        }

        return $basePath;
    }

    private function resolveTempDirectoryPath(): string
    {
        $systemTempPath = sys_get_temp_dir();
        if (is_dir($systemTempPath) && is_writable($systemTempPath)) {
            return $systemTempPath;
        }

        $storageTmpPath = storage_path('app/tmp');
        if (! is_dir($storageTmpPath)) {
            @mkdir($storageTmpPath, 0775, true);
        }

        if (is_dir($storageTmpPath) && is_writable($storageTmpPath)) {
            return $storageTmpPath;
        }

        throw new RuntimeException('No writable temporary directory is available for database export.');
    }

    private function buildCsvFromRows(array $rows): string
    {
        $stream = fopen('php://temp', 'r+');
        if (! is_resource($stream)) {
            return '';
        }

        $headers = collect($rows)
            ->flatMap(fn (array $row): array => array_keys($row))
            ->unique()
            ->values()
            ->all();

        if (empty($headers)) {
            fputcsv($stream, ['message']);
            fputcsv($stream, ['No rows']);
            rewind($stream);
            $csv = stream_get_contents($stream);
            fclose($stream);

            return is_string($csv) ? $csv : '';
        }

        fputcsv($stream, $headers);

        foreach ($rows as $row) {
            $line = [];
            foreach ($headers as $header) {
                $value = $row[$header] ?? null;
                $line[] = $this->normalizeCsvValue($value);
            }
            fputcsv($stream, $line);
        }

        rewind($stream);
        $csv = stream_get_contents($stream);
        fclose($stream);

        return is_string($csv) ? $csv : '';
    }

    private function normalizeCsvValue(mixed $value): mixed
    {
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if (is_array($value) || is_object($value)) {
            return json_encode($value, JSON_UNESCAPED_SLASHES);
        }

        return $value;
    }

    private function collectExpenseInvoiceFiles(): array
    {
        if (! Schema::hasTable('tbl_expenses') || ! Schema::hasColumn('tbl_expenses', 'invoice_url')) {
            return [];
        }

        $publicDisk = Storage::disk('public');
        $invoiceUrls = DB::table('tbl_expenses')
            ->whereNotNull('invoice_url')
            ->pluck('invoice_url')
            ->filter(static fn ($value): bool => is_string($value) && trim($value) !== '')
            ->map(static fn (string $value): string => trim($value))
            ->unique()
            ->values();

        $files = [];
        foreach ($invoiceUrls as $invoiceUrl) {
            $publicPath = $this->resolvePublicRelativePathFromUrl($invoiceUrl);
            if (! $publicPath) {
                $files[] = [
                    'invoice_url' => $invoiceUrl,
                    'public_path' => null,
                    'zip_path' => null,
                    'absolute_path' => null,
                    'exists' => 0,
                    'size_bytes' => 0,
                    'note' => 'Skipped: invoice_url is not a local /storage file path.',
                ];
                continue;
            }

            $exists = $publicDisk->exists($publicPath);
            $files[] = [
                'invoice_url' => $invoiceUrl,
                'public_path' => $publicPath,
                'zip_path' => 'files/invoices/' . ltrim(str_replace('\\', '/', $publicPath), '/'),
                'absolute_path' => $exists ? $publicDisk->path($publicPath) : null,
                'exists' => $exists ? 1 : 0,
                'size_bytes' => $exists ? (int) ($publicDisk->size($publicPath) ?? 0) : 0,
                'note' => $exists ? '' : 'Missing file on public disk.',
            ];
        }

        return $files;
    }

    private function resolvePublicRelativePathFromUrl(string $url): ?string
    {
        $value = trim($url);
        if ($value === '') {
            return null;
        }

        $path = parse_url($value, PHP_URL_PATH);
        $path = is_string($path) ? trim($path) : $value;
        if ($path === '') {
            return null;
        }

        $storageMarker = '/storage/';
        $position = strpos($path, $storageMarker);
        if ($position !== false) {
            return ltrim(substr($path, $position + strlen($storageMarker)), '/');
        }

        if (str_starts_with($path, 'storage/')) {
            return ltrim(substr($path, strlen('storage/')), '/');
        }

        return null;
    }
}
