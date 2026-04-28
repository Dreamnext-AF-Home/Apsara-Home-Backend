<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class GoogleDriveUploadService
{
    public function isConfigured(): bool
    {
        return $this->folderId() !== ''
            && ($this->hasOauthConfig() || $this->hasServiceAccountConfig());
    }

    /**
     * @return array{id: string|null, name: string|null, webViewLink: string|null}
     */
    public function uploadFile(string $absolutePath, string $uploadName, string $mimeType = 'application/zip'): array
    {
        if (! $this->isConfigured()) {
            throw new RuntimeException('Google Drive upload is not configured.');
        }

        if (! is_file($absolutePath)) {
            throw new RuntimeException('Export file not found for Google Drive upload.');
        }

        $accessToken = $this->fetchAccessToken();
        $fileContent = file_get_contents($absolutePath);
        if ($fileContent === false) {
            throw new RuntimeException('Unable to read export file for Google Drive upload.');
        }

        $metadata = [
            'name' => $uploadName,
            'parents' => [$this->folderId()],
        ];

        $boundary = 'afhome-boundary-' . bin2hex(random_bytes(12));
        $body = '';
        $body .= '--' . $boundary . "\r\n";
        $body .= "Content-Type: application/json; charset=UTF-8\r\n\r\n";
        $body .= json_encode($metadata, JSON_UNESCAPED_SLASHES) . "\r\n";
        $body .= '--' . $boundary . "\r\n";
        $body .= 'Content-Type: ' . $mimeType . "\r\n\r\n";
        $body .= $fileContent . "\r\n";
        $body .= '--' . $boundary . "--\r\n";

        $response = Http::withToken($accessToken)
            ->withHeaders([
                'Content-Type' => 'multipart/related; boundary=' . $boundary,
            ])
            ->withBody($body, 'multipart/related; boundary=' . $boundary)
            ->post('https://www.googleapis.com/upload/drive/v3/files?uploadType=multipart&fields=id,name,webViewLink');

        if (! $response->successful()) {
            throw new RuntimeException('Google Drive upload failed: ' . $response->status() . ' ' . $response->body());
        }

        $json = $response->json();

        return [
            'id' => is_array($json) ? ($json['id'] ?? null) : null,
            'name' => is_array($json) ? ($json['name'] ?? null) : null,
            'webViewLink' => is_array($json) ? ($json['webViewLink'] ?? null) : null,
        ];
    }

    private function fetchAccessToken(): string
    {
        if ($this->hasOauthConfig()) {
            return $this->fetchAccessTokenViaOauthRefreshToken();
        }

        if ($this->hasServiceAccountConfig()) {
            return $this->fetchAccessTokenViaServiceAccount();
        }

        throw new RuntimeException('Google Drive auth is not configured.');
    }

    private function fetchAccessTokenViaOauthRefreshToken(): string
    {
        $tokenResponse = Http::asForm()->post('https://oauth2.googleapis.com/token', [
            'grant_type' => 'refresh_token',
            'client_id' => $this->oauthClientId(),
            'client_secret' => $this->oauthClientSecret(),
            'refresh_token' => $this->oauthRefreshToken(),
        ]);

        if (! $tokenResponse->successful()) {
            throw new RuntimeException('Failed to fetch Google access token via OAuth refresh token: ' . $tokenResponse->status() . ' ' . $tokenResponse->body());
        }

        $accessToken = (string) ($tokenResponse->json('access_token') ?? '');
        if ($accessToken === '') {
            throw new RuntimeException('Google OAuth access token is empty.');
        }

        return $accessToken;
    }

    private function fetchAccessTokenViaServiceAccount(): string
    {
        $now = time();
        $header = ['alg' => 'RS256', 'typ' => 'JWT'];
        $payload = [
            'iss' => $this->serviceAccountEmail(),
            'scope' => 'https://www.googleapis.com/auth/drive.file',
            'aud' => 'https://oauth2.googleapis.com/token',
            'iat' => $now,
            'exp' => $now + 3600,
        ];

        $segments = [
            $this->base64UrlEncode(json_encode($header, JSON_UNESCAPED_SLASHES)),
            $this->base64UrlEncode(json_encode($payload, JSON_UNESCAPED_SLASHES)),
        ];
        $signingInput = implode('.', $segments);

        $signature = '';
        $privateKey = openssl_pkey_get_private($this->serviceAccountPrivateKey());
        if ($privateKey === false) {
            throw new RuntimeException('Invalid Google service account private key.');
        }

        $ok = openssl_sign($signingInput, $signature, $privateKey, OPENSSL_ALGO_SHA256);
        if (! $ok) {
            throw new RuntimeException('Failed to sign Google service account JWT.');
        }

        $jwt = $signingInput . '.' . $this->base64UrlEncode($signature);

        $tokenResponse = Http::asForm()->post('https://oauth2.googleapis.com/token', [
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion' => $jwt,
        ]);

        if (! $tokenResponse->successful()) {
            throw new RuntimeException('Failed to fetch Google access token: ' . $tokenResponse->status() . ' ' . $tokenResponse->body());
        }

        $accessToken = (string) ($tokenResponse->json('access_token') ?? '');
        if ($accessToken === '') {
            throw new RuntimeException('Google access token is empty.');
        }

        return $accessToken;
    }

    private function hasOauthConfig(): bool
    {
        return $this->oauthClientId() !== ''
            && $this->oauthClientSecret() !== ''
            && $this->oauthRefreshToken() !== '';
    }

    private function hasServiceAccountConfig(): bool
    {
        return $this->serviceAccountEmail() !== ''
            && $this->serviceAccountPrivateKey() !== '';
    }

    private function folderId(): string
    {
        $rawId = trim((string) config('services.google_drive.folder_id', ''));
        if ($rawId !== '') {
            return $rawId;
        }

        $url = trim((string) config('services.google_drive.folder_url', ''));
        if ($url === '') {
            return '';
        }

        if (preg_match('~/folders/([^/?]+)~', $url, $matches) === 1) {
            return trim((string) ($matches[1] ?? ''));
        }

        return '';
    }

    private function serviceAccountEmail(): string
    {
        return trim((string) config('services.google_drive.service_account_email', ''));
    }

    private function serviceAccountPrivateKey(): string
    {
        $raw = (string) config('services.google_drive.service_account_private_key', '');
        $normalized = str_replace('\n', "\n", $raw);
        return trim($normalized);
    }

    private function oauthClientId(): string
    {
        return trim((string) config('services.google_drive.oauth_client_id', ''));
    }

    private function oauthClientSecret(): string
    {
        return trim((string) config('services.google_drive.oauth_client_secret', ''));
    }

    private function oauthRefreshToken(): string
    {
        return trim((string) config('services.google_drive.oauth_refresh_token', ''));
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
