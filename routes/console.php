<?php

use App\Services\Payments\PaymongoPaymentSyncService;
use App\Services\Zq\ZqTrackingSyncService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\Schema;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('backend:check', function () {
    $migrationTableExists = Schema::hasTable('migrations');
    $migrationCount = $migrationTableExists ? DB::table('migrations')->count() : 0;
    $latestMigration = $migrationTableExists
        ? DB::table('migrations')->orderByDesc('id')->value('migration')
        : null;

    $this->newLine();
    $this->info('Backend check OK');
    $this->line('App: ' . config('app.name'));
    $this->line('Environment: ' . app()->environment());
    $this->line('Database connection: ' . DB::connection()->getName());
    $this->line('Database name: ' . config('database.connections.' . config('database.default') . '.database'));
    $this->line('Migrations table: ' . ($migrationTableExists ? 'found' : 'missing'));
    $this->line('Migration count: ' . $migrationCount);
    $this->line('Latest migration: ' . ($latestMigration ?? 'none'));
    $this->newLine();
})->purpose('Print a quick backend and migration status check');

Artisan::command('payments:sync-pending {--limit=25}', function () {
    /** @var PaymongoPaymentSyncService $service */
    $service = app(PaymongoPaymentSyncService::class);
    $summary = $service->syncPendingOrders((int) $this->option('limit'));

    $this->newLine();
    $this->info('PayMongo pending payment sync completed.');
    $this->line('Processed: ' . (int) ($summary['processed'] ?? 0));
    $this->line('Updated: ' . (int) ($summary['updated'] ?? 0));
    $this->line('Skipped: ' . (int) ($summary['skipped'] ?? 0));

    $errors = $summary['errors'] ?? [];
    if (! empty($errors)) {
        $this->warn('Errors:');
        foreach ($errors as $error) {
            $this->line('- ' . $error);
        }
    }

    $this->newLine();
})->purpose('Reconcile recent pending checkout sessions with PayMongo');

Artisan::command('zq:sync-tracking {--limit=25}', function () {
    /** @var ZqTrackingSyncService $service */
    $service = app(ZqTrackingSyncService::class);
    $summary = $service->syncPendingOrders((int) $this->option('limit'));

    $this->newLine();
    $this->info('ZQ tracking sync completed.');
    $this->line('Processed: ' . (int) ($summary['processed'] ?? 0));
    $this->line('Updated: ' . (int) ($summary['updated'] ?? 0));
    $this->line('Skipped: ' . (int) ($summary['skipped'] ?? 0));

    $errors = $summary['errors'] ?? [];
    if (! empty($errors)) {
        $this->warn('Errors:');
        foreach ($errors as $error) {
            $this->line('- ' . $error);
        }
    }

    $this->newLine();
})->purpose('Sync pending ZQ tracking updates into local orders');

