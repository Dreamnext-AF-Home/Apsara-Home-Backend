<?php

namespace App\Http\Controllers\Api;

use App\Events\CustomerAccountDeleted;
use App\Http\Controllers\Controller;
use App\Models\AdminNotification;
use App\Models\Customer;
use App\Models\CustomerWalletLedger;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Illuminate\Support\Str;

class MemberController extends Controller
{
    private const MEMBERS_CACHE_VERSION_KEY = 'admin:members:cache-version';

    public function topEarners(Request $request): JsonResponse
    {
        $search = trim((string) $request->query('q', ''));
        $tier = trim((string) $request->query('tier', ''));
        $sort = trim((string) $request->query('sort', 'earnings'));
        $allowedSorts = ['earnings', 'orders', 'referrals', 'total_spent'];

        if (!in_array($sort, $allowedSorts, true)) {
            $sort = 'earnings';
        }

        $cacheVersion = $this->membersCacheVersion();
        $cacheKey = 'admin:members:top-earners:' . md5(json_encode([
            'v' => $cacheVersion,
            'q' => $search,
            'tier' => $tier,
            'sort' => $sort,
        ]));

        $payloadBuilder = function () use ($search, $tier, $sort) {
            $paidStatuses = ['paid', 'succeeded', 'success'];
            $members = Customer::query()
                ->select([
                    'tbl_customer.c_userid',
                    'tbl_customer.c_username',
                    'tbl_customer.c_fname',
                    'tbl_customer.c_mname',
                    'tbl_customer.c_lname',
                    'tbl_customer.c_email',
                    'tbl_customer.c_rank',
                    'tbl_customer.c_totalpair',
                    'tbl_customer.c_gpv',
                    'tbl_customer.c_totalincome',
                    'tbl_customer.c_date_started',
                    'tbl_customer.c_last_logindate',
                    'tbl_customer.c_lockstatus',
                    'tbl_customer.c_accnt_status',
                ])
                ->when($search !== '', fn ($query) => $this->applyMemberSearch($query, $search))
                ->when($tier !== '', function ($query) use ($tier) {
                    if ($tier === 'Lifestyle Elite') {
                        $query->where('tbl_customer.c_rank', '>=', 5);
                        return;
                    }

                    if ($tier === 'Lifestyle Consultant') {
                        $query->where('tbl_customer.c_rank', 4);
                        return;
                    }

                    if ($tier === 'Home Stylist') {
                        $query->where('tbl_customer.c_rank', 3);
                        return;
                    }

                    if ($tier === 'Home Builder') {
                        $query->where('tbl_customer.c_rank', 2);
                        return;
                    }

                    if ($tier === 'Home Starter') {
                        $query->where('tbl_customer.c_rank', '<=', 1);
                    }
                })
                ->get();

            $memberIds = $members->pluck('c_userid')->map(fn ($id) => (int) $id)->all();
            $referralCounts = empty($memberIds)
                ? collect()
                : Customer::query()
                    ->selectRaw('c_sponsor, COUNT(*) as total')
                    ->whereIn('c_sponsor', $memberIds)
                    ->groupBy('c_sponsor')
                    ->pluck('total', 'c_sponsor');
            $orderMetrics = empty($memberIds)
                ? collect()
                : DB::table('tbl_checkout_history')
                    ->selectRaw('ch_customer_id, COUNT(*) as total_orders, COALESCE(SUM(ch_amount), 0) as total_spent')
                    ->whereIn('ch_customer_id', $memberIds)
                    ->whereIn('ch_status', $paidStatuses)
                    ->groupBy('ch_customer_id')
                    ->get()
                    ->keyBy('ch_customer_id');

            $rows = $members
                ->map(function (Customer $customer) use ($orderMetrics, $referralCounts): array {
                    $customerId = (int) $customer->c_userid;
                    $metrics = $orderMetrics->get($customerId);

                    return [
                        'id' => $customerId,
                        'name' => $this->displayName($customer),
                        'email' => (string) ($customer->c_email ?? ''),
                        'tier' => $this->mapTier((int) ($customer->c_rank ?? 0)),
                        'earnings' => (float) ($customer->c_totalincome ?? 0),
                        'orders' => (int) ($metrics->total_orders ?? 0),
                        'referrals' => (int) ($referralCounts[$customerId] ?? 0),
                        'status' => $this->mapStatus(
                            (int) ($customer->c_lockstatus ?? 0),
                            (int) ($customer->c_accnt_status ?? 0)
                        ),
                        'joinedAt' => $this->formatDate($customer->c_date_started),
                        'lastActive' => $this->formatDate($customer->c_last_logindate) ?: $this->formatDate($customer->c_date_started),
                        'totalSpent' => (float) ($metrics->total_spent ?? 0),
                    ];
                })
                ->sort(function (array $left, array $right) use ($sort) {
                    $leftMetric = match ($sort) {
                        'orders' => (int) ($left['orders'] ?? 0),
                        'referrals' => (int) ($left['referrals'] ?? 0),
                        'total_spent' => (float) ($left['totalSpent'] ?? 0),
                        default => (float) ($left['earnings'] ?? 0),
                    };

                    $rightMetric = match ($sort) {
                        'orders' => (int) ($right['orders'] ?? 0),
                        'referrals' => (int) ($right['referrals'] ?? 0),
                        'total_spent' => (float) ($right['totalSpent'] ?? 0),
                        default => (float) ($right['earnings'] ?? 0),
                    };

                    if ($leftMetric === $rightMetric) {
                        return ((int) ($right['id'] ?? 0)) <=> ((int) ($left['id'] ?? 0));
                    }

                    return $rightMetric <=> $leftMetric;
                })
                ->values();

            return [
                'summary' => [
                    'totalEarnings' => (float) $rows->sum('earnings'),
                    'activeEarners' => (int) $rows->filter(fn (array $row) => (float) ($row['earnings'] ?? 0) > 0)->count(),
                    'avgEarnings' => $rows->count() > 0 ? (float) ($rows->sum('earnings') / $rows->count()) : 0.0,
                    'topEarnerAmount' => (float) ($rows->first()['earnings'] ?? 0),
                    'totalMembers' => (int) $rows->count(),
                ],
                'members' => $rows->all(),
            ];
        };

        try {
            $payload = Cache::remember($cacheKey, now()->addMinutes(2), $payloadBuilder);
        } catch (\Throwable $exception) {
            $payload = $payloadBuilder();
        }

        return response()->json($payload);
    }

