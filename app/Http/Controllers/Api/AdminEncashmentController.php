<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Admin;
use App\Models\Customer;
use App\Models\CustomerWalletLedger;
use App\Models\EncashmentRequest;
use App\Support\CustomerCashWallet;
use App\Support\YearlyGlobalPurchaseBonus;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AdminEncashmentController extends Controller
{
    public function index(Request $request)
    {
        $admin = $this->resolveAdmin($request);
        if (!$admin) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $validated = $request->validate([
            'filter' => 'nullable|string|max:40',
            'q' => 'nullable|string|max:120',
            'page' => 'nullable|integer|min:1',
            'per_page' => 'nullable|integer|min:1|max:100',
        ]);

        $filter = $this->normalizeFilter((string) ($validated['filter'] ?? 'all'));
        $search = trim((string) ($validated['q'] ?? ''));
        $perPage = (int) ($validated['per_page'] ?? 20);

        $query = EncashmentRequest::query()
            ->with(['customer:c_userid,c_username,c_email,c_fname,c_mname,c_lname,c_totalincome'])
            ->when($search !== '', function ($builder) use ($search) {
                $builder->where(function ($q) use ($search) {
                    $q->where('er_reference_no', 'like', "%{$search}%")
                        ->orWhere('er_invoice_no', 'like', "%{$search}%")
                        ->orWhere('er_account_name', 'like', "%{$search}%");
                });
            });

        $this->applyFilter($query, $filter);

        $rows = $query
            ->orderByRaw("CASE er_status WHEN 'pending' THEN 1 WHEN 'approved_by_admin' THEN 2 WHEN 'released' THEN 3 ELSE 4 END")
            ->orderByDesc('created_at')
            ->paginate($perPage);

        $customerIds = collect($rows->items())
            ->map(fn (EncashmentRequest $row) => (int) $row->er_customer_id)
            ->filter(fn (int $id) => $id > 0)
            ->unique()
            ->values()
            ->all();

        $lockedByCustomer = [];
        if (!empty($customerIds)) {
            $lockedByCustomer = EncashmentRequest::query()
                ->selectRaw('er_customer_id, COALESCE(SUM(er_amount), 0) as total_locked')
                ->whereIn('er_customer_id', $customerIds)
                ->whereIn('er_status', ['pending', 'approved_by_admin', 'on_hold'])
                ->groupBy('er_customer_id')
                ->pluck('total_locked', 'er_customer_id')
                ->map(fn ($amount) => (float) $amount)
                ->all();
        }

        return response()->json([
            'requests' => collect($rows->items())->map(fn (EncashmentRequest $row) => $this->transform($row, $lockedByCustomer))->values(),
            'meta' => [
                'current_page' => $rows->currentPage(),
                'last_page' => $rows->lastPage(),
                'per_page' => $rows->perPage(),
                'total' => $rows->total(),
                'from' => $rows->firstItem(),
                'to' => $rows->lastItem(),
            ],
            'counts' => $this->counts(),
        ]);
    }

    public function approve(Request $request, int $id)
    {
        $admin = $this->resolveAdmin($request);
        if (!$admin) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }
        if (!$this->canApprove($admin)) {
            return response()->json(['message' => 'Forbidden: approval access is limited to accounting.'], 403);
        }

        $validated = $request->validate([
            'notes' => 'required|string|min:5|max:1000',
        ]);

        $row = EncashmentRequest::query()->where('er_id', $id)->firstOrFail();
        if (!in_array($row->er_status, ['pending', 'on_hold'], true)) {
            return response()->json(['message' => 'Only pending/on-hold requests can be approved.'], 422);
        }

        $row->fill([
            'er_status' => 'approved_by_admin',
            'er_admin_notes' => $validated['notes'],
            'er_approved_by' => (int) $admin->id,
            'er_approved_at' => now(),
        ])->save();

        return response()->json(['message' => 'Encashment approved by admin.']);
    }

    public function reject(Request $request, int $id)
    {
        $admin = $this->resolveAdmin($request);
        if (!$admin) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }
        if (!$this->canApprove($admin)) {
            return response()->json(['message' => 'Forbidden: rejection access is limited to accounting.'], 403);
        }

        $validated = $request->validate([
            'notes' => 'required|string|min:5|max:1000',
        ]);

        $row = EncashmentRequest::query()->where('er_id', $id)->firstOrFail();
        if (in_array($row->er_status, ['released', 'rejected'], true)) {
            return response()->json(['message' => 'Request is already finalized.'], 422);
        }

        $row->fill([
            'er_status' => 'rejected',
            'er_admin_notes' => $validated['notes'],
        ])->save();

        return response()->json(['message' => 'Encashment request rejected.']);
    }

    public function release(Request $request, int $id)
    {
        $admin = $this->resolveAdmin($request);
        if (!$admin) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }
        if (!$this->canRelease($admin)) {
            return response()->json(['message' => 'Forbidden: release access is limited to finance officer.'], 403);
        }

        $validated = $request->validate([
            'notes' => 'required|string|min:5|max:1000',
            'proof_url' => 'required|url|max:1200',
            'proof_public_id' => 'nullable|string|max:255',
        ]);

        $row = EncashmentRequest::query()->where('er_id', $id)->firstOrFail();
        if ($row->er_status !== 'approved_by_admin') {
            return response()->json(['message' => 'Only admin-approved requests can be released.'], 422);
        }

        DB::transaction(function () use ($row, $validated, $admin) {
            $lockedRow = EncashmentRequest::query()->where('er_id', (int) $row->er_id)->lockForUpdate()->firstOrFail();
            $customer = Customer::query()->where('c_userid', (int) $lockedRow->er_customer_id)->lockForUpdate()->first();
            if (!$customer) {
                throw ValidationException::withMessages([
                    'encashment' => ['Customer account not found.'],
                ]);
            }

            $amount = (float) $lockedRow->er_amount;
            $currentCash = CustomerCashWallet::balance($customer);

            $alreadyDebited = CustomerWalletLedger::query()
                ->where('wl_wallet_type', 'cash')
                ->where('wl_entry_type', 'debit')
                ->where('wl_source_type', 'encashment')
                ->where('wl_source_id', (int) $lockedRow->er_id)
                ->exists();

            if (!$alreadyDebited) {
                if ($currentCash < $amount) {
                    throw ValidationException::withMessages([
                        'amount' => ['Insufficient customer cash balance for release deduction.'],
                    ]);
                }

                $customer->c_totalincome = $currentCash - $amount;
                $customer->save();

                CustomerWalletLedger::create([
                    'wl_customer_id' => (int) $customer->c_userid,
                    'wl_wallet_type' => 'cash',
                    'wl_entry_type' => 'debit',
                    'wl_amount' => $amount,
                    'wl_source_type' => 'encashment',
                    'wl_source_id' => (int) $lockedRow->er_id,
                    'wl_reference_no' => $lockedRow->er_reference_no,
                    'wl_notes' => 'Cash debit posted on encashment release.',
                    'wl_created_by' => (int) $admin->id,
                ]);
            }

            $lockedRow->fill([
                'er_status' => 'released',
                'er_invoice_no' => $lockedRow->er_invoice_no ?: $this->generateInvoiceNo(),
                'er_accounting_notes' => $validated['notes'],
                'er_proof_url' => $validated['proof_url'],
                'er_proof_public_id' => $validated['proof_public_id'] ?? null,
                'er_proof_uploaded_by' => (int) $admin->id,
                'er_proof_uploaded_at' => now(),
                'er_released_by' => (int) $admin->id,
                'er_released_at' => now(),
            ])->save();
        });

        return response()->json(['message' => 'Encashment released successfully.']);
    }

    public function awardYearlyGlobalBonus(Request $request)
    {
        $admin = $this->resolveAdmin($request);
        if (!$admin) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }
        if ((int) $admin->user_level_id !== 1) {
            return response()->json(['message' => 'Forbidden: only super admin can award yearly global bonus.'], 403);
        }

        $validated = $request->validate([
            'year' => 'nullable|integer|min:2000|max:3000',
        ]);

        $year = (int) ($validated['year'] ?? now()->subYear()->year);
        $result = YearlyGlobalPurchaseBonus::awardForYear($year, (int) $admin->id);

        return response()->json([
            'message' => 'Yearly global purchase bonus awarding completed.',
            'result' => $result,
        ]);
    }

    private function transform(EncashmentRequest $row, array $lockedByCustomer = []): array
    {
        $customer = $row->customer;
        $name = $customer
            ? trim(implode(' ', array_filter([$customer->c_fname ?? null, $customer->c_mname ?? null, $customer->c_lname ?? null])))
            : '';
        $cashBalance = $customer ? CustomerCashWallet::balance($customer) : 0.0;
        $lockedAmount = (float) ($lockedByCustomer[(int) $row->er_customer_id] ?? 0);
        $availableAmount = max(0, $cashBalance - $lockedAmount);
        $shortfall = max(0, (float) $row->er_amount - $cashBalance);

        return [
            'id' => (int) $row->er_id,
            'reference_no' => $row->er_reference_no,
            'invoice_no' => $row->er_invoice_no,
            'affiliate_name' => $name !== '' ? $name : ($customer->c_username ?? 'Affiliate'),
            'affiliate_email' => $customer->c_email ?? null,
            'amount' => (float) $row->er_amount,
            'channel' => $row->er_channel,
            'account_name' => $row->er_account_name,
            'account_number' => $row->er_account_number,
            'notes' => $row->er_notes,
            'status' => $row->er_status,
            'admin_notes' => $row->er_admin_notes,
            'accounting_notes' => $row->er_accounting_notes,
            'proof_url' => $row->er_proof_url,
            'proof_public_id' => $row->er_proof_public_id,
            'proof_uploaded_by' => $row->er_proof_uploaded_by ? (int) $row->er_proof_uploaded_by : null,
            'proof_uploaded_at' => optional($row->er_proof_uploaded_at)->toDateTimeString(),
            'wallet_cash_balance' => round($cashBalance, 2),
            'wallet_locked_amount' => round($lockedAmount, 2),
            'wallet_available_amount' => round($availableAmount, 2),
            'can_release_by_balance' => $cashBalance >= (float) $row->er_amount,
            'balance_shortfall' => round($shortfall, 2),
            'approved_by' => $row->er_approved_by ? (int) $row->er_approved_by : null,
            'approved_at' => optional($row->er_approved_at)->toDateTimeString(),
            'released_by' => $row->er_released_by ? (int) $row->er_released_by : null,
            'released_at' => optional($row->er_released_at)->toDateTimeString(),
            'created_at' => optional($row->created_at)->toDateTimeString(),
            'updated_at' => optional($row->updated_at)->toDateTimeString(),
        ];
    }

    private function applyFilter($query, string $filter): void
    {
        if ($filter === 'all' || $filter === '') {
            return;
        }

        if ($filter === 'pending') {
            $query->whereIn('er_status', ['pending', 'approved_by_admin', 'on_hold']);
            return;
        }

        if ($filter === 'released') {
            $query->where('er_status', 'released');
            return;
        }

        $query->where('er_status', $filter);
    }

    private function counts(): array
    {
        $base = EncashmentRequest::query();

        return [
            'all' => (int) (clone $base)->count(),
            'pending' => (int) (clone $base)->whereIn('er_status', ['pending', 'approved_by_admin', 'on_hold'])->count(),
            'released' => (int) (clone $base)->where('er_status', 'released')->count(),
        ];
    }

    private function normalizeFilter(string $filter): string
    {
        $normalized = strtolower(trim($filter));
        $normalized = str_replace([' ', '-'], '_', $normalized);

        return match ($normalized) {
            'all_requests' => 'all',
            default => $normalized,
        };
    }

    private function resolveAdmin(Request $request): ?Admin
    {
        $user = $request->user();
        return $user instanceof Admin ? $user : null;
    }

    private function canApprove(Admin $admin): bool
    {
        return $this->roleFromAdmin($admin) === 'accounting';
    }

    private function canRelease(Admin $admin): bool
    {
        return in_array($this->roleFromAdmin($admin), ['finance_officer', 'super_admin'], true);
    }

    private function roleFromAdmin(Admin $admin): string
    {
        return match ((int) $admin->user_level_id) {
            1 => 'super_admin',
            2 => 'admin',
            3 => 'csr',
            4 => 'web_content',
            5 => 'accounting',
            6 => 'finance_officer',
            default => 'staff',
        };
    }

    private function generateInvoiceNo(): string
    {
        $year = now()->format('Y');

        for ($attempt = 0; $attempt < 20; $attempt++) {
            $count = EncashmentRequest::query()
                ->whereYear('created_at', now()->year)
                ->whereNotNull('er_invoice_no')
                ->count() + 1 + $attempt;
            $candidate = sprintf('INV-ENC-%s-%04d', $year, $count);
            if (!EncashmentRequest::query()->where('er_invoice_no', $candidate)->exists()) {
                return $candidate;
            }
        }

        return sprintf('INV-ENC-%s-%s', $year, strtoupper(substr(md5((string) microtime(true)), 0, 6)));
    }
}
