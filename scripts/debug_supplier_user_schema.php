<?php

require __DIR__ . '/../vendor/autoload.php';

$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$row = Illuminate\Support\Facades\DB::selectOne(
    "select is_nullable, data_type, column_default
     from information_schema.columns
     where table_name = 'tbl_supplier_user'
       and column_name = 'su_id'"
);

echo json_encode($row, JSON_PRETTY_PRINT) . PHP_EOL;

