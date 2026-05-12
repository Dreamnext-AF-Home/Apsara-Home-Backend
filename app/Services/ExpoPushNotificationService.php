<?php

namespace App\Services;

use App\Models\ExpoDeviceToken;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ExpoPushNotificationService
{
    private string $expoApiUrl = 'https://exp.host/--/api/v2/push/send';

    public function sendToCustomer(int $customerId, array $notification): array
    {
        $tokens = ExpoDeviceToken::query()
            ->where('edt_customer_id', $customerId)
            ->where('edt_is_active', true)
            ->pluck('edt_token')
            ->toArray();

        if (empty($tokens)) {
            Log::info("No active Expo tokens for customer {$customerId}");
            return ['sent' => 0, 'failed' => 0];
        }

        // Ensure critical fields for background/closed app notifications
        $notification = $this->ensureNotificationFields($notification);

        return $this->sendBatch($tokens, $notification);
    }

    public function sendBatch(array $tokens, array $notification): array
    {
        if (empty($tokens)) {
            return ['sent' => 0, 'failed' => 0];
        }

        $sent = 0;
        $failed = 0;

        foreach (array_chunk($tokens, 100) as $chunk) {
            $messages = array_map(function (string $token) use ($notification) {
                return array_merge(['to' => $token], $notification);
            }, $chunk);

            try {
                $response = Http::timeout(30)
                    ->post($this->expoApiUrl, $messages);

                if ($response->successful()) {
                    $data = $response->json();
                    if (is_array($data)) {
                        foreach ($data as $item) {
                            if (isset($item['status']) && $item['status'] === 'ok') {
                                $sent++;
                            } else {
                                $failed++;
                                Log::warning('Expo push notification failed', ['response' => $item]);
                            }
                        }
                    }
                } else {
                    $failed += count($chunk);
                    Log::error('Expo API error', ['status' => $response->status(), 'body' => $response->body()]);
                }
            } catch (\Exception $e) {
                $failed += count($chunk);
                Log::error('Expo push notification error', ['error' => $e->getMessage()]);
            }
        }

        return ['sent' => $sent, 'failed' => $failed];
    }

    public function sendToToken(string $token, array $notification): bool
    {
        try {
            // Ensure critical fields for background/closed app notifications
            $notification = $this->ensureNotificationFields($notification);
            $message = array_merge(['to' => $token], $notification);

            $response = Http::timeout(30)
                ->post($this->expoApiUrl, [$message]);

            if ($response->successful()) {
                $data = $response->json();
                if (is_array($data) && isset($data[0]['status']) && $data[0]['status'] === 'ok') {
                    return true;
                }
                Log::warning('Expo push notification failed for token', ['token' => $token, 'response' => $data[0] ?? null]);
                return false;
            }

            Log::error('Expo API error for token', ['token' => $token, 'status' => $response->status()]);
            return false;
        } catch (\Exception $e) {
            Log::error('Expo push notification error', ['token' => $token, 'error' => $e->getMessage()]);
            return false;
        }
    }

    public function validateToken(string $token): bool
    {
        return preg_match('/^ExponentPushToken\[.+\]$/', $token) === 1;
    }

    /**
     * Ensure critical notification fields for proper delivery when app is closed/background.
     * Sets priority to high and channelId for Android to properly display notifications.
     */
    private function ensureNotificationFields(array $notification): array
    {
        // Set priority to 'high' for immediate delivery on Android when app is closed
        if (!isset($notification['priority'])) {
            $notification['priority'] = 'high';
        }

        // Set channelId for Android to use the default notification channel
        if (!isset($notification['channelId'])) {
            $notification['channelId'] = 'default';
        }

        return $notification;
    }
}
