<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AdminNotification;
use App\Models\Customer;
use App\Models\CustomerAddress;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Str;
use App\Mail\Auth\RegistrationOtpMail;
use App\Mail\Auth\CustomerPasswordResetMail;
use App\Mail\Auth\UsernameChangeOtpMail;
use App\Mail\Auth\ReferralRegistrationAlertMail;

class AuthController extends Controller
{
    private const PASSWORD_RESET_TTL_MINUTES = 60;

    public function register(Request $request)
    {
        $request->merge([
            'referred_by' => $this->normalizeReferralValue((string) $request->input('referred_by', '')),
        ]);

        $validated = $request->validate([
            'first_name'            => 'required|string|max:255',
            'last_name'             => 'required|string|max:255',
            'middle_name'           => 'nullable|string|max:255',
            'name'                  => 'required|string|max:255',
            'email'                 => ['required', 'email', Rule::unique('tbl_customer', 'c_email')],
            'username'              => ['required', 'string', 'max:255', Rule::unique('tbl_customer', 'c_username')],
            'phone'                 => 'nullable|string|max:20',
            'birth_date'            => 'nullable|date',
            'gender'                => 'nullable|in:male,female,other',
            'occupation'            => 'nullable|string|max:155',
            'work_location'         => 'nullable|in:local,overseas',
            'country'               => 'nullable|string|max:45',
            'referred_by'           => 'required|string|max:255',
            'password'              => [
                'required',
                'string',
                'min:8',
                'confirmed',
                'regex:/[A-Z]/',
                'regex:/[a-z]/',
                'regex:/[0-9]/',
                'regex:/[^A-Za-z0-9]/',
            ],
            'address'               => 'nullable|string|max:500',
            'barangay'              => 'nullable|string|max:255',
            'city'                  => 'nullable|string|max:255',
            'province'              => 'nullable|string|max:255',
            'region'                => 'nullable|string|max:255',
            'zip_code'              => 'nullable|string|max:20',
        ], [
            'password.min' => 'Password must be at least 8 characters.',
            'password.confirmed' => 'Password confirmation does not match.',
            'password.regex' => 'Password must include uppercase, lowercase, number, and special character.',
        ]);

        $this->validateNoBadWords([
            'first_name' => $validated['first_name'] ?? null,
            'last_name' => $validated['last_name'] ?? null,
            'middle_name' => $validated['middle_name'] ?? null,
            'name' => $validated['name'] ?? null,
            'username' => $validated['username'] ?? null,
        ]);

        $referrer = Customer::query()
            ->select(['c_userid', 'c_username', 'c_accnt_status', 'c_lockstatus'])
            ->whereRaw('LOWER(c_username) = ?', [strtolower((string) $validated['referred_by'])])
            ->where('c_accnt_status', 1)
            ->where('c_lockstatus', 0)
            ->first();

        if (! $referrer) {
            throw ValidationException::withMessages([
                'referred_by' => ['Referral code is invalid or referrer account is not verified.'],
            ]);
        }

        $verificationToken = (string) Str::uuid();
        $otp = (string) random_int(1000, 9999);

        Cache::put($this->registrationOtpCacheKey($verificationToken), [
            'otp_hash' => Hash::make($otp),
            'payload' => Crypt::encryptString(json_encode([
                'validated' => $validated,
                'referrer_user_id' => (int) $referrer->c_userid,
            ], JSON_THROW_ON_ERROR)),
            'email' => (string) $validated['email'],
        ], now()->addMinutes(10));

        $this->sendRegistrationOtpEmail((string) $validated['email'], $otp);

        return response()->json([
            'message' => 'A 4-digit verification code has been sent to your email.',
            'requires_otp' => true,
            'verification_token' => $verificationToken,
            'email' => (string) $validated['email'],
        ]);
    }

