<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

require __DIR__ . '/../vendor/autoload.php';

$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$defaultSource = dirname(__DIR__) . '/../tbl_customer (1).sql';
$arguments = array_slice($argv, 1);
$apply = in_array('--apply', $arguments, true);
$nonFlagArguments = array_values(array_filter(
    $arguments,
    static fn (string $argument): bool => ! str_starts_with($argument, '--')
));
$sourcePath = $nonFlagArguments[0] ?? $defaultSource;

if (! is_file($sourcePath)) {
    fwrite(STDERR, "Source file not found: {$sourcePath}\n");
    exit(1);
}

if (! Schema::hasTable('tbl_customer') || ! Schema::hasColumn('tbl_customer', 'c_sponsor')) {
    fwrite(STDERR, "tbl_customer.c_sponsor is not available in the current database.\n");
    exit(1);
}

/**
 * Parse the first N SQL values from a tuple line while respecting quoted strings.
 *
 * @return list<string>
 */
function parseSqlTuplePrefix(string $tupleLine, int $fieldCount): array
{
    $values = [];
    $buffer = '';
    $inString = false;
    $length = strlen($tupleLine);

    for ($i = 0; $i < $length; $i++) {
        $char = $tupleLine[$i];

        if ($inString) {
            if ($char === "'") {
                if ($i + 1 < $length && $tupleLine[$i + 1] === "'") {
                    $buffer .= "'";
                    $i++;
                    continue;
                }

                $inString = false;
                continue;
            }

            $buffer .= $char;
            continue;
        }

        if ($char === "'") {
            $inString = true;
            continue;
        }

        if ($char === ',') {
            $values[] = trim($buffer);
            $buffer = '';

            if (count($values) === $fieldCount) {
                return $values;
            }

            continue;
        }

        if ($char === ')') {
            $values[] = trim($buffer);
            return $values;
        }

        if ($char === '(' && $buffer === '') {
            continue;
        }

        $buffer .= $char;
    }

    if ($buffer !== '') {
        $values[] = trim($buffer);
    }

    return $values;
}

/**
 * @return array<int, int>
 */
function loadSponsorMap(string $path): array
{
    $handle = fopen($path, 'rb');
    if ($handle === false) {
        throw new RuntimeException("Unable to open source file: {$path}");
    }

    $mapping = [];
    $insideCustomerInsert = false;

    while (($line = fgets($handle)) !== false) {
        $trimmed = trim($line);

        if (
            str_starts_with($trimmed, 'INSERT INTO "tbl_customer"')
            || str_starts_with($trimmed, 'INSERT INTO `tbl_customer`')
        ) {
            $insideCustomerInsert = true;
            continue;
        }

        if (! $insideCustomerInsert || $trimmed === '' || $trimmed[0] !== '(') {
            continue;
        }

        $prefix = parseSqlTuplePrefix($trimmed, 7);
        if (count($prefix) < 7) {
            continue;
        }

        $customerId = (int) $prefix[0];
        $sponsorId = strtoupper($prefix[6]) === 'NULL' ? 0 : (int) $prefix[6];
        $mapping[$customerId] = $sponsorId;

        if (str_ends_with($trimmed, ');')) {
            $insideCustomerInsert = false;
        }
    }

    fclose($handle);

    return $mapping;
}

$sourceMapping = loadSponsorMap($sourcePath);
if ($sourceMapping === []) {
    fwrite(STDERR, "No sponsor mapping could be parsed from {$sourcePath}\n");
    exit(1);
}

$currentRows = DB::table('tbl_customer')
    ->select('c_userid', 'c_sponsor')
    ->orderBy('c_userid')
    ->get();

$currentMapping = [];
foreach ($currentRows as $row) {
    $currentMapping[(int) $row->c_userid] = $row->c_sponsor === null ? null : (int) $row->c_sponsor;
}

$updates = [];
$unchanged = 0;
$missingInCurrentDb = 0;

foreach ($sourceMapping as $customerId => $sponsorId) {
    if (! array_key_exists($customerId, $currentMapping)) {
        $missingInCurrentDb++;
        continue;
    }

    if ($currentMapping[$customerId] === $sponsorId) {
        $unchanged++;
        continue;
    }

    $updates[$customerId] = $sponsorId;
}

$summary = [
    'source_path' => $sourcePath,
    'source_rows' => count($sourceMapping),
    'current_rows' => count($currentMapping),
    'rows_to_update' => count($updates),
    'unchanged_rows' => $unchanged,
    'missing_in_current_db' => $missingInCurrentDb,
    'sample_updates' => array_slice(
        array_map(
            static fn (int $customerId, int $sponsorId): array => [
                'c_userid' => $customerId,
                'restore_c_sponsor' => $sponsorId,
                'current_c_sponsor' => $currentMapping[$customerId] ?? null,
            ],
            array_keys($updates),
            array_values($updates)
        ),
        0,
        10
    ),
];

if (! $apply) {
    echo json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(0);
}

$backupPath = storage_path('app/restore_customer_sponsors_backup_' . date('Ymd_His') . '.json');
$backupRows = [];
foreach ($updates as $customerId => $_sponsorId) {
    $backupRows[] = [
        'c_userid' => $customerId,
        'c_sponsor' => $currentMapping[$customerId],
    ];
}

if (! is_dir(dirname($backupPath))) {
    mkdir(dirname($backupPath), 0777, true);
}

file_put_contents($backupPath, json_encode($backupRows, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

DB::transaction(function () use ($updates): void {
    foreach ($updates as $customerId => $sponsorId) {
        DB::table('tbl_customer')
            ->where('c_userid', $customerId)
            ->update(['c_sponsor' => $sponsorId]);
    }
});

$summary['backup_path'] = $backupPath;
$summary['applied'] = true;

echo json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
