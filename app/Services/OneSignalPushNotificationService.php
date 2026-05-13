<?php

namespace App\Services;

use App\Models\OneSignalDeviceToken;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class OneSignalPushNotificationService
{
    private string $oneSignalApiUrl = 'https://onesignal.com/api/v1/notifications';
    private string $oneSignalAppId;
    private string $oneSignalApiKey;

    public function __construct()
    {
        $this->oneSignalAppId = config('services.onesignal.app_id');
        $this->oneSignalApiKey = config('services.onesignal.rest_api_key');
    }

    public function sendToCustomer(int $customerId, array $notification): array
    {
        $playerIds = OneSignalDeviceToken::query()
            ->where('odt_customer_id', $customerId)
            ->where('odt_is_active', true)
            ->pluck('odt_player_id')
            ->toArray();

        if (empty($playerIds)) {
            Log::info("No active OneSignal tokens for customer {$customerId}");
            return ['sent' => 0, 'failed' => 0];
        }

        return $this->sendBatch($playerIds, $notification);
    }

    public function sendBatch(array $playerIds, array $notification): array
    {
        if (empty($playerIds)) {
            return ['sent' => 0, 'failed' => 0];
        }

        try {
            Log::info('🚀 [DEBUG] sendBatch - Notification payload being sent', [
                'total_player_ids' => count($playerIds),
                'notification_keys' => array_keys($notification),
                'full_notification_payload' => json_encode($notification, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
            ]);

            $payload = array_merge(
                [
                    'app_id' => $this->oneSignalAppId,
                    'include_player_ids' => $playerIds,
                ],
                $notification
            );

            Log::info('📤 [DEBUG] Sending batch to OneSignal API', [
                'player_ids_count' => count($playerIds),
                'payload_keys' => array_keys($payload),
            ]);

            $response = Http::timeout(30)
                ->withHeaders([
                    'Authorization' => 'Basic ' . $this->oneSignalApiKey,
                    'Content-Type' => 'application/json; charset=utf-8',
                ])
                ->post($this->oneSignalApiUrl, $payload);

            Log::info('📨 [DEBUG] OneSignal API response received', [
                'status_code' => $response->status(),
                'successful' => $response->successful(),
                'response_body' => $response->body(),
            ]);

            if ($response->successful()) {
                $data = $response->json();
                $sent = count($playerIds);
                $failed = 0;

                Log::info('✅ OneSignal notification sent successfully', [
                    'notification_id' => $data['body']['notification_id'] ?? null,
                    'recipients' => $sent,
                ]);

                return ['sent' => $sent, 'failed' => $failed];
            } else {
                $failed = count($playerIds);
                Log::error('❌ OneSignal API error', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);
                return ['sent' => 0, 'failed' => $failed];
            }
        } catch (\Exception $e) {
            Log::error('❌ OneSignal push notification error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return ['sent' => 0, 'failed' => count($playerIds)];
        }
    }

    public function sendToPlayerId(string $playerId, array $notification): bool
    {
        try {
            Log::info('🚀 [DEBUG] sendToPlayerId - Sending to single player', [
                'player_id' => $playerId,
                'notification_keys' => array_keys($notification),
            ]);

            $payload = array_merge(
                [
                    'app_id' => $this->oneSignalAppId,
                    'include_player_ids' => [$playerId],
                ],
                $notification
            );

            $response = Http::timeout(30)
                ->withHeaders([
                    'Authorization' => 'Basic ' . $this->oneSignalApiKey,
                    'Content-Type' => 'application/json; charset=utf-8',
                ])
                ->post($this->oneSignalApiUrl, $payload);

            if ($response->successful()) {
                Log::info('✅ OneSignal notification sent to player', [
                    'player_id' => $playerId,
                    'notification_id' => $response->json()['body']['notification_id'] ?? null,
                ]);
                return true;
            }

            Log::error('❌ OneSignal API error for player', [
                'player_id' => $playerId,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
            return false;
        } catch (\Exception $e) {
            Log::error('❌ OneSignal push notification error', [
                'player_id' => $playerId,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    public function validatePlayerId(string $playerId): bool
    {
        // OneSignal player IDs are typically UUID format
        return !empty($playerId) && strlen($playerId) > 0;
    }
}
