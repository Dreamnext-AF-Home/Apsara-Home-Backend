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
use Illuminate\Database\QueryException;
use Illuminate\Validation\Rule;

class MemberController extends Controller
{
    private const MEMBERS_CACHE_VERSION_KEY = 'admin:members:cache-version';

    public function index(Request $request): JsonResponse
    {
        $perPage = (int) $request->integer('per_page', 25);
        $perPage = max(1, min($perPage, 100));
        $search = trim((string) $request->query('q', ''));
        $status = trim((string) $request->query('status', ''));
        $tier = trim((string) $request->query('tier', ''));
        $sort = trim((string) $request->query('sort', 'default'));
        $cacheVersion = $this->membersCacheVersion();

        $cacheKey = 'admin:members:index:' . md5(json_encode([
            'v' => $cacheVersion,
            'page' => (int) $request->integer('page', 1),
            'per_page' => $perPage,
            'q' => $search,
            'status' => $status,
            'tier' => $tier,
            'sort' => $sort,
        ]));

        $payload = Cache::remember($cacheKey, now()->addMinutes(2), function () use ($perPage, $search, $status, $tier, $sort) {
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
                    'tbl_customer.c_date_started',
                    'tbl_customer.c_last_logindate',
                ])
                ->when($search !== '', function ($query) use ($search) {
                    $like = '%' . $search . '%';

                    $query->where(function ($inner) use ($like) {
                        $inner->where('tbl_customer.c_username', 'ilike', $like)
                            ->orWhere('tbl_customer.c_email', 'ilike', $like)
                            ->orWhere('tbl_customer.c_fname', 'ilike', $like)
                            ->orWhere('tbl_customer.c_mname', 'ilike', $like)
                            ->orWhere('tbl_customer.c_lname', 'ilike', $like)
                            ->orWhereRaw(
                                "TRIM(COALESCE(tbl_customer.c_fname, '') || ' ' || COALESCE(tbl_customer.c_mname, '') || ' ' || COALESCE(tbl_customer.c_lname, '')) ILIKE ?",
                                [$like]
                            );
                    });
                })
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
                            'tbl_customer.c_date_started',
                            'tbl_customer.c_last_logindate',
                        )
                        ->selectRaw('COUNT(referrals.c_userid) as referral_sort_total')
                        ->orderByDesc('referral_sort_total')
                        ->orderByDesc('tbl_customer.c_userid');
                }, function ($query) {
                    $query->orderByDesc('tbl_customer.c_userid');
                })
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
                        'username' => (string) ($customer->c_username ?? ''),
                        'email' => (string) ($customer->c_email ?: ''),
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
        $payload = Cache::remember('admin:members:stats:' . $this->membersCacheVersion(), now()->addMinutes(2), function () {
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

        try {
            $customer->delete();
        } catch (QueryException $e) {
            return response()->json([
                'message' => 'This member cannot be deleted yet because related records still exist.',
            ], 409);
        }

        $this->bustMembersCache();

        return response()->json([
            'message' => "{$memberName} deleted successfully.",
        ]);
    }

    public function referralTree(): JsonResponse
    {
        $payload = Cache::remember('admin:members:referral-tree:' . $this->membersCacheVersion(), now()->addMinutes(2), function () {
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

    private function membersCacheVersion(): int
    {
        return (int) Cache::get(self::MEMBERS_CACHE_VERSION_KEY, 1);
    }

    private function bustMembersCache(): void
    {
        Cache::forever(
            self::MEMBERS_CACHE_VERSION_KEY,
            $this->membersCacheVersion() + 1
        );
    }
}
