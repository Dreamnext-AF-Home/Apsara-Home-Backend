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
            Log::info('FCM: Starting sendToCustomer', ['customer_id' => $customerId]);

            $tokens = FcmDeviceToken::query()
                ->where('fdt_customer_id', $customerId)
                ->where('fdt_is_active', true)
                ->pluck('fdt_fcm_token')
                ->toArray();

            Log::info('FCM: Tokens retrieved', [
                'customer_id' => $customerId,
                'token_count' => count($tokens),
                'tokens' => $tokens,
            ]);

            if (empty($tokens)) {
                Log::warning('FCM: No active FCM tokens for customer', ['customer_id' => $customerId]);
                return ['sent' => 0, 'failed' => 0];
            }

            return $this->sendBatch($tokens, $notification);
        } catch (\Exception $e) {
            Log::error('FCM: Error in sendToCustomer', [
                'customer_id' => $customerId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
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
                Log::error('FCM: Project ID not configured');
                return ['sent' => 0, 'failed' => count($tokens)];
            }

            $notification = $this->ensureNotificationFields($notification);
            $sent = 0;
            $failed = 0;

            Log::info('FCM: Starting batch send', [
                'token_count' => count($tokens),
                'notification_title' => $notification['title'] ?? 'N/A',
            ]);

            foreach ($tokens as $token) {
                if ($this->sendToToken($token, $notification)) {
                    $sent++;
                } else {
                    $failed++;
                }
            }

            Log::info('FCM: Batch send complete', [
                'sent' => $sent,
                'failed' => $failed,
                'total_tokens' => count($tokens),
            ]);
            return ['sent' => $sent, 'failed' => $failed];
        } catch (\Exception $e) {
            Log::error('FCM: Batch error', [
                'error' => $e->getMessage(),
                'token_count' => count($tokens),
                'trace' => $e->getTraceAsString(),
            ]);
            return ['sent' => 0, 'failed' => count($tokens)];
        }
    }

    public function sendToToken(string $token, array $notification): bool
    {
        try {
            if (!$this->projectId) {
                Log::error('FCM: Project ID not configured');
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

            $androidNotification = [
                'title' => $title,
                'body' => $body,
                'channel_id' => 'default',
                'click_action' => 'FLUTTER_NOTIFICATION_CLICK',
                'color' => $color,
                'notification_priority' => 'PRIORITY_MAX',
                'sound' => 'default',
                'tag' => 'firebase-notification',
                'ticker' => $title,
            ];

            // Only add image if it exists and is not empty
            if ($image && trim((string) $image) !== '') {
                $androidNotification['image'] = (string) $image;
            }

            // Send data-only notification so app can handle via notifee with buttons
            // Include notification data in the data payload for app to use
            $dataPayload = array_merge($data, [
                'title' => $title,
                'body' => $body,
                'image' => $image ?: '',
            ]);

            $payload = [
                'message' => [
                    'token' => $token,
                    'data' => $dataPayload,
                    'android' => [
                        'priority' => 'HIGH',
                        'ttl' => '3600s',
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
                Log::error('FCM: Failed to get access token for token: ' . substr($token, 0, 20) . '...');
                return false;
            }

            Log::info('FCM: Sending message', [
                'token' => substr($token, 0, 20) . '...',
                'title' => $title,
                'body' => $body,
                'has_image' => !empty($image),
                'image_url' => !empty($image) ? substr($image, 0, 50) . '...' : 'NONE',
            ]);

            $response = Http::withToken($accessToken)
                ->post(
                    "https://fcm.googleapis.com/v1/projects/{$this->projectId}/messages:send",
                    $payload
                );

            if ($response->successful()) {
                Log::info('FCM: Message sent successfully', [
                    'token' => substr($token, 0, 20) . '...',
                    'response' => $response->body(),
                ]);
                return true;
            } else {
                Log::error('FCM: Send failed', [
                    'token' => substr($token, 0, 20) . '...',
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);
                return false;
            }
        } catch (\Exception $e) {
            Log::error('FCM: Exception in sendToToken', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
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