    public function verifyRegistrationOtp(Request $request)
    {
        $validated = $request->validate([
            'verification_token' => 'required|string',
            'otp' => 'required|string|size:4',
        ]);

        $cached = Cache::get($this->registrationOtpCacheKey($validated['verification_token']));

        if (!is_array($cached) || empty($cached['otp_hash']) || empty($cached['payload'])) {
            throw ValidationException::withMessages([
                'otp' => ['The verification code has expired. Please register again.'],
            ]);
        }

        if (!Hash::check((string) $validated['otp'], (string) $cached['otp_hash'])) {
            throw ValidationException::withMessages([
                'otp' => ['Invalid verification code.'],
            ]);
        }

        $payload = json_decode(Crypt::decryptString((string) $cached['payload']), true, 512, JSON_THROW_ON_ERROR);
        $registration = $payload['validated'] ?? [];
        $referrerUserId = (int) ($payload['referrer_user_id'] ?? 0);

        if (empty($registration['email']) || empty($registration['username'])) {
            throw ValidationException::withMessages([
                'otp' => ['The verification payload is invalid. Please register again.'],
            ]);
        }

        if (Customer::query()->where('c_email', (string) $registration['email'])->exists()) {
            throw ValidationException::withMessages([
                'email' => ['This email is already registered.'],
            ]);
        }

        if (Customer::query()->where('c_username', (string) $registration['username'])->exists()) {
            throw ValidationException::withMessages([
                'username' => ['This username is already taken.'],
            ]);
        }

        $customer = Customer::create([
            'c_fname'        => $registration['first_name'],
            'c_lname'        => $registration['last_name'],
            'c_mname'        => $registration['middle_name'] ?? null,
            'c_username'     => $registration['username'],
            'c_email'        => $registration['email'],
            'c_mobile'       => $registration['phone'] ?? '0',
            'c_bdate'        => $registration['birth_date'] ?? null,
            'c_gender'       => $this->mapGenderToInt($registration['gender'] ?? null),
            'c_occupation'   => $registration['occupation'] ?? 'None',
            'c_country'      => $registration['country'] ?? (($registration['work_location'] ?? 'local') === 'overseas' ? 'Overseas' : 'Philippines'),
            'c_password'     => Hash::make($registration['password']),
            'c_password_pin' => '',
            'c_rank'         => 0,
            'c_sponsor'      => $referrerUserId,
            'c_date_started' => now(),
            'c_address'      => $registration['address'] ?? null,
            'c_barangay'     => $registration['barangay'] ?? null,
            'c_city'         => $registration['city'] ?? null,
            'c_province'     => $registration['province'] ?? null,
            'c_region'       => $registration['region'] ?? null,
            'c_zipcode'      => $registration['zip_code'] ?? null,
        ]);

        $this->createPrimaryAddressRecord($customer);
        $referrer = Customer::query()->where('c_userid', $referrerUserId)->first();
        if ($referrer instanceof Customer) {
            $this->notifyReferrerAboutRegistration($referrer, $customer);
        }

        Cache::forget($this->registrationOtpCacheKey($validated['verification_token']));

        return response()->json([
            'message' => 'Registration complete. You can now sign in.',
            'user' => $this->transformCustomer($customer),
        ], 201);
    }

    public function resendRegistrationOtp(Request $request)
    {
        $validated = $request->validate([
            'verification_token' => 'required|string',
        ]);

        $cached = Cache::get($this->registrationOtpCacheKey($validated['verification_token']));

        if (!is_array($cached) || empty($cached['payload']) || empty($cached['email'])) {
            throw ValidationException::withMessages([
                'verification_token' => ['The verification session has expired. Please register again.'],
            ]);
        }

        $otp = (string) random_int(1000, 9999);

        Cache::put($this->registrationOtpCacheKey($validated['verification_token']), [
            'otp_hash' => Hash::make($otp),
            'payload' => $cached['payload'],
            'email' => (string) $cached['email'],
        ], now()->addMinutes(10));

        $this->sendRegistrationOtpEmail((string) $cached['email'], $otp);

        return response()->json([
            'message' => 'A new verification code has been sent.',
        ]);
    }

