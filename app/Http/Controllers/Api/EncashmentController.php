<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AdminNotification;
use App\Models\CheckoutHistory;
use App\Models\Customer;
use App\Models\CustomerVerificationRequest;
use App\Models\CustomerWalletLedger;
use App\Models\EncashmentPayoutMethod;
use App\Models\EncashmentRequest;
use Illuminate\Http\Request;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Pusher\Pusher;

class EncashmentController extends Controller
{
    public function walletOverview(Request $request)
    {
        $customer = $request->user();
        if (!$customer instanceof Customer) {
            return response()->json(['message' => 'Only customer accounts can view wallet data.'], 403);
        }

        $validated = $request->validate([
            'page' => 'nullable|integer|min:1',
            'per_page' => 'nullable|integer|min:1|max:100',
            'wallet_type' => 'nullable|in:all,cash,pv,rewards',
        ]);

        $walletType = (string) ($validated['wallet_type'] ?? 'all');
        $perPage = (int) ($validated['per_page'] ?? 20);

        $ledgerRows = null;
        $cashCredits = 0.0;
        $cashDebits = 0.0;
        $pvCredits = 0.0;
        $pvDebits = 0.0;

        if (Schema::hasTable('tbl_customer_wallet_ledger')) {
            $ledgerQuery = CustomerWalletLedger::query()
                ->where('wl_customer_id', (int) $customer->c_userid)
                ->when($walletType !== 'all', function ($query) use ($walletType) {
                    $query->where('wl_wallet_type', $walletType);
                });

            $ledgerRows = (clone $ledgerQuery)
                ->orderByDesc('created_at')
                ->orderByDesc('wl_id')
                ->paginate($perPage);

            $cashCredits = (float) (clone $ledgerQuery)
                ->where('wl_wallet_type', 'cash')
                ->where('wl_entry_type', 'credit')
                ->sum('wl_amount');
            $cashDebits = (float) (clone $ledgerQuery)
                ->where('wl_wallet_type', 'cash')
                ->where('wl_entry_type', 'debit')
                ->sum('wl_amount');
            $pvCredits = (float) (clone $ledgerQuery)
                ->where('wl_wallet_type', 'pv')
                ->where('wl_entry_type', 'credit')
                ->sum('wl_amount');
            $pvDebits = (float) (clone $ledgerQuery)
                ->where('wl_wallet_type', 'pv')
                ->where('wl_entry_type', 'debit')
                ->sum('wl_amount');
        }

        $encashmentPendingLocked = (float) EncashmentRequest::query()
            ->where('er_customer_id', (int) $customer->c_userid)
            ->whereIn('er_status', ['pending', 'approved_by_admin', 'on_hold'])
            ->sum('er_amount');

        $pendingPv = (float) CheckoutHistory::query()
            ->where('ch_customer_id', (int) $customer->c_userid)
            ->where('ch_earned_pv', '>', 0)
            ->whereNull('ch_pv_posted_at')
            ->whereNotIn('ch_status', ['failed', 'cancelled', 'expired'])
            ->sum('ch_earned_pv');

        $lifetimePv = 0.0;
        if (Schema::hasTable('tbl_customer_wallet_ledger')) {
            $lifetimePv = (float) CustomerWalletLedger::query()
                ->where('wl_customer_id', (int) $customer->c_userid)
                ->where('wl_wallet_type', 'pv')
                ->where('wl_entry_type', 'credit')
                ->sum('wl_amount');
        }

        $totalReferrals = (int) Customer::query()
            ->where('c_sponsor', (int) $customer->c_userid)
            ->count();

        $verifiedReferrals = (int) Customer::query()
            ->where('c_sponsor', (int) $customer->c_userid)
            ->where('c_lockstatus', 0)
            ->where('c_accnt_status', 1)
            ->count();

        $activeReferrals = $verifiedReferrals;

        $isVerifiedAffiliate = (int) ($customer->c_accnt_status ?? 0) === 1
            && (int) ($customer->c_lockstatus ?? 0) === 0;

        $reservedAffiliateVoucherAmount = 0.0;
        $affiliateVouchers = collect();
        if (Schema::hasTable('tbl_affiliate_voucher_issuances')) {
            $reservedAffiliateVoucherAmount = (float) DB::table('tbl_affiliate_voucher_issuances')
                ->where('avi_customer_id', (int) $customer->c_userid)
                ->where('avi_status', 'active')
                ->sum('avi_amount');

            $affiliateVouchers = DB::table('tbl_affiliate_voucher_issuances')
                ->where('avi_customer_id', (int) $customer->c_userid)
                ->orderByDesc('created_at')
                ->limit(20)
                ->get([
                    'avi_id',
                    'avi_code',
                    'avi_amount',
                    'avi_status',
                    'avi_redeemed_by_customer_id',
                    'avi_redeemed_at',
                    'avi_expires_at',
                    'created_at',
                    'updated_at',
                ]);
        }

        $afVoucherSourceBalance = 0.0;
        if (Schema::hasTable('tbl_vouchers')) {
            $today = now()->toDateString();

            $afVoucherSourceBalance = (float) DB::table('tbl_vouchers')
                ->where('v_cid', (int) $customer->c_userid)
                ->where('v_active', 0)
                ->where(function ($query) use ($today) {
                    $query->whereNull('v_valid_from')
                        ->orWhereDate('v_valid_from', '<=', $today);
                })
                ->where(function ($query) use ($today) {
                    $query->whereNull('v_valid_to')
                        ->orWhereDate('v_valid_to', '>=', $today);
                })
                ->sum('v_value');
        }

        $cashbackSourceBalance = 0.0;
        if (Schema::hasTable('tbl_transaction')) {
            $cashbackSourceBalance += (float) DB::table('tbl_transaction')
                ->where('t_receiver', (int) $customer->c_userid)
                ->where('t_rebate', '>', 0)
                ->sum('t_rebate');
        }
        if (Schema::hasTable('tbl_vows_transaction')) {
            $cashbackSourceBalance += (float) DB::table('tbl_vows_transaction')
                ->where('t_receiver', (int) $customer->c_userid)
                ->where('t_status', 1)
                ->where('t_rebate', '>', 0)
                ->sum('t_rebate');
        }

        $afVoucherBalance = max(0, $afVoucherSourceBalance);
        $cashbackBalance = max(0, $cashbackSourceBalance - $reservedAffiliateVoucherAmount);

        $cashbackRate = 0.0;
        if (Schema::hasTable('tbl_control_panel')) {
            $cashbackRate = (float) (DB::table('tbl_control_panel')->value('cashback_percentage') ?? 0);
        }

        return response()->json([
            'summary' => [
                'cash_balance' => round((float) ($customer->c_totalincome ?? 0), 2),
                'pv_balance' => round((float) ($customer->c_gpv ?? 0), 2),
                'current_pv' => round((float) ($customer->c_pv ?? 0), 2),
                'personal_purchase_pv' => round((float) ($customer->c_ppv ?? 0), 2),
                'group_pv' => round((float) ($customer->c_gpv ?? 0), 2),
                'current_month_group_pv' => round((float) ($customer->c_gpv_cmonth ?? 0), 2),
                'current_cv' => round((float) ($customer->c_cv ?? 0), 2),
                'pending_pv' => round($pendingPv, 2),
                'lifetime_pv' => round($lifetimePv, 2),
                'cash_credits' => round($cashCredits, 2),
                'cash_debits' => round($cashDebits, 2),
                'pv_credits' => round($pvCredits, 2),
                'pv_debits' => round($pvDebits, 2),
                'encashment_locked' => round($encashmentPendingLocked, 2),
                'encashment_available' => round(max(0, ((float) ($customer->c_totalincome ?? 0)) - $encashmentPendingLocked), 2),
                'af_voucher_balance' => round($afVoucherBalance, 2),
                'available_egc_balance' => 0,
                'cashback_balance' => round($cashbackBalance, 2),
                'cashback_rate' => round($cashbackRate, 2),
                'af_voucher_source_balance' => round($afVoucherSourceBalance, 2),
                'af_voucher_reserved_balance' => round($reservedAffiliateVoucherAmount, 2),
                'cashback_source_balance' => round($cashbackSourceBalance, 2),
                'cashback_reserved_balance' => round($reservedAffiliateVoucherAmount, 2),
                'can_create_affiliate_voucher' => $isVerifiedAffiliate,
                'referrals' => [
                    'total' => $totalReferrals,
                    'verified' => $verifiedReferrals,
                    'active' => $activeReferrals,
                ],
            ],
            'ledger' => collect($ledgerRows?->items() ?? [])->map(function (CustomerWalletLedger $row) {
                return [
                    'id' => (int) $row->wl_id,
                    'wallet_type' => (string) $row->wl_wallet_type,
                    'entry_type' => (string) $row->wl_entry_type,
                    'amount' => (float) $row->wl_amount,
                    'source_type' => $row->wl_source_type,
                    'source_id' => $row->wl_source_id ? (int) $row->wl_source_id : null,
                    'reference_no' => $row->wl_reference_no,
                    'notes' => $row->wl_notes,
                    'created_by' => $row->wl_created_by ? (int) $row->wl_created_by : null,
                    'created_at' => optional($row->created_at)->toDateTimeString(),
                    'updated_at' => optional($row->updated_at)->toDateTimeString(),
                ];
            })->values(),
            'meta' => [
                'current_page' => $ledgerRows?->currentPage() ?? 1,
                'last_page' => $ledgerRows?->lastPage() ?? 1,
                'per_page' => $ledgerRows?->perPage() ?? $perPage,
                'total' => $ledgerRows?->total() ?? 0,
                'from' => $ledgerRows?->firstItem() ?? null,
                'to' => $ledgerRows?->lastItem() ?? null,
            ],
            'affiliate_vouchers' => $affiliateVouchers->map(function ($row) {
                return [
                    'id' => (int) $row->avi_id,
                    'code' => (string) $row->avi_code,
                    'amount' => (float) $row->avi_amount,
                    'status' => (string) $row->avi_status,
                    'redeemed_by_customer_id' => $row->avi_redeemed_by_customer_id ? (int) $row->avi_redeemed_by_customer_id : null,
                    'redeemed_at' => $row->avi_redeemed_at,
                    'expires_at' => $row->avi_expires_at,
                    'created_at' => $row->created_at,
                    'updated_at' => $row->updated_at,
                ];
            })->values(),
        ]);
    }

