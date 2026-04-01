<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class VisionEmbeddingService
{
    public function embedImage(string $image): ?array
    {
        $endpoint = trim((string) config('services.vision_embedding.url', env('VISION_EMBEDDING_URL', '')));
        if ($endpoint === '') {
            return null;
        }

        try {
            $payload = $this->buildPayload($image);
            if ($payload === null) {
                return null;
            }

            $res = Http::timeout(20)->post($endpoint, $payload);

            if ($res->failed()) {
                Log::warning('Vision embedding request failed', [
                    'status' => $res->status(),
                    'body' => $res->body(),
                ]);
                return null;
            }

            $vector = $res->json('embedding');
            if (!is_array($vector) || empty($vector)) {
                return null;
            }

            return $vector;
        } catch (\Throwable $e) {
            Log::warning('Vision embedding request error', [
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    private function buildPayload(string $image): ?array
    {
        $trimmed = trim($image);
        if ($trimmed === '') {
            return null;
        }

        if (str_starts_with($trimmed, 'data:image/')) {
            return ['image_data' => $trimmed];
        }

        return ['image_url' => $trimmed];
    }
}