    public function login(Request $request)
    {
        $request->validate([
            'email'    => 'required|string',
            'password' => 'required|string',
        ]);

        $identifier = trim($request->email);
        $customer = Customer::query()
            ->where(function ($query) use ($identifier) {
                $query
                    ->whereRaw('LOWER(c_email) = ?', [mb_strtolower($identifier, 'UTF-8')])
                    ->orWhereRaw('LOWER(c_username) = ?', [mb_strtolower($identifier, 'UTF-8')]);
            })
            ->first();

        if (! $customer) {
            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        $password = (string) $request->password;
        $hashMatch = $this->matchesHashedCustomerPassword($customer, $password);
        $legacyDirectMatch = $this->matchesLegacyCustomerPassword($customer, $password, false);
        $legacyCaseInsensitiveMatch = $this->matchesLegacyCustomerPassword($customer, $password, true);
        if (! $hashMatch && ! $legacyDirectMatch && ! $legacyCaseInsensitiveMatch) {
            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        if ((int) ($customer->c_lockstatus ?? 0) === 1) {
            return response()->json([
                'message' => 'Your account has been banned. Please contact support for assistance.',
                'reason' => 'banned',
            ], 403);
        }

        $mustChangePassword = $this->customerRequiresPasswordChange($customer)
            || $legacyDirectMatch
            || $legacyCaseInsensitiveMatch
            || ! $this->passwordMeetsModernRequirements($password);

        // Once the member is successfully using the modern hashed password,
        // legacy plain-password storage should be cleared automatically.
        if (
            $hashMatch
            && ! $legacyDirectMatch
            && ! $legacyCaseInsensitiveMatch
            && trim((string) ($customer->c_password_pin ?? '')) !== ''
        ) {
            $customer->c_password_pin = '';
        }

        if ($mustChangePassword && ! $this->customerRequiresPasswordChange($customer)) {
            $customer->c_password_change_required = true;
        }

        if ($customer->isDirty(['c_password_pin', 'c_password_change_required'])) {
            $customer->save();
        }

        $token = $customer->createToken('auth_token')->plainTextToken;

        return response()->json([
            'user'  => $this->transformCustomer($customer),
            'token' => $token,
            'message' => $mustChangePassword
                ? 'Your account was signed in using a legacy password. Please change your password before continuing to the shop.'
                : null,
        ]);
    }

    public function forgotPassword(Request $request)
    {
        $validated = $request->validate([
            'email' => 'required|email',
        ]);

        $customer = Customer::query()
            ->where('c_email', trim((string) $validated['email']))
            ->first();

        if ($customer) {
            $token = Str::random(64);
            $expiresAt = now()->addMinutes(self::PASSWORD_RESET_TTL_MINUTES);
            $payload = [
                'customer_id' => (int) $customer->c_userid,
                'email' => (string) $customer->c_email,
                'name' => $this->fullName($customer),
                'expires_at' => $expiresAt->toIso8601String(),
            ];

            Cache::put($this->passwordResetCacheKey($token), $payload, $expiresAt);

            $resetUrl = sprintf(
                '%s/reset-password?token=%s',
                rtrim((string) env('FRONTEND_URL', config('app.url')), '/'),
                urlencode($token)
            );

            Mail::mailer('resend')->to($payload['email'])->send(new CustomerPasswordResetMail(
                name: $payload['name'],
                email: $payload['email'],
                resetUrl: $resetUrl,
                expiresAt: $expiresAt->toDayDateTimeString(),
            ));
        }

        return response()->json([
            'message' => 'If that email exists in our records, a reset link has been sent.',
        ]);
    }

    public function showResetToken(string $token)
    {
        $payload = Cache::get($this->passwordResetCacheKey($token));
        if (!is_array($payload)) {
            return response()->json(['message' => 'Reset link is invalid or expired.'], 404);
        }

        return response()->json([
            'reset' => [
                'email' => (string) $payload['email'],
                'name' => (string) $payload['name'],
                'expires_at' => (string) $payload['expires_at'],
            ],
        ]);
    }

    public function resetPassword(Request $request)
    {
        $validated = $request->validate([
            'token' => 'required|string',
            'password' => [
                'required',
                'string',
                'min:8',
                'confirmed',
                'regex:/[A-Z]/',
                'regex:/[a-z]/',
                'regex:/[0-9]/',
                'regex:/[^A-Za-z0-9]/',
            ],
        ], [
            'password.min' => 'Password must be at least 8 characters.',
            'password.confirmed' => 'Password confirmation does not match.',
            'password.regex' => 'Password must include uppercase, lowercase, number, and special character.',
        ]);

        $payload = Cache::get($this->passwordResetCacheKey((string) $validated['token']));
        if (!is_array($payload)) {
            throw ValidationException::withMessages([
                'token' => ['Reset link is invalid or expired.'],
            ]);
        }

        $customer = Customer::query()->where('c_userid', (int) $payload['customer_id'])->first();
        if (! $customer) {
            Cache::forget($this->passwordResetCacheKey((string) $validated['token']));

            throw ValidationException::withMessages([
                'token' => ['Customer account could not be found.'],
            ]);
        }

        $plainPassword = (string) $validated['password'];
        $customer->c_password = Hash::make($plainPassword);
        $customer->c_password_pin = '';
        $customer->save();

        Cache::forget($this->passwordResetCacheKey((string) $validated['token']));

        return response()->json([
            'message' => 'Your password has been reset. You may now sign in.',
        ]);
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Logged out successfully.']);
    }

    public function me(Request $request)
    {
        $customer = $request->user();

        if ((int) ($customer->c_lockstatus ?? 0) === 1) {
            optional($customer->currentAccessToken())->delete();

            return response()->json([
                'message' => 'Your account has been banned. Please contact support for assistance.',
                'reason' => 'banned',
            ], 401);
        }

        return response()->json($this->transformCustomer($customer));
    }

    public function referralTree(Request $request)
    {
        /** @var Customer $customer */
        $customer = $request->user();

        $descendants = Customer::query()
            ->select([
                'c_userid',
                'c_username',
                'c_fname',
                'c_mname',
                'c_lname',
                'c_email',
                'c_accnt_status',
                'c_lockstatus',
                'c_totalincome',
                'c_date_started',
                'c_sponsor',
            ])
            ->orderBy('c_userid')
            ->get();

        $descendantsBySponsor = $descendants
            ->filter(fn (Customer $member) => (int) ($member->c_sponsor ?? 0) > 0)
            ->groupBy('c_sponsor');

        $buildNode = function (Customer $member, array $path = []) use (&$buildNode, $descendantsBySponsor): array {
            $memberId = (int) $member->c_userid;
            $nextPath = [...$path, $memberId];

            $children = collect($descendantsBySponsor->get($memberId, []))
                ->reject(fn (Customer $child) => in_array((int) $child->c_userid, $nextPath, true))
                ->map(fn (Customer $child): array => $buildNode($child, $nextPath))
                ->values();

            $node = $this->transformReferralNode($member);
            $node['children_count'] = $children->count();
            $node['children'] = $children->all();

            return $node;
        };

        $levelOneMembers = $descendants
            ->where('c_sponsor', (int) $customer->c_userid)
            ->orderByDesc('c_userid')
            ->values();

        $levelOneIds = $levelOneMembers->pluck('c_userid')->all();

        $levelTwoMembers = empty($levelOneIds)
            ? collect()
            : $descendants
                ->whereIn('c_sponsor', $levelOneIds)
                ->values();

        $secondLevelCount = $levelTwoMembers->count();
        $directCount = $levelOneMembers->count();

        $children = $levelOneMembers
            ->map(fn (Customer $member): array => $buildNode($member))
            ->values();

        return response()->json([
            'root' => $this->transformReferralNode($customer),
            'summary' => [
                'direct_count' => $directCount,
                'second_level_count' => $secondLevelCount,
                'total_network' => $descendants->count(),
            ],
            'children' => $children,
        ]);
    }

    public function updateMe(Request $request)
    {
        /** @var Customer $customer */
        $customer = $request->user();

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'username' => [
                'nullable',
                'string',
                'max:255',
                Rule::unique('tbl_customer', 'c_username')->ignore($customer->c_userid, 'c_userid'),
            ],
            'phone' => 'nullable|string|max:25',
            'address' => 'nullable|string|max:500',
            'barangay' => 'nullable|string|max:255',
            'city' => 'nullable|string|max:255',
            'province' => 'nullable|string|max:255',
            'region' => 'nullable|string|max:255',
            'zip_code' => 'nullable|string|max:20',
            'avatar_url' => 'nullable|url|max:1200',
        ]);

        [$firstName, $middleName, $lastName] = $this->splitName((string) $validated['name']);

        $customer->c_fname = $firstName;
        $customer->c_mname = $middleName;
        $customer->c_lname = $lastName;

        if (array_key_exists('username', $validated) && $validated['username'] !== null) {
            $customer->c_username = $validated['username'];
        }

        if (array_key_exists('phone', $validated) && $validated['phone'] !== null) {
            $customer->c_mobile = $validated['phone'];
        }

        if (array_key_exists('address', $validated)) {
            $customer->c_address = $validated['address'] ?: null;
        }

        if (array_key_exists('barangay', $validated)) {
            $customer->c_barangay = $validated['barangay'] ?: null;
        }

        if (array_key_exists('city', $validated)) {
            $customer->c_city = $validated['city'] ?: null;
        }

        if (array_key_exists('province', $validated)) {
            $customer->c_province = $validated['province'] ?: null;
        }

        if (array_key_exists('region', $validated)) {
            $customer->c_region = $validated['region'] ?: null;
        }

        if (array_key_exists('zip_code', $validated)) {
            $customer->c_zipcode = $validated['zip_code'] ?: null;
        }

        if (array_key_exists('avatar_url', $validated)) {
            $customer->c_avatar_url = $validated['avatar_url'] ?: null;
        }

        $customer->save();

        return response()->json($this->transformCustomer($customer));
    }