    public function createAffiliateVoucher(Request $request)
    {
        $customer = $request->user();
        if (!$customer instanceof Customer) {
            return response()->json(['message' => 'Only customer accounts can create affiliate vouchers.'], 403);
        }

        $isVerifiedAffiliate = (int) ($customer->c_accnt_status ?? 0) === 1
            && (int) ($customer->c_lockstatus ?? 0) === 0;

        if (!$isVerifiedAffiliate) {
            return response()->json([
                'message' => 'Only verified affiliate accounts can create vouchers.',
            ], 422);
        }

        $validated = $request->validate([
            'amount' => 'required|numeric|min:1|max:999999.99',
        ]);

        if (!Schema::hasTable('tbl_affiliate_voucher_issuances')) {
            return response()->json([
                'message' => 'Affiliate voucher issuance table is not available.',
            ], 422);
        }

        $voucher = DB::transaction(function () use ($customer, $validated) {
            $requestedAmount = round((float) $validated['amount'], 2);

            $sourceBalance = 0.0;
            if (Schema::hasTable('tbl_transaction')) {
                $sourceBalance += (float) DB::table('tbl_transaction')
                    ->where('t_receiver', (int) $customer->c_userid)
                    ->where('t_rebate', '>', 0)
                    ->sum('t_rebate');
            }
            if (Schema::hasTable('tbl_vows_transaction')) {
                $sourceBalance += (float) DB::table('tbl_vows_transaction')
                    ->where('t_receiver', (int) $customer->c_userid)
                    ->where('t_status', 1)
                    ->where('t_rebate', '>', 0)
                    ->sum('t_rebate');
            }

            $reservedAmount = (float) DB::table('tbl_affiliate_voucher_issuances')
                ->where('avi_customer_id', (int) $customer->c_userid)
                ->where('avi_status', 'active')
                ->sum('avi_amount');

            $availableAmount = max(0, $sourceBalance - $reservedAmount);
            if ($requestedAmount > $availableAmount) {
                throw new HttpResponseException(response()->json([
                    'message' => 'Requested voucher amount exceeds your available cashback balance.',
                    'available_balance' => round($availableAmount, 2),
                ], 422));
            }

            $nextId = ((int) DB::table('tbl_affiliate_voucher_issuances')->max('avi_id')) + 1;
            $code = sprintf('AFV-AFF-%06d', $nextId);

            $id = DB::table('tbl_affiliate_voucher_issuances')->insertGetId([
                'avi_customer_id' => (int) $customer->c_userid,
                'avi_code' => $code,
                'avi_amount' => $requestedAmount,
                'avi_status' => 'active',
                'avi_expires_at' => now()->addYear(),
                'created_at' => now(),
                'updated_at' => now(),
            ], 'avi_id');

            return DB::table('tbl_affiliate_voucher_issuances')
                ->where('avi_id', $id)
                ->first([
                    'avi_id',
                    'avi_code',
                    'avi_amount',
                    'avi_status',
                    'avi_redeemed_by_customer_id',
                    'avi_redeemed_at',
                    'avi_expires_at',
                    'created_at',
                    'updated_at',
                ]);
        });

        return response()->json([
            'message' => 'Affiliate voucher created successfully.',
            'voucher' => [
                'id' => (int) $voucher->avi_id,
                'code' => (string) $voucher->avi_code,
                'amount' => (float) $voucher->avi_amount,
                'status' => (string) $voucher->avi_status,
                'redeemed_by_customer_id' => $voucher->avi_redeemed_by_customer_id ? (int) $voucher->avi_redeemed_by_customer_id : null,
                'redeemed_at' => $voucher->avi_redeemed_at,
                'expires_at' => $voucher->avi_expires_at,
                'created_at' => $voucher->created_at,
                'updated_at' => $voucher->updated_at,
            ],
        ], 201);
    }

