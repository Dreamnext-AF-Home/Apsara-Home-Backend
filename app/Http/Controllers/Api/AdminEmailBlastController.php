<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Mail\Admin\EmailBlastMail;
use App\Models\Admin;
use App\Models\Customer;
use App\Models\Supplier;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;

class AdminEmailBlastController extends Controller
{
    public function send(Request $request)
    {
        try {
            \Log::info('EmailBlast send() - START');
            $actor = $this->resolveAdmin($request);
            \Log::info('EmailBlast resolveAdmin result', ['actor' => $actor ? 'Found' : 'null']);

            if (!$actor) {
                \Log::error('EmailBlast - resolveAdmin failed, returning 401');
                return response()->json(['message' => 'Unauthorized'], 401);
            }

            \Log::info('EmailBlast - Auth passed, checking permissions');

            $canSend = $this->canSendEmailBlasts($actor);
            \Log::info('EmailBlast canSendEmailBlasts', ['can_send' => $canSend, 'user_level_id' => $actor->user_level_id]);

            if (!$canSend) {
                \Log::error('EmailBlast - Permission denied', ['user_level_id' => $actor->user_level_id]);
                return response()->json(['message' => 'Forbidden: you do not have permission to send email blasts.'], 403);
            }

            $validated = $request->validate([
                'subject' => 'required|string|max:255',
                'body' => 'required|string|max:50000',
                'banner_image' => 'nullable|image|max:5120',
                'recipients' => 'required|array|min:1',
                'recipients.*' => 'required|email|max:255',
                'attachments' => 'nullable|array',
                'attachments.*' => 'nullable|file|max:10240',
            ]);

            $subject = trim($validated['subject']);
            $body = trim($validated['body']);
            $emails = array_unique(array_filter($validated['recipients']));

            if (empty($subject) || empty($body)) {
                throw ValidationException::withMessages([
                    'subject' => 'Subject and body cannot be empty.',
                ]);
            }

            if (empty($emails)) {
                return response()->json([
                    'message' => 'No recipients found matching the criteria.',
                    'sent_count' => 0,
                ], 400);
            }

            $bannerImageBase64 = null;
            if ($request->hasFile('banner_image') && $request->file('banner_image')->isValid()) {
                $imageContent = file_get_contents($request->file('banner_image')->getRealPath());
                $bannerImageBase64 = base64_encode($imageContent);
            }

            $attachments = [];
            if (!empty($validated['attachments'])) {
                foreach ($validated['attachments'] as $file) {
                    $attachments[] = $file->getRealPath();
                }
            }

            $sentCount = 0;
            $failedCount = 0;
            $failedEmails = [];

            foreach (array_chunk($emails, 100) as $batch) {
                try {
                    Mail::to($batch)
                        ->send(new EmailBlastMail(
                            subject: $subject,
                            body: $body,
                            brandName: 'AF Home',
                            bannerImageBase64: $bannerImageBase64,
                            attachments: $attachments
                        ));

                    $sentCount += count($batch);
                } catch (\Exception $e) {
                    $failedCount += count($batch);
                    $failedEmails = array_merge($failedEmails, $batch);
                    \Illuminate\Support\Facades\Log::error('Email blast send failed', [
                        'batch_size' => count($batch),
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            return response()->json([
                'message' => 'Email blast sent successfully.',
                'sent_count' => $sentCount,
                'failed_count' => $failedCount,
                'failed_emails' => count($failedEmails) > 0 ? $failedEmails : null,
            ], 200);
        } catch (ValidationException $e) {
            return response()->json([
                'message' => 'Validation failed.',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('Unexpected error in email blast', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'message' => 'An unexpected error occurred.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function getRecipients(Request $request)
    {
        try {
            $actor = $this->resolveAdmin($request);
            if (!$actor) {
                return response()->json(['message' => 'Unauthorized'], 401);
            }

            $validated = $request->validate([
                'recipient_type' => 'required|string|in:members,suppliers,all',
                'recipient_filter' => 'nullable|array',
                'recipient_filter.status' => 'nullable|string',
                'recipient_filter.tier' => 'nullable|string',
                'page' => 'nullable|integer|min:1',
                'per_page' => 'nullable|integer|min:1|max:100000',
            ]);

            $recipientType = $validated['recipient_type'];
            $filter = $validated['recipient_filter'] ?? [];
            $perPage = (int) ($validated['per_page'] ?? 50);

            $emails = $this->getRecipientEmails($recipientType, $filter);

            $totalCount = count($emails);
            $paginatedEmails = array_slice($emails, 0, $perPage);

            return response()->json([
                'recipients' => $paginatedEmails,
                'total_count' => $totalCount,
                'per_page' => $perPage,
            ], 200);
        } catch (ValidationException $e) {
            return response()->json([
                'message' => 'Validation failed.',
                'errors' => $e->errors(),
            ], 422);
        }
    }

    private function getRecipientEmails(string $type, array $filter = []): array
    {
        $emails = [];

        if ($type === 'members' || $type === 'all') {
            $query = Customer::query()->whereNotNull('c_email');
            $emails = array_merge($emails, $query->pluck('c_email')->toArray());
        }

        if ($type === 'suppliers' || $type === 'all') {
            $query = Supplier::query()->whereNotNull('s_email');
            $emails = array_merge($emails, $query->pluck('s_email')->toArray());
        }

        return array_unique(array_filter($emails));
    }

    private function canSendEmailBlasts($actor): bool
    {
        $allowedLevels = [1, 2, 5];
        return in_array($actor->user_level_id, $allowedLevels);
    }

    private function resolveAdmin(Request $request): ?Admin
    {
        $user = $request->user();
        $userClass = $user ? get_class($user) : 'null';
        \Log::info('resolveAdmin - User check', ['user' => $userClass, 'is_admin_class' => $userClass === Admin::class]);

        // Check by class name instead of instanceof due to Sanctum deserialization
        if (!$user || $userClass !== Admin::class) {
            \Log::error('resolveAdmin - Failed check', ['user' => $userClass, 'admin_class' => Admin::class]);
            return null;
        }

        return $user;
    }
}