    public function changePassword(Request $request)
    {
        /** @var Customer $customer */
        $customer = $request->user();
        $passwordChangeRequired = $this->customerRequiresPasswordChange($customer);

        $validated = $request->validate([
            'current_password' => $passwordChangeRequired ? 'nullable|string' : 'required|string',
            'new_password' => [
                'required',
                'string',
                'min:8',
                'confirmed',
                'regex:/[A-Z]/',
                'regex:/[a-z]/',
                'regex:/[0-9]/',
                'regex:/[^A-Za-z0-9]/',
            ],
        ], [
            'new_password.min' => 'Password must be at least 8 characters.',
            'new_password.confirmed' => 'Password confirmation does not match.',
            'new_password.regex' => 'Password must include uppercase, lowercase, number, and special character.',
        ]);

        $currentPassword = (string) ($validated['current_password'] ?? '');
        if (! $passwordChangeRequired) {
            if (! $this->matchesAnyCustomerPassword($customer, $currentPassword)) {
                throw ValidationException::withMessages([
                    'current_password' => ['Your current password is incorrect.'],
                ]);
            }
        }

        $newPassword = (string) $validated['new_password'];
        if ($this->matchesAnyCustomerPassword($customer, $newPassword)) {
            throw ValidationException::withMessages([
                'new_password' => ['New password must be different from your current password.'],
            ]);
        }

        $customer->c_password = Hash::make($newPassword);
        $customer->c_password_pin = '';
        $customer->c_password_change_required = false;
        $customer->save();

        return response()->json([
            'message' => 'Your password has been updated successfully.',
            'user' => $this->transformCustomer($customer),
        ]);
    }

