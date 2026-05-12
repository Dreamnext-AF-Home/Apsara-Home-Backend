<?php
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();
echo 'cloud_name=' . config('services.cloudinary.cloud_name') . PHP_EOL;
echo 'api_key=' . config('services.cloudinary.api_key') . PHP_EOL;
echo 'api_secret_len=' . strlen((string) config('services.cloudinary.api_secret')) . PHP_EOL;
