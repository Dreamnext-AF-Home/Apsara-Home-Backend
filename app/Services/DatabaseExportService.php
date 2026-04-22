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
        $tempBase = tempnam(sys_get_temp_dir(), 'afhome_db_export_');
        if ($tempBase === false) {
            throw new RuntimeException('Failed to initialize temporary export file.');
        }
        $tempZipPath = $tempBase . '.zip';
        @unlink($tempBase);

        $zip = new ZipArchive();
        $zipStatus = $zip->open($tempZipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        if ($zipStatus !== true) {
            throw new RuntimeException('Failed to create export archive.');
        }

        $tableSummaries = [];
        $previewCsv = '';
        $previewTable = '';
        $totalRows = 0;

        foreach ($tables as $table) {
            $rows = DB::table($table)->get()->map(
                static fn (object $row): array => (array) $row
            )->all();

            $rowCount = count($rows);
            $totalRows += $rowCount;
            $csv = $this->buildCsvFromRows($rows);

            if ($previewCsv === '') {
                $previewCsv = $csv;
                $previewTable = $table;
            }

            $zip->addFromString($table . '.csv', $csv);
            $tableSummaries[] = [
                'name' => $table,
                'row_count' => $rowCount,
            ];
        }

        $summaryCsv = $this->buildCsvFromRows($tableSummaries);
        $zip->addFromString('_summary.csv', $summaryCsv);
        $zip->close();

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
                if (is_bool($value)) {
                    $line[] = $value ? '1' : '0';
                } elseif (is_array($value) || is_object($value)) {
                    $line[] = json_encode($value, JSON_UNESCAPED_SLASHES);
                } else {
                    $line[] = $value;
                }
            }
            fputcsv($stream, $line);
        }

        rewind($stream);
        $csv = stream_get_contents($stream);
        fclose($stream);

        return is_string($csv) ? $csv : '';
    }
}