Artisan::command('psgc:import-addresses {--truncate : Truncate address tables before import}', function () {
    $psgcBaseUrl = 'https://psgc.gitlab.io/api';
    $philippinesCountryId = 175;

    $fetchPsgc = function (string $path) use ($psgcBaseUrl) {
        $response = Http::acceptJson()
            ->timeout(60)
            ->retry(3, 500)
            ->get("{$psgcBaseUrl}{$path}");

        if (! $response->successful()) {
            throw new RuntimeException("PSGC request failed for {$path} with HTTP {$response->status()}.");
        }

        return $response->json();
    };

    $normalizeItems = function (array $items): array {
        return collect($items)
            ->map(function ($item) {
                $code = trim((string) ($item['code'] ?? ''));
                $name = trim((string) ($item['regionName'] ?? $item['name'] ?? ''));

                return [
                    'code' => $code,
                    'name' => $name,
                ];
            })
            ->filter(fn (array $item) => $item['code'] !== '' && $item['name'] !== '')
            ->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)
            ->values()
            ->all();
    };

    $insertInChunks = function (string $table, array $rows) {
        foreach (array_chunk($rows, 1000) as $chunk) {
            DB::table($table)->insert($chunk);
        }
    };

    $toRegionCode = function (string $psgcCode): string {
        return substr($psgcCode, 0, 2);
    };

    $toProvinceCode = function (string $psgcCode): string {
        return substr($psgcCode, 0, 4);
    };

    $toCityCode = function (string $psgcCode): string {
        return substr($psgcCode, 0, 6);
    };

    $this->info('Fetching PSGC regions...');
    $regions = $normalizeItems($fetchPsgc('/regions/'));

    if (empty($regions)) {
        $this->error('PSGC returned no regions. Import aborted.');
        return self::FAILURE;
    }

    $regionRows = [];
    $provinceRows = [];
    $cityRows = [];
    $barangayRows = [];
    $provinceCodesByRegion = [];
    $cityCodesForBarangays = [];

    foreach ($regions as $region) {
        $regionRows[] = [
            'region_name' => $region['name'],
            'region_code' => $toRegionCode($region['code']),
            'country_id' => $philippinesCountryId,
        ];

        $this->line("Fetching provinces for {$region['name']}...");
        $regionProvinces = $normalizeItems($fetchPsgc("/regions/{$region['code']}/provinces/"));

        if (empty($regionProvinces)) {
            $this->line("No provinces for {$region['name']}; fetching region-level cities/municipalities...");
            $regionCities = $normalizeItems($fetchPsgc("/regions/{$region['code']}/cities-municipalities/"));

            foreach ($regionCities as $city) {
                $cityRows[] = [
                    'city_name' => $city['name'],
                    'region_code' => $toRegionCode($region['code']),
                    'prov_code' => '',
                    'city_code' => $toCityCode($city['code']),
                    'psgc_code' => $city['code'],
                    'city_status' => 1,
                ];

                $cityCodesForBarangays[] = [
                    'region_code' => $toRegionCode($region['code']),
                    'prov_code' => '',
                    'city_code' => $toCityCode($city['code']),
                    'city_psgc_code' => $city['code'],
                    'city_name' => $city['name'],
                ];
            }

            continue;
        }

        foreach ($regionProvinces as $province) {
            $provinceRows[] = [
                'prov_name' => $province['name'],
                'region_code' => $toRegionCode($region['code']),
                'prov_code' => $toProvinceCode($province['code']),
                'psgc_code' => $province['code'],
                'prov_status' => 1,
            ];

            $provinceCodesByRegion[] = [
                'region_code' => $toRegionCode($region['code']),
                'prov_code' => $toProvinceCode($province['code']),
                'prov_psgc_code' => $province['code'],
                'prov_name' => $province['name'],
            ];
        }
    }

    foreach ($provinceCodesByRegion as $provinceMeta) {
        $this->line("Fetching cities/municipalities for {$provinceMeta['prov_name']}...");
        $provinceCities = $normalizeItems($fetchPsgc("/provinces/{$provinceMeta['prov_psgc_code']}/cities-municipalities/"));

        foreach ($provinceCities as $city) {
            $cityRows[] = [
                'city_name' => $city['name'],
                'region_code' => $provinceMeta['region_code'],
                'prov_code' => $provinceMeta['prov_code'],
                'city_code' => $toCityCode($city['code']),
                'psgc_code' => $city['code'],
                'city_status' => 1,
            ];

            $cityCodesForBarangays[] = [
                'region_code' => $provinceMeta['region_code'],
                'prov_code' => $provinceMeta['prov_code'],
                'city_code' => $toCityCode($city['code']),
                'city_psgc_code' => $city['code'],
                'city_name' => $city['name'],
            ];
        }
    }

    foreach ($cityCodesForBarangays as $cityMeta) {
        $this->line("Fetching barangays for {$cityMeta['city_name']}...");
        $cityBarangays = $normalizeItems($fetchPsgc("/cities-municipalities/{$cityMeta['city_psgc_code']}/barangays/"));

        foreach ($cityBarangays as $barangay) {
            $barangayRows[] = [
                'barangay_name' => $barangay['name'],
                'region_code' => $cityMeta['region_code'],
                'prov_code' => $cityMeta['prov_code'],
                'city_code' => $cityMeta['city_code'],
                'barangay_code' => substr($barangay['code'], 0, 10),
                'barangay_status' => 1,
            ];
        }
    }

    DB::transaction(function () use ($regionRows, $provinceRows, $cityRows, $barangayRows, $insertInChunks) {
        DB::statement('TRUNCATE TABLE tbl_address_barangay, tbl_address_city, tbl_address_province, tbl_address_region RESTART IDENTITY CASCADE');

        $insertInChunks('tbl_address_region', $regionRows);
        $insertInChunks('tbl_address_province', $provinceRows);
        $insertInChunks('tbl_address_city', $cityRows);
        $insertInChunks('tbl_address_barangay', $barangayRows);
    });

    $this->newLine();
    $this->info('PSGC address import completed.');
    $this->line('Regions: ' . count($regionRows));
    $this->line('Provinces: ' . count($provinceRows));
    $this->line('Cities/Municipalities: ' . count($cityRows));
    $this->line('Barangays: ' . count($barangayRows));
    $this->newLine();

    return self::SUCCESS;
})->purpose('Import PSGC regions, provinces, cities, and barangays into backend address tables');

Schedule::command('payments:sync-pending --limit=25')
    ->everyFiveMinutes()
    ->withoutOverlapping();

Schedule::command('zq:sync-tracking --limit=25')
    ->everyFiveMinutes()
    ->withoutOverlapping();
