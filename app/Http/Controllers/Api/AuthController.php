<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AdminNotification;
use App\Models\Customer;
use App\Models\CustomerLoginSession;
use App\Models\CustomerAddress;
use App\Models\MemberActivityLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Str;
use App\Support\MemberMonthlyActivation;
use App\Support\MemberActivityLogger;
use App\Mail\Auth\RegistrationOtpMail;
use App\Mail\Auth\PortalLoginOtpMail;
use App\Mail\Auth\PortalLoginApprovalMail;
use App\Mail\Auth\CustomerPasswordResetMail;
use App\Mail\Auth\UsernameChangeOtpMail;
use App\Mail\Auth\ReferralRegistrationAlertMail;
use Pusher\Pusher;
use Laravel\Sanctum\PersonalAccessToken;

class AuthController extends Controller
{
    private const PASSWORD_RESET_TTL_MINUTES = 60;
    private const LOGIN_OTP_TTL_MINUTES = 10;
    private const LOGIN_OTP_MAX_ATTEMPTS = 5;
    private const LOGIN_APPROVAL_TTL_MINUTES = 10;

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
            'barangay_code'         => 'nullable|string|max:20',
            'city_code'             => 'nullable|string|max:20',
            'province_code'         => 'nullable|string|max:20',
            'region_code'           => 'nullable|string|max:20',
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

        if ($this->looksLikeEmailUsername((string) ($validated['username'] ?? ''))) {
            throw ValidationException::withMessages([
                'username' => ['Username must not be an email address. Please choose a username without @gmail.com, @yahoo.com, and similar email formats.'],
            ]);
        }

        $referrer = Customer::query()
            ->select(['c_userid', 'c_username', 'c_accnt_status', 'c_lockstatus'])
            ->whereRaw('LOWER(c_username) = ?', [strtolower((string) $validated['referred_by'])])
            ->where('c_lockstatus', 0)
            ->first();

