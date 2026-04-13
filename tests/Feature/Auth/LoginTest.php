<?php

namespace Tests\Feature\Auth;

use App\Models\Customer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class LoginTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_can_login_using_email(): void
    {
        $customer = Customer::create([
            'c_userid' => 1,
            'c_fname' => 'Juan',
            'c_lname' => 'Dela Cruz',
            'c_username' => 'juan123',
            'c_email' => 'juan@example.com',
            'c_password' => Hash::make('Password@123'),
            'c_password_pin' => '',
            'c_password_change_required' => false,
            'c_accnt_status' => 0,
            'c_lockstatus' => 0,
        ]);

        $response = $this->postJson('/api/auth/login', [
            'email' => 'juan@example.com',
            'password' => 'Password@123',
        ]);

        $response
            ->assertOk()
            ->assertJsonStructure([
                'user' => ['id', 'name', 'email', 'username'],
                'token',
                'message',
            ]);

        $this->assertSame($customer->c_email, $response->json('user.email'));
    }

    public function test_customer_cannot_login_with_wrong_password(): void
    {
        Customer::create([
            'c_userid' => 1,
            'c_fname' => 'Juan',
            'c_lname' => 'Dela Cruz',
            'c_username' => 'juan123',
            'c_email' => 'juan@example.com',
            'c_password' => Hash::make('Password@123'),
            'c_password_pin' => '',
            'c_password_change_required' => false,
            'c_accnt_status' => 0,
            'c_lockstatus' => 0,
        ]);

        $response = $this->postJson('/api/auth/login', [
            'email' => 'juan@example.com',
            'password' => 'WrongPassword@123',
        ]);

        $response
            ->assertStatus(422)
            ->assertJsonValidationErrors(['email']);
    }
}