    public function sendUsernameChangeOtp(Request $request)
    {
        $customer = $request->user();
        if (! $customer instanceof Customer) {
            return response()->json(['message' => 'Only customer accounts can change usernames.'], 403);
        }

        $validated = $request->validate([
            'username' => ['required', 'string', 'max:120', 'regex:/^[A-Za-z]+$/'],
        ], [
            'username.regex' => 'Username must contain letters only (A-Z).',
        ]);

        $nextUsername = trim((string) $validated['username']);
        $this->validateNoBadWords(['username' => $nextUsername]);

        $currentUsername = trim((string) ($customer->c_username ?? ''));
        if ($nextUsername === '' || strcasecmp($nextUsername, $currentUsername) === 0) {
            return response()->json(['message' => 'This is already your current username.'], 422);
        }

        $email = trim((string) ($customer->c_email ?? ''));
        if ($email === '') {
            return response()->json(['message' => 'Your account email is missing. Please update your profile email first.'], 422);
        }

        $duplicate = Customer::query()
            ->whereRaw('LOWER(c_username) = ?', [mb_strtolower($nextUsername, 'UTF-8')])
            ->where('c_userid', '!=', (int) $customer->c_userid)
            ->exists();
        if ($duplicate) {
            return response()->json(['message' => 'This username is already taken.'], 422);
        }

        $existingPending = DB::table('tbl_tickets')
            ->where('t_subject', $this->usernameChangeTicketSubject())
            ->where('t_eid', (int) $customer->c_userid)
            ->where('t_status', 1)
            ->orderByDesc('t_id')
            ->first();
        if ($existingPending) {
            return response()->json(['message' => 'You already have a pending username change request.'], 422);
        }

        $verificationToken = (string) Str::uuid();
        $otp = (string) random_int(1000, 9999);

        Cache::put($this->usernameChangeOtpCacheKey($verificationToken), [
            'otp_hash' => Hash::make($otp),
            'payload' => Crypt::encryptString(json_encode([
                'customer_id' => (int) $customer->c_userid,
                'requested_username' => $nextUsername,
                'current_username' => $currentUsername,
            ], JSON_THROW_ON_ERROR)),
            'email' => $email,
        ], now()->addMinutes(10));

        $this->sendUsernameChangeOtpEmail($email, $otp);

        return response()->json([
            'message' => 'A 4-digit verification code has been sent to your email.',
            'verification_token' => $verificationToken,
            'email' => $email,
        ]);
    }

    public function submitUsernameChangeRequest(Request $request)
    {
        $customer = $request->user();
        if (! $customer instanceof Customer) {
            return response()->json(['message' => 'Only customer accounts can change usernames.'], 403);
        }

        $validated = $request->validate([
            'verification_token' => 'required|string',
            'otp' => 'required|string|size:4',
        ]);

        $cached = Cache::get($this->usernameChangeOtpCacheKey((string) $validated['verification_token']));
        if (!is_array($cached) || empty($cached['otp_hash']) || empty($cached['payload'])) {
            throw ValidationException::withMessages([
                'otp' => ['The verification code has expired. Please request a new code.'],
            ]);
        }

        if (!Hash::check((string) $validated['otp'], (string) $cached['otp_hash'])) {
            throw ValidationException::withMessages([
                'otp' => ['Invalid verification code.'],
            ]);
        }

        $payload = json_decode(Crypt::decryptString((string) $cached['payload']), true, 512, JSON_THROW_ON_ERROR);
        $payloadCustomerId = (int) ($payload['customer_id'] ?? 0);
        if ($payloadCustomerId !== (int) $customer->c_userid) {
            return response()->json(['message' => 'The verification session is invalid.'], 403);
        }

        $requestedUsername = trim((string) ($payload['requested_username'] ?? ''));
        if ($requestedUsername === '') {
            throw ValidationException::withMessages([
                'otp' => ['The verification payload is invalid. Please request a new code.'],
            ]);
        }

        $duplicate = Customer::query()
            ->whereRaw('LOWER(c_username) = ?', [mb_strtolower($requestedUsername, 'UTF-8')])
            ->where('c_userid', '!=', (int) $customer->c_userid)
            ->exists();
        if ($duplicate) {
            throw ValidationException::withMessages([
                'username' => ['This username is already taken.'],
            ]);
        }

        $existingPending = DB::table('tbl_tickets')
            ->where('t_subject', $this->usernameChangeTicketSubject())
            ->where('t_eid', (int) $customer->c_userid)
            ->where('t_status', 1)
            ->orderByDesc('t_id')
            ->first();
        if ($existingPending) {
            return response()->json(['message' => 'You already have a pending username change request.'], 422);
        }

        $ticketId = DB::table('tbl_tickets')->insertGetId([
            't_bid' => 0,
            't_eid' => (int) $customer->c_userid,
            't_department' => 1,
            't_subject' => $this->usernameChangeTicketSubject(),
            't_urgency' => 2,
            't_related' => 0,
            't_view_status' => 1,
            't_status' => 1,
            't_date' => now(),
            't_archive' => 0,
            't_category' => 0,
        ], 't_id');

        $requestPayload = [
            'type' => 'username_change_request',
            'current_username' => trim((string) ($customer->c_username ?? '')) ?: null,
            'requested_username' => $requestedUsername,
        ];

        DB::table('tbl_tickets_details')->insert([
            't_id' => (int) $ticketId,
            'td_content' => json_encode($requestPayload, JSON_THROW_ON_ERROR),
            'td_attachment' => null,
            'td_datetime' => now(),
            'td_rate' => 0,
            'td_eid' => (int) $customer->c_userid,
            'td_replystat' => 0,
            'td_viewstat' => '1',
            'td_ip' => (string) $request->ip(),
        ]);

        $customerName = $this->fullName($customer);
        AdminNotification::query()->firstOrCreate(
            [
                'an_type' => 'username_change_request',
                'an_source_type' => 'ticket',
                'an_source_id' => (int) $ticketId,
            ],
            [
                'an_severity' => 'warning',
                'an_title' => 'Username Change Request',
                'an_message' => sprintf(
                    '%s requested a username change from "%s" to "%s".',
                    $customerName !== '' ? $customerName : ('Member #' . $customer->c_userid),
                    trim((string) ($customer->c_username ?? '')),
                    $requestedUsername
                ),
                'an_href' => '/admin/inquiry',
                'an_payload' => [
                    'ticket_id' => (int) $ticketId,
                    'customer_id' => (int) $customer->c_userid,
                    'customer_name' => $customerName,
                    'customer_email' => (string) ($customer->c_email ?? ''),
                    'current_username' => trim((string) ($customer->c_username ?? '')),
                    'requested_username' => $requestedUsername,
                ],
                'an_created_at' => now(),
            ]
        );

        Cache::forget($this->usernameChangeOtpCacheKey((string) $validated['verification_token']));

        return response()->json([
            'message' => 'Request submitted. Please wait for admin approval.',
            'request' => $this->transformUsernameChangeTicket((int) $ticketId),
        ]);
    }

