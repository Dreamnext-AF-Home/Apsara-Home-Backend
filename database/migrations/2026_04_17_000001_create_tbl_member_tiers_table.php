<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('tbl_member_tiers')) {
            return;
        }

        Schema::create('tbl_member_tiers', function (Blueprint $table) {
            $table->unsignedSmallInteger('mt_id')->primary();
            $table->string('mt_name', 100)->unique();
            $table->unsignedSmallInteger('mt_rank')->unique();
            $table->text('mt_description')->nullable();
            $table->decimal('mt_min_pv', 12, 2)->nullable()->comment('Minimum PV required for this tier');
            $table->integer('mt_min_direct_referrals')->nullable()->comment('Minimum direct referrals required');
            $table->integer('mt_min_group_volume')->nullable()->comment('Minimum group volume required');
            $table->boolean('mt_is_active')->default(true);
            $table->integer('mt_sort_order')->default(0);
            $table->timestamps();

            $table->index('mt_rank');
            $table->index('mt_is_active');
        });

        // Seed initial tiers
        $this->seedInitialTiers();
    }

    public function down(): void
    {
        Schema::dropIfExists('tbl_member_tiers');
    }

    private function seedInitialTiers(): void
    {
        DB::table('tbl_member_tiers')->insert([
            [
                'mt_id' => 1,
                'mt_name' => 'Home Starter',
                'mt_rank' => 1,
                'mt_description' => 'Entry-level membership tier',
                'mt_is_active' => true,
                'mt_sort_order' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'mt_id' => 2,
                'mt_name' => 'Home Builder',
                'mt_rank' => 2,
                'mt_description' => 'Intermediate membership tier',
                'mt_is_active' => true,
                'mt_sort_order' => 2,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'mt_id' => 3,
                'mt_name' => 'Home Stylist',
                'mt_rank' => 3,
                'mt_description' => 'Advanced membership tier',
                'mt_is_active' => true,
                'mt_sort_order' => 3,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'mt_id' => 4,
                'mt_name' => 'Lifestyle Consultant',
                'mt_rank' => 4,
                'mt_description' => 'Senior membership tier',
                'mt_is_active' => true,
                'mt_sort_order' => 4,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'mt_id' => 5,
                'mt_name' => 'Lifestyle Elite',
                'mt_rank' => 5,
                'mt_description' => 'Premium membership tier',
                'mt_is_active' => true,
                'mt_sort_order' => 5,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
    }
};