    public function store(Request $request)
    {
        $customer = $request->user();
        if (!$customer instanceof Customer) {
            return response()->json(['message' => 'Only customer accounts can submit encashment requests.'], 403);
        }

        $validated = $request->validate([
            'amount' => 'required|numeric|min:1',
            'channel' => 'required|in:bank,gcash,maya',
            'account_name' => 'nullable|string|max:255',
            'account_number' => 'nullable|string|max:120',
            'notes' => 'nullable|string|max:1000',
        ]);

        $rules = $this->rules();
        $eligibility = $this->evaluateEligibility($customer, $rules);
        if (!$eligibility['eligible']) {
            return response()->json([
                'message' => $eligibility['message'],
                'eligibility' => $eligibility,
                'policy' => $this->policyMeta($rules),
            ], 422);
        }

        $amount = (float) $validated['amount'];
        if ($amount < $rules['min_amount']) {
            return response()->json([
                'message' => 'Minimum encashment amount is ' . number_format($rules['min_amount'], 2) . '.',
                'policy' => $this->policyMeta($rules),
            ], 422);
        }

        if ($amount > $eligibility['available_amount']) {
            return response()->json([
                'message' => 'Requested amount exceeds your available encashment balance.',
                'eligibility' => $eligibility,
                'policy' => $this->policyMeta($rules),
            ], 422);
        }

        $requestRow = EncashmentRequest::create([
            'er_reference_no' => $this->generateReferenceNo(),
            'er_customer_id' => (int) $customer->c_userid,
            'er_amount' => $amount,
            'er_channel' => $validated['channel'],
            'er_account_name' => $validated['account_name'] ?? null,
            'er_account_number' => $validated['account_number'] ?? null,
            'er_notes' => $validated['notes'] ?? null,
            'er_status' => 'pending',
        ]);

        return response()->json([
            'message' => 'Encashment request submitted.',
            'request' => $this->transform($requestRow, $customer),
            'eligibility' => $this->evaluateEligibility($customer->fresh(), $rules),
            'policy' => $this->policyMeta($rules),
        ], 201);
    }

