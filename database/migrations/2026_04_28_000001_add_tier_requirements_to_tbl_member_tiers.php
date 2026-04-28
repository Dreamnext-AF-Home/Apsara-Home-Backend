<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tbl_member_tiers', function (Blueprint $table) {
            if (!Schema::hasColumn('tbl_member_tiers', 'mt_min_active_members')) {
                $table->integer('mt_min_active_members')->default(0)->after('mt_min_group_volume')
                    ->comment('Min direct referrals with cumulative PV >= mt_min_active_member_pv');
            }
            if (!Schema::hasColumn('tbl_member_tiers', 'mt_min_active_member_pv')) {
                $table->decimal('mt_min_active_member_pv', 12, 2)->default(0)->after('mt_min_active_members')
                    ->comment('Min cumulative PV threshold for a direct to count as active member');
            }
            if (!Schema::hasColumn('tbl_member_tiers', 'mt_min_active_builders')) {
                $table->integer('mt_min_active_builders')->default(0)->after('mt_min_active_member_pv')
                    ->comment('Min direct referrals at Home Builder tier (rank >= 2)');
            }
            if (!Schema::hasColumn('tbl_member_tiers', 'mt_min_active_leaders')) {
                $table->integer('mt_min_active_leaders')->default(0)->after('mt_min_active_builders')
                    ->comment('Min direct referrals at Home Stylist tier (rank >= 3)');
            }
            if (!Schema::hasColumn('tbl_member_tiers', 'mt_max_group_levels')) {
                $table->integer('mt_max_group_levels')->default(0)->after('mt_min_active_leaders')
                    ->comment('Max levels for Group Purchase Bonus for this tier');
            }
        });

        // Seed tier thresholds
        DB::table('tbl_member_tiers')->where('mt_rank', 1)->update([
            'mt_min_pv'                => 0,
            'mt_min_direct_referrals'  => 0,
            'mt_min_group_volume'      => 0,
            'mt_min_active_members'    => 0,
            'mt_min_active_member_pv'  => 0,
            'mt_min_active_builders'   => 0,
            'mt_min_active_leaders'    => 0,
            'mt_max_group_levels'      => 0,
        ]);

        DB::table('tbl_member_tiers')->where('mt_rank', 2)->update([
            'mt_min_pv'                => 300,
            'mt_min_direct_referrals'  => 2,
            'mt_min_group_volume'      => 0,
            'mt_min_active_members'    => 0,
            'mt_min_active_member_pv'  => 0,
            'mt_min_active_builders'   => 0,
            'mt_min_active_leaders'    => 0,
            'mt_max_group_levels'      => 1,
        ]);

        DB::table('tbl_member_tiers')->where('mt_rank', 3)->update([
            'mt_min_pv'                => 1000,
            'mt_min_direct_referrals'  => 5,
            'mt_min_group_volume'      => 0,
            'mt_min_active_members'    => 2,
            'mt_min_active_member_pv'  => 300,
            'mt_min_active_builders'   => 0,
            'mt_min_active_leaders'    => 0,
            'mt_max_group_levels'      => 2,
        ]);

        DB::table('tbl_member_tiers')->where('mt_rank', 4)->update([
            'mt_min_pv'                => 3000,
            'mt_min_direct_referrals'  => 10,
            'mt_min_group_volume'      => 10000,
            'mt_min_active_members'    => 0,
            'mt_min_active_member_pv'  => 0,
            'mt_min_active_builders'   => 5,
            'mt_min_active_leaders'    => 0,
            'mt_max_group_levels'      => 5,
        ]);

        DB::table('tbl_member_tiers')->where('mt_rank', 5)->update([
            'mt_min_pv'                => 8000,
            'mt_min_direct_referrals'  => 20,
            'mt_min_group_volume'      => 30000,
            'mt_min_active_members'    => 0,
            'mt_min_active_member_pv'  => 0,
            'mt_min_active_builders'   => 0,
            'mt_min_active_leaders'    => 10,
            'mt_max_group_levels'      => 10,
        ]);
    }

    public function down(): void
    {
        Schema::table('tbl_member_tiers', function (Blueprint $table) {
            $table->dropColumn([
                'mt_min_active_members',
                'mt_min_active_member_pv',
                'mt_min_active_builders',
                'mt_min_active_leaders',
                'mt_max_group_levels',
            ]);
        });
    }
};