    public function latestUsernameChangeRequest(Request $request)
    {
        $customer = $request->user();
        if (! $customer instanceof Customer) {
            return response()->json(['request' => null]);
        }

        $latest = DB::table('tbl_tickets')
            ->where('t_subject', $this->usernameChangeTicketSubject())
            ->where('t_eid', (int) $customer->c_userid)
            ->orderByDesc('t_id')
            ->first();

        return response()->json([
            'request' => $latest ? $this->transformUsernameChangeTicket((int) $latest->t_id) : null,
        ]);
    }

    private function transformCustomer(Customer $customer): array
    {
        $fullName = $this->fullName($customer);

        $accountStatus = (int) ($customer->c_accnt_status ?? 0);
        $lockStatus = (int) ($customer->c_lockstatus ?? 0);
        $verificationStatus = $lockStatus === 1
            ? 'blocked'
            : match ($accountStatus) {
                1 => 'verified',
                2 => 'pending_review',
                default => 'not_verified',
            };

        return [
            'id' => (int) $customer->c_userid,
            'name' => $fullName,
            'email' => $customer->c_email,
            'username' => $customer->c_username,
            'phone' => $customer->c_mobile,
            'address' => (string) ($customer->c_address ?? ''),
            'barangay' => (string) ($customer->c_barangay ?? ''),
            'city' => (string) ($customer->c_city ?? ''),
            'province' => (string) ($customer->c_province ?? ''),
            'region' => (string) ($customer->c_region ?? ''),
            'zip_code' => (string) ($customer->c_zipcode ?? ''),
            'avatar_url' => $customer->c_avatar_url,
            'rank' => (int) ($customer->c_rank ?? 0),
            'account_status' => $accountStatus,
            'lock_status' => $lockStatus,
            'verification_status' => $verificationStatus,
            'email_verified' => true,
            'password_change_required' => $this->customerRequiresPasswordChange($customer),
        ];
    }

    private function customerRequiresPasswordChange(Customer $customer): bool
    {
        return (bool) ($customer->c_password_change_required ?? false);
    }

    private function getCustomerPasswordCandidates(Customer $customer): array
    {
        return array_values(array_filter(array_unique([
            trim((string) ($customer->c_password ?? '')),
            trim((string) ($customer->c_password_pin ?? '')),
        ]), static fn (string $value): bool => $value !== ''));
    }

    private function matchesLegacyCustomerPassword(Customer $customer, string $password, bool $ignoreCase): bool
    {
        foreach ($this->getCustomerPasswordCandidates($customer) as $stored) {
            if (password_get_info($stored)['algo'] !== null) {
                continue;
            }

            if (! $ignoreCase && hash_equals($stored, $password)) {
                return true;
            }

            if (
                $ignoreCase
                && mb_strtolower($stored, 'UTF-8') === mb_strtolower($password, 'UTF-8')
            ) {
                return true;
            }
        }

        return false;
    }

    private function matchesHashedCustomerPassword(Customer $customer, string $password): bool
    {
        foreach ($this->getCustomerPasswordCandidates($customer) as $stored) {
            if (password_get_info($stored)['algo'] === null) {
                continue;
            }

            if (Hash::check($password, $stored)) {
                return true;
            }
        }

        return false;
    }