    public function myRequests(Request $request)
    {
        $customer = $request->user();
        if (!$customer instanceof Customer) {
            return response()->json(['message' => 'Only customer accounts can view this resource.'], 403);
        }

        $rows = EncashmentRequest::query()
            ->where('er_customer_id', (int) $customer->c_userid)
            ->orderByDesc('created_at')
            ->paginate(20);

        $rules = $this->rules();

        return response()->json([
            'requests' => collect($rows->items())->map(fn (EncashmentRequest $row) => $this->transform($row, $customer))->values(),
            'payout_methods' => EncashmentPayoutMethod::query()
                ->where('epm_customer_id', (int) $customer->c_userid)
                ->orderByDesc('epm_is_default')
                ->orderByDesc('created_at')
                ->get()
                ->map(fn (EncashmentPayoutMethod $row) => $this->transformPayoutMethod($row))
                ->values(),
            'meta' => [
                'current_page' => $rows->currentPage(),
                'last_page' => $rows->lastPage(),
                'per_page' => $rows->perPage(),
                'total' => $rows->total(),
                'from' => $rows->firstItem(),
                'to' => $rows->lastItem(),
            ],
            'eligibility' => $this->evaluateEligibility($customer, $rules),
            'policy' => $this->policyMeta($rules),
            'verification' => $this->verificationMeta($customer),
        ]);
    }

