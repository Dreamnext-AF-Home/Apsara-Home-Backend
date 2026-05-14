<?php

namespace App\Services;

use App\Models\FcmDeviceToken;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;

class FirebaseMessagingService
{
    private $projectId;
    private $credentialsPath;

    public function __construct()
    {
        try {
            $credentialsPath = config('services.firebase.credentials');
            $rawJsonCredentials = env('FIREBASE_CREDENTIALS_JSON');

            if (is_string($rawJsonCredentials) && trim($rawJsonCredentials) !== '') {
                $resolvedCredentialsPath = $credentialsPath;
                if (!str_starts_with($resolvedCredentialsPath, DIRECTORY_SEPARATOR)) {
                    $resolvedCredentialsPath = base_path($resolvedCredentialsPath);
                }

                if (!file_exists($resolvedCredentialsPath)) {
                    $credentialsDir = dirname($resolvedCredentialsPath);
                    if (!is_dir($credentialsDir)) {
                        @mkdir($credentialsDir, 0755, true);
                    }
                    @file_put_contents($resolvedCredentialsPath, $rawJsonCredentials);
                    @chmod($resolvedCredentialsPath, 0600);
                }

                $credentialsPath = $resolvedCredentialsPath;
            }

            if (!file_exists($credentialsPath)) {
                $credentialsPath = base_path($credentialsPath);
            }

            if (!file_exists($credentialsPath)) {
                Log::warning('Firebase credentials file not found', ['path' => $credentialsPath]);
                return;
            }

            $credentialsJson = json_decode(file_get_contents($credentialsPath), true);

            if (!$credentialsJson || !is_array($credentialsJson)) {
                Log::error('Invalid Firebase credentials JSON');
                return;
            }

            $this->projectId = $credentialsJson['project_id'] ?? null;
            $this->credentialsPath = $credentialsPath;
            Log::info('Firebase initialized successfully');
        } catch (\Throwable $e) {
            Log::error('Firebase initialization error', ['error' => $e->getMessage()]);
        }
    }

    public function sendToCustomer(int $customerId, array $notification): array
    {
        try {
            Log::info('Sending FCM notification to customer', ['customer_id' => $customerId]);

            $tokens = FcmDeviceToken::query()
                ->where('fdt_customer_id', $customerId)
                ->where('fdt_is_active', true)
                ->pluck('fdt_fcm_token')
                ->toArray();

            if (empty($tokens)) {
                Log::info('No active FCM tokens for customer', ['customer_id' => $customerId]);
                return ['sent' => 0, 'failed' => 0];
            }

            return $this->sendBatch($tokens, $notification);
        } catch (\Exception $e) {
            Log::error('Error sending notification', ['customer_id' => $customerId, 'error' => $e->getMessage()]);
            return ['sent' => 0, 'failed' => count($tokens ?? [])];
        }
    }

    public function sendBatch(array $tokens, array $notification): array
    {
        try {
            if (empty($tokens)) {
                return ['sent' => 0, 'failed' => 0];
            }

            if (!$this->projectId) {
                Log::error('Firebase project ID not configured');
                return ['sent' => 0, 'failed' => count($tokens)];
            }

            $notification = $this->ensureNotificationFields($notification);
            $sent = 0;
            $failed = 0;

            foreach ($tokens as $token) {
                if ($this->sendToToken($token, $notification)) {
                    $sent++;
                } else {
                    $failed++;
                }
            }

            Log::info('FCM batch sent', ['sent' => $sent, 'failed' => $failed]);
            return ['sent' => $sent, 'failed' => $failed];
        } catch (\Exception $e) {
            Log::error('FCM batch error', ['error' => $e->getMessage()]);
            return ['sent' => 0, 'failed' => count($tokens)];
        }
    }

    public function sendToToken(string $token, array $notification): bool
    {
        try {
            if (!$this->projectId) {
                Log::error('Firebase project ID not configured');
                return false;
            }

            $notification = $this->ensureNotificationFields($notification);

            $title = $notification['title'] ?? 'Notification';
            $body = $notification['body'] ?? '';
            $image = $notification['image'] ?? null;
            $color = $notification['color'] ?? '#0284c7';
            $data = $notification['data'] ?? [];

            if ($image) {
                $data['image'] = $image;
            }

            $payload = [
                'message' => [
                    'token' => $token,
                    'notification' => [
                        'title' => $title,
                        'body' => $body,
                    ],
                    'data' => $data,
                    'android' => [
                        'priority' => 'HIGH',
                        'ttl' => '3600s',
                        'notification' => [
                            'title' => $title,
                            'body' => $body,
                            'channel_id' => 'default',
                            'click_action' => 'FLUTTER_NOTIFICATION_CLICK',
                            'color' => $color,
                            'image' => $image,
                            'notification_priority' => 'PRIORITY_MAX',
                            'sound' => 'default',
                            'tag' => 'firebase-notification',
                            'ticker' => $title,
                        ],
                    ],
                    'apns' => [
                        'headers' => [
                            'apns-priority' => '10',
                        ],
                    ],
                ],
            ];

            $accessToken = $this->getAccessToken();
            if (!$accessToken) {
                Log::error('Failed to get Firebase access token');
                return false;
            }

            $response = Http::withToken($accessToken)
                ->post(
                    "https://fcm.googleapis.com/v1/projects/{$this->projectId}/messages:send",
                    $payload
                );

            if ($response->successful()) {
                Log::info('FCM sent to token');
                return true;
            } else {
                Log::error('FCM send error', ['status' => $response->status(), 'body' => $response->body()]);
                return false;
            }
        } catch (\Exception $e) {
            Log::error('FCM send error', ['error' => $e->getMessage()]);
            return false;
        }
    }

    private function getAccessToken(): ?string
    {
        try {
            if (!file_exists($this->credentialsPath)) {
                Log::error('Credentials file not found');
                return null;
            }

            $credentialsJson = json_decode(file_get_contents($this->credentialsPath), true);

            $header = base64_encode(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
            $now = time();
            $payload = base64_encode(json_encode([
                'iss' => $credentialsJson['client_email'],
                'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
                'aud' => 'https://oauth2.googleapis.com/token',
                'exp' => $now + 3600,
                'iat' => $now,
            ]));

            $signature = '';
            openssl_sign("{$header}.{$payload}", $signature, $credentialsJson['private_key'], 'sha256');
            $signature = base64_encode($signature);

            $jwt = "{$header}.{$payload}.{$signature}";

            $response = Http::post('https://oauth2.googleapis.com/token', [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $jwt,
            ]);

            if ($response->successful()) {
                return $response['access_token'] ?? null;
            }

            Log::error('Token request failed', ['status' => $response->status()]);
            return null;
        } catch (\Throwable $e) {
            Log::error('Failed to fetch access token', ['error' => $e->getMessage()]);
            return null;
        }
    }

    private function ensureNotificationFields(array $notification): array
    {
        if (!isset($notification['priority'])) {
            $notification['priority'] = 'high';
        }

        if (!isset($notification['channelId'])) {
            $notification['channelId'] = 'default';
        }

        return $notification;
    }
}