        if (! $referrer) {
            throw ValidationException::withMessages([
                'referred_by' => ['Referral code is invalid or referrer account is unavailable.'],
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

        $customer = DB::transaction(function () use ($registration, $referrerUserId) {
            if (DB::connection()->getDriverName() === 'pgsql') {
                DB::statement('LOCK TABLE tbl_customer IN EXCLUSIVE MODE');
            }

            $nextCustomerId = ((int) DB::table('tbl_customer')->whereNotNull('c_userid')->max('c_userid')) + 1;

            return Customer::create([
                'c_userid'       => $nextCustomerId,
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
                'c_password_change_required' => false,
                'c_rank'         => 0,
                'c_accnt_status' => 0,
                'c_lockstatus'   => 0,
                'c_sponsor'      => $referrerUserId,
                'c_date_started' => now(),
                'c_address'      => $registration['address'] ?? null,
                'c_barangay'     => $registration['barangay'] ?? null,
                'c_city'         => $registration['city'] ?? null,
                'c_province'     => $registration['province'] ?? null,
                'c_region'       => $registration['region'] ?? null,
                'c_region_code'  => $registration['region_code'] ?? null,
                'c_province_code'=> $registration['province_code'] ?? null,
                'c_city_code'    => $registration['city_code'] ?? null,
                'c_barangay_code'=> $registration['barangay_code'] ?? null,
                'c_zipcode'      => $registration['zip_code'] ?? null,
            ]);
        });

        $this->createPrimaryAddressRecord($customer);
        $referrer = Customer::query()->where('c_userid', $referrerUserId)->first();
        if ($referrer instanceof Customer) {
            $this->notifyReferrerAboutRegistration($referrer, $customer);
        }
        $this->notifyAdminsAboutNewRegistration($customer);

        // Log registration activity
        try {
            MemberActivityLog::create([
                'mal_customer_id' => (int) $customer->c_userid,
                'mal_activity_type' => 'registration',
                'mal_action' => MemberActivityLog::ACTION_CREATE,
                'mal_description' => 'New member registered',
                'mal_resource_type' => 'account',
                'mal_resource_id' => (int) $customer->c_userid,
                'mal_details' => [
                    'username' => $customer->c_username,
                    'email' => $customer->c_email,
                    'referrer_id' => $referrerUserId,
                ],
                'mal_ip_address' => request()->ip(),
                'mal_user_agent' => request()->userAgent(),
                'mal_created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('Failed to log registration activity', [
                'customer_id' => (int) $customer->c_userid,
                'error' => $e->getMessage(),
            ]);
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

    public function checkUsernameAvailability(Request $request)
    {
        $validated = $request->validate([
            'username' => ['required', 'string', 'max:255'],
        ]);

        $username = trim((string) $validated['username']);

        if ($username === '') {
            return response()->json([
                'available' => false,
                'message' => 'Username is required.',
            ], 422);
        }

        if ($this->looksLikeEmailUsername($username)) {
            return response()->json([
                'available' => false,
                'message' => 'Username must not be an email address.',
            ]);
        }

        $exists = Customer::query()
            ->whereRaw('LOWER(c_username) = ?', [mb_strtolower($username, 'UTF-8')])
            ->exists();

        return response()->json([
            'available' => ! $exists,
            'message' => $exists ? 'This username is already taken.' : 'Username is available.',
        ]);
    }

    public function checkEmailAvailability(Request $request)
    {
        $validated = $request->validate([
            'email' => ['required', 'string', 'email', 'max:255'],
        ]);

        $email = trim((string) $validated['email']);

        if ($email === '') {
            return response()->json([
                'available' => false,
                'message' => 'Email address is required.',
            ], 422);
        }

        $exists = Customer::query()
            ->whereRaw('LOWER(c_email) = ?', [mb_strtolower($email, 'UTF-8')])
            ->exists();

        return response()->json([
            'available' => ! $exists,
            'message' => $exists ? 'This email is already registered.' : 'Email address is available.',
        ]);
    }

    public function login(Request $request)
    {
        $otpValue = trim((string) $request->input('otp', ''));
        $challengeTokenValue = trim((string) $request->input('otp_challenge_token', ''));
        $mfaChallengeTokenValue = trim((string) $request->input('mfa_challenge_token', ''));
        $otpLower = strtolower($otpValue);
        $challengeLower = strtolower($challengeTokenValue);
        $mfaChallengeLower = strtolower($mfaChallengeTokenValue);
        $request->merge([
            'otp' => (!in_array($otpLower, ['', 'undefined', 'null'], true)) ? $otpValue : null,
            'otp_challenge_token' => (!in_array($challengeLower, ['', 'undefined', 'null'], true)) ? $challengeTokenValue : null,
            'mfa_challenge_token' => (!in_array($mfaChallengeLower, ['', 'undefined', 'null'], true)) ? $mfaChallengeTokenValue : null,
        ]);

        $request->validate([
            'email'    => 'required|string',
            'password' => 'required|string',
            'otp' => 'nullable|string|size:6',
            'otp_challenge_token' => 'nullable|string',
            'mfa_challenge_token' => 'nullable|string',
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

        if ((bool) ($customer->c_two_factor_enabled ?? false)) {
            $approvalRequired = $this->requiresLoginApproval($customer, $request);
            $mfaChallengeToken = trim((string) $request->input('mfa_challenge_token', ''));

            if ($approvalRequired) {
                if ($mfaChallengeToken === '') {
                    $mfaChallengeToken = (string) Str::uuid();
                    $this->issueLoginApprovalChallenge(
                        challengeToken: $mfaChallengeToken,
                        customer: $customer,
                        request: $request,
                    );

                    return response()->json([
                        'requires_mfa_approval' => true,
                        'mfa_challenge_token' => $mfaChallengeToken,
                        'message' => 'A new device sign-in approval link was sent to your email.',
                    ], 202);
                }

                $approvalStatus = $this->getLoginApprovalChallengeStatus($mfaChallengeToken, $customer);
                if ($approvalStatus === 'pending') {
                    return response()->json([
                        'requires_mfa_approval' => true,
                        'mfa_challenge_token' => $mfaChallengeToken,
                        'message' => 'Please approve this sign-in from your email before continuing.',
                    ], 202);
                }

                if ($approvalStatus === 'denied') {
                    throw ValidationException::withMessages([
                        'login' => ['This sign-in request was denied. Please try again if this was you.'],
                    ]);
                }

                if ($approvalStatus !== 'approved') {
                    throw ValidationException::withMessages([
                        'login' => ['The sign-in approval session has expired. Please sign in again.'],
                    ]);
                }

                $this->consumeLoginApprovalChallenge($mfaChallengeToken);
            }
        }

        $modernPasswordInUse = $hashMatch
            && ! $legacyDirectMatch
            && ! $legacyCaseInsensitiveMatch
            && $this->passwordMeetsModernRequirements($password);

        // Auto-heal newly registered accounts that were incorrectly flagged
        // even though they already use a modern hashed password and no legacy pin.
        if (
            $modernPasswordInUse
            && trim((string) ($customer->c_password_pin ?? '')) === ''
            && $this->customerRequiresPasswordChange($customer)
        ) {
            $customer->c_password_change_required = false;
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

        $tokenResult = $customer->createToken('auth_token');
        $token = $tokenResult->plainTextToken;
        $plainTokenId = (int) ($tokenResult->accessToken->id ?? 0);
        try {
            $this->recordLoginSession($customer, $request, $plainTokenId > 0 ? $plainTokenId : null);
            MemberActivityLogger::logLogin((int) $customer->c_userid, $request);
        } catch (\Throwable $e) {
            report($e);
        }

        // Log login activity
        try {
            MemberActivityLog::create([
                'mal_customer_id' => (int) $customer->c_userid,
                'mal_activity_type' => MemberActivityLog::ACTIVITY_LOGIN,
                'mal_action' => MemberActivityLog::ACTION_CREATE,
                'mal_description' => 'Member logged in',
                'mal_ip_address' => $request->ip(),
                'mal_user_agent' => $request->userAgent(),
                'mal_created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            // Log silently if activity logging fails
            Log::warning('Failed to log login activity', [
                'customer_id' => (int) $customer->c_userid,
                'error' => $e->getMessage(),
            ]);
        }

        return response()->json([
            'user'  => $this->transformCustomer($customer),
            'token' => $token,
            'message' => $mustChangePassword
                ? 'Your account was signed in using a legacy password. Please change your password before continuing to the shop.'
                : null,
        ]);
    }

    public function resendLoginOtp(Request $request)
    {
        $request->validate([
            'mfa_challenge_token' => 'required|string',
        ]);

        $challengeToken = trim((string) $request->input('mfa_challenge_token'));
        $cached = Cache::get($this->loginApprovalCacheKey($challengeToken));
        if (! is_array($cached) || empty($cached['customer_id'])) {
            throw ValidationException::withMessages([
                'mfa_challenge_token' => ['The sign-in approval session has expired. Please sign in again.'],
            ]);
        }

        $customer = Customer::query()->where('c_userid', (int) $cached['customer_id'])->first();
        if (! $customer) {
            Cache::forget($this->loginApprovalCacheKey($challengeToken));
            throw ValidationException::withMessages([
                'mfa_challenge_token' => ['Customer account not found. Please sign in again.'],
            ]);
        }

        $this->issueLoginApprovalChallenge(
            challengeToken: $challengeToken,
            customer: $customer,
            request: $request,
            preserveStatus: true,
        );

        return response()->json([
            'requires_mfa_approval' => true,
            'mfa_challenge_token' => $challengeToken,
            'message' => 'A new sign-in approval email has been sent.',
        ]);
    }

    public function loginMfaStatus(Request $request)
    {
        $validated = $request->validate([
            'mfa_challenge_token' => 'required|string',
        ]);

        $challengeToken = trim((string) $validated['mfa_challenge_token']);
        $cached = Cache::get($this->loginApprovalCacheKey($challengeToken));
        if (! is_array($cached) || empty($cached['status'])) {
            return response()->json([
                'status' => 'expired',
                'message' => 'Sign-in approval session expired. Please sign in again.',
            ], 410);
        }

        return response()->json([
            'status' => (string) $cached['status'],
            'message' => match ((string) $cached['status']) {
                'approved' => 'Sign-in approved. You can continue in your app.',
                'denied' => 'Sign-in request denied.',
                default => 'Waiting for your approval.',
            },
        ]);
    }

    public function respondLoginMfa(Request $request)
    {
        $validated = $request->validate([
            'mfa_challenge_token' => 'required|string',
            'decision' => 'required|string|in:approve,deny',
        ]);

        $challengeToken = trim((string) $validated['mfa_challenge_token']);
        $cached = Cache::get($this->loginApprovalCacheKey($challengeToken));
        if (! is_array($cached) || empty($cached['customer_id'])) {
            return response()->json([
                'status' => 'expired',
                'message' => 'This sign-in request has expired.',
            ], 410);
        }

        $status = ((string) $validated['decision']) === 'approve' ? 'approved' : 'denied';
        $cached['status'] = $status;
        $cached['responded_at'] = now()->toIso8601String();
        Cache::put(
            $this->loginApprovalCacheKey($challengeToken),
            $cached,
            now()->addMinutes(self::LOGIN_APPROVAL_TTL_MINUTES),
        );

        return response()->json([
            'status' => $status,
            'message' => $status === 'approved'
                ? 'Sign-in approved. You can go back to the app.'
                : 'Sign-in denied. If this was not you, please change your password.',
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
        /** @var Customer $customer */
        $customer = $request->user();
        $token = $customer->currentAccessToken();
        $tokenId = (int) ($token?->id ?? 0);

        if ($token) {
            $token->delete();
        }

        try {
            if ($tokenId > 0) {
                $this->revokeSessionByTokenId((int) $customer->c_userid, $tokenId, 'logout');
            }
            MemberActivityLogger::logLogout((int) $customer->c_userid, $request);
        } catch (\Throwable $e) {
            report($e);
        }

        // Log logout activity
        try {
            if ($customer instanceof Customer) {
                MemberActivityLog::create([
                    'mal_customer_id' => (int) $customer->c_userid,
                    'mal_activity_type' => MemberActivityLog::ACTIVITY_LOGOUT,
                    'mal_action' => MemberActivityLog::ACTION_CREATE,
                    'mal_description' => 'Member logged out',
                    'mal_ip_address' => $request->ip(),
                    'mal_user_agent' => $request->userAgent(),
                    'mal_created_at' => now(),
                ]);
            }
        } catch (\Throwable $e) {
            Log::warning('Failed to log logout activity', [
                'customer_id' => $customer->c_userid ?? null,
                'error' => $e->getMessage(),
            ]);
        }

        return response()->json(['message' => 'Logged out successfully.']);
    }

    public function me(Request $request)
    {
        $customer = $request->user();

        if ($customer instanceof Customer) {
            $customer->loadMissing('sponsor:c_userid,c_username,c_fname,c_mname,c_lname');
        }

        if ((int) ($customer->c_lockstatus ?? 0) === 1) {
            optional($customer->currentAccessToken())->delete();

            return response()->json([
                'message' => 'Your account has been banned. Please contact support for assistance.',
                'reason' => 'banned',
            ], 401);
        }

        try {
            $currentTokenId = (int) ($customer->currentAccessToken()?->id ?? 0);
            if ($currentTokenId > 0) {
                $this->touchSessionByTokenId((int) $customer->c_userid, $currentTokenId);
            }
        } catch (\Throwable $e) {
            report($e);
        }

        return response()->json($this->transformCustomer($customer));
    }

    public function activity(Request $request)
    {
        /** @var Customer $customer */
        $customer = $request->user();

        $items = MemberActivityLog::forCustomer((int) $customer->c_userid)
            ->limit(30)
            ->get()
            ->map(function (MemberActivityLog $log): array {
                return [
                    'id' => (int) $log->mal_id,
                    'activity_type' => (string) $log->mal_activity_type,
                    'action' => (string) $log->mal_action,
                    'title' => $this->activityTitle($log),
                    'description' => (string) ($log->mal_description ?? ''),
                    'created_at' => optional($log->mal_created_at)->toIso8601String(),
                    'ip_address' => (string) ($log->mal_ip_address ?? ''),
                    'user_agent' => (string) ($log->mal_user_agent ?? ''),
                ];
            })
            ->values();

        return response()->json([
            'items' => $items,
        ]);
    }

    public function sessions(Request $request)
    {
        /** @var Customer $customer */
        $customer = $request->user();
        $currentTokenId = (int) ($customer->currentAccessToken()?->id ?? 0);

        $tokenRows = PersonalAccessToken::query()
            ->where('tokenable_type', Customer::class)
            ->where('tokenable_id', (int) $customer->c_userid)
            ->orderByDesc('last_used_at')
            ->orderByDesc('created_at')
            ->limit(50)
            ->get();

        $tokenIds = $tokenRows->pluck('id')->map(fn ($id) => (int) $id)->filter(fn (int $id) => $id > 0)->values();

        $sessionsByToken = collect();
        if ($this->isSessionTrackingReady() && $tokenIds->isNotEmpty()) {
            $sessionsByToken = CustomerLoginSession::query()
                ->where('cls_customer_id', (int) $customer->c_userid)
                ->whereIn('cls_token_id', $tokenIds->all())
                ->whereNull('cls_revoked_at')
                ->orderByDesc('cls_last_active_at')
                ->orderByDesc('cls_created_at')
                ->get()
                ->keyBy(fn (CustomerLoginSession $row) => (int) ($row->cls_token_id ?? 0));
        }

        $items = $tokenRows
            ->map(function (PersonalAccessToken $token) use ($sessionsByToken, $currentTokenId): array {
                $tokenId = (int) $token->id;
                /** @var CustomerLoginSession|null $session */
                $session = $sessionsByToken->get($tokenId);

                $platform = (string) ($session?->cls_platform ?? 'Unknown OS');
                $browser = (string) ($session?->cls_browser ?? 'Unknown Browser');
                $device = (string) ($session?->cls_device ?? 'Desktop');
                $location = (string) ($session?->cls_location ?? 'Unknown location');
                $ipAddress = (string) ($session?->cls_ip_address ?? '');
                $userAgent = (string) ($session?->cls_user_agent ?? '');

                if (($platform === 'Unknown OS' || $browser === 'Unknown Browser') && $userAgent !== '') {
                    [$uaPlatform, $uaBrowser, $uaDevice] = $this->detectDeviceInfo($userAgent);
                    $platform = $uaPlatform;
                    $browser = $uaBrowser;
                    $device = $uaDevice;
                }

                $createdAt = $session?->cls_created_at ?? $token->created_at;
                $lastActiveAt = $session?->cls_last_active_at ?? $token->last_used_at ?? $token->created_at;

                return [
                    'id' => (int) ($session?->cls_id ?? 0),
                    'token_id' => $tokenId,
                    'device' => $device,
                    'platform' => $platform,
                    'browser' => $browser,
                    'location' => $location,
                    'ip_address' => $ipAddress,
                    'user_agent' => $userAgent,
                    'created_at' => optional($createdAt)->toIso8601String(),
                    'last_active_at' => optional($lastActiveAt)->toIso8601String(),
                    'is_current' => $tokenId === $currentTokenId,
                ];
            })
            ->values();

        return response()->json([
            'items' => $items,
        ]);
    }

    public function revokeSession(Request $request, int $tokenId)
    {
        /** @var Customer $customer */
        $customer = $request->user();
        $tokenId = (int) $tokenId;
        if ($tokenId <= 0) {
            throw ValidationException::withMessages([
                'token_id' => ['Invalid session token.'],
            ]);
        }

        $token = PersonalAccessToken::query()
            ->where('id', $tokenId)
            ->where('tokenable_type', Customer::class)
            ->where('tokenable_id', (int) $customer->c_userid)
            ->first();

        if (! $token) {
            throw ValidationException::withMessages([
                'token_id' => ['Session not found.'],
            ]);
        }

        $isCurrent = (int) ($customer->currentAccessToken()?->id ?? 0) === $tokenId;

        $token->delete();
        $this->revokeSessionByTokenId((int) $customer->c_userid, $tokenId, $isCurrent ? 'logout_current' : 'logout_device');

        if ($isCurrent) {
            MemberActivityLogger::logLogout((int) $customer->c_userid, $request);
        }

        return response()->json([
            'message' => $isCurrent ? 'Current device signed out successfully.' : 'Device signed out successfully.',
            'revoked_token_id' => $tokenId,
            'is_current' => $isCurrent,
        ]);
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
                'c_gpv',
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
            ->sortByDesc('c_userid')
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

        $networkIds = collect($children)
            ->flatMap(function (array $node) {
                $collectIds = function (array $current) use (&$collectIds): array {
                    $ids = [(int) ($current['id'] ?? 0)];
                    foreach (($current['children'] ?? []) as $child) {
                        $ids = [...$ids, ...$collectIds($child)];
                    }
                    return $ids;
                };
                return $collectIds($node);
            })
            ->filter(fn (int $id) => $id > 0)
            ->unique()
            ->values();

        $networkMembers = $networkIds->isEmpty()
            ? collect()
            : $descendants->whereIn('c_userid', $networkIds->all())->values();
        $totalNetwork = $networkMembers->count();
        $totalPv = (float) $networkMembers->sum(fn (Customer $member) => (float) ($member->c_gpv ?? 0));

        return response()->json([
            'root' => $this->transformReferralNode($customer),
            'summary' => [
                'direct_count' => $directCount,
                'second_level_count' => $secondLevelCount,
                'total_network' => $totalNetwork,
                'total_pv' => $totalPv,
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
            'two_factor_enabled' => 'nullable|boolean',
        ]);

        [$firstName, $middleName, $lastName] = $this->splitName((string) $validated['name']);

        if (array_key_exists('username', $validated) && $validated['username'] !== null && $this->looksLikeEmailUsername((string) $validated['username'])) {
            throw ValidationException::withMessages([
                'username' => ['Username must not be an email address. Please choose a username without @gmail.com, @yahoo.com, and similar email formats.'],
            ]);
        }

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

        if (array_key_exists('two_factor_enabled', $validated)) {
            $customer->c_two_factor_enabled = (bool) $validated['two_factor_enabled'];
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
            'referrer_id' => (int) ($customer->c_sponsor ?? 0),
            'referrer_username' => $customer->sponsor?->c_username ? (string) $customer->sponsor->c_username : null,
            'referrer_name' => $customer->sponsor instanceof Customer ? $this->fullName($customer->sponsor) : null,
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
            'monthly_activation' => MemberMonthlyActivation::summary($customer),
            'email_verified' => true,
            'password_change_required' => $this->customerRequiresPasswordChange($customer),
            'two_factor_enabled' => (bool) ($customer->c_two_factor_enabled ?? false),
        ];
    }

    private function activityTitle(MemberActivityLog $log): string
    {
        $type = (string) ($log->mal_activity_type ?? '');
        $description = trim((string) ($log->mal_description ?? ''));
        if ($description !== '') {
            return $description;
        }

        return match ($type) {
            MemberActivityLog::ACTIVITY_LOGIN => 'Signed in',
            MemberActivityLog::ACTIVITY_LOGOUT => 'Signed out',
            MemberActivityLog::ACTIVITY_PROFILE_UPDATE => 'Updated profile details',
            MemberActivityLog::ACTIVITY_PASSWORD_CHANGE => 'Changed account password',
            MemberActivityLog::ACTIVITY_ADDRESS_UPDATE => 'Updated address information',
            MemberActivityLog::ACTIVITY_PURCHASE => 'Placed an order',
            default => 'Account activity',
        };
    }

    private function recordLoginSession(Customer $customer, Request $request, ?int $tokenId = null): void
    {
        if (! $this->isSessionTrackingReady()) {
            return;
        }
        $userAgent = trim((string) ($request->userAgent() ?? ''));
        [$platform, $browser, $device] = $this->detectDeviceInfo($userAgent);
        $location = $this->resolveRequestLocation($request);

        CustomerLoginSession::create([
            'cls_customer_id' => (int) $customer->c_userid,
            'cls_token_id' => $tokenId,
            'cls_device' => $device,
            'cls_platform' => $platform,
            'cls_browser' => $browser,
            'cls_location' => $location,
            'cls_ip_address' => (string) ($request->ip() ?? ''),
            'cls_user_agent' => $userAgent,
            'cls_last_active_at' => now(),
            'cls_created_at' => now(),
        ]);
    }

    private function revokeSessionByTokenId(int $customerId, int $tokenId, string $reason): void
    {
        if (! $this->isSessionTrackingReady()) {
            return;
        }
        CustomerLoginSession::query()
            ->where('cls_customer_id', $customerId)
            ->where('cls_token_id', $tokenId)
            ->whereNull('cls_revoked_at')
            ->update([
                'cls_revoked_at' => now(),
                'cls_revoke_reason' => $reason,
            ]);
    }

    private function touchSessionByTokenId(int $customerId, int $tokenId): void
    {
        if (! $this->isSessionTrackingReady()) {
            return;
        }

        CustomerLoginSession::query()
            ->where('cls_customer_id', $customerId)
            ->where('cls_token_id', $tokenId)
            ->whereNull('cls_revoked_at')
            ->update([
                'cls_last_active_at' => now(),
            ]);
    }

    private function isSessionTrackingReady(): bool
    {
        try {
            return Schema::hasTable('tbl_customer_login_sessions');
        } catch (\Throwable $e) {
            report($e);
            return false;
        }
    }

    private function resolveRequestLocation(Request $request): string
    {
        $city = trim((string) ($request->header('X-App-City') ?? $request->header('X-City') ?? ''));
        $region = trim((string) ($request->header('X-App-Region') ?? $request->header('X-Region') ?? ''));
        $country = trim((string) ($request->header('CF-IPCountry') ?? $request->header('X-App-Country') ?? $request->header('X-Country') ?? ''));

        $parts = array_values(array_filter([$city, $region, $country], fn (string $v): bool => $v !== ''));
        if (!empty($parts)) {
            return implode(', ', $parts);
        }

        $ip = (string) ($request->ip() ?? '');
        if ($ip === '127.0.0.1' || $ip === '::1' || strtolower($ip) === 'localhost') {
            return 'Localhost';
        }

        return $ip !== '' ? $ip : 'Unknown location';
    }

    private function detectDeviceInfo(string $userAgent): array
    {
        $ua = strtolower($userAgent);

        $platform = 'Unknown OS';
        if (str_contains($ua, 'windows')) {
            $platform = 'Windows';
        } elseif (str_contains($ua, 'mac os') || str_contains($ua, 'macintosh')) {
            $platform = 'macOS';
        } elseif (str_contains($ua, 'android')) {
            $platform = 'Android';
        } elseif (str_contains($ua, 'iphone') || str_contains($ua, 'ipad') || str_contains($ua, 'ios')) {
            $platform = 'iOS';
        } elseif (str_contains($ua, 'linux')) {
            $platform = 'Linux';
        }

        $browser = 'Unknown Browser';
        if (str_contains($ua, 'edg/')) {
            $browser = 'Edge';
        } elseif (str_contains($ua, 'opr/') || str_contains($ua, 'opera')) {
            $browser = 'Opera';
        } elseif (str_contains($ua, 'chrome/') && !str_contains($ua, 'edg/')) {
            $browser = 'Chrome';
        } elseif (str_contains($ua, 'safari/') && !str_contains($ua, 'chrome/')) {
            $browser = 'Safari';
        } elseif (str_contains($ua, 'firefox/')) {
            $browser = 'Firefox';
        }

        $device = 'Desktop';
        if (str_contains($ua, 'mobile') || str_contains($ua, 'iphone') || str_contains($ua, 'android')) {
            $device = 'Mobile';
        } elseif (str_contains($ua, 'ipad') || str_contains($ua, 'tablet')) {
            $device = 'Tablet';
        }

        return [$platform, $browser, $device];
    }

    private function issueLoginOtpChallenge(string $challengeToken, Customer $customer, int $attempts = 0): void
    {
        $email = trim((string) $customer->c_email);
        if ($email === '') {
            throw ValidationException::withMessages([
                'login' => ['This account has no email configured for OTP verification.'],
            ]);
        }

        $otp = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $expiresAt = now()->addMinutes(self::LOGIN_OTP_TTL_MINUTES);

        Cache::put($this->loginOtpCacheKey($challengeToken), [
            'customer_id' => (int) $customer->c_userid,
            'otp_hash' => Hash::make($otp),
            'attempts' => $attempts,
        ], $expiresAt);

        try {
            Mail::mailer('resend')->to($email)->send(new PortalLoginOtpMail(
                otp: $otp,
                email: $email,
                portalLabel: 'AF Home',
                expiresInMinutes: (string) self::LOGIN_OTP_TTL_MINUTES,
            ));
        } catch (\Throwable $e) {
            report($e);
            throw ValidationException::withMessages([
                'login' => ['Unable to send OTP email right now. Please try again shortly.'],
            ]);
        }
    }

    private function validateLoginOtpChallenge(string $challengeToken, Customer $customer, string $otp): void
    {
        $cached = Cache::get($this->loginOtpCacheKey($challengeToken));
        if (! is_array($cached) || empty($cached['otp_hash']) || empty($cached['customer_id'])) {
            throw ValidationException::withMessages([
                'otp' => ['OTP session expired. Please sign in again.'],
            ]);
        }

        if ((int) $cached['customer_id'] !== (int) $customer->c_userid) {
            throw ValidationException::withMessages([
                'otp' => ['OTP session mismatch. Please sign in again.'],
            ]);
        }

        $attempts = (int) ($cached['attempts'] ?? 0);
        if (! Hash::check($otp, (string) $cached['otp_hash'])) {
            $attempts++;
            if ($attempts >= self::LOGIN_OTP_MAX_ATTEMPTS) {
                Cache::forget($this->loginOtpCacheKey($challengeToken));
                throw ValidationException::withMessages([
                    'otp' => ['Too many invalid OTP attempts. Please sign in again.'],
                ]);
            }

            $cached['attempts'] = $attempts;
            Cache::put(
                $this->loginOtpCacheKey($challengeToken),
                $cached,
                now()->addMinutes(self::LOGIN_OTP_TTL_MINUTES),
            );

            throw ValidationException::withMessages([
                'otp' => ['Invalid OTP code.'],
            ]);
        }

        Cache::forget($this->loginOtpCacheKey($challengeToken));
    }

    private function loginOtpCacheKey(string $challengeToken): string
    {
        return 'customer:login-otp:' . $challengeToken;
    }

    private function requiresLoginApproval(Customer $customer, Request $request): bool
    {
        if (! $this->isSessionTrackingReady()) {
            return true;
        }

        $userAgent = trim((string) ($request->userAgent() ?? ''));
        if ($userAgent === '') {
            return true;
        }

        return ! CustomerLoginSession::query()
            ->where('cls_customer_id', (int) $customer->c_userid)
            ->whereNull('cls_revoked_at')
            ->where('cls_user_agent', $userAgent)
            ->exists();
    }

    private function issueLoginApprovalChallenge(
        string $challengeToken,
        Customer $customer,
        Request $request,
        bool $preserveStatus = false,
    ): void {
        $email = trim((string) $customer->c_email);
        if ($email === '') {
            throw ValidationException::withMessages([
                'login' => ['This account has no email configured for login approval.'],
            ]);
        }

        $existing = Cache::get($this->loginApprovalCacheKey($challengeToken));
        $status = ($preserveStatus && is_array($existing)) ? (string) ($existing['status'] ?? 'pending') : 'pending';
        if (! in_array($status, ['pending', 'approved', 'denied'], true)) {
            $status = 'pending';
        }

        $userAgent = trim((string) ($request->userAgent() ?? ''));
        [$platform, $browser, $device] = $this->detectDeviceInfo($userAgent);
        $location = $this->resolveRequestLocation($request);
        $ipAddress = (string) ($request->ip() ?? '');

        $frontendUrl = rtrim((string) env('FRONTEND_URL', config('app.url')), '/');
        $approveUrl = sprintf(
            '%s/mfa-approval?token=%s&decision=approve',
            $frontendUrl,
            urlencode($challengeToken),
        );
        $denyUrl = sprintf(
            '%s/mfa-approval?token=%s&decision=deny',
            $frontendUrl,
            urlencode($challengeToken),
        );

        Cache::put($this->loginApprovalCacheKey($challengeToken), [
            'customer_id' => (int) $customer->c_userid,
            'status' => $status,
            'device' => $device,
            'platform' => $platform,
            'browser' => $browser,
            'ip_address' => $ipAddress,
            'location' => $location,
            'user_agent' => $userAgent,
            'responded_at' => is_array($existing) ? ($existing['responded_at'] ?? null) : null,
        ], now()->addMinutes(self::LOGIN_APPROVAL_TTL_MINUTES));

        try {
            Mail::mailer('resend')->to($email)->send(new PortalLoginApprovalMail(
                portalLabel: 'AF Home',
                email: $email,
                device: $device,
                platform: $platform,
                browser: $browser,
                location: $location,
                ipAddress: $ipAddress,
                approveUrl: $approveUrl,
                denyUrl: $denyUrl,
                expiresInMinutes: (string) self::LOGIN_APPROVAL_TTL_MINUTES,
            ));
        } catch (\Throwable $e) {
            report($e);
            throw ValidationException::withMessages([
                'login' => ['Unable to send sign-in approval email right now. Please try again shortly.'],
            ]);
        }
    }

    private function getLoginApprovalChallengeStatus(string $challengeToken, Customer $customer): string
    {
        $cached = Cache::get($this->loginApprovalCacheKey($challengeToken));
        if (! is_array($cached) || empty($cached['customer_id'])) {
            return 'expired';
        }

        if ((int) $cached['customer_id'] !== (int) $customer->c_userid) {
            return 'expired';
        }

        $status = (string) ($cached['status'] ?? 'pending');
        if (! in_array($status, ['pending', 'approved', 'denied'], true)) {
            return 'pending';
        }

        return $status;
    }

    private function consumeLoginApprovalChallenge(string $challengeToken): void
    {
        Cache::forget($this->loginApprovalCacheKey($challengeToken));
    }

    private function loginApprovalCacheKey(string $challengeToken): string
    {
        return 'customer:login-approval:' . $challengeToken;
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
            'a_country' => $this->normalizeAddressCountryValue($customer->c_country ?? null),
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

    private function normalizeAddressCountryValue(?string $country): string
    {
        $value = trim((string) $country);

        if ($value === '' || strcasecmp($value, 'philippines') === 0 || strtoupper($value) === 'PH') {
            return '175';
        }

        if (ctype_digit($value)) {
            return $value;
        }

        return '0';
    }

    private function notifyAdminsAboutNewRegistration(Customer $customer): void
    {
        $displayName = $this->fullName($customer);
        $joinedAt = $customer->c_date_started ?? now();

        $notification = AdminNotification::query()->firstOrCreate(
            [
                'an_type' => 'member_joined',
                'an_source_type' => 'customer',
                'an_source_id' => (int) $customer->c_userid,
            ],
            [
                'an_severity' => 'success',
                'an_title' => 'New Member Joined',
                'an_message' => sprintf(
                    '%s joined as a new member.',
                    $displayName !== '' ? $displayName : ('Member #' . (int) $customer->c_userid)
                ),
                'an_href' => '/admin/members',
                'an_payload' => [
                    'customer_id' => (int) $customer->c_userid,
                    'customer_name' => $displayName,
                    'customer_email' => (string) ($customer->c_email ?? ''),
                    'username' => (string) ($customer->c_username ?? ''),
                    'joined_at' => optional($joinedAt)->toDateTimeString(),
                ],
                'an_created_at' => $joinedAt,
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
                'type' => 'member_joined',
                'title' => (string) $notification->an_title,
                'description' => (string) $notification->an_message,
                'href' => (string) ($notification->an_href ?? '/admin/members'),
                'created_at' => optional($notification->an_created_at)->toDateTimeString(),
                'payload' => is_array($notification->an_payload) ? $notification->an_payload : null,
            ]);
        } catch (\Throwable $e) {
            Log::warning('Failed to publish admin realtime member registration notification.', [
                'customer_id' => (int) $customer->c_userid,
                'error' => $e->getMessage(),
            ]);
        }
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

    private function looksLikeEmailUsername(string $value): bool
    {
        $trimmed = trim($value);

        return $trimmed !== '' && str_contains($trimmed, '@');
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
