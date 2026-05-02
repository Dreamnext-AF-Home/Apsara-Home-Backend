<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AdminNotification;
use App\Models\Customer;
use App\Models\CustomerLoginSession;
use App\Models\CustomerAddress;
use App\Models\MemberActivityLog;
use App\Models\MemberTier;
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
use App\Support\TierEvaluator;
use App\Services\CloudinaryUploadService;
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
        return $this->handleRegistration($request, true);
    }

    public function mobileRegister(Request $request)
    {
        return $this->handleRegistration($request, false);
    }

    private function handleRegistration(Request $request, bool $requireTurnstile)
    {
        if ($requireTurnstile) {
            $turnstileToken = trim((string) $request->input('cf_turnstile_response', ''));
            if (!(new \App\Services\TurnstileService())->verifySignup($turnstileToken, (string) $request->ip())) {
                return response()->json(['message' => 'Bot verification failed.'], 422);
            }
        }

        $request->merge([
            'referred_by' => $this->normalizeReferralValue((string) $request->input('referred_by', '')),
        ]);

        $validated = $request->validate([
            'first_name'            => 'required|string|max:255',
            'last_name'             => 'required|string|max:255',
            'middle_name'           => 'nullable|string|max:255',
            'name'                  => 'required|string|max:255',
            'email'                 => ['required', 'email', Rule::unique('tbl_customer', 'c_email')],
            'username'              => ['required', 'string', 'max:255', 'regex:/^[A-Za-z0-9]+$/', Rule::unique('tbl_customer', 'c_username')],
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
            'username.regex' => 'Username must contain letters and numbers only.',
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
            TierEvaluator::evaluate($referrer);
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

        if (! preg_match('/^[A-Za-z0-9]+$/', $username)) {
            return response()->json([
                'available' => false,
                'message' => 'Username must contain letters and numbers only.',
            ]);
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

    public function checkReferralAvailability(Request $request)
    {
        $validated = $request->validate([
            'referred_by' => ['required', 'string', 'max:255'],
        ]);

        $referral = $this->normalizeReferralValue((string) $validated['referred_by']);

        if ($referral === '') {
            return response()->json([
                'available' => false,
                'message' => 'Referral code is required.',
            ], 422);
        }

        $referrer = Customer::query()
            ->select(['c_userid', 'c_username'])
            ->whereRaw('LOWER(c_username) = ?', [mb_strtolower($referral, 'UTF-8')])
            ->where('c_lockstatus', 0)
            ->first();

        return response()->json([
            'available' => $referrer instanceof Customer,
            'message' => $referrer instanceof Customer
                ? 'Referral code is valid.'
                : 'Referral code is invalid or referrer account is unavailable.',
            'normalized_referral' => $referral,
            'referrer_username' => $referrer instanceof Customer ? (string) ($referrer->c_username ?? '') : null,
        ]);
    }

    public function login(Request $request)
    {
        $turnstileToken = trim((string) $request->input('cf_turnstile_response', ''));
        if (!(new \App\Services\TurnstileService())->verifyLogin($turnstileToken, (string) $request->ip())) {
            return response()->json(['message' => 'Bot verification failed.'], 422);
        }

        return $this->handleLogin($request);
    }

    public function mobileLogin(Request $request)
    {
        return $this->handleLogin($request);
    }

    private function handleLogin(Request $request)
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
        $turnstileToken = trim((string) $request->input('cf_turnstile_response', ''));
        if (!(new \App\Services\TurnstileService())->verifyForgotPassword($turnstileToken, (string) $request->ip())) {
            return response()->json(['message' => 'Bot verification failed.'], 422);
        }

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
                    'mal_description' => 'Member logged out from all devices',
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

        return response()->json(['message' => 'Logged out successfully from all devices.']);
    }

    public function me(Request $request)
    {
        $customer = $request->user();

        if ($customer instanceof Customer) {
            TierEvaluator::evaluate($customer);
            $customer->refresh();
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
                'c_avatar_url',
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
            ->groupBy(fn (Customer $member) => (int) ($member->c_sponsor ?? 0));

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

        $customerId = (int) $customer->c_userid;

        $customerColumns = [
            'c_userid',
            'c_username',
            'c_fname',
            'c_mname',
            'c_lname',
            'c_email',
            'c_avatar_url',
            'c_accnt_status',
            'c_lockstatus',
            'c_totalincome',
            'c_gpv',
            'c_date_started',
            'c_sponsor',
        ];

        $levelOneMembers = Customer::query()
            ->select($customerColumns)
            ->where('c_sponsor', $customerId)
            ->orderByDesc('c_userid')
            ->get();

        $inferredDirectIds = $this->inferredDirectReferralIdsFromCheckouts($customerId);
        if (! empty($inferredDirectIds)) {
            $inferredMembers = Customer::query()
                ->select($customerColumns)
                ->whereIn('c_userid', $inferredDirectIds)
                ->get();

            $levelOneMembers = $levelOneMembers
                ->concat($inferredMembers)
                ->unique(fn (Customer $member) => (int) $member->c_userid)
                ->values();
        }

        $levelOneMembers = $levelOneMembers
            ->sortByDesc('c_userid')
            ->values();

        $levelOneIds = $levelOneMembers->pluck('c_userid')->all();

        $levelTwoMembers = empty($levelOneIds)
            ? collect()
            : $descendants
                ->filter(fn (Customer $member) => in_array((int) ($member->c_sponsor ?? 0), array_map('intval', $levelOneIds), true))
                ->values();

        $secondLevelCount = $levelTwoMembers->count();
        $directCount = $levelOneMembers->count();

        $children = $levelOneMembers
            ->map(fn (Customer $member): array => $buildNode($member))
            ->values();

        $countNodes = function (array $nodes) use (&$countNodes): int {
            $count = count($nodes);
            foreach ($nodes as $node) {
                $count += $countNodes($node['children'] ?? []);
            }
            return $count;
        };

        $totalNetwork = $countNodes($children->all());

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
            'first_name' => 'nullable|string|max:255',
            'middle_name' => 'nullable|string|max:255',
            'last_name' => 'nullable|string|max:255',
            'username' => [
                'nullable',
                'string',
                'max:255',
                Rule::unique('tbl_customer', 'c_username')->ignore($customer->c_userid, 'c_userid'),
            ],
            'phone' => 'nullable|string|max:20',
            'birth_date' => 'nullable|date',
            'gender' => 'nullable|in:male,female,other',
            'occupation' => 'nullable|string|max:155',
            'work_location' => 'nullable|in:local,overseas',
            'country' => 'nullable|string|max:45',
            'address' => 'nullable|string|max:500',
            'barangay' => 'nullable|string|max:255',
            'city' => 'nullable|string|max:255',
            'province' => 'nullable|string|max:255',
            'region' => 'nullable|string|max:255',
            'barangay_code' => 'nullable|string|max:20',
            'city_code' => 'nullable|string|max:20',
            'province_code' => 'nullable|string|max:20',
            'region_code' => 'nullable|string|max:20',
            'zip_code' => 'nullable|string|max:20',
            'avatar_url' => 'nullable|url|max:1200',
            'two_factor_enabled' => 'nullable|boolean',
        ]);

        if (array_key_exists('first_name', $validated) || array_key_exists('middle_name', $validated) || array_key_exists('last_name', $validated)) {
            $firstName = $validated['first_name'] ?? $customer->c_fname;
            $middleName = $validated['middle_name'] ?? $customer->c_mname;
            $lastName = $validated['last_name'] ?? $customer->c_lname;
        } else {
            [$firstName, $middleName, $lastName] = $this->splitName((string) $validated['name']);
        }

        if (array_key_exists('username', $validated) && $validated['username'] !== null && $this->looksLikeEmailUsername((string) $validated['username'])) {
            throw ValidationException::withMessages([
                'username' => ['Username must not be an email address. Please choose a username without @gmail.com, @yahoo.com, and similar email formats.'],
            ]);
        }

        $customer->c_fname = $firstName;
        if (array_key_exists('middle_name', $validated)) {
            $customer->c_mname = ($validated['middle_name'] ?? '') !== ''
                ? trim((string) $validated['middle_name'])
                : null;
        } else {
            $customer->c_mname = $middleName;
        }
        $customer->c_lname = $lastName;

        if (array_key_exists('username', $validated) && $validated['username'] !== null) {
            $customer->c_username = $validated['username'];
        }

        if (array_key_exists('phone', $validated) && $validated['phone'] !== null) {
            $customer->c_mobile = $validated['phone'];
        }

        if (array_key_exists('birth_date', $validated)) {
            $customer->c_bdate = $validated['birth_date'] ?: null;
        }

        if (array_key_exists('gender', $validated)) {
            $customer->c_gender = $this->mapGenderToInt($validated['gender'] ?? null);
        }

        if (array_key_exists('occupation', $validated)) {
            $customer->c_occupation = $validated['occupation'] ?: null;
        }

        if (array_key_exists('country', $validated)) {
            $customer->c_country = $validated['country'] ?: null;
        } elseif (
            array_key_exists('work_location', $validated)
            && ($validated['work_location'] ?? null) === 'local'
            && trim((string) ($customer->c_country ?? '')) === ''
        ) {
            $customer->c_country = 'Philippines';
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

        if (array_key_exists('barangay_code', $validated)) {
            $customer->c_barangay_code = $validated['barangay_code'] ?: null;
        }

        if (array_key_exists('city_code', $validated)) {
            $customer->c_city_code = $validated['city_code'] ?: null;
        }

        if (array_key_exists('province_code', $validated)) {
            $customer->c_province_code = $validated['province_code'] ?: null;
        }

        if (array_key_exists('region_code', $validated)) {
            $customer->c_region_code = $validated['region_code'] ?: null;
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

    public function uploadAvatar(Request $request, CloudinaryUploadService $cloudinary)
    {
        /** @var Customer $customer */
        $customer = $request->user();

        $validated = $request->validate([
            'file' => 'required|image|mimes:jpeg,jpg,png,webp,gif|max:5120',
        ]);

        try {
            $upload = $cloudinary->uploadImage($validated['file'], 'apsara/profile');
            $avatarUrl = (string) ($upload['secure_url'] ?? '');

            if ($avatarUrl === '') {
                return response()->json(['message' => 'Profile photo upload returned no image URL.'], 422);
            }

            $customer->c_avatar_url = $avatarUrl;
            $customer->save();

            return response()->json([
                'message' => 'Profile photo updated successfully.',
                'avatar_url' => $avatarUrl,
                'user' => $this->transformCustomer($customer),
            ]);
        } catch (\Throwable $exception) {
            report($exception);

            return response()->json([
                'message' => $exception->getMessage() ?: 'Failed to upload profile photo.',
            ], 422);
        }
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
            'username' => ['required', 'string', 'max:120', 'regex:/^[A-Za-z0-9]+$/'],
        ], [
            'username.regex' => 'Username must contain letters and numbers only.',
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

        $rank = (int) ($customer->c_rank ?? 0);
        $badgeName = MemberTier::getTierNameByRank($rank);

        return [
            'id' => (int) $customer->c_userid,
            'name' => $fullName,
            'first_name' => (string) ($customer->c_fname ?? ''),
            'last_name' => (string) ($customer->c_lname ?? ''),
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
            'barangay_code' => (string) ($customer->c_barangay_code ?? ''),
            'city_code' => (string) ($customer->c_city_code ?? ''),
            'province_code' => (string) ($customer->c_province_code ?? ''),
            'region_code' => (string) ($customer->c_region_code ?? ''),
            'zip_code' => (string) ($customer->c_zipcode ?? ''),
            'middle_name' => ($middleName = trim((string) ($customer->c_mname ?? ''))) !== '' ? $middleName : null,
            'birth_date' => $this->formatNullableDate($customer->c_bdate ?? null),
            'gender' => $this->mapIntToGender((int) ($customer->c_gender ?? 0)),
            'occupation' => ($occupation = trim((string) ($customer->c_occupation ?? ''))) !== '' ? $occupation : null,
            'work_location' => $this->inferWorkLocation($customer->c_country ?? null),
            'country' => ($country = trim((string) ($customer->c_country ?? ''))) !== '' ? $country : null,
            'avatar_url' => $customer->c_avatar_url,
            'rank' => $rank,
            'badge' => $rank,
            'badge_name' => $badgeName,
            'account_status' => $accountStatus,
            'lock_status' => $lockStatus,
            'verification_status' => $verificationStatus,
            'monthly_activation' => MemberMonthlyActivation::summary($customer),
            'profile_complete' => $this->isCustomerProfileComplete($customer),
            'profile_completion_percentage' => $this->customerProfileCompletionPercentage($customer),
            'email_verified' => true,
            'password_change_required' => $this->customerRequiresPasswordChange($customer),
            'two_factor_enabled' => (bool) ($customer->c_two_factor_enabled ?? false),
            'totp_enabled' => (bool) ($customer->c_totp_enabled ?? false),
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
            'avatar_url' => (string) ($customer->c_avatar_url ?? ''),
            'joined_at' => (string) ($customer->c_date_started ?? ''),
            'total_earnings' => (float) ($customer->c_totalincome ?? 0),
            'total_pv' => (float) ($customer->c_gpv ?? 0),
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

    private function mapIntToGender(int $gender): ?string
    {
        return match ($gender) {
            1 => 'male',
            2 => 'female',
            3 => 'other',
            default => null,
        };
    }

    private function mapGenderFromInt(mixed $gender): ?string
    {
        if ($gender === null || $gender === '') {
            return null;
        }

        return $this->mapIntToGender((int) $gender);
    }

    private function formatNullableDate(mixed $value): ?string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        $stringValue = trim((string) $value);
        if ($stringValue === '') {
            return null;
        }

        $timestamp = strtotime($stringValue);
        if ($timestamp === false) {
            return $stringValue;
        }

        return date('Y-m-d', $timestamp);
    }

    private function inferWorkLocation(?string $country): ?string
    {
        $value = trim((string) $country);
        if ($value === '') {
            return null;
        }

        if (
            strcasecmp($value, 'philippines') === 0
            || strtoupper($value) === 'PH'
            || $value === '175'
            || strcasecmp($value, 'local') === 0
        ) {
            return 'local';
        }

        return 'overseas';
    }

    private function customerProfileCompletionPercentage(Customer $customer): int
    {
        $country = trim((string) ($customer->c_country ?? ''));
        $occupation = trim((string) ($customer->c_occupation ?? ''));
        $phone = trim((string) ($customer->c_mobile ?? ''));

        $checks = [
            trim($this->fullName($customer)) !== '',
            trim((string) ($customer->c_email ?? '')) !== '',
            $phone !== '' && $phone !== '0',
            trim((string) ($customer->c_username ?? '')) !== '',
            $this->formatNullableDate($customer->c_bdate ?? null) !== null,
            $this->mapIntToGender((int) ($customer->c_gender ?? 0)) !== null,
            $occupation !== '' && strcasecmp($occupation, 'none') !== 0,
            $this->inferWorkLocation($country) !== null,
            $country !== '',
        ];

        return (int) round((count(array_filter($checks)) / count($checks)) * 100);
    }

    private function isCustomerProfileComplete(Customer $customer): bool
    {
        return $this->customerProfileCompletionPercentage($customer) >= 100;
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

    private function inferredDirectReferralIdsFromCheckouts(int $customerId): array
    {
        if (
            $customerId <= 0
            || !Schema::hasTable('tbl_checkout_history')
            || !Schema::hasColumn('tbl_checkout_history', 'ch_referrer_customer_id')
            || !Schema::hasColumn('tbl_checkout_history', 'ch_customer_id')
        ) {
            return [];
        }

        return DB::table('tbl_checkout_history')
            ->where('ch_referrer_customer_id', $customerId)
            ->whereNotNull('ch_customer_id')
            ->where('ch_customer_id', '<>', 0)
            ->where('ch_customer_id', '<>', $customerId)
            ->distinct()
            ->pluck('ch_customer_id')
            ->map(fn ($id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->values()
            ->all();
    }

    // ========================
    // Social Authentication (OAuth)
    // ========================

    public function redirectToProvider(string $provider)
    {
        $allowedProviders = ['google', 'facebook'];

        if (!in_array($provider, $allowedProviders, true)) {
            return response()->json(['message' => 'Invalid provider.'], 400);
        }

        $config = config("services.{$provider}");

        if (!$config || empty($config['client_id']) || empty($config['client_secret'])) {
            return response()->json(['message' => 'OAuth not configured for this provider.'], 500);
        }

        $state = bin2hex(random_bytes(32));
        $nonce = bin2hex(random_bytes(16));

        Cache::put("oauth_state:{$state}", [
            'provider' => $provider,
            'nonce' => $nonce,
            'created_at' => now()->toIso8601String(),
        ], now()->addMinutes(10));

        $baseUrls = [
            'google' => 'https://accounts.google.com/o/oauth2/v2/auth',
            'facebook' => 'https://www.facebook.com/v18.0/dialog/oauth',
        ];

        $scopes = [
            'google' => 'openid email profile',
            'facebook' => 'email public_profile',
        ];

        $params = [
            'client_id' => $config['client_id'],
            'redirect_uri' => $config['redirect'],
            'response_type' => 'code',
            'scope' => $scopes[$provider],
            'state' => $state,
        ];

        if ($provider === 'google') {
            $params['nonce'] = $nonce;
            $params['access_type'] = 'offline';
            $params['prompt'] = 'consent';
        }

        $url = $baseUrls[$provider] . '?' . http_build_query($params);

        return response()->json([
            'redirect_url' => $url,
            'state' => $state,
        ]);
    }

    public function handleProviderCallback(Request $request, string $provider)
    {
        $allowedProviders = ['google', 'facebook'];

        if (!in_array($provider, $allowedProviders, true)) {
            return response()->json(['message' => 'Invalid provider.'], 400);
        }

        $validated = $request->validate([
            'code' => 'required|string',
            'state' => 'required|string',
        ]);

        $stateData = Cache::get("oauth_state:{$validated['state']}");

        if (!$stateData || ($stateData['provider'] ?? '') !== $provider) {
            return response()->json(['message' => 'Invalid or expired state.'], 400);
        }

        Cache::forget("oauth_state:{$validated['state']}");

        $config = config("services.{$provider}");

        try {
            $tokenResponse = $this->exchangeCodeForToken($provider, $validated['code'], $config);

            if (!$tokenResponse || empty($tokenResponse['access_token'])) {
                return response()->json(['message' => 'Failed to obtain access token.'], 400);
            }

            $userInfo = $this->getUserInfoFromProvider($provider, $tokenResponse['access_token']);

            if (!$userInfo || empty($userInfo['email'])) {
                return response()->json(['message' => 'Failed to obtain user information.'], 400);
            }

            return $this->processSocialLogin($provider, $userInfo, $tokenResponse, $request);
        } catch (\Throwable $e) {
            Log::error('OAuth callback error', [
                'provider' => $provider,
                'error' => $e->getMessage(),
            ]);

            return response()->json(['message' => 'Authentication failed.'], 500);
        }
    }

    private function exchangeCodeForToken(string $provider, string $code, array $config): ?array
    {
        $tokenUrls = [
            'google' => 'https://oauth2.googleapis.com/token',
            'facebook' => 'https://graph.facebook.com/v18.0/oauth/access_token',
        ];

        $params = [
            'client_id' => $config['client_id'],
            'client_secret' => $config['client_secret'],
            'code' => $code,
            'redirect_uri' => $config['redirect'],
            'grant_type' => 'authorization_code',
        ];

        if ($provider === 'facebook') {
            unset($params['grant_type']);
        }

        $httpClient = new \GuzzleHttp\Client();

        $response = $httpClient->post($tokenUrls[$provider], [
            'form_params' => $params,
            'timeout' => 30,
        ]);

        return json_decode($response->getBody()->getContents(), true);
    }

    private function getUserInfoFromProvider(string $provider, string $accessToken): ?array
    {
        $httpClient = new \GuzzleHttp\Client();

        if ($provider === 'google') {
            $response = $httpClient->get('https://openidconnect.googleapis.com/v1/userinfo', [
                'headers' => ['Authorization' => "Bearer {$accessToken}"],
                'timeout' => 30,
            ]);

            $data = json_decode($response->getBody()->getContents(), true);

            return [
                'id' => $data['sub'] ?? null,
                'email' => $data['email'] ?? null,
                'name' => $data['name'] ?? null,
                'given_name' => $data['given_name'] ?? null,
                'family_name' => $data['family_name'] ?? null,
                'picture' => $data['picture'] ?? null,
                'verified' => $data['email_verified'] ?? false,
            ];
        }

        if ($provider === 'facebook') {
            $response = $httpClient->get('https://graph.facebook.com/v18.0/me', [
                'query' => [
                    'access_token' => $accessToken,
                    'fields' => 'id,name,email,first_name,last_name,picture',
                ],
                'timeout' => 30,
            ]);

            $data = json_decode($response->getBody()->getContents(), true);

            return [
                'id' => $data['id'] ?? null,
                'email' => $data['email'] ?? null,
                'name' => $data['name'] ?? null,
                'given_name' => $data['first_name'] ?? null,
                'family_name' => $data['last_name'] ?? null,
                'picture' => $data['picture']['data']['url'] ?? null,
                'verified' => true,
            ];
        }

        return null;
    }

    private function processSocialLogin(string $provider, array $userInfo, array $tokenResponse, Request $request)
    {
        $email = strtolower(trim((string) ($userInfo['email'] ?? '')));
        $providerId = (string) ($userInfo['id'] ?? '');

        if ($email === '' || $providerId === '') {
            return response()->json(['message' => 'Invalid user information received.'], 400);
        }

        // Check if this social account is already linked
        $existingSocial = \App\Models\CustomerSocialAccount::query()
            ->where('csa_provider', $provider)
            ->where('csa_provider_id', $providerId)
            ->first();

        if ($existingSocial) {
            $customer = Customer::query()->where('c_userid', $existingSocial->csa_customer_id)->first();

            if ($customer) {
                // Update tokens
                $existingSocial->update([
                    'csa_token' => $tokenResponse['access_token'] ?? null,
                    'csa_refresh_token' => $tokenResponse['refresh_token'] ?? $existingSocial->csa_refresh_token,
                    'csa_token_expires_at' => isset($tokenResponse['expires_in'])
                        ? now()->addSeconds((int) $tokenResponse['expires_in'])
                        : null,
                    'csa_provider_data' => $userInfo,
                ]);

                return $this->completeSocialLogin($customer, $request);
            }
        }

        // Check if customer exists with this email
        $customer = Customer::query()
            ->whereRaw('LOWER(c_email) = ?', [$email])
            ->first();

        if ($customer) {
            // Link social account to existing customer
            \App\Models\CustomerSocialAccount::create([
                'csa_customer_id' => $customer->c_userid,
                'csa_provider' => $provider,
                'csa_provider_id' => $providerId,
                'csa_token' => $tokenResponse['access_token'] ?? null,
                'csa_refresh_token' => $tokenResponse['refresh_token'] ?? null,
                'csa_token_expires_at' => isset($tokenResponse['expires_in'])
                    ? now()->addSeconds((int) $tokenResponse['expires_in'])
                    : null,
                'csa_provider_data' => $userInfo,
            ]);

            return $this->completeSocialLogin($customer, $request);
        }

        // Create new customer account
        $customer = DB::transaction(function () use ($email, $userInfo) {
            if (DB::connection()->getDriverName() === 'pgsql') {
                DB::statement('LOCK TABLE tbl_customer IN EXCLUSIVE MODE');
            }

            $nextCustomerId = ((int) DB::table('tbl_customer')->whereNotNull('c_userid')->max('c_userid')) + 1;
            $username = $this->generateUniqueUsernameFromEmail($email);

            return Customer::create([
                'c_userid' => $nextCustomerId,
                'c_fname' => $userInfo['given_name'] ?? null,
                'c_lname' => $userInfo['family_name'] ?? null,
                'c_username' => $username,
                'c_email' => $email,
                'c_mobile' => '0',
                'c_password' => \Illuminate\Support\Facades\Hash::make(\Illuminate\Support\Str::random(32)),
                'c_password_pin' => '',
                'c_password_change_required' => false,
                'c_rank' => 0,
                'c_accnt_status' => 0,
                'c_lockstatus' => 0,
                'c_sponsor' => 0,
                'c_date_started' => now(),
            ]);
        });

        // Create social account link
        \App\Models\CustomerSocialAccount::create([
            'csa_customer_id' => $customer->c_userid,
            'csa_provider' => $provider,
            'csa_provider_id' => $providerId,
            'csa_token' => $tokenResponse['access_token'] ?? null,
            'csa_refresh_token' => $tokenResponse['refresh_token'] ?? null,
            'csa_token_expires_at' => isset($tokenResponse['expires_in'])
                ? now()->addSeconds((int) $tokenResponse['expires_in'])
                : null,
            'csa_provider_data' => $userInfo,
        ]);

        return $this->completeSocialLogin($customer, $request);
    }

    private function generateUniqueUsernameFromEmail(string $email): string
    {
        $base = preg_replace('/[^a-zA-Z0-9]/', '', explode('@', $email)[0] ?? 'user');
        $base = strtolower(substr($base, 0, 20));
        $username = $base;
        $counter = 1;

        while (Customer::query()->where('c_username', $username)->exists()) {
            $suffix = random_int(1000, 9999);
            $username = substr($base, 0, 16) . $suffix;
            $counter++;

            if ($counter > 10) {
                $username = 'user' . time() . random_int(1000, 9999);
                break;
            }
        }

        return $username;
    }

    private function completeSocialLogin(Customer $customer, Request $request)
    {
        if ((int) ($customer->c_lockstatus ?? 0) === 1) {
            return response()->json([
                'message' => 'Your account has been banned. Please contact support.',
                'reason' => 'banned',
            ], 403);
        }

        $tokenResult = $customer->createToken('auth_token');
        $token = $tokenResult->plainTextToken;
        $plainTokenId = (int) ($tokenResult->accessToken->id ?? 0);

        try {
            $this->recordLoginSession($customer, $request, $plainTokenId > 0 ? $plainTokenId : null);
        } catch (\Throwable $e) {
            report($e);
        }

        try {
            MemberActivityLog::create([
                'mal_customer_id' => (int) $customer->c_userid,
                'mal_activity_type' => 'login',
                'mal_action' => 'create',
                'mal_description' => 'Member logged in via social authentication',
                'mal_resource_type' => 'account',
                'mal_resource_id' => (int) $customer->c_userid,
                'mal_ip_address' => $request->ip(),
                'mal_user_agent' => $request->userAgent(),
                'mal_created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            // non-fatal
        }

        return response()->json([
            'user' => $this->transformCustomer($customer),
            'token' => $token,
            'message' => 'Login successful.',
        ]);
    }

    public function linkSocialAccount(Request $request, string $provider)
    {
        $allowedProviders = ['google', 'facebook'];

        if (!in_array($provider, $allowedProviders, true)) {
            return response()->json(['message' => 'Invalid provider.'], 400);
        }

        $validated = $request->validate([
            'provider_id' => 'required|string',
            'token' => 'required|string',
            'email' => 'required|email',
            'name' => 'nullable|string',
        ]);

        /** @var Customer $customer */
        $customer = $request->user();

        // Check if this specific provider account is already linked to anyone
        $existing = \App\Models\CustomerSocialAccount::query()
            ->where('csa_provider', $provider)
            ->where('csa_provider_id', $validated['provider_id'])
            ->first();

        if ($existing) {
            if ((int) $existing->csa_customer_id === (int) $customer->c_userid) {
                return response()->json(['message' => 'Account already linked.'], 200);
            }

            return response()->json(['message' => 'This social account is linked to another user.'], 409);
        }

        // Check if customer already has a different account for this provider linked
        $existingForProvider = \App\Models\CustomerSocialAccount::query()
            ->where('csa_customer_id', $customer->c_userid)
            ->where('csa_provider', $provider)
            ->first();

        if ($existingForProvider) {
            return response()->json([
                'message' => 'You already have a ' . ucfirst($provider) . ' account linked. Unlink it first before linking a different one.',
            ], 409);
        }

        // Verify the token with provider (basic check)
        $userInfo = $this->getUserInfoFromProvider($provider, $validated['token']);

        if (!$userInfo || (string) $userInfo['id'] !== $validated['provider_id']) {
            return response()->json(['message' => 'Invalid token or provider ID.'], 400);
        }

        // Validate that the social account email matches the user's account email
        $socialEmail = strtolower(trim((string) ($userInfo['email'] ?? '')));
        $userEmail = strtolower(trim((string) ($customer->c_email ?? '')));

        if ($socialEmail !== $userEmail) {
            return response()->json([
                'message' => 'Email mismatch. The ' . ucfirst($provider) . ' account email does not match your account email.',
                'social_email' => $socialEmail,
                'account_email' => $userEmail,
            ], 400);
        }

        // Create social account link
        \App\Models\CustomerSocialAccount::create([
            'csa_customer_id' => $customer->c_userid,
            'csa_provider' => $provider,
            'csa_provider_id' => $validated['provider_id'],
            'csa_token' => $validated['token'],
            'csa_provider_data' => $userInfo,
        ]);

        return response()->json([
            'message' => ucfirst($provider) . ' account linked successfully.',
        ]);
    }

    public function unlinkSocialAccount(Request $request, string $provider)
    {
        $allowedProviders = ['google', 'facebook'];

        if (!in_array($provider, $allowedProviders, true)) {
            return response()->json(['message' => 'Invalid provider.'], 400);
        }

        /** @var Customer $customer */
        $customer = $request->user();

        $deleted = \App\Models\CustomerSocialAccount::query()
            ->where('csa_customer_id', $customer->c_userid)
            ->where('csa_provider', $provider)
            ->delete();

        if ($deleted === 0) {
            return response()->json(['message' => 'No linked account found.'], 404);
        }

        return response()->json([
            'message' => ucfirst($provider) . ' account unlinked successfully.',
        ]);
    }

    public function getLinkedAccounts(Request $request)
    {
        /** @var Customer $customer */
        $customer = $request->user();

        $accounts = \App\Models\CustomerSocialAccount::query()
            ->where('csa_customer_id', $customer->c_userid)
            ->get(['csa_provider', 'created_at'])
            ->map(function ($account) {
                return [
                    'provider' => $account->csa_provider,
                    'linked_at' => $account->created_at->toIso8601String(),
                ];
            });

        return response()->json([
            'accounts' => $accounts,
        ]);
    }

    // ========================
    // Simplified Google Login
    // ========================

    public function googleLogin(Request $request)
    {
        $validated = $request->validate([
            'id_token' => 'required|string',
        ]);

        try {
            // Decode the JWT ID token (basic verification without Google Client)
            $tokenParts = explode('.', $validated['id_token']);
            
            if (count($tokenParts) !== 3) {
                return response()->json(['message' => 'Invalid ID token format.'], 400);
            }

            // Decode the payload
            $payload = json_decode(base64_decode(str_replace(['-', '_'], ['+', '/'], $tokenParts[1])), true);

            if (!$payload) {
                return response()->json(['message' => 'Failed to decode ID token.'], 400);
            }

            // Basic validation
            if (empty($payload['email']) || empty($payload['sub'])) {
                return response()->json(['message' => 'Invalid ID token payload.'], 400);
            }

            // Check if token is expired
            if (isset($payload['exp']) && $payload['exp'] < time()) {
                return response()->json(['message' => 'ID token has expired.'], 400);
            }

            // Verify audience (your Google Client ID)
            if (isset($payload['aud']) && $payload['aud'] !== config('services.google.client_id')) {
                return response()->json(['message' => 'Invalid token audience.'], 400);
            }

            // Extract user information
            $email = strtolower($payload['email']);
            $googleId = $payload['sub'];
            $name = $payload['name'] ?? null;
            $firstName = $payload['given_name'] ?? null;
            $lastName = $payload['family_name'] ?? null;
            $picture = $payload['picture'] ?? null;

            // Check if this Google account is already linked
            $existingSocial = \App\Models\CustomerSocialAccount::query()
                ->where('csa_provider', 'google')
                ->where('csa_provider_id', $googleId)
                ->first();

            if ($existingSocial) {
                $customer = Customer::query()->where('c_userid', $existingSocial->csa_customer_id)->first();

                if ($customer) {
                    // Update provider data
                    $existingSocial->update([
                        'csa_provider_data' => [
                            'id' => $googleId,
                            'email' => $email,
                            'name' => $name,
                            'given_name' => $firstName,
                            'family_name' => $lastName,
                            'picture' => $picture,
                            'verified' => $payload['email_verified'] ?? false,
                        ],
                    ]);

                    return $this->completeSocialLogin($customer, $request);
                }
            }

            // Check if customer exists with this email
            $customer = Customer::query()
                ->whereRaw('LOWER(c_email) = ?', [$email])
                ->first();

            if ($customer) {
                // Link Google account to existing customer
                \App\Models\CustomerSocialAccount::create([
                    'csa_customer_id' => $customer->c_userid,
                    'csa_provider' => 'google',
                    'csa_provider_id' => $googleId,
                    'csa_token' => $validated['id_token'],
                    'csa_provider_data' => [
                        'id' => $googleId,
                        'email' => $email,
                        'name' => $name,
                        'given_name' => $firstName,
                        'family_name' => $lastName,
                        'picture' => $picture,
                        'verified' => $payload['email_verified'] ?? false,
                    ],
                ]);

                return $this->completeSocialLogin($customer, $request);
            }

            // Create new customer account
            $customer = DB::transaction(function () use ($email, $firstName, $lastName) {
                if (DB::connection()->getDriverName() === 'pgsql') {
                    DB::statement('LOCK TABLE tbl_customer IN EXCLUSIVE MODE');
                }

                $nextCustomerId = ((int) DB::table('tbl_customer')->whereNotNull('c_userid')->max('c_userid')) + 1;
                $username = $this->generateUniqueUsernameFromEmail($email);

                return Customer::create([
                    'c_userid' => $nextCustomerId,
                    'c_fname' => $firstName,
                    'c_lname' => $lastName,
                    'c_username' => $username,
                    'c_email' => $email,
                    'c_mobile' => '0',
                    'c_password' => \Illuminate\Support\Facades\Hash::make(\Illuminate\Support\Str::random(32)),
                    'c_password_pin' => '',
                    'c_password_change_required' => false,
                    'c_rank' => 0,
                    'c_accnt_status' => 0,
                    'c_lockstatus' => 0,
                    'c_sponsor' => 0,
                    'c_date_started' => now(),
                ]);
            });

            // Create social account link
            \App\Models\CustomerSocialAccount::create([
                'csa_customer_id' => $customer->c_userid,
                'csa_provider' => 'google',
                'csa_provider_id' => $googleId,
                'csa_token' => $validated['id_token'],
                'csa_provider_data' => [
                    'id' => $googleId,
                    'email' => $email,
                    'name' => $name,
                    'given_name' => $firstName,
                    'family_name' => $lastName,
                    'picture' => $picture,
                    'verified' => $payload['email_verified'] ?? false,
                ],
            ]);

            return $this->completeSocialLogin($customer, $request);

        } catch (\Throwable $e) {
            return response()->json(['message' => 'Authentication failed.'], 500);
        }
    }

    public function googleCallback(Request $request)
    {
        $validated = $request->validate([
            'id_token' => 'required|string',
        ]);

        try {
            $token = $validated['id_token'];
            $tokenParts = explode('.', $token);
            
            // Check if it's an access token (starts with 'ya29.') or ID token (JWT with 3 parts)
            if (strpos($token, 'ya29.') === 0) {
                // This is an access token, use Google API to get user info
                $response = \Http::get('https://www.googleapis.com/oauth2/v2/userinfo', [
                    'access_token' => $token
                ]);

                if (!$response->successful()) {
                    return response()->json(['message' => 'Invalid access token.'], 400);
                }

                $userInfo = $response->json();
                
                if (empty($userInfo['email'])) {
                    return response()->json(['message' => 'Failed to get user information.'], 400);
                }

                // Extract user information from API response
                $email = strtolower($userInfo['email']);
                $googleId = $userInfo['id'];
                $name = $userInfo['name'] ?? null;
                $firstName = $userInfo['given_name'] ?? null;
                $lastName = $userInfo['family_name'] ?? null;
                $picture = $userInfo['picture'] ?? null;
                
            } elseif (count($tokenParts) === 3) {
                // This is an ID token (JWT), decode it

                // Decode the payload
                $payload = json_decode(base64_decode(str_replace(['-', '_'], ['+', '/'], $tokenParts[1])), true);

                if (!$payload) {
                    return response()->json(['message' => 'Failed to decode ID token.'], 400);
                }

                // Basic validation
                if (empty($payload['email']) || empty($payload['sub'])) {
                    return response()->json(['message' => 'Invalid ID token payload.'], 400);
                }

                // Check if token is expired
                if (isset($payload['exp']) && $payload['exp'] < time()) {
                    return response()->json(['message' => 'ID token has expired.'], 400);
                }

                // Extract user information from ID token
                $email = strtolower($payload['email']);
                $googleId = $payload['sub'];
                $name = $payload['name'] ?? null;
                $firstName = $payload['given_name'] ?? null;
                $lastName = $payload['family_name'] ?? null;
                $picture = $payload['picture'] ?? null;
                
            } else {
                return response()->json(['message' => 'Invalid token format.'], 400);
            }

            $socialAccount = \App\Models\CustomerSocialAccount::query()
                ->where('csa_provider', 'google')
                ->where('csa_provider_id', $googleId)
                ->first();

            if (!$socialAccount) {
                return response()->json([
                    'message' => 'No Google account found. Please link your Google account first.',
                    'error' => 'social_account_not_found'
                ], 401);
            }

            $customer = Customer::query()->where('c_userid', $socialAccount->csa_customer_id)->first();

            if (!$customer) {
                return response()->json(['message' => 'Customer account not found.'], 401);
            }

            // Update the social account with latest data
            $socialAccount->update([
                'csa_token' => $validated['id_token'],
                'csa_provider_data' => [
                    'id' => $googleId,
                    'email' => $email,
                    'name' => $name,
                    'given_name' => $firstName,
                    'family_name' => $lastName,
                    'picture' => $picture,
                    'verified' => $payload['email_verified'] ?? false,
                ],
            ]);

            return $this->completeSocialLogin($customer, $request);

        } catch (\Throwable $e) {
            return response()->json(['message' => 'Authentication failed.'], 500);
        }
    }

    public function facebookCallback(Request $request)
    {
        $validated = $request->validate([
            'access_token' => 'required|string',
            'provider_id'  => 'required|string',
        ]);

        try {
            $accessToken = $validated['access_token'];
            $providerId  = $validated['provider_id'];

            $response = \Http::get('https://graph.facebook.com/v18.0/me', [
                'fields'       => 'id,name,email,first_name,last_name',
                'access_token' => $accessToken,
            ]);

            if (!$response->successful()) {
                return response()->json(['message' => 'Invalid Facebook access token.'], 400);
            }

            $userInfo = $response->json();

            if (!empty($userInfo['error'])) {
                return response()->json(['message' => 'Invalid Facebook access token.'], 400);
            }

            $facebookId = $userInfo['id'] ?? null;

            if (!$facebookId || $facebookId !== $providerId) {
                return response()->json(['message' => 'Facebook token verification failed.'], 400);
            }

            $socialAccount = \App\Models\CustomerSocialAccount::query()
                ->where('csa_provider', 'facebook')
                ->where('csa_provider_id', $facebookId)
                ->first();

            if (!$socialAccount) {
                return response()->json([
                    'message' => 'No Facebook account found. Please link your Facebook account first.',
                    'error'   => 'social_account_not_found',
                ], 401);
            }

            $customer = Customer::query()->where('c_userid', $socialAccount->csa_customer_id)->first();

            if (!$customer) {
                return response()->json(['message' => 'Customer account not found.'], 401);
            }

            $socialAccount->update([
                'csa_token'         => $accessToken,
                'csa_provider_data' => $userInfo,
            ]);

            return $this->completeSocialLogin($customer, $request);

        } catch (\Throwable $e) {
            return response()->json(['message' => 'Authentication failed.'], 500);
        }
    }

    public function facebookDataDeletion(Request $request)
    {
        $signedRequest = $request->input('signed_request');

        if (!$signedRequest || !str_contains($signedRequest, '.')) {
            return response()->json(['error' => 'Missing or invalid signed_request.'], 400);
        }

        [$encodedSig, $payload] = explode('.', $signedRequest, 2);

        $data = json_decode(base64_decode(strtr($payload, '-_', '+/')), true);
        $facebookUserId = $data['user_id'] ?? null;

        if ($facebookUserId) {
            \App\Models\CustomerSocialAccount::query()
                ->where('csa_provider', 'facebook')
                ->where('csa_provider_id', (string) $facebookUserId)
                ->delete();
        }

        $confirmationCode = 'fbdel_' . ($facebookUserId ?? uniqid());

        return response()->json([
            'url' => url('/api/auth/facebook/data-deletion/status?id=' . $confirmationCode),
            'confirmation_code' => $confirmationCode,
        ]);
    }

    /**
     * Facebook data deletion status endpoint (required by Facebook Platform Policy)
     */
    public function facebookDataDeletionStatus(Request $request)
    {
        $confirmationId = $request->query('id');

        if (!$confirmationId) {
            return response()->json(['error' => 'Missing confirmation ID.'], 400);
        }

        return response()->json([
            'id' => $confirmationId,
            'status' => 'deleted',
            'deletion_time' => now()->toIso8601String(),
        ]);
    }

    public function accountSnapshot(Request $request)
    {
        $customer = $request->user();
        if (!$customer) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $customerId = (int) $customer->getAuthIdentifier();

        try {
            // Get Orders Summary
            $orders = DB::table('tbl_checkout_history')
                ->where('ch_customer_id', $customerId)
                ->orderByDesc('ch_paid_at')
                ->orderByDesc('ch_id')
                ->get();

            $ordersSummary = [
                'total' => $orders->count(),
                'pending' => $orders->whereIn('ch_status', ['pending', 'pending_approval'])->count(),
                'paid' => $orders->whereIn('ch_status', ['paid', 'succeeded', 'success'])->count(),
                'shipped' => $orders->where('ch_fulfillment_status', 'shipped')->count(),
                'delivered' => $orders->where('ch_fulfillment_status', 'delivered')->count(),
                'completed' => $orders->whereIn('ch_status', ['completed', 'shipped'])->count(),
                'total_spent' => (float) $orders->whereIn('ch_status', ['paid', 'completed', 'shipped'])->sum('ch_amount'),
                'recent_orders' => $orders->take(5)->map(function ($order) {
                    $status = $order->ch_fulfillment_status
                        ? (string) $order->ch_fulfillment_status
                        : $this->mapCheckoutStatusToOrderStatus((string) $order->ch_status);

                    return [
                        'id' => (int) $order->ch_id,
                        'order_number' => $order->ch_checkout_id,
                        'status' => $status,
                        'product_name' => $order->ch_product_name ?: $order->ch_description,
                        'amount' => (float) $order->ch_amount,
                        'date' => $order->ch_paid_at ? (is_string($order->ch_paid_at) ? $order->ch_paid_at : $order->ch_paid_at->format('Y-m-d H:i:s')) : null,
                        'image' => $order->ch_product_image ?: '/Images/HeroSection/sofas.jpg',
                    ];
                })->toArray()
            ];

            // Get Wishlist Summary
            $wishlistItems = DB::table('tbl_customer_wishlist')
                ->where('cw_customer_id', $customerId)
                ->count();

            // Get Customer Reviews
            $customerReviews = DB::table('tbl_product_reviews as r')
                ->join('tbl_product as p', 'p.pd_id', '=', 'r.pr_product_id')
                ->where('r.pr_customer_id', $customerId)
                ->orderByDesc('r.created_at')
                ->get([
                    'r.pr_id',
                    'r.pr_product_id',
                    'r.pr_rating',
                    'r.pr_review',
                    'r.created_at',
                    'p.pd_name',
                    'p.pd_image',
                ]);

            $reviewsSummary = [
                'total' => $customerReviews->count(),
                'average_rating' => $customerReviews->count() > 0 
                    ? round($customerReviews->sum('pr_rating') / $customerReviews->count(), 2)
                    : 0,
                'recent_reviews' => $customerReviews->take(5)->map(function ($review) {
                    return [
                        'id' => (int) $review->pr_id,
                        'product_id' => (int) $review->pr_product_id,
                        'product_name' => $review->pd_name,
                        'rating' => (int) $review->pr_rating,
                        'review' => $review->pr_review,
                        'date' => is_string($review->created_at) ? $review->created_at : $review->created_at->format('Y-m-d H:i:s'),
                        'product_image' => $review->pd_image ?: '/Images/HeroSection/sofas.jpg',
                    ];
                })->toArray()
            ];

            // Get Loyalty/Tier Information
            $tier = $this->mapCustomerTier((int) ($customer->c_rank ?? 0));
            $referralColumns = [
                'c_userid',
                'c_username',
                'c_fname',
                'c_mname',
                'c_lname',
                'c_email',
                'c_avatar_url',
                'c_accnt_status',
                'c_lockstatus',
                'c_totalincome',
                'c_gpv',
                'c_date_started',
                'c_sponsor',
            ];
            $referralMembers = Customer::query()
                ->select($referralColumns)
                ->orderBy('c_userid')
                ->get();
            $referralMembersBySponsor = $referralMembers
                ->filter(fn (Customer $member) => (int) ($member->c_sponsor ?? 0) > 0)
                ->groupBy(fn (Customer $member) => (int) ($member->c_sponsor ?? 0));
            $buildReferralSnapshotNode = function (Customer $member, array $path = []) use (&$buildReferralSnapshotNode, $referralMembersBySponsor): array {
                $memberId = (int) $member->c_userid;
                $nextPath = [...$path, $memberId];

                $children = collect($referralMembersBySponsor->get($memberId, []))
                    ->reject(fn (Customer $child) => in_array((int) $child->c_userid, $nextPath, true))
                    ->sortByDesc('c_userid')
                    ->map(fn (Customer $child): array => $buildReferralSnapshotNode($child, $nextPath))
                    ->values();

                $node = $this->transformReferralNode($member);
                $node['children_count'] = $children->count();
                $node['children'] = $children->all();

                return $node;
            };
            $directReferralMembers = collect($referralMembersBySponsor->get($customerId, []))
                ->sortByDesc('c_userid')
                ->values();

            $personalPv = (float) DB::table('tbl_checkout_history')
                ->where('ch_customer_id', $customerId)
                ->whereNotNull('ch_pv_posted_at')
                ->sum('ch_earned_pv');

            $directIds = $directReferralMembers->pluck('c_userid')->map(fn ($id) => (int) $id)->toArray();
            $activeMembersCount = 0;
            if (!empty($directIds)) {
                $directPvSums = DB::table('tbl_checkout_history')
                    ->whereIn('ch_customer_id', $directIds)
                    ->whereNotNull('ch_pv_posted_at')
                    ->groupBy('ch_customer_id')
                    ->selectRaw('ch_customer_id, SUM(ch_earned_pv) as total_pv')
                    ->get()
                    ->keyBy('ch_customer_id');
                foreach ($directIds as $directId) {
                    if ((float) ($directPvSums->get($directId)?->total_pv ?? 0) >= 300) {
                        $activeMembersCount++;
                    }
                }
            }
            $activeBuildersCount = $directReferralMembers->filter(fn (Customer $m) => (int) ($m->c_rank ?? 0) >= 2)->count();
            $activeLeadersCount  = $directReferralMembers->filter(fn (Customer $m) => (int) ($m->c_rank ?? 0) >= 3)->count();

            $loyaltyInfo = [
                'tier' => $tier,
                'rank' => (int) ($customer->c_rank ?? 0),
                'badge_name' => $tier,
                'total_orders' => (int) ($customer->c_totalpair ?? 0),
                'total_spent' => (float) ($customer->c_gpv ?? 0),
                'total_earnings' => (float) ($customer->c_totalincome ?? 0),
                'pv_balance' => (float) ($customer->c_gpv ?? 0),
                'cash_balance' => (float) ($customer->c_totalincome ?? 0),
                'personal_pv' => $personalPv,
                'active_members_count' => $activeMembersCount,
                'active_builders_count' => $activeBuildersCount,
                'active_leaders_count' => $activeLeadersCount,
                'referral_count' => $directReferralMembers->count(),
                'direct_referrals' => $directReferralMembers
                    ->map(fn (Customer $member): array => $buildReferralSnapshotNode($member))
                    ->values()
                    ->all(),
                'join_date' => $customer->c_date_started ? (is_string($customer->c_date_started) ? $customer->c_date_started : $customer->c_date_started->format('Y-m-d')) : null,
                'last_login' => $customer->c_last_logindate ? (is_string($customer->c_last_logindate) ? $customer->c_last_logindate : $customer->c_last_logindate->format('Y-m-d H:i:s')) : null,
            ];

            // Account Status
            $accountStatus = $this->mapAccountStatus(
                (int) ($customer->c_lockstatus ?? 0),
                (int) ($customer->c_accnt_status ?? 0)
            );

            // Profile Information
            $profileInfo = [
                'id' => $customerId,
                'username' => $customer->c_username,
                'first_name' => $customer->c_fname,
                'last_name' => $customer->c_lname,
                'email' => $customer->c_email,
                'phone' => $customer->c_mobile,
                'avatar_url' => $customer->c_avatar_url,
                'verification_status' => $accountStatus['verification_status'],
                'account_status' => $accountStatus['account_status'],
            ];

            return response()->json([
                'profile' => $profileInfo,
                'loyalty' => $loyaltyInfo,
                'orders' => $ordersSummary,
                'wishlist' => [
                    'total_items' => $wishlistItems,
                ],
                'reviews' => $reviewsSummary,
                'snapshot_date' => now()->format('Y-m-d H:i:s'),
            ]);

        } catch (\Throwable $e) {
            Log::error('Account snapshot error', [
                'customer_id' => $customerId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json(['message' => 'Failed to load account snapshot.'], 500);
        }
    }

    private function mapCheckoutStatusToOrderStatus(string $status): string
    {
        return match ($status) {
            'pending', 'pending_approval' => 'pending',
            'paid', 'succeeded', 'success' => 'paid',
            'completed' => 'completed',
            'shipped' => 'shipped',
            'delivered' => 'delivered',
            'failed', 'cancelled' => 'cancelled',
            default => 'unknown',
        };
    }

    private function mapCustomerTier(int $rank): string
    {
        return match ($rank) {
            5 => 'Lifestyle Elite',
            4 => 'Lifestyle Consultant',
            3 => 'Home Stylist',
            2 => 'Home Builder',
            1 => 'Home Starter',
            default => 'Home Starter',
        };
    }

    private function mapAccountStatus(int $lockStatus, int $accountStatus): array
    {
        $verificationStatus = match ($accountStatus) {
            1 => 'verified',
            2 => 'pending_review',
            default => 'not_verified',
        };

        $accountStatus = match (true) {
            $lockStatus === 1 => 'blocked',
            $accountStatus === 2 => 'kyc_review',
            $accountStatus === 0 => 'pending',
            default => 'active',
        };

        return [
            'verification_status' => $verificationStatus,
            'account_status' => $accountStatus,
        ];
    }

}
