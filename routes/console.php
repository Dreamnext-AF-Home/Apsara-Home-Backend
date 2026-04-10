<?php

use App\Services\Payments\PaymongoPaymentSyncService;
use App\Services\Zq\ZqTrackingSyncService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
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

Schedule::command('payments:sync-pending --limit=25')
    ->everyFiveMinutes()
    ->withoutOverlapping();

Schedule::command('zq:sync-tracking --limit=25')
    ->everyFiveMinutes()
    ->withoutOverlapping();