    public function storePayoutMethod(Request $request)
    {
        $customer = $request->user();
        if (!$customer instanceof Customer) {
            return response()->json(['message' => 'Only customer accounts can manage payout methods.'], 403);
        }

        $validated = $request->validate([
            'label' => 'required|string|min:2|max:120',
            'method_type' => 'required|in:gcash,maya,online_banking,card',
            'account_name' => 'nullable|string|max:255',
            'account_number' => 'nullable|string|max:120',
            'mobile_number' => 'nullable|string|max:40',
            'email_address' => 'nullable|email|max:255',
            'bank_name' => 'nullable|string|max:120',
            'bank_code' => 'nullable|string|max:50',
            'account_type' => 'nullable|in:savings,checking',
            'card_holder_name' => 'nullable|string|max:255',
            'card_brand' => 'nullable|in:visa,mastercard,jcb,amex,other',
            'card_last4' => 'nullable|string|max:4',
            'is_default' => 'nullable|boolean',
        ]);

        $methodType = (string) $validated['method_type'];
        $channel = $this->mapMethodTypeToChannel($methodType);

        $accountName = trim((string) ($validated['account_name'] ?? ''));
        $accountNumber = trim((string) ($validated['account_number'] ?? ''));
        $mobileNumber = trim((string) ($validated['mobile_number'] ?? ''));
        $bankName = trim((string) ($validated['bank_name'] ?? ''));
        $cardHolderName = trim((string) ($validated['card_holder_name'] ?? ''));
        $cardLast4 = preg_replace('/\D/', '', (string) ($validated['card_last4'] ?? ''));

        if (in_array($methodType, ['gcash', 'maya'], true)) {
            if ($accountName === '' || $mobileNumber === '') {
                return response()->json(['message' => 'Account name and mobile number are required for e-wallet methods.'], 422);
            }
            $accountNumber = $mobileNumber;
        }

        if ($methodType === 'online_banking') {
            if ($accountName === '' || $accountNumber === '' || $bankName === '') {
                return response()->json(['message' => 'Bank name, account name, and account number are required for online banking.'], 422);
            }
        }

        if ($methodType === 'card') {
            if ($cardHolderName === '' || strlen($cardLast4) !== 4 || empty($validated['card_brand'])) {
                return response()->json(['message' => 'Card holder, card brand, and 4-digit card suffix are required for card method.'], 422);
            }
            $accountName = $cardHolderName;
            $accountNumber = '****' . $cardLast4;
        }

        $isDefault = (bool) ($validated['is_default'] ?? false);
        if ($isDefault) {
            EncashmentPayoutMethod::query()
                ->where('epm_customer_id', (int) $customer->c_userid)
                ->update(['epm_is_default' => false]);
        }

        $row = EncashmentPayoutMethod::create([
            'epm_customer_id' => (int) $customer->c_userid,
            'epm_label' => trim((string) $validated['label']),
            'epm_method_type' => $methodType,
            'epm_channel' => $channel,
            'epm_account_name' => $accountName !== '' ? $accountName : null,
            'epm_account_number' => $accountNumber !== '' ? $accountNumber : null,
            'epm_mobile_number' => $mobileNumber !== '' ? $mobileNumber : null,
            'epm_email' => isset($validated['email_address']) ? trim((string) $validated['email_address']) : null,
            'epm_bank_name' => $bankName !== '' ? $bankName : null,
            'epm_bank_code' => isset($validated['bank_code']) ? trim((string) $validated['bank_code']) : null,
            'epm_account_type' => $validated['account_type'] ?? null,
            'epm_card_holder_name' => $cardHolderName !== '' ? $cardHolderName : null,
            'epm_card_brand' => $validated['card_brand'] ?? null,
            'epm_card_last4' => strlen($cardLast4) === 4 ? $cardLast4 : null,
            'epm_is_default' => $isDefault,
        ]);

        return response()->json([
            'message' => 'Payout method saved.',
            'method' => $this->transformPayoutMethod($row),
        ], 201);
    }

    public function destroyPayoutMethod(Request $request, int $id)
    {
        $customer = $request->user();
        if (!$customer instanceof Customer) {
            return response()->json(['message' => 'Only customer accounts can manage payout methods.'], 403);
        }

        $row = EncashmentPayoutMethod::query()
            ->where('epm_id', $id)
            ->where('epm_customer_id', (int) $customer->c_userid)
            ->first();

        if (!$row) {
            return response()->json(['message' => 'Payout method not found.'], 404);
        }

        $row->delete();

        return response()->json(['message' => 'Payout method deleted.']);
    }

