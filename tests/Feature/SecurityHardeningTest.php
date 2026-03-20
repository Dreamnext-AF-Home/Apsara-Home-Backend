<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\SupplierUser;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SecurityHardeningTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->createPersonalAccessTokensTable();
        $this->createCustomerTable();
        $this->createCheckoutHistoryTable();
        $this->createSupplierTables();
    }

    public function test_checkout_verify_keeps_pending_status_for_unpaid_sessions(): void
    {
        Config::set('services.paymongo.secret_key', 'test_secret');
        Config::set('services.paymongo.api_base_url', 'https://example.test');

        Http::fake([
            'https://example.test/v1/checkout_sessions/*' => Http::response([
                'data' => [
                    'attributes' => [
                        'status' => 'active',
                        'payment_intent' => [
                            'id' => 'pi_test_123',
                        ],
                    ],
                ],
            ], 200),
        ]);

        $response = $this->getJson('/api/payments/checkout-session/cs_test_123');

        $response
            ->assertOk()
            ->assertJson([
                'checkout_id' => 'cs_test_123',
                'status' => 'pending',
                'payment_intent_id' => 'pi_test_123',
            ]);
    }

    public function test_customer_login_no_longer_accepts_plaintext_password_pin(): void
    {
        Customer::query()->create([
            'c_userid' => 1,
            'c_email' => 'customer@example.com',
            'c_username' => 'customer1',
            'c_password' => Hash::make('StrongPass1!'),
            'c_password_pin' => 'old-plain-pin',
            'c_password_change_required' => false,
        ]);

        $response = $this->postJson('/api/auth/login', [
            'email' => 'customer@example.com',
            'password' => 'old-plain-pin',
        ]);

        $response
            ->assertStatus(422)
            ->assertJsonValidationErrors('email');
    }

    public function test_change_password_clears_plaintext_password_pin(): void
    {
        $customer = Customer::query()->create([
            'c_userid' => 2,
            'c_email' => 'member@example.com',
            'c_username' => 'member1',
            'c_password' => Hash::make('CurrentPass1!'),
            'c_password_pin' => 'legacy-pin',
            'c_password_change_required' => false,
        ]);

        Sanctum::actingAs($customer);

        $response = $this->patchJson('/api/auth/change-password', [
            'current_password' => 'CurrentPass1!',
            'new_password' => 'NewPass2@',
            'new_password_confirmation' => 'NewPass2@',
        ]);

        $response->assertOk();

        $this->assertSame('', $customer->fresh()->c_password_pin);
    }

    public function test_customer_token_cannot_access_admin_member_endpoint(): void
    {
        $customer = Customer::query()->create([
            'c_userid' => 3,
            'c_email' => 'blocked@example.com',
            'c_username' => 'blocked1',
            'c_password' => Hash::make('CurrentPass1!'),
            'c_password_pin' => '',
            'c_password_change_required' => false,
        ]);

        Sanctum::actingAs($customer);

        $response = $this->getJson('/api/admin/members');

        $response
            ->assertStatus(403)
            ->assertJson([
                'message' => 'Forbidden: admin access required.',
            ]);
    }

    public function test_supplier_token_can_still_access_supplier_scoped_admin_supplier_index(): void
    {
        DB::table('tbl_supplier')->insert([
            's_id' => 10,
            's_name' => 'Demo Supplier',
            's_company' => 'Demo Supplier Co',
        ]);

        DB::table('tbl_supplier_user')->insert([
            'su_id' => 5,
            'su_supplier' => 10,
            'su_username' => 'supplier1',
            'su_password' => Hash::make('SupplierPass1!'),
            'su_email' => 'supplier@example.com',
        ]);

        $supplierUser = SupplierUser::query()->findOrFail(5);

        Sanctum::actingAs($supplierUser);

        $response = $this->getJson('/api/admin/suppliers');

        $response
            ->assertOk()
            ->assertJsonCount(1, 'suppliers')
            ->assertJsonPath('suppliers.0.id', 10);
    }

    private function createPersonalAccessTokensTable(): void
    {
        Schema::dropIfExists('personal_access_tokens');

        Schema::create('personal_access_tokens', function (Blueprint $table) {
            $table->id();
            $table->morphs('tokenable');
            $table->string('name');
            $table->string('token', 64)->unique();
            $table->text('abilities')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });
    }

    private function createCustomerTable(): void
    {
        Schema::dropIfExists('tbl_customer');

        Schema::create('tbl_customer', function (Blueprint $table) {
            $table->integer('c_userid')->primary();
            $table->string('c_email')->nullable();
            $table->string('c_username')->nullable();
            $table->string('c_password')->nullable();
            $table->string('c_password_pin')->nullable();
            $table->boolean('c_password_change_required')->default(false);
            $table->string('c_fname')->nullable();
            $table->string('c_mname')->nullable();
            $table->string('c_lname')->nullable();
            $table->integer('c_accnt_status')->default(1);
            $table->integer('c_lockstatus')->default(0);
            $table->string('c_mobile')->nullable();
            $table->string('c_address')->nullable();
            $table->string('c_barangay')->nullable();
            $table->string('c_city')->nullable();
            $table->string('c_province')->nullable();
            $table->string('c_region')->nullable();
            $table->string('c_zipcode')->nullable();
            $table->string('c_avatar_url')->nullable();
            $table->integer('c_rank')->default(0);
            $table->decimal('c_totalincome', 12, 2)->default(0);
            $table->decimal('c_gpv', 12, 2)->default(0);
            $table->integer('c_totalpair')->default(0);
            $table->integer('c_sponsor')->default(0);
            $table->timestamp('c_date_started')->nullable();
            $table->timestamp('c_last_logindate')->nullable();
        });
    }

    private function createCheckoutHistoryTable(): void
    {
        Schema::dropIfExists('tbl_checkout_history');

        Schema::create('tbl_checkout_history', function (Blueprint $table) {
            $table->integer('ch_id')->primary()->nullable(false);
            $table->string('ch_checkout_id')->nullable();
            $table->string('ch_status')->nullable();
            $table->string('ch_payment_intent_id')->nullable();
            $table->timestamp('ch_paid_at')->nullable();
            $table->string('ch_fulfillment_status')->nullable();
            $table->string('ch_approval_status')->nullable();
        });
    }

    private function createSupplierTables(): void
    {
        Schema::dropIfExists('tbl_supplier_user');
        Schema::dropIfExists('tbl_supplier');

        Schema::create('tbl_supplier', function (Blueprint $table) {
            $table->integer('s_id')->primary();
            $table->string('s_name')->nullable();
            $table->string('s_company')->nullable();
            $table->string('s_email')->nullable();
            $table->string('s_contact')->nullable();
            $table->string('s_address')->nullable();
            $table->integer('s_status')->default(1);
        });

        Schema::create('tbl_supplier_user', function (Blueprint $table) {
            $table->integer('su_id')->primary();
            $table->integer('su_supplier');
            $table->string('su_fullname')->nullable();
            $table->string('su_username')->nullable();
            $table->string('su_password')->nullable();
            $table->string('su_email')->nullable();
            $table->integer('su_level_type')->default(0);
            $table->timestamp('su_date_created')->nullable();
            $table->string('su_PIN')->nullable();
            $table->string('su_ASESSION_STAT')->nullable();
            $table->timestamp('su_last_logindate')->nullable();
            $table->string('su_last_ipadd')->nullable();
            $table->string('su_last_loginloc')->nullable();
        });
    }
}
