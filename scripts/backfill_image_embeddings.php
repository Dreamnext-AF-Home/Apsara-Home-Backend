<?php

use App\Services\VisionEmbeddingService;
use Illuminate\Support\Facades\DB;

require __DIR__ . '/../vendor/autoload.php';

$app = require_once __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$embedder = new VisionEmbeddingService();

$limit = null;
$offset = 0;
$sleepMs = 0;
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--limit=')) {
        $limit = (int) substr($arg, strlen('--limit='));
    } elseif (str_starts_with($arg, '--offset=')) {
        $offset = (int) substr($arg, strlen('--offset='));
    } elseif (str_starts_with($arg, '--sleep-ms=')) {
        $sleepMs = max(0, (int) substr($arg, strlen('--sleep-ms=')));
    }
}

$reset = in_array('--reset', $argv, true);
if ($reset) {
    DB::table('tbl_product_image_embeddings')->truncate();
    echo "Embeddings table truncated.\n";
}

$photoQuery = DB::table('tbl_product_photo as pp')
    ->select('pp.pp_id', 'pp.pp_pdid', 'pp.pp_filename')
    ->orderBy('pp.pp_id')
    ->when($offset > 0, fn ($q) => $q->offset($offset))
    ->when($limit !== null && $limit > 0, fn ($q) => $q->limit($limit));

$photoRows = $photoQuery->get();

$backendBase = rtrim((string) env('APP_URL', ''), '/');
if ($backendBase === '') {
    $backendBase = rtrim((string) env('FRONTEND_URL', ''), '/');
}

$total = $photoRows->count();
$failed = 0;
$skipped = 0;
$embedUrl = trim((string) env('VISION_EMBEDDING_URL', ''));
if ($embedUrl === '') {
    echo "VISION_EMBEDDING_URL is not set. Please set it in .env and retry.\n";
    exit(1);
}

$failureLogPath = storage_path('logs/vision_embed_failures.log');

$inserted = 0;
foreach ($photoRows as $row) {
    $photoId = (int) $row->pp_id;
    $productId = (int) $row->pp_pdid;
    $filename = trim((string) ($row->pp_filename ?? ''));
    if ($productId <= 0 || $filename === '') {
        $skipped++;
        continue;
    }

    $imageSource = null;
    if (preg_match('#^https?://#i', $filename)) {
        $imageSource = $filename;
    } else {
        $localPath = base_path('public/product_img/' . $filename);
        if (is_file($localPath) && is_readable($localPath)) {
            $mime = mime_content_type($localPath) ?: 'image/jpeg';
            $imageSource = 'data:' . $mime . ';base64,' . base64_encode(file_get_contents($localPath));
        } elseif ($backendBase !== '') {
            $imageSource = $backendBase . '/product_img/' . rawurlencode($filename);
        }
    }

    if ($imageSource === null) {
        $failed++;
        continue;
    }

    $exists = DB::table('tbl_product_image_embeddings')
        ->where('pie_photo_id', $photoId)
        ->exists();
    if ($exists) {
        $skipped++;
        continue;
    }

    $embedding = null;
    for ($attempt = 1; $attempt <= 3; $attempt++) {
        $embedding = $embedder->embedImage($imageSource);
        if (is_array($embedding) && !empty($embedding)) {
            break;
        }
        usleep(250000);
    }
    if (!is_array($embedding) || empty($embedding)) {
        $failed++;
        file_put_contents($failureLogPath, $imageSource . PHP_EOL, FILE_APPEND);
        continue;
    }

    DB::table('tbl_product_image_embeddings')->insert([
        'pie_product_id' => $productId,
        'pie_photo_id' => $photoId,
        'pie_image_url' => $imageSource,
        'pie_embedding' => DB::raw("'" . '[' . implode(',', array_map(
            static fn ($v) => number_format((float) $v, 6, '.', ''),
            $embedding
        )) . ']' . "'"),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $inserted++;
    if ($inserted % 25 === 0) {
        echo "Inserted {$inserted} embeddings... (failed {$failed}, skipped {$skipped})\n";
    }
    if ($sleepMs > 0) {
        usleep($sleepMs * 1000);
    }
}

echo "Done. Inserted {$inserted} embeddings. Failed {$failed}. Skipped {$skipped}. Total {$total}.\n";
if ($failed > 0) {
    echo "Failed URLs logged to {$failureLogPath}\n";
}