    public function submitVerificationRequest(Request $request)
    {
        $customer = $request->user();
        if (!$customer instanceof Customer) {
            return response()->json(['message' => 'Only customer accounts can submit verification requests.'], 403);
        }

        if ((int) ($customer->c_lockstatus ?? 0) === 1) {
            return response()->json([
                'message' => 'Your account is currently blocked. Please contact support for verification assistance.',
            ], 422);
        }

        if ((int) ($customer->c_accnt_status ?? 0) === 1) {
            return response()->json([
                'message' => 'Your account is already verified and active.',
                'status' => 'verified',
                'approval_owner' => 'admin',
            ]);
        }

        $existingPending = CustomerVerificationRequest::query()
            ->where('cvr_customer_id', (int) $customer->c_userid)
            ->whereIn('cvr_status', ['pending_review', 'for_review', 'on_hold'])
            ->latest('cvr_id')
            ->first();
        if ($existingPending) {
            return response()->json([
                'message' => 'You already have a pending verification request. Please wait for admin review.',
                'status' => 'pending_review',
                'approval_owner' => 'admin',
                'reference_no' => $existingPending->cvr_reference_no,
            ], 422);
        }

        $validated = $request->validate([
            'full_name' => 'required|string|min:3|max:255',
            'birth_date' => 'required|date',
            'id_type' => 'required|string|max:60',
            'id_number' => 'required|string|max:120',
            'contact_number' => 'required|string|max:60',
            'address_line' => 'required|string|max:255',
            'city' => 'required|string|max:120',
            'province' => 'required|string|max:120',
            'postal_code' => 'required|string|max:20',
            'country' => 'required|string|max:80',
            'notes' => 'required|string|max:1000',
            'id_front_url' => 'required|url|max:1200',
            'id_back_url' => 'required|url|max:1200',
            'selfie_url' => 'required|url|max:1200',
            'profile_photo_url' => 'nullable|url|max:1200',
        ]);

        $referenceNo = $this->generateVerificationReferenceNo();
        $verificationRequest = CustomerVerificationRequest::create([
            'cvr_customer_id' => (int) $customer->c_userid,
            'cvr_reference_no' => $referenceNo,
            'cvr_status' => 'pending_review',
            'cvr_full_name' => $validated['full_name'],
            'cvr_birth_date' => $validated['birth_date'] ?? null,
            'cvr_id_type' => $validated['id_type'],
            'cvr_id_number' => $validated['id_number'] ?? null,
            'cvr_contact_number' => $validated['contact_number'] ?? null,
            'cvr_address_line' => $validated['address_line'] ?? null,
            'cvr_city' => $validated['city'] ?? null,
            'cvr_province' => $validated['province'] ?? null,
            'cvr_postal_code' => $validated['postal_code'] ?? null,
            'cvr_country' => $validated['country'] ?? 'Philippines',
            'cvr_notes' => $validated['notes'] ?? null,
            'cvr_id_front_url' => $validated['id_front_url'],
            'cvr_id_back_url' => $validated['id_back_url'] ?? null,
            'cvr_selfie_url' => $validated['selfie_url'],
            'cvr_profile_photo_url' => $validated['profile_photo_url'] ?? ($customer->c_avatar_url ?? null),
        ]);

        if ((int) ($customer->c_accnt_status ?? 0) !== 2) {
            $customer->c_accnt_status = 2; // KYC/verification review queue
            $customer->save();
        }

        $this->notifyAdminKycSubmitted($customer->fresh(), $verificationRequest);

        return response()->json([
            'message' => 'Verification request submitted. Please wait for admin approval.',
            'status' => 'pending_review',
            'approval_owner' => 'admin',
            'reference_no' => $referenceNo,
            'verification' => $this->verificationMeta($customer->fresh()),
        ]);
    }

    private function verificationMeta(Customer $customer): array
    {
        if ((int) ($customer->c_lockstatus ?? 0) === 1) {
            return [
                'status' => 'blocked',
                'reference_no' => null,
                'submitted_at' => null,
            ];
        }

        if ((int) ($customer->c_accnt_status ?? 0) === 1) {
            return [
                'status' => 'verified',
                'reference_no' => null,
                'submitted_at' => null,
            ];
        }

        $pending = CustomerVerificationRequest::query()
            ->where('cvr_customer_id', (int) $customer->c_userid)
            ->whereIn('cvr_status', ['pending_review', 'for_review', 'on_hold'])
            ->latest('cvr_id')
            ->first();

        if ($pending) {
            return [
                'status' => 'pending_review',
                'reference_no' => $pending->cvr_reference_no,
                'submitted_at' => optional($pending->created_at)->toDateTimeString(),
            ];
        }

        return [
            'status' => ((int) ($customer->c_accnt_status ?? 0) === 2) ? 'pending_review' : 'not_submitted',
            'reference_no' => null,
            'submitted_at' => null,
        ];
    }

    private function generateVerificationReferenceNo(): string
    {
        $date = now()->format('Ymd');

        for ($attempt = 0; $attempt < 20; $attempt++) {
            $count = CustomerVerificationRequest::query()
                ->whereDate('created_at', now()->toDateString())
                ->count() + 1 + $attempt;
            $candidate = sprintf('KYC-%s-%04d', $date, $count);
            if (!CustomerVerificationRequest::query()->where('cvr_reference_no', $candidate)->exists()) {
                return $candidate;
            }
        }

        return sprintf('KYC-%s-%s', $date, strtoupper(substr(md5((string) microtime(true)), 0, 6)));
    }

