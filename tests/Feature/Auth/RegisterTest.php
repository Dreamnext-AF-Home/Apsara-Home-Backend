<?php

namespace Tests\Feature\Auth;

use App\Models\Customer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
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
}
