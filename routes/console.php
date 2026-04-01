<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
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
