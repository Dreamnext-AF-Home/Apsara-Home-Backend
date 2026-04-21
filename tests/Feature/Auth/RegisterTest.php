<?php

namespace Tests\Feature\Auth;

use App\Models\Customer;
use App\Models\CustomerAddress;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class RegisterTest extends TestCase
{
    use RefreshDatabase;

    public function test_register_returns_verification_token_when_payload_is_valid(): void
    {
        Mail::fake();

        Customer::create([
            'c_userid' => 1,
            'c_fname' => 'Ref',
            'c_lname' => 'User',
            'c_username' => 'referrer1',
            'c_email' => 'referrer@example.com',
            'c_password' => bcrypt('Password@123'),
            'c_password_pin' => '',
            'c_password_change_required' => false,
            'c_accnt_status' => 1,
            'c_lockstatus' => 0,
        ]);

        $response = $this->postJson('/api/auth/register', [
            'first_name' => 'Rafa',
            'last_name' => 'Santos',
            'middle_name' => '',
            'name' => 'Rafa Santos',
            'email' => 'rafa@example.com',
            'username' => 'rafasantos',
            'phone' => '09123456789',
            'birth_date' => '2000-01-01',
            'gender' => 'male',
            'occupation' => 'Developer',
            'work_location' => 'local',
            'country' => 'Philippines',
            'referred_by' => 'referrer1',
            'password' => 'Password@123',
            'password_confirmation' => 'Password@123',
            'address' => 'Test Address',
            'barangay' => 'Test Barangay',
            'city' => 'Test City',
            'province' => 'Test Province',
            'region' => 'Test Region',
            'zip_code' => '1000',
        ]);

        $response
            ->assertOk()
            ->assertJson([
                'requires_otp' => true,
                'email' => 'rafa@example.com',
            ])
            ->assertJsonStructure([
                'message',
                'requires_otp',
                'verification_token',
                'email',
            ]);
    }

    public function test_register_rejects_duplicate_email(): void
    {
        Mail::fake();

        Customer::create([
            'c_userid' => 1,
            'c_fname' => 'Ref',
            'c_lname' => 'User',
            'c_username' => 'referrer1',
            'c_email' => 'referrer@example.com',
            'c_password' => bcrypt('Password@123'),
            'c_password_pin' => '',
            'c_password_change_required' => false,
            'c_accnt_status' => 1,
            'c_lockstatus' => 0,
        ]);

        Customer::create([
            'c_userid' => 2,
            'c_fname' => 'Existing',
            'c_lname' => 'User',
            'c_username' => 'existinguser',
            'c_email' => 'rafa@example.com',
            'c_password' => bcrypt('Password@123'),
            'c_password_pin' => '',
            'c_password_change_required' => false,
            'c_accnt_status' => 0,
            'c_lockstatus' => 0,
        ]);

        $response = $this->postJson('/api/auth/register', [
            'first_name' => 'Rafa',
            'last_name' => 'Santos',
            'middle_name' => '',
            'name' => 'Rafa Santos',
            'email' => 'rafa@example.com',
            'username' => 'newrafa',
            'phone' => '09123456789',
            'birth_date' => '2000-01-01',
            'gender' => 'male',
            'occupation' => 'Developer',
            'work_location' => 'local',
            'country' => 'Philippines',
            'referred_by' => 'referrer1',
            'password' => 'Password@123',
            'password_confirmation' => 'Password@123',
        ]);

        $response
            ->assertStatus(422)
            ->assertJsonValidationErrors(['email']);
    }

    public function test_check_username_availability_accepts_alphanumeric_username(): void
    {
        $response = $this->getJson('/api/auth/register/check-username?username=test1122');

        $response
            ->assertOk()
            ->assertJson([
                'available' => true,
                'message' => 'Username is available.',
            ]);
    }

    public function test_check_username_availability_rejects_symbols(): void
    {
        $response = $this->getJson('/api/auth/register/check-username?username=test_1122');

        $response
            ->assertOk()
            ->assertJson([
                'available' => false,
                'message' => 'Username must contain letters and numbers only.',
            ]);
    }

    public function test_check_referral_availability_accepts_full_referral_link(): void
    {
        Customer::create([
            'c_userid' => 1,
            'c_fname' => 'Ref',
            'c_lname' => 'User',
            'c_username' => 'referrer1',
            'c_email' => 'referrer@example.com',
            'c_password' => bcrypt('Password@123'),
            'c_password_pin' => '',
            'c_password_change_required' => false,
            'c_accnt_status' => 1,
            'c_lockstatus' => 0,
        ]);

        $response = $this->getJson('/api/auth/register/check-referral?referred_by=' . urlencode('https://afhome.ph/signup?ref=referrer1'));

        $response
            ->assertOk()
            ->assertJson([
                'available' => true,
                'message' => 'Referral code is valid.',
                'normalized_referral' => 'referrer1',
                'referrer_username' => 'referrer1',
            ]);
    }

    public function test_check_referral_availability_rejects_locked_referrer(): void
    {
        Customer::create([
            'c_userid' => 1,
            'c_fname' => 'Locked',
            'c_lname' => 'User',
            'c_username' => 'lockedref',
            'c_email' => 'locked@example.com',
            'c_password' => bcrypt('Password@123'),
            'c_password_pin' => '',
            'c_password_change_required' => false,
            'c_accnt_status' => 1,
            'c_lockstatus' => 1,
        ]);

        $response = $this->getJson('/api/auth/register/check-referral?referred_by=lockedref');

        $response
            ->assertOk()
            ->assertJson([
                'available' => false,
                'message' => 'Referral code is invalid or referrer account is unavailable.',
                'normalized_referral' => 'lockedref',
            ]);
    }

    public function test_register_rejects_username_with_symbols(): void
    {
        Mail::fake();

        Customer::create([
            'c_userid' => 1,
            'c_fname' => 'Ref',
            'c_lname' => 'User',
            'c_username' => 'referrer1',
            'c_email' => 'referrer@example.com',
            'c_password' => bcrypt('Password@123'),
            'c_password_pin' => '',
            'c_password_change_required' => false,
            'c_accnt_status' => 1,
            'c_lockstatus' => 0,
        ]);

        $response = $this->postJson('/api/auth/register', [
            'first_name' => 'Rafa',
            'last_name' => 'Santos',
            'middle_name' => '',
            'name' => 'Rafa Santos',
            'email' => 'rafa.symbol@example.com',
            'username' => 'rafa_1122',
            'phone' => '09123456789',
            'birth_date' => '2000-01-01',
            'gender' => 'male',
            'occupation' => 'Developer',
            'work_location' => 'local',
            'country' => 'Philippines',
            'referred_by' => 'referrer1',
            'password' => 'Password@123',
            'password_confirmation' => 'Password@123',
        ]);

        $response
            ->assertStatus(422)
            ->assertJsonValidationErrors(['username']);
    }

    public function test_verify_registration_otp_creates_customer_address_when_country_is_philippines_text(): void
    {
        Mail::fake();

        if (!Schema::hasTable('tbl_customer_address')) {
            Schema::create('tbl_customer_address', function (Blueprint $table): void {
                $table->increments('a_id');
                $table->integer('a_cid');
                $table->string('a_fullname')->nullable();
                $table->string('a_mobile')->nullable();
                $table->string('a_mobile_code')->nullable();
                $table->string('a_address')->nullable();
                $table->string('a_country')->nullable();
                $table->string('a_region')->nullable();
                $table->string('a_province')->nullable();
                $table->string('a_city')->nullable();
                $table->string('a_barangay')->nullable();
                $table->string('a_region_code')->nullable();
                $table->string('a_province_code')->nullable();
                $table->string('a_city_code')->nullable();
                $table->string('a_barangay_code')->nullable();
                $table->integer('a_shipping_status')->default(0);
                $table->integer('a_billing_status')->default(0);
                $table->string('a_postcode')->nullable();
                $table->string('a_address_type')->nullable();
                $table->text('a_notes')->nullable();
            });
        }

        Customer::create([
            'c_userid' => 1,
            'c_fname' => 'Ref',
            'c_lname' => 'User',
            'c_username' => 'referrer1',
            'c_email' => 'referrer@example.com',
            'c_password' => bcrypt('Password@123'),
            'c_password_pin' => '',
            'c_password_change_required' => false,
            'c_accnt_status' => 1,
            'c_lockstatus' => 0,
        ]);

        $verificationToken = (string) Str::uuid();
        $otp = '1981';

        Cache::put("registration_otp:{$verificationToken}", [
            'otp_hash' => Hash::make($otp),
            'payload' => Crypt::encryptString(json_encode([
                'validated' => [
                    'first_name' => 'Test',
                    'last_name' => 'User',
                    'middle_name' => '',
                    'name' => 'Test User',
                    'email' => 'test.user@example.com',
                    'username' => 'testuser1981',
                    'phone' => '9292260447',
                    'birth_date' => '2000-01-01',
                    'gender' => 'male',
                    'occupation' => 'Developer',
                    'work_location' => 'local',
                    'country' => 'Philippines',
                    'password' => 'Password@123',
                    'address' => '9023 New York St.',
                    'barangay' => 'Carangag',
                    'barangay_code' => '050203013',
                    'city' => 'San Andres',
                    'city_code' => '050203000',
                    'province' => 'Catanduanes',
                    'province_code' => '0520',
                    'region' => 'Region V',
                    'region_code' => '05',
                    'zip_code' => '1870',
                ],
                'referrer_user_id' => 1,
            ], JSON_THROW_ON_ERROR)),
            'email' => 'test.user@example.com',
        ], now()->addMinutes(10));

        $response = $this->postJson('/api/auth/register/verify-otp', [
            'verification_token' => $verificationToken,
            'otp' => $otp,
        ]);

        $response
            ->assertStatus(201)
            ->assertJson([
                'message' => 'Registration complete. You can now sign in.',
            ]);

        $customer = Customer::query()->where('c_email', 'test.user@example.com')->first();

        $this->assertNotNull($customer);
        $this->assertSame('Philippines', $customer->c_country);
        $this->assertDatabaseHas('tbl_customer_address', [
            'a_cid' => $customer->c_userid,
            'a_country' => '175',
            'a_region' => 'Region V',
            'a_province' => 'Catanduanes',
            'a_city' => 'San Andres',
            'a_barangay' => 'Carangag',
        ]);
    }
}