    private function notifyAdminKycSubmitted(Customer $customer, CustomerVerificationRequest $verificationRequest): void
    {
        $customerName = trim(implode(' ', array_filter([
            $verificationRequest->cvr_full_name ?: null,
            $customer->c_fname ?? null,
            $customer->c_mname ?? null,
            $customer->c_lname ?? null,
        ])));
        $displayName = $customerName !== '' ? $customerName : ($customer->c_username ?: 'Affiliate');
        $referenceNo = (string) ($verificationRequest->cvr_reference_no ?? '');

        $notification = AdminNotification::query()->firstOrCreate(
            [
                'an_type' => 'kyc_submitted',
                'an_source_type' => 'kyc_request',
                'an_source_id' => (int) $verificationRequest->cvr_id,
            ],
            [
                'an_severity' => 'warning',
                'an_title' => 'New KYC Verification Request',
                'an_message' => sprintf(
                    '%s submitted a KYC request%s.',
                    $displayName,
                    $referenceNo !== '' ? ' (' . $referenceNo . ')' : ''
                ),
                'an_href' => '/admin/members/kyc',
                'an_payload' => [
                    'kyc_request_id' => (int) $verificationRequest->cvr_id,
                    'customer_id' => (int) $customer->c_userid,
                    'customer_name' => $displayName,
                    'customer_email' => $customer->c_email,
                    'reference_no' => $referenceNo,
                    'status' => 'pending_review',
                ],
                'an_created_at' => now(),
            ]
        );

        $appId = (string) config('services.pusher.app_id', '');
        $key = (string) config('services.pusher.key', '');
        $secret = (string) config('services.pusher.secret', '');

        if ($appId === '' || $key === '' || $secret === '') {
            return;
        }

        try {
            $pusher = new Pusher(
                $key,
                $secret,
                $appId,
                [
                    'cluster' => (string) config('services.pusher.cluster', 'ap1'),
                    'useTLS' => (bool) config('services.pusher.use_tls', true),
                ]
            );

            $pusher->trigger('private-admin-orders', 'notification.created', [
                'id' => (int) $notification->an_id,
                'type' => 'kyc_submitted',
                'title' => (string) $notification->an_title,
                'description' => (string) $notification->an_message,
                'href' => (string) ($notification->an_href ?? '/admin/members/kyc'),
                'created_at' => now()->toDateTimeString(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('Failed to publish admin realtime KYC notification.', [
                'kyc_request_id' => (int) $verificationRequest->cvr_id,
                'customer_id' => (int) $customer->c_userid,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function transform(EncashmentRequest $row, Customer $customer): array
    {
        $name = trim(implode(' ', array_filter([
            $customer->c_fname ?? null,
            $customer->c_mname ?? null,
            $customer->c_lname ?? null,
        ])));

        return [
            'id' => (int) $row->er_id,
            'reference_no' => $row->er_reference_no,
            'invoice_no' => $row->er_invoice_no,
            'amount' => (float) $row->er_amount,
            'channel' => $row->er_channel,
            'account_name' => $row->er_account_name,
            'account_number' => $row->er_account_number,
            'notes' => $row->er_notes,
            'status' => $row->er_status,
            'proof_url' => $row->er_proof_url,
            'proof_uploaded_at' => optional($row->er_proof_uploaded_at)->toDateTimeString(),
            'affiliate_name' => $name !== '' ? $name : ($customer->c_username ?? 'Affiliate'),
            'affiliate_email' => $customer->c_email ?? null,
            'approved_at' => optional($row->er_approved_at)->toDateTimeString(),
            'released_at' => optional($row->er_released_at)->toDateTimeString(),
            'created_at' => optional($row->created_at)->toDateTimeString(),
            'updated_at' => optional($row->updated_at)->toDateTimeString(),
        ];
    }

    private function transformPayoutMethod(EncashmentPayoutMethod $row): array
    {
        return [
            'id' => (int) $row->epm_id,
            'label' => (string) $row->epm_label,
            'method_type' => (string) $row->epm_method_type,
            'channel' => (string) $row->epm_channel,
            'account_name' => $row->epm_account_name,
            'account_number' => $row->epm_account_number,
            'mobile_number' => $row->epm_mobile_number,
            'email_address' => $row->epm_email,
            'bank_name' => $row->epm_bank_name,
            'bank_code' => $row->epm_bank_code,
            'account_type' => $row->epm_account_type,
            'card_holder_name' => $row->epm_card_holder_name,
            'card_brand' => $row->epm_card_brand,
            'card_last4' => $row->epm_card_last4,
            'is_default' => (bool) $row->epm_is_default,
            'created_at' => optional($row->created_at)->toDateTimeString(),
            'updated_at' => optional($row->updated_at)->toDateTimeString(),
        ];
    }

    private function mapMethodTypeToChannel(string $methodType): string
    {
        if ($methodType === 'gcash') {
            return 'gcash';
        }
        if ($methodType === 'maya') {
            return 'maya';
        }

        return 'bank';
    }

    private function generateReferenceNo(): string
    {
        $date = now()->format('Ymd');

        for ($attempt = 0; $attempt < 15; $attempt++) {
            $count = EncashmentRequest::query()
                ->whereDate('created_at', now()->toDateString())
                ->count() + 1 + $attempt;
            $candidate = sprintf('ENC-%s-%04d', $date, $count);
            if (!EncashmentRequest::query()->where('er_reference_no', $candidate)->exists()) {
                return $candidate;
            }
        }

        return sprintf('ENC-%s-%s', $date, strtoupper(substr(md5((string) microtime(true)), 0, 6)));
    }

    private function rules(): array
    {
        return [
            'min_amount' => max(1, (float) env('ENCASHMENT_MIN_AMOUNT', 500)),
            'min_points' => max(0, (float) env('ENCASHMENT_MIN_POINTS', 0)),
            'cooldown_hours' => max(0, (int) env('ENCASHMENT_COOLDOWN_HOURS', 24)),
            'require_active_account' => filter_var(env('ENCASHMENT_REQUIRE_ACTIVE_ACCOUNT', true), FILTER_VALIDATE_BOOL),
        ];
    }

    private function evaluateEligibility(Customer $customer, array $rules): array
    {
        $grossEarnings = (float) ($customer->c_totalincome ?? 0);
        $points = (float) ($customer->c_gpv ?? 0);

        $openStatuses = ['pending', 'approved_by_admin', 'on_hold'];
        $lockedAmount = (float) EncashmentRequest::query()
            ->where('er_customer_id', (int) $customer->c_userid)
            ->whereIn('er_status', $openStatuses)
            ->sum('er_amount');
        $availableAmount = max(0, $grossEarnings - $lockedAmount);

        $lastRequest = EncashmentRequest::query()
            ->where('er_customer_id', (int) $customer->c_userid)
            ->latest('created_at')
            ->first();

        $remainingCooldownMinutes = 0;
        if ($rules['cooldown_hours'] > 0 && $lastRequest?->created_at) {
            $cooldownEndsAt = $lastRequest->created_at->copy()->addHours($rules['cooldown_hours']);
            if ($cooldownEndsAt->isFuture()) {
                $remainingCooldownMinutes = now()->diffInMinutes($cooldownEndsAt);
            }
        }

        $blocked = false;
        $message = 'Eligible for encashment request.';

        if ($rules['require_active_account'] && ((int) ($customer->c_lockstatus ?? 0) === 1 || (int) ($customer->c_accnt_status ?? 0) !== 1)) {
            $blocked = true;
            $message = 'Your account must be active and verified before encashment.';
        } elseif ($points < $rules['min_points']) {
            $blocked = true;
            $message = 'Minimum points requirement not met for encashment.';
        } elseif ($availableAmount < $rules['min_amount']) {
            $blocked = true;
            $message = 'You do not have enough available balance for minimum encashment.';
        } elseif ($remainingCooldownMinutes > 0) {
            $blocked = true;
            $message = 'Please wait for cooldown period before submitting another request.';
        }

        return [
            'eligible' => !$blocked,
            'message' => $message,
            'available_amount' => round($availableAmount, 2),
            'locked_amount' => round($lockedAmount, 2),
            'gross_earnings' => round($grossEarnings, 2),
            'current_points' => round($points, 2),
            'remaining_cooldown_minutes' => $remainingCooldownMinutes,
            'has_active_account' => ((int) ($customer->c_lockstatus ?? 0) === 0) && ((int) ($customer->c_accnt_status ?? 0) === 1),
        ];
    }

    private function policyMeta(array $rules): array
    {
        return [
            'min_amount' => round((float) $rules['min_amount'], 2),
            'min_points' => round((float) $rules['min_points'], 2),
            'cooldown_hours' => (int) $rules['cooldown_hours'],
            'require_active_account' => (bool) $rules['require_active_account'],
        ];
    }
}
