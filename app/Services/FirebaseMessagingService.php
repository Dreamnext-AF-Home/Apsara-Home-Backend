<?php

namespace App\Services;

use App\Models\FcmDeviceToken;
use Illuminate\Support\Facades\Log;
use Kreait\Firebase\Factory;
use Kreait\Firebase\Messaging\CloudMessage;
use Kreait\Firebase\Messaging\Notification;

class FirebaseMessagingService
{
    private $messaging;

    public function __construct()
    {
        try {
            $credentialsPath = config('services.firebase.credentials');

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

            $title = $notification['title'] ?? $notification['headings']['en'] ?? 'Notification';
            $body = $notification['body'] ?? $notification['contents']['en'] ?? '';
            $image = $notification['image'] ?? $notification['big_picture'] ?? null;
            $data = $notification['data'] ?? [];

            $notif = Notification::create($title, $body);

            if ($image) {
                $data['image'] = $image;
            }

            $message = CloudMessage::new()
                ->withNotification($notif)
                ->withData($data);

            $report = $this->messaging->sendMulticast($message, $tokens);

            $successful = $report->successes()->count();
            $failed = $report->failures()->count();

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

            $title = $notification['title'] ?? $notification['headings']['en'] ?? 'Notification';
            $body = $notification['body'] ?? $notification['contents']['en'] ?? '';
            $image = $notification['image'] ?? $notification['big_picture'] ?? null;
            $data = $notification['data'] ?? [];

            $notif = Notification::create($title, $body);

            if ($image) {
                $data['image'] = $image;
            }

            $message = CloudMessage::new()
                ->withNotification($notif)
                ->withData($data);

            $this->messaging->send($message, $token);

            Log::info('✅ FCM sent to token');
            return true;
        } catch (\Exception $e) {
            Log::error('❌ FCM send error', [
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }
}
