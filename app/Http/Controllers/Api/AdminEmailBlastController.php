<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Mail\Admin\EmailBlastMail;
use App\Models\Admin;
use App\Models\Customer;
use App\Models\Supplier;
use Carbon\Carbon;
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
                'recipients' => 'nullable|array',
                'recipients.*' => 'required|email|max:255',
                'recipient_type' => 'nullable|string|in:members,suppliers,all',
                'attachments' => 'nullable|array',
                'attachments.*' => 'nullable|file|max:10240',
            ]);

            $subject = trim($validated['subject']);
            $body = trim($validated['body']);
            $recipientType = (string) ($validated['recipient_type'] ?? '');
            $recipientRows = $this->resolveRecipientRows(
                type: $recipientType !== '' ? $recipientType : 'members',
                providedEmails: $validated['recipients'] ?? []
            );

            if (empty($subject) || empty($body)) {
                throw ValidationException::withMessages([
                    'subject' => 'Subject and body cannot be empty.',
                ]);
            }

            if (empty($recipientRows)) {
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

            foreach ($recipientRows as $recipient) {
                try {
                    $variables = $this->buildPersonalizationVariables($recipient);
                    $personalizedSubject = $this->applyTemplateVariables($subject, $variables);
                    $personalizedBody = $this->applyTemplateVariables($body, $variables);

                    Mail::to($recipient['email'])
                        ->send(new EmailBlastMail(
                            subject: $personalizedSubject,
                            body: $personalizedBody,
                            brandName: 'AF Home',
                            bannerImageBase64: $bannerImageBase64,
                            attachments: $attachments
                        ));

                    $sentCount++;
                } catch (\Exception $e) {
                    $failedCount++;
                    $failedEmails[] = (string) ($recipient['email'] ?? '');
                    \Illuminate\Support\Facades\Log::error('Email blast send failed', [
                        'recipient' => (string) ($recipient['email'] ?? ''),
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

    /**
     * @return array<int, array{email:string,name:string,first_name:string,last_name:string,username:string,registered_at:string}>
     */
    private function resolveRecipientRows(string $type, array $providedEmails = []): array
    {
        $normalizedProvidedEmails = collect($providedEmails)
            ->map(fn ($email) => strtolower(trim((string) $email)))
            ->filter(fn (string $email) => $email !== '')
            ->unique()
            ->values();

        if ($normalizedProvidedEmails->isNotEmpty()) {
            $rows = Customer::query()
                ->whereIn('c_email', $normalizedProvidedEmails->all())
                ->get(['c_email', 'c_fname', 'c_lname', 'c_username', 'c_date_started'])
                ->map(function (Customer $customer): array {
                    $email = strtolower(trim((string) ($customer->c_email ?? '')));
                    $firstName = trim((string) ($customer->c_fname ?? ''));
                    $lastName = trim((string) ($customer->c_lname ?? ''));
                    $fullName = trim($firstName . ' ' . $lastName);
                    $username = trim((string) ($customer->c_username ?? ''));
                    $registeredAt = $customer->c_date_started ? Carbon::parse($customer->c_date_started)->toDateString() : '';

                    return [
                        'email' => $email,
                        'name' => $fullName !== '' ? $fullName : ($username !== '' ? $username : 'Customer'),
                        'first_name' => $firstName !== '' ? $firstName : 'Customer',
                        'last_name' => $lastName,
                        'username' => $username !== '' ? $username : 'member',
                        'registered_at' => $registeredAt,
                    ];
                })
                ->filter(fn (array $row) => $row['email'] !== '')
                ->values()
                ->all();

            if (!empty($rows)) {
                return $rows;
            }

            return $normalizedProvidedEmails
                ->map(function (string $email): array {
                    $localName = trim((string) explode('@', $email)[0]);
                    return [
                        'email' => $email,
                        'name' => $localName !== '' ? $localName : 'Customer',
                        'first_name' => $localName !== '' ? $localName : 'Customer',
                        'last_name' => '',
                        'username' => $localName !== '' ? $localName : 'member',
                        'registered_at' => '',
                    ];
                })
                ->all();
        }

        if ($type !== 'members') {
            // Announcements currently target members only for personalization.
            $type = 'members';
        }

        return Customer::query()
            ->whereNotNull('c_email')
            ->where('c_email', '!=', '')
            ->get(['c_email', 'c_fname', 'c_lname', 'c_username', 'c_date_started'])
            ->map(function (Customer $customer): array {
                $email = strtolower(trim((string) ($customer->c_email ?? '')));
                $firstName = trim((string) ($customer->c_fname ?? ''));
                $lastName = trim((string) ($customer->c_lname ?? ''));
                $fullName = trim($firstName . ' ' . $lastName);
                $username = trim((string) ($customer->c_username ?? ''));
                $registeredAt = $customer->c_date_started ? Carbon::parse($customer->c_date_started)->toDateString() : '';

                return [
                    'email' => $email,
                    'name' => $fullName !== '' ? $fullName : ($username !== '' ? $username : 'Customer'),
                    'first_name' => $firstName !== '' ? $firstName : 'Customer',
                    'last_name' => $lastName,
                    'username' => $username !== '' ? $username : 'member',
                    'registered_at' => $registeredAt,
                ];
            })
            ->filter(fn (array $row) => $row['email'] !== '')
            ->unique('email')
            ->values()
            ->all();
    }

    /**
     * @param array{email:string,name:string,first_name:string,last_name:string,username:string,registered_at:string} $recipient
     * @return array<string,string>
     */
    private function buildPersonalizationVariables(array $recipient): array
    {
        $now = now();
        return [
            'customer_name' => (string) ($recipient['name'] ?? 'Customer'),
            'first_name' => (string) ($recipient['first_name'] ?? 'Customer'),
            'last_name' => (string) ($recipient['last_name'] ?? ''),
            'username' => (string) ($recipient['username'] ?? 'member'),
            'customer_email' => (string) ($recipient['email'] ?? ''),
            'email' => (string) ($recipient['email'] ?? ''),
            'store_name' => 'AF Home',
            'current_date' => $now->format('F j, Y'),
            'current_time' => $now->format('g:i A'),
            'current_year' => $now->format('Y'),
            'member_since' => (string) ($recipient['registered_at'] ?? ''),
            'support_email' => (string) config('mail.from.address', 'support@afhome.com'),
        ];
    }

    /**
     * Replaces both {{variable}} and {{ variable }} tokens.
     * Unknown variables are left as-is.
     *
     * @param array<string,string> $variables
     */
    private function applyTemplateVariables(string $content, array $variables): string
    {
        $result = $content;
        foreach ($variables as $key => $value) {
            $escapedKey = preg_quote((string) $key, '/');
            // Replace tokens like {{customer_name}}, {{ customer_name }}, and variants with extra spaces/non-breaking spaces.
            $result = (string) preg_replace(
                '/\{\{[\s\x{00A0}]*' . $escapedKey . '[\s\x{00A0}]*\}\}/iu',
                $value,
                $result
            );
        }

        return $result;
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
