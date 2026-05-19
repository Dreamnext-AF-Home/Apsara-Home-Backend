<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Admin;
use App\Models\Customer;
use App\Models\CustomerNotification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class AdminInquiryController extends Controller
{
    public function webstoreRequests(Request $request)
    {
        $subject = mb_strtolower($this->webstoreRequestTicketSubject(), 'UTF-8');
        $tickets = DB::table('tbl_tickets')
            ->where(function ($query) use ($subject) {
                $query
                    ->whereRaw('LOWER(TRIM(t_subject)) = ?', [$subject])
                    ->orWhereRaw('LOWER(TRIM(t_subject)) = ?', ['webstore request'])
                    ->orWhereRaw('LOWER(TRIM(t_subject)) = ?', ['partner webstore request']);
            })
            ->orderByDesc('t_id')
            ->get();

        $rows = $tickets->map(function ($ticket): array {
            $customer = Customer::query()->where('c_userid', (int) $ticket->t_eid)->first();
            $customerName = $customer instanceof Customer ? $this->fullName($customer) : null;

            $requestDetail = DB::table('tbl_tickets_details')
                ->where('t_id', (int) $ticket->t_id)
                ->where('td_replystat', 0)
                ->orderBy('td_id')
                ->first();
            $payload = $this->decodeWebstorePayload($requestDetail?->td_content ?? null);

            return [
                'id' => (int) $ticket->t_id,
                'ticket_id' => (int) $ticket->t_id,
                'customer_id' => (int) ($ticket->t_eid ?? 0),
                'customer_name' => $customerName,
                'customer_email' => $customer instanceof Customer ? (string) ($customer->c_email ?? '') : null,
                'full_name' => (string) ($payload['full_name'] ?? ''),
                'username' => (string) ($payload['username'] ?? ''),
                'email' => (string) ($payload['email'] ?? ''),
                'slug_name' => (string) ($payload['slug_name'] ?? ''),
                'display_name' => (string) ($payload['display_name'] ?? ''),
                'status' => $this->mapTicketDecisionStatus((int) $ticket->t_status, (int) $ticket->t_id),
                'submitted_at' => $ticket->t_date ? (string) $ticket->t_date : null,
            ];
        })->values();

        return response()->json(['requests' => $rows]);
    }

    public function usernameChangeRequests(Request $request)
    {
        $tickets = DB::table('tbl_tickets')
            ->where('t_subject', $this->usernameChangeTicketSubject())
            ->orderByDesc('t_id')
            ->get();

        $rows = $tickets->map(function ($ticket): array {
            $customer = Customer::query()->where('c_userid', (int) $ticket->t_eid)->first();
            $customerName = $customer instanceof Customer ? $this->fullName($customer) : null;

            $requestDetail = DB::table('tbl_tickets_details')
                ->where('t_id', (int) $ticket->t_id)
                ->where('td_replystat', 0)
                ->orderBy('td_id')
                ->first();
            $payload = $this->decodeUsernameChangePayload($requestDetail?->td_content ?? null);

            return [
                'id' => (int) $ticket->t_id,
                'ticket_id' => (int) $ticket->t_id,
                'customer_id' => (int) ($ticket->t_eid ?? 0),
                'customer_name' => $customerName,
                'customer_email' => $customer instanceof Customer ? (string) ($customer->c_email ?? '') : null,
                'current_username' => (string) ($payload['current_username'] ?? ''),
                'requested_username' => (string) ($payload['requested_username'] ?? ''),
                'status' => $this->mapUsernameChangeStatus((int) $ticket->t_status, (int) $ticket->t_id),
                'submitted_at' => $ticket->t_date ? (string) $ticket->t_date : null,
            ];
        })->values();

        return response()->json(['requests' => $rows]);
    }

    public function approveUsernameChange(Request $request, int $id)
    {
        $admin = $request->user();

        $ticket = DB::table('tbl_tickets')->where('t_id', $id)->first();
        if (! $ticket) {
            return response()->json(['message' => 'Username change request not found.'], 404);
        }

        if ((int) $ticket->t_status !== 1) {
            return response()->json(['message' => 'This request is no longer pending.'], 422);
        }

        $customer = Customer::query()->where('c_userid', (int) $ticket->t_eid)->first();
        if (! $customer) {
            return response()->json(['message' => 'Customer account not found.'], 404);
        }

        $requestDetail = DB::table('tbl_tickets_details')
            ->where('t_id', (int) $ticket->t_id)
            ->where('td_replystat', 0)
            ->orderBy('td_id')
            ->first();
        $payload = $this->decodeUsernameChangePayload($requestDetail?->td_content ?? null);
        $requestedUsername = trim((string) ($payload['requested_username'] ?? ''));
        if ($requestedUsername === '') {
            return response()->json(['message' => 'Requested username is invalid.'], 422);
        }

        $duplicate = Customer::query()
            ->whereRaw('LOWER(c_username) = ?', [mb_strtolower($requestedUsername, 'UTF-8')])
            ->where('c_userid', '!=', (int) $customer->c_userid)
            ->exists();
        if ($duplicate) {
            return response()->json(['message' => 'This username is already taken.'], 422);
        }

        $customer->c_username = $requestedUsername;
        $customer->save();

        $reviewedAt = now('Asia/Manila');

        DB::table('tbl_tickets')->where('t_id', (int) $ticket->t_id)->update([
            't_status' => 2,
            't_view_status' => 2,
        ]);

        $decisionPayload = [
            'type' => 'username_change_decision',
            'decision' => 'approved',
            'reviewed_by' => $admin instanceof Admin ? (int) $admin->id : null,
            'reviewed_at' => $reviewedAt->toDateTimeString(),
        ];

        DB::table('tbl_tickets_details')->insert([
            't_id' => (int) $ticket->t_id,
            'td_content' => json_encode($decisionPayload, JSON_THROW_ON_ERROR),
            'td_attachment' => null,
            'td_datetime' => now(),
            'td_rate' => 0,
            'td_eid' => $admin instanceof Admin ? (int) $admin->id : 0,
            'td_replystat' => 1,
            'td_viewstat' => '1',
            'td_ip' => (string) $request->ip(),
        ]);

        CustomerNotification::query()->create([
            'cn_customer_id' => (int) $customer->c_userid,
            'cn_type' => 'username_change',
            'cn_severity' => 'success',
            'cn_title' => 'Username Change Request',
            'cn_message' => sprintf(
                'Your username request has been approved by admin (%s).',
                $reviewedAt->format('F j, Y g:i A')
            ),
            'cn_href' => '/profile?tab=change-username',
            'cn_payload' => [
                'ticket_id' => (int) $ticket->t_id,
                'requested_username' => $requestedUsername,
                'approved_at' => $reviewedAt->toDateTimeString(),
            ],
            'cn_source_type' => 'username_change_request',
            'cn_source_id' => (int) $ticket->t_id,
            'cn_created_at' => $reviewedAt,
        ]);

        return response()->json(['message' => 'Username change approved.']);
    }

    public function rejectUsernameChange(Request $request, int $id)
    {
        $admin = $request->user();

        $ticket = DB::table('tbl_tickets')->where('t_id', $id)->first();
        if (! $ticket) {
            return response()->json(['message' => 'Username change request not found.'], 404);
        }

        if ((int) $ticket->t_status !== 1) {
            return response()->json(['message' => 'This request is no longer pending.'], 422);
        }

        DB::table('tbl_tickets')->where('t_id', (int) $ticket->t_id)->update([
            't_status' => 2,
            't_view_status' => 2,
        ]);

        $decisionPayload = [
            'type' => 'username_change_decision',
            'decision' => 'rejected',
            'reviewed_by' => $admin instanceof Admin ? (int) $admin->id : null,
            'reviewed_at' => now()->toDateTimeString(),
        ];

        DB::table('tbl_tickets_details')->insert([
            't_id' => (int) $ticket->t_id,
            'td_content' => json_encode($decisionPayload, JSON_THROW_ON_ERROR),
            'td_attachment' => null,
            'td_datetime' => now(),
            'td_rate' => 0,
            'td_eid' => $admin instanceof Admin ? (int) $admin->id : 0,
            'td_replystat' => 2,
            'td_viewstat' => '1',
            'td_ip' => (string) $request->ip(),
        ]);

        return response()->json(['message' => 'Username change rejected.']);
    }

    public function approveWebstoreRequest(Request $request, int $id)
    {
        $admin = $request->user();

        $ticket = DB::table('tbl_tickets')->where('t_id', $id)->first();
        if (! $ticket) {
            return response()->json(['message' => 'Webstore request not found.'], 404);
        }

        if ((int) $ticket->t_status !== 1) {
            return response()->json(['message' => 'This request is no longer pending.'], 422);
        }

        DB::table('tbl_tickets')->where('t_id', (int) $ticket->t_id)->update([
            't_status' => 2,
            't_view_status' => 2,
        ]);

        $reviewedAt = now('Asia/Manila');

        $decisionPayload = [
            'type' => 'webstore_request_decision',
            'decision' => 'approved',
            'reviewed_by' => $admin instanceof Admin ? (int) $admin->id : null,
            'reviewed_at' => $reviewedAt->toDateTimeString(),
        ];

        DB::table('tbl_tickets_details')->insert([
            't_id' => (int) $ticket->t_id,
            'td_content' => json_encode($decisionPayload, JSON_THROW_ON_ERROR),
            'td_attachment' => null,
            'td_datetime' => now(),
            'td_rate' => 0,
            'td_eid' => $admin instanceof Admin ? (int) $admin->id : 0,
            'td_replystat' => 1,
            'td_viewstat' => '1',
            'td_ip' => (string) $request->ip(),
        ]);

        $requestDetail = DB::table('tbl_tickets_details')
            ->where('t_id', (int) $ticket->t_id)
            ->where('td_replystat', 0)
            ->orderBy('td_id')
            ->first();
        $payload = $this->decodeWebstorePayload($requestDetail?->td_content ?? null);
        $recipientEmail = trim((string) ($payload['email'] ?? ''));
        if ($recipientEmail === '') {
            $customer = Customer::query()->where('c_userid', (int) ($ticket->t_eid ?? 0))->first();
            $recipientEmail = trim((string) ($customer?->c_email ?? ''));
        }

        if ($recipientEmail !== '' && filter_var($recipientEmail, FILTER_VALIDATE_EMAIL)) {
            $frontendUrl = rtrim((string) env('FRONTEND_URL', config('app.url')), '/');
            $partnerLoginUrl = $frontendUrl . '/partner/login';
            $displayName = trim((string) ($payload['display_name'] ?? ''));
            $slugName = trim((string) ($payload['slug_name'] ?? ''));
            $emailSubject = 'Partner Webstore Request Approved';
            $emailBody = implode("\n", [
                'Hello,',
                '',
                'Great news. Your Partner Webstore request has been approved.',
                '',
                $displayName !== '' ? "Display Name: {$displayName}" : null,
                $slugName !== '' ? "Slug: {$slugName}" : null,
                '',
                "Partner Login Link: {$partnerLoginUrl}",
                '',
                'Please use this link to access your partner portal.',
                '',
                'AF Home Team',
            ]);

            try {
                Mail::mailer('resend')->raw(
                    preg_replace("/\n{3,}/", "\n\n", (string) $emailBody),
                    function ($message) use ($recipientEmail, $emailSubject): void {
                        $message->to($recipientEmail)->subject($emailSubject);
                    }
                );
            } catch (\Throwable $e) {
                Log::warning('Failed to send approved webstore partner login email.', [
                    'ticket_id' => (int) $ticket->t_id,
                    'email' => $recipientEmail,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        CustomerNotification::query()->create([
            'cn_customer_id' => (int) ($ticket->t_eid ?? 0),
            'cn_type' => 'webstore_request',
            'cn_severity' => 'success',
            'cn_title' => 'Webstore Request',
            'cn_message' => sprintf(
                'Your webstore request has been approved by admin (%s).',
                $reviewedAt->format('F j, Y g:i A')
            ),
            'cn_href' => '/profile?tab=webstore',
            'cn_payload' => [
                'ticket_id' => (int) $ticket->t_id,
                'approved_at' => $reviewedAt->toDateTimeString(),
            ],
            'cn_source_type' => 'webstore_request',
            'cn_source_id' => (int) $ticket->t_id,
            'cn_created_at' => $reviewedAt,
        ]);

        return response()->json(['message' => 'Webstore request approved.']);
    }

    public function rejectWebstoreRequest(Request $request, int $id)
    {
        $admin = $request->user();

        $ticket = DB::table('tbl_tickets')->where('t_id', $id)->first();
        if (! $ticket) {
            return response()->json(['message' => 'Webstore request not found.'], 404);
        }

        if ((int) $ticket->t_status !== 1) {
            return response()->json(['message' => 'This request is no longer pending.'], 422);
        }

        DB::table('tbl_tickets')->where('t_id', (int) $ticket->t_id)->update([
            't_status' => 2,
            't_view_status' => 2,
        ]);

        $reviewedAt = now('Asia/Manila');

        $decisionPayload = [
            'type' => 'webstore_request_decision',
            'decision' => 'rejected',
            'reviewed_by' => $admin instanceof Admin ? (int) $admin->id : null,
            'reviewed_at' => $reviewedAt->toDateTimeString(),
        ];

        DB::table('tbl_tickets_details')->insert([
            't_id' => (int) $ticket->t_id,
            'td_content' => json_encode($decisionPayload, JSON_THROW_ON_ERROR),
            'td_attachment' => null,
            'td_datetime' => now(),
            'td_rate' => 0,
            'td_eid' => $admin instanceof Admin ? (int) $admin->id : 0,
            'td_replystat' => 2,
            'td_viewstat' => '1',
            'td_ip' => (string) $request->ip(),
        ]);

        CustomerNotification::query()->create([
            'cn_customer_id' => (int) ($ticket->t_eid ?? 0),
            'cn_type' => 'webstore_request',
            'cn_severity' => 'warning',
            'cn_title' => 'Webstore Request',
            'cn_message' => sprintf(
                'Your webstore request has been rejected by admin (%s).',
                $reviewedAt->format('F j, Y g:i A')
            ),
            'cn_href' => '/profile?tab=webstore',
            'cn_payload' => [
                'ticket_id' => (int) $ticket->t_id,
                'rejected_at' => $reviewedAt->toDateTimeString(),
            ],
            'cn_source_type' => 'webstore_request',
            'cn_source_id' => (int) $ticket->t_id,
            'cn_created_at' => $reviewedAt,
        ]);

        return response()->json(['message' => 'Webstore request rejected.']);
    }

    public function destroyWebstoreRequest(Request $request, int $id)
    {
        $ticket = DB::table('tbl_tickets')->where('t_id', $id)->first();
        if (! $ticket) {
            return response()->json(['message' => 'Webstore request not found.'], 404);
        }

        DB::transaction(function () use ($ticket): void {
            DB::table('tbl_tickets_details')->where('t_id', (int) $ticket->t_id)->delete();
            DB::table('tbl_tickets')->where('t_id', (int) $ticket->t_id)->delete();

            DB::table('tbl_admin_notifications')
                ->where('an_source_type', 'ticket')
                ->where('an_source_id', (int) $ticket->t_id)
                ->where('an_type', 'webstore_request')
                ->delete();

            DB::table('tbl_customer_notifications')
                ->where('cn_source_type', 'webstore_request')
                ->where('cn_source_id', (int) $ticket->t_id)
                ->delete();
        });

        return response()->json(['message' => 'Webstore request deleted.']);
    }

    private function fullName(Customer $customer): string
    {
        $fullName = trim(implode(' ', array_filter([
            $customer->c_fname,
            $customer->c_mname,
            $customer->c_lname,
        ])));

        if ($fullName !== '') {
            return $fullName;
        }

        return (string) ($customer->c_username ?: ('Member #' . $customer->c_userid));
    }

    private function usernameChangeTicketSubject(): string
    {
        return 'Username Change Request';
    }

    private function webstoreRequestTicketSubject(): string
    {
        return 'Partner Webstore Request';
    }

    private function decodeUsernameChangePayload(?string $content): array
    {
        if (!is_string($content) || trim($content) === '') {
            return [];
        }

        $decoded = json_decode($content, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function decodeWebstorePayload(?string $content): array
    {
        if (!is_string($content) || trim($content) === '') {
            return [];
        }

        $decoded = json_decode($content, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function mapUsernameChangeStatus(int $ticketStatus, int $ticketId): string
    {
        return $this->mapTicketDecisionStatus($ticketStatus, $ticketId);
    }

    private function mapTicketDecisionStatus(int $ticketStatus, int $ticketId): string
    {
        if ($ticketStatus === 1) {
            return 'pending_review';
        }

        $latestDecision = DB::table('tbl_tickets_details')
            ->where('t_id', $ticketId)
            ->whereIn('td_replystat', [1, 2])
            ->orderByDesc('td_id')
            ->first();

        if ($latestDecision && (int) $latestDecision->td_replystat === 2) {
            return 'rejected';
        }

        return 'approved';
    }
}
