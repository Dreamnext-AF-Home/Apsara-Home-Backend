<?php

namespace App\Services;

use App\Models\FcmDeviceToken;
use Illuminate\Support\Facades\Log;
use Kreait\Firebase\Factory;
use Kreait\Firebase\Messaging\CloudMessage;

class FirebaseMessagingService
{
    private $messaging;

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

            // Handle both absolute and relative paths
            if (!file_exists($credentialsPath)) {
                $credentialsPath = base_path($credentialsPath);
            }

            if (!file_exists($credentialsPath)) {
                Log::warning('Firebase credentials file not found', ['path' => $credentialsPath]);
                return;
            }

            $credentialsJson = json_decode(file_get_contents($credentialsPath), true);

            if (!$credentialsJson) {
                Log::error('Invalid Firebase credentials JSON');
                return;
            }

            $factory = (new Factory)
                ->withServiceAccount($credentialsJson);
            $this->messaging = $factory->createMessaging();
            Log::info('✅ Firebase initialized successfully');
        } catch (\Exception $e) {
            Log::error('Firebase initialization error:', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
        }
    }

    public function sendToCustomer(int $customerId, array $notification): array
    {
        try {
            Log::info('📤 Sending FCM notification to customer', [
                'customer_id' => $customerId,
            ]);

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
            Log::error('❌ Error sending notification', [
                'customer_id' => $customerId,
                'error' => $e->getMessage(),
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

            if (!$this->messaging) {
                Log::error('Firebase messaging not initialized');
                return ['sent' => 0, 'failed' => count($tokens)];
            }

            Log::info('📤 Sending batch FCM notifications', [
                'token_count' => count($tokens),
            ]);

            // Ensure critical fields for background/closed app notifications
            $notification = $this->ensureNotificationFields($notification);

            $title = $notification['title'] ?? $notification['headings']['en'] ?? 'Notification';
            $body = $notification['body'] ?? $notification['contents']['en'] ?? '';
            $image = $notification['image'] ?? $notification['big_picture'] ?? null;
            $color = $notification['color'] ?? '#0284c7';
            $data = $notification['data'] ?? [];

            if ($image) {
                $data['image'] = $image;
            }

            $messagePayload = [
                'notification' => [
                    'title' => $title,
                    'body' => $body,
                ],
                'data' => $data,
                'android' => [
                    'priority' => 'high',
                    'notification' => [
                        'image' => $image,
                        'channel_id' => $notification['channelId'] ?? 'default',
                        'color' => $color,
                    ],
                ],
                'apns' => [
                    'headers' => [
                        'apns-priority' => '10',
                    ],
                ],
            ];

            $message = CloudMessage::fromArray($messagePayload);

            try {
                $report = $this->messaging->sendMulticast($message, $tokens);

                $successful = $report->successes()->count();
                $failed = $report->failures()->count();

                Log::info('📊 FCM Report Details', [
                    'successful_count' => $successful,
                    'failed_count' => $failed,
                    'tokens_sent' => count($tokens),
                ]);

                // Log failure details
                if ($failed > 0) {
                    $failureDetails = [];
                    foreach ($report->failures()->getItems() as $failure) {
                        $failureDetails[] = [
                            'token' => method_exists($failure, 'target') ? $failure->target()->value() : null,
                            'error' => $failure->error()->getMessage(),
                            'error_class' => get_class($failure->error()),
                        ];
                    }

                    Log::error('❌ FCM batch failures', [
                        'valid_tokens' => $report->validTokens(),
                        'invalid_tokens' => $report->invalidTokens(),
                        'unknown_tokens' => $report->unknownTokens(),
                        'failure_details' => $failureDetails,
                    ]);
                }
            } catch (\Exception $e) {
                Log::error('💥 FCM SendMulticast Exception', [
                    'message' => $e->getMessage(),
                    'code' => $e->getCode(),
                    'trace' => $e->getTraceAsString(),
                ]);
                return ['sent' => 0, 'failed' => count($tokens)];
            }

            Log::info('✅ FCM batch sent', [
                'sent' => $successful,
                'failed' => $failed,
            ]);

            return ['sent' => $successful, 'failed' => $failed];
        } catch (\Exception $e) {
            Log::error('❌ FCM batch error', [
                'error' => $e->getMessage(),
            ]);
            return ['sent' => 0, 'failed' => count($tokens)];
        }
    }

    public function sendToToken(string $token, array $notification): bool
    {
        try {
            if (!$this->messaging) {
                Log::error('Firebase messaging not initialized');
                return false;
            }

            Log::info('📤 Sending FCM to single token', ['token' => substr($token, 0, 20) . '...']);

            // Ensure critical fields for background/closed app notifications
            $notification = $this->ensureNotificationFields($notification);

            $title = $notification['title'] ?? $notification['headings']['en'] ?? 'Notification';
            $body = $notification['body'] ?? $notification['contents']['en'] ?? '';
            $image = $notification['image'] ?? $notification['big_picture'] ?? null;
            $color = $notification['color'] ?? '#0284c7';
            $data = $notification['data'] ?? [];

            if ($image) {
                $data['image'] = $image;
            }

            $messagePayload = [
                'token' => $token,
                'notification' => [
                    'title' => $title,
                    'body' => $body,
                ],
                'data' => $data,
                'android' => [
                    'priority' => 'high',
                    'notification' => [
                        'image' => $image,
                        'channel_id' => $notification['channelId'] ?? 'default',
                        'color' => $color,
                    ],
                ],
                'apns' => [
                    'headers' => [
                        'apns-priority' => '10',
                    ],
                ],
            ];

            $message = CloudMessage::fromArray($messagePayload);
            $this->messaging->send($message);

            Log::info('✅ FCM sent to token');
            return true;
        } catch (\Exception $e) {
            Log::error('❌ FCM send error', [
                'error' => $e->getMessage(),
            ]);
            return false;
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