    public function index(Request $request): JsonResponse
    {
        $perPage = (int) $request->integer('per_page', 25);
        $perPage = max(1, min($perPage, 100));
        $search = trim((string) $request->query('q', ''));
        $status = trim((string) $request->query('status', ''));
        $tier = trim((string) $request->query('tier', ''));
        $registration = trim((string) $request->query('registration', ''));
        $profilePhoto = trim((string) $request->query('profile_photo', ''));
        $sort = trim((string) $request->query('sort', 'default'));
        $cacheVersion = $this->membersCacheVersion();

        $cacheKey = 'admin:members:index:' . md5(json_encode([
            'v' => $cacheVersion,
            'page' => (int) $request->integer('page', 1),
            'per_page' => $perPage,
            'q' => $search,
            'status' => $status,
            'tier' => $tier,
            'registration' => $registration,
            'profile_photo' => $profilePhoto,
            'sort' => $sort,
        ]));

        $payloadBuilder = function () use ($perPage, $search, $status, $tier, $registration, $profilePhoto, $sort) {
            $paginator = Customer::query()
                ->select([
                    'tbl_customer.c_userid',
                    'tbl_customer.c_username',
                    'tbl_customer.c_fname',
                    'tbl_customer.c_mname',
                    'tbl_customer.c_lname',
                    'tbl_customer.c_email',
                    'tbl_customer.c_mobile',
                    'tbl_customer.c_address',
                    'tbl_customer.c_barangay',
                    'tbl_customer.c_city',
                    'tbl_customer.c_province',
                    'tbl_customer.c_region',
                    'tbl_customer.c_zipcode',
                    'tbl_customer.c_avatar_url',
                    'tbl_customer.c_lockstatus',
                    'tbl_customer.c_accnt_status',
                    'tbl_customer.c_rank',
                    'tbl_customer.c_totalpair',
                    'tbl_customer.c_gpv',
                    'tbl_customer.c_totalincome',
                    'tbl_customer.c_sponsor',
                    'tbl_customer.c_date_started',
                    'tbl_customer.c_last_logindate',
                ])
                ->when($search !== '', fn ($query) => $this->applyMemberSearch($query, $search))
                ->when($status !== '', function ($query) use ($status) {
                    if ($status === 'blocked') {
                        $query->where('tbl_customer.c_lockstatus', 1);
                        return;
                    }

                    if ($status === 'pending') {
                        $query->where('tbl_customer.c_lockstatus', 0)->where('tbl_customer.c_accnt_status', 0);
                        return;
                    }

                    if ($status === 'kyc_review') {
                        $query->where('tbl_customer.c_lockstatus', 0)->where('tbl_customer.c_accnt_status', 2);
                        return;
                    }

                    if ($status === 'active') {
                        $query->where('tbl_customer.c_lockstatus', 0)->where('tbl_customer.c_accnt_status', 1);
                    }
                })
                ->when($tier !== '', function ($query) use ($tier) {
                    if ($tier === 'Lifestyle Elite') {
                        $query->where('tbl_customer.c_rank', '>=', 5);
                        return;
                    }

                    if ($tier === 'Lifestyle Consultant') {
                        $query->where('tbl_customer.c_rank', 4);
                        return;
                    }

                    if ($tier === 'Home Stylist') {
                        $query->where('tbl_customer.c_rank', 3);
                        return;
                    }

                    if ($tier === 'Home Builder') {
                        $query->where('tbl_customer.c_rank', 2);
                        return;
                    }

                    if ($tier === 'Home Starter') {
                        $query->where('tbl_customer.c_rank', '<=', 1);
                    }
                })
                ->when($registration !== '', function ($query) use ($registration) {
                    if ($registration === 'new') {
                        $query->whereNotNull('tbl_customer.c_date_started')
                            ->whereRaw("tbl_customer.c_date_started >= (CURRENT_DATE - INTERVAL '6 days')");
                        return;
                    }

                    if ($registration === 'referred') {
                        $query->whereNotNull('tbl_customer.c_sponsor')
                            ->where('tbl_customer.c_sponsor', '<>', 0);
                        return;
                    }

                    if ($registration === 'direct') {
                        $query->where(function ($inner) {
                            $inner->whereNull('tbl_customer.c_sponsor')
                                ->orWhere('tbl_customer.c_sponsor', 0);
                        });
                    }
                })
                ->when($profilePhoto !== '', function ($query) use ($profilePhoto) {
                    if ($profilePhoto === 'with_photo') {
                        $query->whereNotNull('tbl_customer.c_avatar_url')
                            ->whereRaw("NULLIF(TRIM(tbl_customer.c_avatar_url), '') IS NOT NULL");
                        return;
                    }

                    if ($profilePhoto === 'no_photo') {
                        $query->where(function ($inner) {
                            $inner->whereNull('tbl_customer.c_avatar_url')
                                ->orWhereRaw("NULLIF(TRIM(tbl_customer.c_avatar_url), '') IS NULL");
                        });
                    }
                })
                ->when($sort === 'referrals_high_low', function ($query) {
                    $query
                        ->leftJoin('tbl_customer as referrals', 'referrals.c_sponsor', '=', 'tbl_customer.c_userid')
                        ->groupBy(
                            'tbl_customer.c_userid',
                            'tbl_customer.c_username',
                            'tbl_customer.c_fname',
                            'tbl_customer.c_mname',
                            'tbl_customer.c_lname',
                            'tbl_customer.c_email',
                            'tbl_customer.c_mobile',
                            'tbl_customer.c_address',
                            'tbl_customer.c_barangay',
                            'tbl_customer.c_city',
                            'tbl_customer.c_province',
                            'tbl_customer.c_region',
                            'tbl_customer.c_zipcode',
                            'tbl_customer.c_avatar_url',
                            'tbl_customer.c_lockstatus',
                            'tbl_customer.c_accnt_status',
                            'tbl_customer.c_rank',
                            'tbl_customer.c_totalpair',
                            'tbl_customer.c_gpv',
                            'tbl_customer.c_totalincome',
                            'tbl_customer.c_sponsor',
                            'tbl_customer.c_date_started',
                            'tbl_customer.c_last_logindate',
                        )
                        ->selectRaw('COUNT(referrals.c_userid) as referral_sort_total')
                        ->orderByDesc('referral_sort_total')
                        ->orderByDesc('tbl_customer.c_userid');
                }, function ($query) use ($sort) {
                    if ($sort === 'newest_registered') {
                        $query
                            ->orderByDesc('tbl_customer.c_date_started')
                            ->orderByDesc('tbl_customer.c_userid');
                        return;
                    }

                    if ($sort === 'oldest_registered') {
                        $query
                            ->orderBy('tbl_customer.c_date_started')
                            ->orderBy('tbl_customer.c_userid');
                        return;
                    }

                    $query->orderByDesc('tbl_customer.c_userid');
                })
                ->paginate($perPage);

            $pageUserIds = collect($paginator->items())->pluck('c_userid')->all();
            $sponsorIds = collect($paginator->items())
                ->pluck('c_sponsor')
                ->filter(fn ($value) => (int) $value > 0)
                ->map(fn ($value) => (int) $value)
                ->unique()
                ->values()
                ->all();
            $referralCounts = empty($pageUserIds)
                ? collect()
                : Customer::query()
                    ->selectRaw('c_sponsor, COUNT(*) as total')
                    ->whereIn('c_sponsor', $pageUserIds)
                    ->groupBy('c_sponsor')
                    ->pluck('total', 'c_sponsor');

            $sponsorsById = empty($sponsorIds)
                ? collect()
                : Customer::query()
                    ->select([
                        'c_userid',
                        'c_username',
                        'c_fname',
                        'c_mname',
                        'c_lname',
                    ])
                    ->whereIn('c_userid', $sponsorIds)
                    ->get()
                    ->keyBy('c_userid');

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
                ->map(function (Customer $customer) use ($referralCounts, $walletCreditsByCustomer, $sponsorsById): array {
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
                    $registeredAt = $this->formatDateTime($customer->c_date_started);
                    $walletCredits = $walletCreditsByCustomer->get((int) $customer->c_userid, ['cash' => 0, 'pv' => 0]);
                    $sponsor = $sponsorsById->get((int) ($customer->c_sponsor ?? 0));
                    $sponsorName = $sponsor instanceof Customer ? $this->displayName($sponsor) : '';
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
                        'username' => (string) ($customer->c_username ?? ''),
                        'email' => (string) ($customer->c_email ?: ''),
                        'referredByName' => $sponsorName,
                        'referredByUsername' => $sponsor instanceof Customer ? (string) ($sponsor->c_username ?? '') : '',
                        'contactNumber' => (string) ($customer->c_mobile ?: ''),
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
                        'createdAt' => $registeredAt,
                        'created_at' => $registeredAt,
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
        };

        try {
            $payload = Cache::remember($cacheKey, now()->addMinutes(2), $payloadBuilder);
        } catch (\Throwable $exception) {
            $payload = $payloadBuilder();
        }

        return response()->json($payload);
    }

    public function stats(Request $request): JsonResponse
    {
        $period = $this->normalizeMemberStatsPeriod((string) $request->query('period', '7d'));
        $cacheKey = 'admin:members:stats:' . $this->membersCacheVersion() . ':' . $period;
        try {
            $cached = Cache::get($cacheKey);
        } catch (\Throwable $exception) {
            $cached = null;
        }

        if (is_array($cached)) {
            return response()->json($cached);
        }

        try {
            $lock = Cache::lock('lock:' . $cacheKey, 30);
            $hasLock = $lock->get();
        } catch (\Throwable $exception) {
            $payload = $this->buildStatsPayload($period);
            return response()->json($payload);
        }

        if ($hasLock) {
            try {
                $payload = $this->buildStatsPayload($period);
                try {
                    Cache::put($cacheKey, $payload, now()->addMinutes(10));
                } catch (\Throwable $exception) {
                    // Ignore cache write failures in local/dev when Redis is unavailable.
                }
                return response()->json($payload);
            } finally {
                $lock->release();
            }
        }

        // Another request is currently computing stats. Wait briefly for cached result.
        usleep(250000);
        try {
            $payload = Cache::get($cacheKey);
        } catch (\Throwable $exception) {
            $payload = null;
        }

        if (is_array($payload)) {
            return response()->json($payload);
        }

        // Fallback in case lock holder failed; still return real data.
        $payload = $this->buildStatsPayload($period);
        try {
            Cache::put($cacheKey, $payload, now()->addMinutes(10));
        } catch (\Throwable $exception) {
            // Ignore cache write failures in local/dev when Redis is unavailable.
        }

        return response()->json($payload);
    }

    public function statDetails(Request $request, string $stat): JsonResponse
    {
        $perPage = (int) $request->integer('per_page', 25);
        $perPage = max(1, min($perPage, 100));
        $search = trim((string) $request->query('q', ''));
        $period = $this->normalizeMemberStatsPeriod((string) $request->query('period', '7d'));

        $allowedStats = [
            'total_members',
            'active',
            'pending',
            'blocked',
            'new_members',
            'total_spent',
            'total_earnings',
            'total_referrals',
        ];

        if (!in_array($stat, $allowedStats, true)) {
            return response()->json([
                'message' => 'Unknown member stat type.',
            ], 404);
        }

        $cacheVersion = $this->membersCacheVersion();
        $cacheKey = 'admin:members:stat-details:' . md5(json_encode([
            'v' => $cacheVersion,
            'stat' => $stat,
            'page' => (int) $request->integer('page', 1),
            'per_page' => $perPage,
            'q' => $search,
            'period' => $period,
        ]));

        $payloadBuilder = function () use ($perPage, $stat, $search, $period) {
            $query = Customer::query()
                ->select([
                    'tbl_customer.c_userid',
                    'tbl_customer.c_username',
                    'tbl_customer.c_fname',
                    'tbl_customer.c_mname',
                    'tbl_customer.c_lname',
                    'tbl_customer.c_email',
                    'tbl_customer.c_mobile',
                    'tbl_customer.c_address',
                    'tbl_customer.c_barangay',
                    'tbl_customer.c_city',
                    'tbl_customer.c_province',
                    'tbl_customer.c_region',
                    'tbl_customer.c_zipcode',
                    'tbl_customer.c_avatar_url',
                    'tbl_customer.c_lockstatus',
                    'tbl_customer.c_accnt_status',
                    'tbl_customer.c_rank',
                    'tbl_customer.c_totalpair',
                    'tbl_customer.c_gpv',
                    'tbl_customer.c_totalincome',
                    'tbl_customer.c_sponsor',
                    'tbl_customer.c_date_started',
                    'tbl_customer.c_last_logindate',
                ])
                ->when($search !== '', fn ($query) => $this->applyMemberSearch($query, $search));

            $metricLabel = 'Status';
            $title = 'All Members';
            $metricResolver = fn (Customer $customer, int $referrals): string => $this->mapStatus(
                (int) $customer->c_lockstatus,
                (int) $customer->c_accnt_status
            );

            if ($stat === 'active') {
                $title = 'Active Members';
                $metricLabel = 'Orders';
                $metricResolver = fn (Customer $customer, int $referrals): string => (string) ((int) $customer->c_totalpair);
                $query->where('tbl_customer.c_lockstatus', 0)->where('tbl_customer.c_accnt_status', 1)
                    ->orderByDesc('tbl_customer.c_totalpair')
                    ->orderByDesc('tbl_customer.c_userid');
            } elseif ($stat === 'pending') {
                $title = 'Pending / KYC Members';
                $metricLabel = 'Verification';
                $metricResolver = fn (Customer $customer, int $referrals): string => $this->mapVerificationStatus(
                    (int) $customer->c_lockstatus,
                    (int) $customer->c_accnt_status
                );
                $query->where('tbl_customer.c_lockstatus', 0)
                    ->whereIn('tbl_customer.c_accnt_status', [0, 2])
                    ->orderBy('tbl_customer.c_accnt_status')
                    ->orderByDesc('tbl_customer.c_userid');
            } elseif ($stat === 'blocked') {
                $title = 'Blocked Members';
                $metricLabel = 'Tier';
                $metricResolver = fn (Customer $customer, int $referrals): string => $this->mapTier((int) $customer->c_rank);
                $query->where('tbl_customer.c_lockstatus', 1)
                    ->orderByDesc('tbl_customer.c_userid');
            } elseif ($stat === 'new_members') {
                $title = 'New Members ' . $this->memberStatsPeriodLabel($period);
                $metricLabel = 'Joined';
                $metricResolver = fn (Customer $customer, int $referrals): string => $this->formatDateTime($customer->c_date_started) ?: 'Unknown date';
                $query->whereNotNull('tbl_customer.c_date_started')
                    ->whereRaw($this->memberStatsPeriodSql($period))
                    ->orderByDesc('tbl_customer.c_date_started')
                    ->orderByDesc('tbl_customer.c_userid');
            } elseif ($stat === 'total_spent') {
                $title = 'Members With Spending';
                $metricLabel = 'Total Spent';
                $metricResolver = fn (Customer $customer, int $referrals): string => 'PHP ' . number_format((float) ($customer->c_gpv ?? 0), 2);
                $query->where('tbl_customer.c_gpv', '>', 0)
                    ->orderByDesc('tbl_customer.c_gpv')
                    ->orderByDesc('tbl_customer.c_userid');
            } elseif ($stat === 'total_earnings') {
                $title = 'Members With Earnings';
                $metricLabel = 'Earnings';
                $metricResolver = fn (Customer $customer, int $referrals): string => 'PHP ' . number_format((float) ($customer->c_totalincome ?? 0), 2);
                $query->where('tbl_customer.c_totalincome', '>', 0)
                    ->orderByDesc('tbl_customer.c_totalincome')
                    ->orderByDesc('tbl_customer.c_userid');
            } elseif ($stat === 'total_referrals') {
                $title = 'Members With Referrals';
                $metricLabel = 'Referrals';
                $metricResolver = fn (Customer $customer, int $referrals): string => (string) $referrals;
                $query
                    ->leftJoin('tbl_customer as referrals', 'referrals.c_sponsor', '=', 'tbl_customer.c_userid')
                    ->groupBy(
                        'tbl_customer.c_userid',
                        'tbl_customer.c_username',
                        'tbl_customer.c_fname',
                        'tbl_customer.c_mname',
                        'tbl_customer.c_lname',
                        'tbl_customer.c_email',
                        'tbl_customer.c_mobile',
                        'tbl_customer.c_address',
                        'tbl_customer.c_barangay',
                        'tbl_customer.c_city',
                        'tbl_customer.c_province',
                        'tbl_customer.c_region',
                        'tbl_customer.c_zipcode',
                        'tbl_customer.c_avatar_url',
                        'tbl_customer.c_lockstatus',
                        'tbl_customer.c_accnt_status',
                        'tbl_customer.c_rank',
                        'tbl_customer.c_totalpair',
                        'tbl_customer.c_gpv',
                        'tbl_customer.c_totalincome',
                        'tbl_customer.c_sponsor',
                        'tbl_customer.c_date_started',
                        'tbl_customer.c_last_logindate',
                    )
                    ->selectRaw('COUNT(referrals.c_userid) as referral_sort_total')
                    ->havingRaw('COUNT(referrals.c_userid) > 0')
                    ->orderByDesc('referral_sort_total')
                    ->orderByDesc('tbl_customer.c_userid');
            } else {
                $query->orderByDesc('tbl_customer.c_userid');
            }

            $paginator = $query->paginate($perPage);

            $pageUserIds = collect($paginator->items())->pluck('c_userid')->all();
            $sponsorIds = collect($paginator->items())
                ->pluck('c_sponsor')
                ->filter(fn ($value) => (int) $value > 0)
                ->map(fn ($value) => (int) $value)
                ->unique()
                ->values()
                ->all();

            $referralCounts = empty($pageUserIds)
                ? collect()
                : Customer::query()
                    ->selectRaw('c_sponsor, COUNT(*) as total')
                    ->whereIn('c_sponsor', $pageUserIds)
                    ->groupBy('c_sponsor')
                    ->pluck('total', 'c_sponsor');

            $sponsorsById = empty($sponsorIds)
                ? collect()
                : Customer::query()
                    ->select([
                        'c_userid',
                        'c_username',
                        'c_fname',
                        'c_mname',
                        'c_lname',
                    ])
                    ->whereIn('c_userid', $sponsorIds)
                    ->get()
                    ->keyBy('c_userid');

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

            $referralChildrenBySponsor = collect();
            if ($stat === 'total_referrals' && !empty($pageUserIds)) {
                $referralChildrenBySponsor = Customer::query()
                    ->select([
                        'c_userid',
                        'c_username',
                        'c_fname',
                        'c_mname',
                        'c_lname',
                        'c_email',
                        'c_mobile',
                        'c_sponsor',
                        'c_rank',
                        'c_lockstatus',
                        'c_accnt_status',
                        'c_date_started',
                    ])
                    ->whereIn('c_sponsor', $pageUserIds)
                    ->orderByDesc('c_date_started')
                    ->orderByDesc('c_userid')
                    ->get()
                    ->groupBy('c_sponsor')
                    ->map(function ($children) {
                        return $children->map(function (Customer $child) {
                            $fullName = trim(implode(' ', array_filter([
                                (string) $child->c_fname,
                                (string) $child->c_mname,
                                (string) $child->c_lname,
                            ])));

                            if ($fullName === '') {
                                $fullName = (string) ($child->c_username ?: ('Member #' . $child->c_userid));
                            }

                            return [
                                'id' => (int) $child->c_userid,
                                'name' => $fullName,
                                'username' => (string) ($child->c_username ?? ''),
                                'email' => (string) ($child->c_email ?: ''),
                                'contactNumber' => (string) ($child->c_mobile ?: ''),
                                'status' => $this->mapStatus(
                                    (int) $child->c_lockstatus,
                                    (int) $child->c_accnt_status
                                ),
                                'tier' => $this->mapTier((int) $child->c_rank),
                                'joinedAt' => $this->formatDateTime($child->c_date_started) ?: $this->formatDate($child->c_date_started),
                            ];
                        })->values()->all();
                    });
            }

            $members = collect($paginator->items())
                ->map(function (Customer $customer) use ($metricResolver, $referralCounts, $walletCreditsByCustomer, $sponsorsById, $referralChildrenBySponsor): array {
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

                    $walletCredits = $walletCreditsByCustomer->get((int) $customer->c_userid, ['cash' => 0, 'pv' => 0]);
                    $sponsor = $sponsorsById->get((int) ($customer->c_sponsor ?? 0));
                    $sponsorName = $sponsor instanceof Customer ? $this->displayName($sponsor) : '';
                    $addressParts = array_filter([
                        (string) ($customer->c_address ?? ''),
                        (string) ($customer->c_barangay ?? ''),
                        (string) ($customer->c_city ?? ''),
                        (string) ($customer->c_province ?? ''),
                        (string) ($customer->c_region ?? ''),
                        (string) ($customer->c_zipcode ?? ''),
                    ], fn ($value) => trim((string) $value) !== '');
                    $referralTotal = (int) ($referralCounts[(int) $customer->c_userid] ?? 0);
                    $registeredAt = $this->formatDateTime($customer->c_date_started);

                    return [
                        'id' => (int) $customer->c_userid,
                        'name' => $fullName,
                        'username' => (string) ($customer->c_username ?? ''),
                        'email' => (string) ($customer->c_email ?: ''),
                        'referredByName' => $sponsorName,
                        'referredByUsername' => $sponsor instanceof Customer ? (string) ($sponsor->c_username ?? '') : '',
                        'contactNumber' => (string) ($customer->c_mobile ?: ''),
                        'avatar' => (string) ($customer->c_avatar_url ?: ''),
                        'verificationStatus' => $verificationStatus,
                        'status' => $status,
                        'tier' => $this->mapTier((int) $customer->c_rank),
                        'orders' => (int) $customer->c_totalpair,
                        'totalSpent' => (float) $customer->c_gpv,
                        'earnings' => (float) $customer->c_totalincome,
                        'walletCashBalance' => (float) ($customer->c_totalincome ?? 0),
                        'walletPvBalance' => (float) ($customer->c_gpv ?? 0),
                        'walletCashCredits' => (float) ($walletCredits['cash'] ?? 0),
                        'walletPvCredits' => (float) ($walletCredits['pv'] ?? 0),
                        'referrals' => $referralTotal,
                        'joinedAt' => $this->formatDate($customer->c_date_started),
                        'createdAt' => $registeredAt,
                        'created_at' => $registeredAt,
                        'lastActiveAt' => $this->formatDate($customer->c_last_logindate) ?: $this->formatDate($customer->c_date_started),
                        'addressLine' => (string) ($customer->c_address ?? ''),
                        'barangay' => (string) ($customer->c_barangay ?? ''),
                        'city' => (string) ($customer->c_city ?? ''),
                        'province' => (string) ($customer->c_province ?? ''),
                        'region' => (string) ($customer->c_region ?? ''),
                        'zipCode' => (string) ($customer->c_zipcode ?? ''),
                        'fullAddress' => !empty($addressParts) ? implode(', ', $addressParts) : '',
                        'metricValue' => $metricResolver($customer, $referralTotal),
                        'referralChildren' => $referralChildrenBySponsor->get((int) $customer->c_userid, []),
                    ];
                })
                ->values();

            return [
                'stat' => $stat,
                'title' => $title,
                'metricLabel' => $metricLabel,
                'search' => $search,
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
        };

        try {
            $payload = Cache::remember($cacheKey, now()->addMinutes(2), $payloadBuilder);
        } catch (\Throwable $exception) {
            $payload = $payloadBuilder();
        }

        return response()->json($payload);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $customer = Customer::query()->where('c_userid', $id)->firstOrFail();

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'username' => [
                'required',
                'string',
                'max:255',
                Rule::unique('tbl_customer', 'c_username')->ignore($customer->c_userid, 'c_userid'),
            ],
            'email' => [
                'required',
                'email',
                'max:255',
                Rule::unique('tbl_customer', 'c_email')->ignore($customer->c_userid, 'c_userid'),
            ],
            'contactNumber' => ['nullable', 'string', 'max:25'],
            'status' => ['required', Rule::in(['active', 'pending', 'blocked', 'kyc_review'])],
            'tier' => ['required', Rule::in([
                'Home Starter',
                'Home Builder',
                'Home Stylist',
                'Lifestyle Consultant',
                'Lifestyle Elite',
            ])],
            'addressLine' => ['nullable', 'string', 'max:255'],
            'barangay' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:255'],
            'province' => ['nullable', 'string', 'max:255'],
            'region' => ['nullable', 'string', 'max:255'],
            'zipCode' => ['nullable', 'string', 'max:50'],
        ]);

        [$firstName, $middleName, $lastName] = $this->splitName((string) $validated['name']);
        [$accountStatus, $lockStatus] = $this->mapStoredStatus((string) $validated['status']);

        $customer->fill([
            'c_fname' => $firstName,
            'c_mname' => $middleName,
            'c_lname' => $lastName,
            'c_username' => trim((string) $validated['username']),
            'c_email' => trim((string) $validated['email']),
            'c_mobile' => trim((string) ($validated['contactNumber'] ?? '')),
            'c_rank' => $this->mapTierToRank((string) $validated['tier']),
            'c_accnt_status' => $accountStatus,
            'c_lockstatus' => $lockStatus,
            'c_address' => trim((string) ($validated['addressLine'] ?? '')),
            'c_barangay' => trim((string) ($validated['barangay'] ?? '')),
            'c_city' => trim((string) ($validated['city'] ?? '')),
            'c_province' => trim((string) ($validated['province'] ?? '')),
            'c_region' => trim((string) ($validated['region'] ?? '')),
            'c_zipcode' => trim((string) ($validated['zipCode'] ?? '')),
        ]);
        $customer->save();

        $this->bustMembersCache();

        return response()->json([
            'message' => 'Member updated successfully.',
        ]);
    }

    public function destroy(int $id): JsonResponse
    {
        $customer = Customer::query()->where('c_userid', $id)->first();

        if (! $customer) {
            return response()->json([
                'message' => 'Member not found.',
            ], 404);
        }

        $memberName = trim(implode(' ', array_filter([
            (string) ($customer->c_fname ?? ''),
            (string) ($customer->c_mname ?? ''),
            (string) ($customer->c_lname ?? ''),
        ])));

        if ($memberName === '') {
            $memberName = (string) ($customer->c_username ?: ('Member #' . $customer->c_userid));
        }

        // Count direct downlines before deletion. Their own downlines remain connected under them.
        $orphanedCount = Customer::query()->where('c_sponsor', $id)->count();

        // Broadcast to the customer's private channel before deleting
        broadcast(new CustomerAccountDeleted($id));

        // Revoke all active tokens — forces logout immediately
        $customer->tokens()->delete();

        try {
            Customer::query()
                ->where('c_sponsor', $id)
                ->update(['c_sponsor' => null]);

            $customer->delete();
        } catch (QueryException $e) {
            return response()->json([
                'message' => 'This member cannot be deleted yet because related records still exist.',
            ], 409);
        }

        $this->bustMembersCache();

        // Notify admin if there are orphaned members
        if ($orphanedCount > 0) {
            AdminNotification::query()->create([
                'an_type'        => 'orphaned_members',
                'an_severity'    => 'warning',
                'an_title'       => 'Members Need Sponsor Assignment',
                'an_message'     => "{$orphanedCount} member(s) lost their sponsor after deleting {$memberName}. Please assign new sponsors.",
                'an_href'        => '/admin/members?filter=no_sponsor',
                'an_payload'     => ['deleted_member' => $memberName, 'orphaned_count' => $orphanedCount],
                'an_source_type' => 'member_deletion',
                'an_source_id'   => $id,
                'an_created_at'  => now(),
            ]);
        }

        return response()->json([
            'message' => "{$memberName} deleted successfully."
                . ($orphanedCount > 0 ? " {$orphanedCount} member(s) need a new sponsor assignment." : ''),
        ]);
    }

    public function orphanedMembers(): JsonResponse
    {
        $members = Customer::query()
            ->whereNull('c_sponsor')
            ->orderBy('c_userid')
            ->get(['c_userid', 'c_fname', 'c_lname', 'c_username', 'c_email', 'c_date_started']);

        $data = $members->map(fn (Customer $c) => [
            'id'         => (int) $c->c_userid,
            'name'       => trim("{$c->c_fname} {$c->c_lname}"),
            'username'   => (string) ($c->c_username ?? ''),
            'email'      => (string) ($c->c_email ?? ''),
            'joined_at'  => $c->c_date_started,
        ]);

        return response()->json([
            'data'  => $data,
            'total' => $data->count(),
        ]);
    }

    public function assignSponsor(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'sponsor_username' => ['required', 'string'],
        ]);