    private function matchesAnyCustomerPassword(Customer $customer, string $password): bool
    {
        return $this->matchesHashedCustomerPassword($customer, $password)
            || $this->matchesLegacyCustomerPassword($customer, $password, false)
            || $this->matchesLegacyCustomerPassword($customer, $password, true);
    }

    private function passwordMeetsModernRequirements(string $password): bool
    {
        return strlen($password) >= 8
            && preg_match('/[A-Z]/', $password) === 1
            && preg_match('/[a-z]/', $password) === 1
            && preg_match('/[0-9]/', $password) === 1
            && preg_match('/[^A-Za-z0-9]/', $password) === 1;
    }

    private function transformReferralNode(Customer $customer): array
    {
        $accountStatus = (int) ($customer->c_accnt_status ?? 0);
        $lockStatus = (int) ($customer->c_lockstatus ?? 0);

        return [
            'id' => (int) $customer->c_userid,
            'name' => $this->fullName($customer),
            'username' => (string) ($customer->c_username ?? ''),
            'email' => (string) ($customer->c_email ?? ''),
            'joined_at' => (string) ($customer->c_date_started ?? ''),
            'total_earnings' => (float) ($customer->c_totalincome ?? 0),
            'verification_status' => $this->verificationStatus($accountStatus, $lockStatus),
        ];
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

    private function verificationStatus(int $accountStatus, int $lockStatus): string
    {
        if ($lockStatus === 1) {
            return 'blocked';
        }

        return match ($accountStatus) {
            1 => 'verified',
            2 => 'pending_review',
            default => 'not_verified',
        };
    }

    private function splitName(string $name): array
    {
        $trimmed = trim($name);
        if ($trimmed === '') {
            return ['', null, null];
        }

        $parts = preg_split('/\s+/', $trimmed) ?: [];
        if (count($parts) === 1) {
            return [$parts[0], null, null];
        }

        if (count($parts) === 2) {
            return [$parts[0], null, $parts[1]];
        }

        $first = array_shift($parts);
        $last = array_pop($parts);
        $middle = implode(' ', $parts);

        return [$first ?? '', $middle !== '' ? $middle : null, $last ?? null];
    }

    private function createPrimaryAddressRecord(Customer $customer): void
    {
        $street = trim((string) ($customer->c_address ?? ''));
        $region = trim((string) ($customer->c_region ?? ''));
        $province = trim((string) ($customer->c_province ?? ''));
        $city = trim((string) ($customer->c_city ?? ''));
        $barangay = trim((string) ($customer->c_barangay ?? ''));

        if ($street === '' || $region === '' || $province === '' || $city === '' || $barangay === '') {
            return;
        }

        $existing = CustomerAddress::query()
            ->where('a_cid', (int) $customer->c_userid)
            ->where('a_address', $street)
            ->where('a_region', $region)
            ->where('a_province', $province)
            ->where('a_city', $city)
            ->where('a_barangay', $barangay)
            ->where('a_postcode', (string) ($customer->c_zipcode ?? '') ?: null)
            ->exists();

        if ($existing) {
            return;
        }

        CustomerAddress::create([
            'a_cid' => (int) $customer->c_userid,
            'a_fullname' => $this->fullName($customer),
            'a_mobile' => (string) ($customer->c_mobile ?? '0'),
            'a_mobile_code' => '0',
            'a_address' => $street,
            'a_country' => (string) ($customer->c_country ?? '175'),
            'a_region' => $region,
            'a_province' => $province,
            'a_city' => $city,
            'a_barangay' => $barangay,
            'a_region_code' => (string) ($customer->c_region_code ?? '') ?: null,
            'a_province_code' => (string) ($customer->c_province_code ?? '') ?: null,
            'a_city_code' => (string) ($customer->c_city_code ?? '') ?: null,
            'a_barangay_code' => (string) ($customer->c_barangay_code ?? '') ?: null,
            'a_shipping_status' => 1,
            'a_billing_status' => 1,
            'a_postcode' => (string) ($customer->c_zipcode ?? '') ?: null,
            'a_address_type' => 'Home',
            'a_notes' => '',
        ]);
    }

    private function mapRole(int $level): string
    {
    return match ($level) {
            1 => 'super_admin',
            2 => 'admin',
            3 => 'csr',
            4 => 'web_content',
            default => 'staff',
    } ;
}

    private function mapGenderToInt(?string $gender): int
    {
        return match ($gender) {
            'male' => 1,
            'female' => 2,
            'other' => 3,
            default => 0,
        };
    }

    private function validateNoBadWords(array $values): void
    {
        $blocked = $this->badWordList();
        $errors = [];

        foreach ($values as $field => $value) {
            if (!is_string($value) || trim($value) === '') {
                continue;
            }

            if ($this->containsBlockedWord($value, $blocked)) {
                $errors[$field] = ['This field contains prohibited words. Please use appropriate text.'];
            }
        }

        if (!empty($errors)) {
            throw ValidationException::withMessages($errors);
        }
    }

    private function containsBlockedWord(string $value, array $blocked): bool
    {
        $lower = strtolower($value);
        $normalized = preg_replace('/[^a-z0-9]+/', ' ', $lower) ?? '';
        $compact = preg_replace('/[^a-z0-9]+/', '', $lower) ?? '';

        foreach ($blocked as $word) {
            $needle = strtolower(trim($word));
            if ($needle === '') {
                continue;
            }

            $needleCompact = preg_replace('/[^a-z0-9]+/', '', $needle) ?? '';

            if (str_contains($normalized, $needle) || ($needleCompact !== '' && str_contains($compact, $needleCompact))) {
                return true;
            }
        }

        return false;
    }

    private function badWordList(): array
    {
        return [
            'fuck',
            'shit',
            'bitch',
            'asshole',
            'puta',
            'gago',
            'ulol',
            'tanga',
            'tarantado',
            'nigger',
            'nigga',
            'faggot',
            'porn',
            'sex',
        ];
    }

    private function transformUsernameChangeTicket(int $ticketId): array
    {
        $ticket = DB::table('tbl_tickets')->where('t_id', $ticketId)->first();
        if (! $ticket) {
            return [];
        }

        $requestDetail = DB::table('tbl_tickets_details')
            ->where('t_id', $ticketId)
            ->where('td_replystat', 0)
            ->orderBy('td_id')
            ->first();

        $payload = $this->decodeUsernameChangePayload($requestDetail?->td_content ?? null);

        $status = $this->mapUsernameChangeStatus((int) $ticket->t_status, $ticketId);

        return [
            'id' => (int) $ticket->t_id,
            'reference_no' => $this->ticketReferenceNo((int) $ticket->t_id),
            'status' => $status,
            'requested_username' => (string) ($payload['requested_username'] ?? ''),
            'review_notes' => $payload['review_notes'] ?? null,
            'reviewed_at' => $payload['reviewed_at'] ?? null,
            'created_at' => $ticket->t_date ? (string) $ticket->t_date : null,
        ];
    }

    private function ticketReferenceNo(int $ticketId): string
    {
        return sprintf('TKT-%06d', $ticketId);
    }

    private function registrationOtpCacheKey(string $verificationToken): string
    {
        return "registration_otp:{$verificationToken}";
    }

    private function usernameChangeOtpCacheKey(string $verificationToken): string
    {
        return "username_change_otp:{$verificationToken}";
    }

    private function passwordResetCacheKey(string $token): string
    {
        return "customer_password_reset:{$token}";
    }

    private function sendRegistrationOtpEmail(string $email, string $otp): void
    {
        Mail::mailer('resend')->to($email)->send(new RegistrationOtpMail($otp, $email));
    }

    private function notifyReferrerAboutRegistration(Customer $referrer, Customer $referral): void
    {
        $recipient = trim((string) ($referrer->c_email ?? ''));
        if ($recipient === '' || !filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
            return;
        }

        $frontend = rtrim((string) env('FRONTEND_URL', config('app.url')), '/');
        $mailRecipient = env('MAIL_TEST_TO') ?: $recipient;

        try {
            Mail::mailer('resend')->to($mailRecipient)->send(new ReferralRegistrationAlertMail([
                'referrer_name' => $this->fullName($referrer),
                'referral_name' => $this->fullName($referral),
                'referral_username' => (string) ($referral->c_username ?? ''),
                'registered_at' => now()->toDayDateTimeString(),
                'login_url' => $frontend . '/login',
            ]));
        } catch (\Throwable $e) {
            report($e);
        }
    }

    private function sendUsernameChangeOtpEmail(string $email, string $otp): void
    {
        Mail::mailer('resend')->to($email)->send(new UsernameChangeOtpMail($otp, $email));
    }

    private function usernameChangeTicketSubject(): string
    {
        return 'Username Change Request';
    }

    private function decodeUsernameChangePayload(?string $content): array
    {
        if (!is_string($content) || trim($content) === '') {
            return [];
        }

        $decoded = json_decode($content, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function mapUsernameChangeStatus(int $ticketStatus, int $ticketId): string
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

    private function normalizeReferralValue(string $value): string
    {
        $trimmed = trim($value);

        if ($trimmed === '') {
            return '';
        }

        if (filter_var($trimmed, FILTER_VALIDATE_URL)) {
            $parts = parse_url($trimmed);
            parse_str($parts['query'] ?? '', $query);

            $fromQuery = trim((string) ($query['ref'] ?? $query['referred_by'] ?? ''));
            if ($fromQuery !== '') {
                return $fromQuery;
            }

            $path = trim((string) ($parts['path'] ?? ''), '/');
            if ($path !== '') {
                $segments = explode('/', $path);
                return trim((string) end($segments));
            }
        }

        return $trimmed;
    }

}
