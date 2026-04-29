<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class CloudinaryUploadService
{
    public function uploadImage(UploadedFile $file, string $folder = 'afhome/expenses/invoices'): array
    {
        return $this->uploadFile($file, $folder, 'image');
    }

    public function uploadVideo(UploadedFile $file, string $folder = 'afhome/reviews/videos'): array
    {
        return $this->uploadFile($file, $folder, 'video');
    }

    private function uploadFile(UploadedFile $file, string $folder, string $resourceType): array
    {
        $cloudName = trim((string) env('CLOUDINARY_CLOUD_NAME', ''));
        $apiKey = trim((string) env('CLOUDINARY_API_KEY', ''));
        $apiSecret = trim((string) env('CLOUDINARY_API_SECRET', ''));

        if ($cloudName === '' || $apiKey === '' || $apiSecret === '') {
            throw new RuntimeException('Cloudinary is not configured. Please set CLOUDINARY_CLOUD_NAME, CLOUDINARY_API_KEY, and CLOUDINARY_API_SECRET.');
        }

        $timestamp = time();
        $folder = trim($folder, '/');

        $signatureBase = "folder={$folder}&timestamp={$timestamp}{$apiSecret}";
        $signature = sha1($signatureBase);

        $endpoint = "https://api.cloudinary.com/v1_1/{$cloudName}/{$resourceType}/upload";

        $response = Http::timeout(30)
            ->attach(
                'file',
                file_get_contents($file->getRealPath()) ?: '',
                $file->getClientOriginalName()
            )
            ->asMultipart()
            ->post($endpoint, [
                'api_key' => $apiKey,
                'timestamp' => (string) $timestamp,
                'folder' => $folder,
                'signature' => $signature,
            ]);

        if (! $response->ok()) {
            $message = (string) data_get($response->json(), 'error.message', 'Cloudinary upload failed.');
            throw new RuntimeException($message);
        }

        $payload = $response->json();
        $secureUrl = (string) data_get($payload, 'secure_url', '');
        $publicId = (string) data_get($payload, 'public_id', '');

        if ($secureUrl === '' || $publicId === '') {
            throw new RuntimeException('Cloudinary returned an invalid upload response.');
        }

        return [
            'secure_url' => $secureUrl,
            'public_id' => $publicId,
        ];
    }
}