        $customer = Customer::query()->where('c_userid', $id)->first();
        if (!$customer) {
            return response()->json(['message' => 'Member not found.'], 404);
        }

        $sponsor = Customer::query()
            ->whereRaw('LOWER(c_username) = ?', [strtolower(trim($validated['sponsor_username']))])
            ->first();

        if (!$sponsor) {
            return response()->json(['message' => 'Sponsor username not found.'], 404);
        }

        if ((int) $sponsor->c_userid === (int) $customer->c_userid) {
            return response()->json(['message' => 'A member cannot be their own sponsor.'], 422);
        }

        $pendingDownlineIds = Customer::query()
            ->where('c_sponsor', (int) $customer->c_userid)
            ->pluck('c_userid')
            ->map(fn ($userId) => (int) $userId)
            ->all();
        $visitedDownlineIds = [];

        while (!empty($pendingDownlineIds)) {
            $downlineId = (int) array_shift($pendingDownlineIds);

            if ($downlineId <= 0 || isset($visitedDownlineIds[$downlineId])) {
                continue;
            }

            if ($downlineId === (int) $sponsor->c_userid) {
                return response()->json([
                    'message' => 'The selected sponsor is in this member\'s downline. Choose another sponsor.',
                ], 422);
            }

            $visitedDownlineIds[$downlineId] = true;

            $childIds = Customer::query()
                ->where('c_sponsor', $downlineId)
                ->pluck('c_userid')
                ->map(fn ($userId) => (int) $userId)
                ->all();

            $pendingDownlineIds = array_merge($pendingDownlineIds, $childIds);
        }

