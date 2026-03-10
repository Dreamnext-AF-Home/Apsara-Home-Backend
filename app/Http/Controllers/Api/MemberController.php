<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\CustomerWalletLedger;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

class MemberController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $perPage = (int) $request->integer('per_page', 25);
        $perPage = max(1, min($perPage, 100));
        $search = trim((string) $request->query('q', ''));
        $status = trim((string) $request->query('status', ''));
        $tier = trim((string) $request->query('tier', ''));

        $cacheKey = 'admin:members:index:' . md5(json_encode([
            'page' => (int) $request->integer('page', 1),
            'per_page' => $perPage,
            'q' => $search,
            'status' => $status,
            'tier' => $tier,
        ]));

        $payload = Cache::remember($cacheKey, now()->addMinutes(2), function () use ($perPage, $search, $status, $tier) {
            $paginator = Customer::query()
                ->select([
                    'c_userid',
                    'c_username',
                    'c_fname',
                    'c_mname',
                    'c_lname',
                    'c_email',
                    'c_address',
                    'c_barangay',
                    'c_city',
                    'c_province',
                    'c_region',
                    'c_zipcode',
                    'c_avatar_url',
                    'c_lockstatus',
                    'c_accnt_status',
                    'c_rank',
                    'c_totalpair',
                    'c_gpv',
                    'c_totalincome',
                    'c_date_started',
                    'c_last_logindate',
                ])
                ->when($search !== '', function ($query) use ($search) {
                    $like = '%' . $search . '%';

                    $query->where(function ($inner) use ($like) {
                        $inner->where('c_username', 'ilike', $like)
                            ->orWhere('c_email', 'ilike', $like)
                            ->orWhere('c_fname', 'ilike', $like)
                            ->orWhere('c_mname', 'ilike', $like)
                            ->orWhere('c_lname', 'ilike', $like)
                            ->orWhereRaw(
                                "TRIM(COALESCE(c_fname, '') || ' ' || COALESCE(c_mname, '') || ' ' || COALESCE(c_lname, '')) ILIKE ?",
                                [$like]
                            );
                    });
                })
                ->when($status !== '', function ($query) use ($status) {
                    if ($status === 'blocked') {
                        $query->where('c_lockstatus', 1);
                        return;
                    }

                    if ($status === 'pending') {
                        $query->where('c_lockstatus', 0)->where('c_accnt_status', 0);
                        return;
                    }

                    if ($status === 'kyc_review') {
                        $query->where('c_lockstatus', 0)->where('c_accnt_status', 2);
                        return;
                    }

                    if ($status === 'active') {
                        $query->where('c_lockstatus', 0)->where('c_accnt_status', 1);
                    }
                })
                ->when($tier !== '', function ($query) use ($tier) {
                    if ($tier === 'Lifestyle Elite') {
                        $query->where('c_rank', '>=', 5);
                        return;
                    }

                    if ($tier === 'Lifestyle Consultant') {
                        $query->where('c_rank', 4);
                        return;
                    }

                    if ($tier === 'Home Stylist') {
                        $query->where('c_rank', 3);
                        return;
                    }

                    if ($tier === 'Home Builder') {
                        $query->where('c_rank', 2);
                        return;
                    }

                    if ($tier === 'Home Starter') {
                        $query->where('c_rank', '<=', 1);
                    }
                })
                ->orderByDesc('c_userid')
                ->paginate($perPage);

            $pageUserIds = collect($paginator->items())->pluck('c_userid')->all();
            $referralCounts = empty($pageUserIds)
                ? collect()
                : Customer::query()
                    ->selectRaw('c_sponsor, COUNT(*) as total')
                    ->whereIn('c_sponsor', $pageUserIds)
                    ->groupBy('c_sponsor')
                    ->pluck('total', 'c_sponsor');

            $walletCreditsByCustomer = collect();
            if (!empty($pageUserIds) && Schema::hasTable('tbl_customer_wallet_ledger')) {
                $walletCreditRows = CustomerWalletLedger::query()
                    ->selectRaw('wl_customer_id, wl_wallet_type, SUM(wl_amount) as total_amount')
                    ->whereIn('wl_customer_id', $pageUserIds)
                    ->where('wl_entry_type', 'credit')
                    ->whereIn('wl_wallet_type', ['cash', 'pv'])
                    ->groupBy('wl_customer_id', 'wl_wallet_type')
                    ->get();

                $walletCreditsByCustomer = $walletCreditRows
                    ->groupBy('wl_customer_id')
                    ->map(function ($rows) {
                        return [
                            'cash' => (float) (($rows->firstWhere('wl_wallet_type', 'cash')->total_amount ?? 0)),
                            'pv' => (float) (($rows->firstWhere('wl_wallet_type', 'pv')->total_amount ?? 0)),
                        ];
                    });
            }

            $members = collect($paginator->items())
                ->map(function (Customer $customer) use ($referralCounts, $walletCreditsByCustomer): array {
                    $fullName = trim(implode(' ', array_filter([
                        (string) $customer->c_fname,
                        (string) $customer->c_mname,
                        (string) $customer->c_lname,
                    ])));

                    if ($fullName === '') {
                        $fullName = (string) ($customer->c_username ?: ('Member #' . $customer->c_userid));
                    }

                    $status = $this->mapStatus(
                        (int) $customer->c_lockstatus,
                        (int) $customer->c_accnt_status
                    );
                    $verificationStatus = $this->mapVerificationStatus(
                        (int) $customer->c_lockstatus,
                        (int) $customer->c_accnt_status
                    );

                    $rank = (int) $customer->c_rank;
                    $tier = $this->mapTier($rank);
                    $joinedAt = $this->formatDate($customer->c_date_started);
                    $lastActiveAt = $this->formatDate($customer->c_last_logindate) ?: $joinedAt;
                    $walletCredits = $walletCreditsByCustomer->get((int) $customer->c_userid, ['cash' => 0, 'pv' => 0]);
                    $addressParts = array_filter([
                        (string) ($customer->c_address ?? ''),
                        (string) ($customer->c_barangay ?? ''),
                        (string) ($customer->c_city ?? ''),
                        (string) ($customer->c_province ?? ''),
                        (string) ($customer->c_region ?? ''),
                        (string) ($customer->c_zipcode ?? ''),
                    ], fn ($value) => trim((string) $value) !== '');

                    return [
                        'id' => (int) $customer->c_userid,
                        'name' => $fullName,
                        'email' => (string) ($customer->c_email ?: ''),
                        'avatar' => (string) ($customer->c_avatar_url ?: ''),
                        'verificationStatus' => $verificationStatus,
                        'status' => $status,
                        'tier' => $tier,
                        'orders' => (int) $customer->c_totalpair,
                        'totalSpent' => (float) $customer->c_gpv,
                        'earnings' => (float) $customer->c_totalincome,
                        'walletCashBalance' => (float) ($customer->c_totalincome ?? 0),
                        'walletPvBalance' => (float) ($customer->c_gpv ?? 0),
                        'walletCashCredits' => (float) ($walletCredits['cash'] ?? 0),
                        'walletPvCredits' => (float) ($walletCredits['pv'] ?? 0),
                        'referrals' => (int) ($referralCounts[(int) $customer->c_userid] ?? 0),
                        'joinedAt' => $joinedAt,
                        'lastActiveAt' => $lastActiveAt,
                        'addressLine' => (string) ($customer->c_address ?? ''),
                        'barangay' => (string) ($customer->c_barangay ?? ''),
                        'city' => (string) ($customer->c_city ?? ''),
                        'province' => (string) ($customer->c_province ?? ''),
                        'region' => (string) ($customer->c_region ?? ''),
                        'zipCode' => (string) ($customer->c_zipcode ?? ''),
                        'fullAddress' => !empty($addressParts) ? implode(', ', $addressParts) : '',
                    ];
                })
                ->values();

            return [
                'members' => $members,
                'meta' => [
                    'current_page' => $paginator->currentPage(),
                    'last_page' => $paginator->lastPage(),
                    'per_page' => $paginator->perPage(),
                    'total' => $paginator->total(),
                    'from' => $paginator->firstItem(),
                    'to' => $paginator->lastItem(),
                ],
            ];
        });

        return response()->json($payload);
    }

    public function stats(): JsonResponse
    {
        $payload = Cache::remember('admin:members:stats', now()->addMinutes(2), function () {
            $total = Customer::count();
            $active = Customer::where('c_lockstatus', 0)->where('c_accnt_status', 1)->count();
            $pending = Customer::where('c_lockstatus', 0)->whereIn('c_accnt_status', [0, 2])->count();
            $blocked = Customer::where('c_lockstatus', 1)->count();
            $totalSpent = (float) Customer::sum('c_gpv');
            $totalEarnings = (float) Customer::sum('c_totalincome');
            $totalReferrals = Customer::whereNotNull('c_sponsor')
                ->where('c_sponsor', '!=', 0)
                ->count();

            return [
                'total' => $total,
                'active' => $active,
                'pending' => $pending,
                'blocked' => $blocked,
                'totalSpent' => $totalSpent,
                'totalEarnings' => $totalEarnings,
                'totalReferrals' => $totalReferrals,
            ];
        });

        return response()->json($payload);
    }

    private function mapStatus(int $lockStatus, int $accountStatus): string
    {
        if ($lockStatus === 1) {
            return 'blocked';
        }

        if ($accountStatus === 2) {
            return 'kyc_review';
        }

        if ($accountStatus === 0) {
            return 'pending';
        }

        return 'active';
    }

    private function mapVerificationStatus(int $lockStatus, int $accountStatus): string
    {
        if ($lockStatus === 1) {
            return 'blocked';
        }

        if ($accountStatus === 1) {
            return 'verified';
        }

        if ($accountStatus === 2) {
            return 'pending_review';
        }

        return 'not_verified';
    }

    private function mapTier(int $rank): string
    {
        if ($rank >= 5) {
            return 'Lifestyle Elite';
        }

        if ($rank >= 4) {
            return 'Lifestyle Consultant';
        }

        if ($rank >= 3) {
            return 'Home Stylist';
        }

        if ($rank >= 2) {
            return 'Home Builder';
        }

        return 'Home Starter';
    }

    private function formatDate(?string $value): string
    {
        if (! $value) {
            return '';
        }

        try {
            return Carbon::parse($value)->format('Y-m-d');
        } catch (\Throwable $exception) {
            return '';
        }
    }
}
