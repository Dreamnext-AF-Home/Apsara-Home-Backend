<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Admin;
use App\Models\Customer;
use App\Models\CustomerVerificationRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdminMemberKycController extends Controller
{
    public function index(Request $request)
    {
        $admin = $this->resolveAdmin($request);
        if (!$admin) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }
        if (!$this->canManageKyc($admin)) {
            return response()->json(['message' => 'Forbidden: you do not have access to KYC verification queue.'], 403);
        }

        $validated = $request->validate([
            'filter' => 'nullable|string|max:40',
            'q' => 'nullable|string|max:120',
            'page' => 'nullable|integer|min:1',
            'per_page' => 'nullable|integer|min:1|max:100',
        ]);

        $filter = $this->normalizeFilter((string) ($validated['filter'] ?? 'pending_review'));
        $search = trim((string) ($validated['q'] ?? ''));
        $perPage = (int) ($validated['per_page'] ?? 20);

        $query = CustomerVerificationRequest::query()
            ->orderByRaw("CASE cvr_status WHEN 'pending_review' THEN 1 WHEN 'on_hold' THEN 2 WHEN 'approved' THEN 3 WHEN 'rejected' THEN 4 ELSE 5 END")
            ->orderByDesc('created_at');

        if ($filter !== 'all') {
            $query->where('cvr_status', $filter);
        }

        if ($search !== '') {
            $query->where(function ($inner) use ($search) {
                $inner->where('cvr_reference_no', 'like', "%{$search}%")
                    ->orWhere('cvr_full_name', 'like', "%{$search}%")
                    ->orWhere('cvr_id_number', 'like', "%{$search}%");
            });
        }

        $rows = $query->paginate($perPage);
        $customerIds = collect($rows->items())
            ->pluck('cvr_customer_id')
            ->filter(fn ($id) => (int) $id > 0)
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();

        $customers = empty($customerIds)
            ? collect()
            : Customer::query()
                ->whereIn('c_userid', $customerIds)
                ->get(['c_userid', 'c_username', 'c_email', 'c_fname', 'c_mname', 'c_lname', 'c_accnt_status', 'c_lockstatus'])
                ->keyBy('c_userid');

        return response()->json([
            'requests' => collect($rows->items())
                ->map(fn (CustomerVerificationRequest $row) => $this->transform($row, $customers->get((int) $row->cvr_customer_id)))
                ->values(),
            'meta' => [
                'current_page' => $rows->currentPage(),
                'last_page' => $rows->lastPage(),
                'per_page' => $rows->perPage(),
                'total' => $rows->total(),
                'from' => $rows->firstItem(),
                'to' => $rows->lastItem(),
            ],
            'counts' => [
                'all' => (int) CustomerVerificationRequest::query()->count(),
                'pending_review' => (int) CustomerVerificationRequest::query()->where('cvr_status', 'pending_review')->count(),
                'on_hold' => (int) CustomerVerificationRequest::query()->where('cvr_status', 'on_hold')->count(),
                'approved' => (int) CustomerVerificationRequest::query()->where('cvr_status', 'approved')->count(),
                'rejected' => (int) CustomerVerificationRequest::query()->where('cvr_status', 'rejected')->count(),
            ],
        ]);
    }

    public function approve(Request $request, int $id)
    {
        $admin = $this->resolveAdmin($request);
        if (!$admin) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }
        if (!$this->canManageKyc($admin)) {
            return response()->json(['message' => 'Forbidden: you do not have access to approve KYC requests.'], 403);
        }

        $validated = $request->validate([
            'notes' => 'nullable|string|max:1000',
        ]);

        $row = CustomerVerificationRequest::query()->where('cvr_id', $id)->firstOrFail();
        if (!in_array((string) $row->cvr_status, ['pending_review', 'on_hold'], true)) {
            return response()->json(['message' => 'Only pending or on-hold requests can be approved.'], 422);
        }

        DB::transaction(function () use ($row, $admin, $validated) {
            $locked = CustomerVerificationRequest::query()
                ->where('cvr_id', (int) $row->cvr_id)
                ->lockForUpdate()
                ->firstOrFail();

            $locked->cvr_status = 'approved';
            $locked->cvr_reviewed_by = (int) $admin->id;
            $locked->cvr_review_notes = $validated['notes'] ?? 'KYC approved by admin.';
            $locked->cvr_reviewed_at = now();
            $locked->save();

            Customer::query()
                ->where('c_userid', (int) $locked->cvr_customer_id)
                ->update([
                    'c_accnt_status' => 1,
                    'c_lockstatus' => 0,
                ]);

            $this->createVerifiedAffiliateVoucher((int) $locked->cvr_customer_id);
        });

        return response()->json(['message' => 'KYC request approved successfully.']);
    }

    public function reject(Request $request, int $id)
    {
        $admin = $this->resolveAdmin($request);
        if (!$admin) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }
        if (!$this->canManageKyc($admin)) {
            return response()->json(['message' => 'Forbidden: you do not have access to reject KYC requests.'], 403);
        }

        $validated = $request->validate([
            'notes' => 'required|string|min:5|max:1000',
        ]);

        $row = CustomerVerificationRequest::query()->where('cvr_id', $id)->firstOrFail();
        if (!in_array((string) $row->cvr_status, ['pending_review', 'on_hold'], true)) {
            return response()->json(['message' => 'Only pending or on-hold requests can be rejected.'], 422);
        }

        DB::transaction(function () use ($row, $admin, $validated) {
            $locked = CustomerVerificationRequest::query()
                ->where('cvr_id', (int) $row->cvr_id)
                ->lockForUpdate()
                ->firstOrFail();

            $locked->cvr_status = 'rejected';
            $locked->cvr_reviewed_by = (int) $admin->id;
            $locked->cvr_review_notes = (string) $validated['notes'];
            $locked->cvr_reviewed_at = now();
            $locked->save();

            Customer::query()
                ->where('c_userid', (int) $locked->cvr_customer_id)
                ->update([
                    'c_accnt_status' => 0,
                ]);
        });

        return response()->json(['message' => 'KYC request rejected.']);
    }

    private function transform(CustomerVerificationRequest $row, ?Customer $customer): array
    {
        $name = $customer
            ? trim(implode(' ', array_filter([
                $customer->c_fname ?? null,
                $customer->c_mname ?? null,
                $customer->c_lname ?? null,
            ])))
            : '';

        return [
            'id' => (int) $row->cvr_id,
            'reference_no' => (string) $row->cvr_reference_no,
            'status' => (string) $row->cvr_status,
            'full_name' => (string) ($row->cvr_full_name ?? ''),
            'birth_date' => optional($row->cvr_birth_date)->toDateString(),
            'id_type' => (string) ($row->cvr_id_type ?? ''),
            'id_number' => $row->cvr_id_number,
            'contact_number' => $row->cvr_contact_number,
            'address_line' => $row->cvr_address_line,
            'city' => $row->cvr_city,
            'province' => $row->cvr_province,
            'postal_code' => $row->cvr_postal_code,
            'country' => $row->cvr_country,
            'notes' => $row->cvr_notes,
            'id_front_url' => $row->cvr_id_front_url,
            'id_back_url' => $row->cvr_id_back_url,
            'selfie_url' => $row->cvr_selfie_url,
            'profile_photo_url' => $row->cvr_profile_photo_url,
            'reviewed_by' => $row->cvr_reviewed_by ? (int) $row->cvr_reviewed_by : null,
            'review_notes' => $row->cvr_review_notes,
            'reviewed_at' => optional($row->cvr_reviewed_at)->toDateTimeString(),
            'created_at' => optional($row->created_at)->toDateTimeString(),
            'updated_at' => optional($row->updated_at)->toDateTimeString(),
            'customer' => [
                'id' => $customer ? (int) $customer->c_userid : (int) $row->cvr_customer_id,
                'name' => $name !== '' ? $name : ($customer?->c_username ?? 'Member'),
                'email' => $customer?->c_email,
                'username' => $customer?->c_username,
                'account_status' => $customer ? (int) ($customer->c_accnt_status ?? 0) : null,
                'lock_status' => $customer ? (int) ($customer->c_lockstatus ?? 0) : null,
            ],
        ];
    }

    private function normalizeFilter(string $value): string
    {
        $normalized = strtolower(trim($value));
        $normalized = str_replace([' ', '-'], '_', $normalized);

        return match ($normalized) {
            'all_requests' => 'all',
            'pending' => 'pending_review',
            default => $normalized,
        };
    }

    private function resolveAdmin(Request $request): ?Admin
    {
        $user = $request->user();
        return $user instanceof Admin ? $user : null;
    }

    private function canManageKyc(Admin $admin): bool
    {
        return in_array((int) $admin->user_level_id, [1, 2, 3], true);
    }

    private function createVerifiedAffiliateVoucher(int $customerId): void
    {
        if (!DB::getSchemaBuilder()->hasTable('tbl_vouchers')) {
            return;
        }

        $voucherCode = sprintf('AFV-KYC-%06d', $customerId);
        $voucherValue = (float) env('AFFILIATE_VERIFIED_VOUCHER_VALUE', 500);

        $existing = DB::table('tbl_vouchers')
            ->where('v_cid', $customerId)
            ->where('v_code', $voucherCode)
            ->exists();

        if ($existing) {
            return;
        }

        DB::table('tbl_vouchers')->insert([
            'v_cid' => $customerId,
            'v_code' => $voucherCode,
            'v_valid_from' => now(),
            'v_valid_to' => now()->addYear(),
            'v_value' => $voucherValue,
            'v_uses' => now(),
            // Old migrated data uses 0 as active.
            'v_active' => 0,
            'v_pid' => 0,
        ]);
    }
}