        $customer->c_sponsor = (int) $sponsor->c_userid;
        $customer->save();

        $this->bustMembersCache();

        return response()->json([
            'message' => "Sponsor assigned successfully. {$customer->c_username} is now under {$sponsor->c_username}.",
        ]);
    }

    public function generateTemporaryPassword(int $id): JsonResponse
    {
        $customer = Customer::query()->where('c_userid', $id)->first();

        if (! $customer) {
            return response()->json([
                'message' => 'Member not found.',
            ], 404);
        }

        $temporaryPassword = $this->makeTemporaryPassword();

        $customer->c_password = Hash::make($temporaryPassword);
        $customer->c_password_pin = '';
        $customer->c_password_change_required = true;
        $customer->c_lockstatus = (int) ($customer->c_lockstatus ?? 0);
        $customer->save();

        $this->bustMembersCache();

        return response()->json([
            'message' => 'Temporary password generated successfully.',
            'temporary_password' => $temporaryPassword,
            'username' => (string) ($customer->c_username ?? ''),
            'member_name' => $this->displayName($customer),
            'password_change_required' => true,
        ]);
    }

    public function referralTree(): JsonResponse
    {
        $payloadBuilder = function () {
            $members = Customer::query()
                ->select([
                    'c_userid',
                    'c_sponsor',
                    'c_username',
                    'c_fname',
                    'c_mname',
                    'c_lname',
                    'c_email',
                    'c_avatar_url',
                    'c_rank',
                    'c_totalincome',
                    'c_date_started',
                    'c_accnt_status',
                    'c_lockstatus',
                ])
                ->orderBy('c_userid')
                ->get();

            $membersById = $members->keyBy('c_userid');
            $childrenBySponsor = $members
                ->filter(fn (Customer $customer) => (int) ($customer->c_sponsor ?? 0) > 0)
                ->groupBy('c_sponsor');

            $visitedIds = collect();

            $buildNode = function (Customer $customer, array $path = []) use (&$buildNode, $childrenBySponsor, $visitedIds): array {
                $customerId = (int) $customer->c_userid;
                $visitedIds->put($customerId, true);

                $nextPath = [...$path, $customerId];
                $children = collect($childrenBySponsor->get((int) $customer->c_userid, []))
                    ->reject(fn (Customer $child) => in_array((int) $child->c_userid, $nextPath, true))
                    ->map(fn (Customer $child) => $buildNode($child, $nextPath))
                    ->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)
                    ->values()
                    ->all();

                $fullName = trim(implode(' ', array_filter([
                    (string) $customer->c_fname,
                    (string) $customer->c_mname,
                    (string) $customer->c_lname,
                ])));

                if ($fullName === '') {
                    $fullName = (string) ($customer->c_username ?: ('Member #' . $customer->c_userid));
                }

                $status = $this->mapStatus(
                    (int) ($customer->c_lockstatus ?? 0),
                    (int) ($customer->c_accnt_status ?? 0)
                );

                return [
                    'id' => (int) $customer->c_userid,
                    'name' => $fullName,
                    'username' => (string) ($customer->c_username ?? ''),
                    'email' => (string) ($customer->c_email ?? ''),
                    'avatar' => (string) ($customer->c_avatar_url ?? ''),
                    'tier' => $this->mapTier((int) ($customer->c_rank ?? 0)),
                    'commissionEarned' => (float) ($customer->c_totalincome ?? 0),
                    'referralCount' => count($children),
                    'joinedAt' => $this->formatDate($customer->c_date_started),
                    'status' => $status,
                    'children' => $children,
                ];
            };

            $rootMembers = $members
                ->filter(function (Customer $customer) use ($membersById) {
                    $sponsorId = (int) ($customer->c_sponsor ?? 0);
                    return $sponsorId <= 0 || ! $membersById->has($sponsorId);
                })
                ->sortBy(function (Customer $customer) {
                    $fullName = trim(implode(' ', array_filter([
                        (string) $customer->c_fname,
                        (string) $customer->c_mname,
                        (string) $customer->c_lname,
                    ])));

                    return $fullName !== '' ? $fullName : (string) ($customer->c_username ?? '');
                }, SORT_NATURAL | SORT_FLAG_CASE)
                ->values();

            $roots = $rootMembers
                ->map(fn (Customer $customer) => $buildNode($customer))
                ->values();

            $remainingMembers = $members
                ->filter(fn (Customer $customer) => ! $visitedIds->has((int) $customer->c_userid))
                ->sortBy(function (Customer $customer) {
                    $fullName = trim(implode(' ', array_filter([
                        (string) $customer->c_fname,
                        (string) $customer->c_mname,
                        (string) $customer->c_lname,
                    ])));

                    return $fullName !== '' ? $fullName : (string) ($customer->c_username ?? '');
                }, SORT_NATURAL | SORT_FLAG_CASE)
                ->values()
                ->map(fn (Customer $customer) => $buildNode($customer))
                ->values();

            $roots = $roots
                ->concat($remainingMembers)
                ->values()
                ->all();

            return [
                'summary' => [
                    'totalMembers' => $members->count(),
                    'activeMembers' => $members->filter(fn (Customer $customer) => $this->mapStatus((int) ($customer->c_lockstatus ?? 0), (int) ($customer->c_accnt_status ?? 0)) === 'active')->count(),
                    'pendingMembers' => $members->filter(fn (Customer $customer) => $this->mapStatus((int) ($customer->c_lockstatus ?? 0), (int) ($customer->c_accnt_status ?? 0)) === 'pending')->count(),
                    'blockedMembers' => $members->filter(fn (Customer $customer) => $this->mapStatus((int) ($customer->c_lockstatus ?? 0), (int) ($customer->c_accnt_status ?? 0)) === 'blocked')->count(),
                    'totalReferrals' => $members->filter(fn (Customer $customer) => (int) ($customer->c_sponsor ?? 0) > 0)->count(),
                    'totalCommissionPaid' => (float) $members->sum(fn (Customer $customer) => (float) ($customer->c_totalincome ?? 0)),
                    'avgCommissionPerMember' => $members->count() > 0
                        ? (float) ($members->sum(fn (Customer $customer) => (float) ($customer->c_totalincome ?? 0)) / $members->count())
                        : 0,
                ],
                'roots' => $roots,
            ];
        };

        try {
            $payload = Cache::remember(
                'admin:members:referral-tree:' . $this->membersCacheVersion(),
                now()->addMinutes(2),
                $payloadBuilder
            );
        } catch (\Throwable $exception) {
            $payload = $payloadBuilder();
        }

        return response()->json($payload);
    }

    private function buildStatsPayload(string $period = '7d'): array
    {
        $normalizedPeriod = $this->normalizeMemberStatsPeriod($period);
        $summary = DB::table('tbl_customer')
            ->selectRaw("
                COUNT(*)::bigint AS total,
                COUNT(*) FILTER (WHERE c_lockstatus = 0 AND c_accnt_status = 1)::bigint AS active,
                COUNT(*) FILTER (WHERE c_lockstatus = 0 AND c_accnt_status IN (0, 2))::bigint AS pending,
                COUNT(*) FILTER (WHERE c_lockstatus = 1)::bigint AS blocked,
                COUNT(*) FILTER (
                    WHERE c_date_started IS NOT NULL
                    AND {$this->memberStatsPeriodSql($normalizedPeriod, 'c_date_started')}
                )::bigint AS new_members,
                COALESCE(SUM(c_gpv), 0)::numeric AS total_spent,
                COALESCE(SUM(c_totalincome), 0)::numeric AS total_earnings,
                COUNT(*) FILTER (WHERE c_sponsor IS NOT NULL AND c_sponsor <> 0)::bigint AS total_referrals
            ")
            ->first();

        return [
            'total' => (int) ($summary->total ?? 0),
            'active' => (int) ($summary->active ?? 0),
            'pending' => (int) ($summary->pending ?? 0),
            'blocked' => (int) ($summary->blocked ?? 0),
            'newMembers' => (int) ($summary->new_members ?? 0),
            'newMembersPeriod' => $normalizedPeriod,
            'newMembersLabel' => $this->memberStatsPeriodLabel($normalizedPeriod),
            'totalSpent' => (float) ($summary->total_spent ?? 0),
            'totalEarnings' => (float) ($summary->total_earnings ?? 0),
            'totalReferrals' => (int) ($summary->total_referrals ?? 0),
        ];
    }

    private function normalizeMemberStatsPeriod(string $period): string
    {
        return match (strtolower(trim($period))) {
            '30d' => '30d',
            'last_month' => 'last_month',
            '3m' => '3m',
            default => '7d',
        };
    }

    private function memberStatsPeriodLabel(string $period): string
    {
        return match ($this->normalizeMemberStatsPeriod($period)) {
            '30d' => 'This 30 Days',
            'last_month' => 'Last Month',
            '3m' => 'Past 3 Months',
            default => 'This 7 Days',
        };
    }

    private function memberStatsPeriodSql(string $period, string $column = 'tbl_customer.c_date_started'): string
    {
        $columnRef = trim($column) !== '' ? $column : 'tbl_customer.c_date_started';

        return match ($this->normalizeMemberStatsPeriod($period)) {
            '30d' => "{$columnRef} >= (CURRENT_DATE - INTERVAL '29 days')",
            'last_month' => "{$columnRef} >= date_trunc('month', CURRENT_DATE - INTERVAL '1 month') AND {$columnRef} < date_trunc('month', CURRENT_DATE)",
            '3m' => "{$columnRef} >= (CURRENT_DATE - INTERVAL '3 months')",
            default => "{$columnRef} >= (CURRENT_DATE - INTERVAL '6 days')",
        };
    }

    private function makeTemporaryPassword(): string
    {
        return 'Afh#' . random_int(1000, 9999) . Str::upper(Str::random(4));
    }

    private function applyMemberSearch($query, string $search)
    {
        $like = '%' . trim($search) . '%';

        return $query->where(function ($inner) use ($like) {
            $inner->where('tbl_customer.c_username', 'ilike', $like)
                ->orWhere('tbl_customer.c_email', 'ilike', $like)
                ->orWhere('tbl_customer.c_fname', 'ilike', $like)
                ->orWhere('tbl_customer.c_mname', 'ilike', $like)
                ->orWhere('tbl_customer.c_lname', 'ilike', $like)
                ->orWhere('tbl_customer.c_mobile', 'ilike', $like)
                ->orWhere('tbl_customer.c_address', 'ilike', $like)
                ->orWhere('tbl_customer.c_barangay', 'ilike', $like)
                ->orWhere('tbl_customer.c_city', 'ilike', $like)
                ->orWhere('tbl_customer.c_province', 'ilike', $like)
                ->orWhere('tbl_customer.c_region', 'ilike', $like)
                ->orWhere('tbl_customer.c_zipcode', 'ilike', $like)
                ->orWhereRaw(
                    "TRIM(CONCAT_WS(' ', NULLIF(TRIM(tbl_customer.c_fname), ''), NULLIF(TRIM(tbl_customer.c_mname), ''), NULLIF(TRIM(tbl_customer.c_lname), ''))) ILIKE ?",
                    [$like]
                )
                ->orWhereRaw(
                    "TRIM(CONCAT_WS(', ', NULLIF(TRIM(tbl_customer.c_address), ''), NULLIF(TRIM(tbl_customer.c_barangay), ''), NULLIF(TRIM(tbl_customer.c_city), ''), NULLIF(TRIM(tbl_customer.c_province), ''), NULLIF(TRIM(tbl_customer.c_region), ''), NULLIF(TRIM(tbl_customer.c_zipcode), ''))) ILIKE ?",
                    [$like]
                );
        });
    }

    private function displayName(Customer $customer): string
    {
        $fullName = trim(implode(' ', array_filter([
            (string) ($customer->c_fname ?? ''),
            (string) ($customer->c_mname ?? ''),
            (string) ($customer->c_lname ?? ''),
        ])));

        return $fullName !== '' ? $fullName : (string) ($customer->c_username ?: ('Member #' . $customer->c_userid));
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

    private function mapTierToRank(string $tier): int
    {
        return match ($tier) {
            'Lifestyle Elite' => 5,
            'Lifestyle Consultant' => 4,
            'Home Stylist' => 3,
            'Home Builder' => 2,
            default => 1,
        };
    }

    private function mapStoredStatus(string $status): array
    {
        return match ($status) {
            'blocked' => [0, 1],
            'kyc_review' => [2, 0],
            'pending' => [0, 0],
            default => [1, 0],
        };
    }

    private function splitName(string $fullName): array
    {
        $parts = preg_split('/\s+/', trim($fullName)) ?: [];
        $parts = array_values(array_filter($parts, fn ($part) => trim((string) $part) !== ''));

        if (count($parts) <= 1) {
            return [$parts[0] ?? $fullName, '', ''];
        }

        if (count($parts) === 2) {
            return [$parts[0], '', $parts[1]];
        }

        $firstName = array_shift($parts) ?? '';
        $lastName = array_pop($parts) ?? '';
        $middleName = implode(' ', $parts);

        return [$firstName, $middleName, $lastName];
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

    private function formatDateTime(?string $value): ?string
    {
        if (! $value) {
            return null;
        }

        try {
            return Carbon::parse($value)->toDateTimeString();
        } catch (\Throwable $exception) {
            return null;
        }
    }

    public function pusherAuth(Request $request): JsonResponse
    {
        $customer = $request->user();
        if (!$customer) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $validated = $request->validate([
            'socket_id'    => 'required|string|max:100',
            'channel_name' => 'required|string|max:255',
        ]);

        $channelName = (string) $validated['channel_name'];
        $expectedChannel = 'private-customer.' . $customer->c_userid;

        if ($channelName !== $expectedChannel) {
            return response()->json(['message' => 'Forbidden channel.'], 403);
        }

        $key    = (string) config('services.pusher.key', '');
        $secret = (string) config('services.pusher.secret', '');

        if ($key === '' || $secret === '') {
            return response()->json(['message' => 'Pusher is not configured.'], 503);
        }

        $socketId  = (string) $validated['socket_id'];
        $signature = hash_hmac('sha256', $socketId . ':' . $channelName, $secret);

        return response()->json(['auth' => $key . ':' . $signature]);
    }

    private function membersCacheVersion(): int
    {
        try {
            return (int) Cache::get(self::MEMBERS_CACHE_VERSION_KEY, 1);
        } catch (\Throwable $exception) {
            return 1;
        }
    }

    private function bustMembersCache(): void
    {
        try {
            Cache::forever(
                self::MEMBERS_CACHE_VERSION_KEY,
                $this->membersCacheVersion() + 1
            );
        } catch (\Throwable $exception) {
            // Ignore cache bust failures in local/dev when Redis is unavailable.
        }
    }
}
