<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MemberTier extends Model
{
    protected $table = 'tbl_member_tiers';
    protected $primaryKey = 'mt_id';
    public $incrementing = false;
    protected $keyType = 'int';

    protected $fillable = [
        'mt_name',
        'mt_rank',
        'mt_description',
        'mt_min_pv',
        'mt_min_direct_referrals',
        'mt_min_group_volume',
        'mt_is_active',
        'mt_sort_order',
    ];

    protected $casts = [
        'mt_min_pv' => 'float',
        'mt_min_direct_referrals' => 'integer',
        'mt_min_group_volume' => 'integer',
        'mt_is_active' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Get tier by rank
     */
    public static function getByRank(int $rank): ?self
    {
        return self::where('mt_rank', $rank)->first();
    }

    /**
     * Get tier by name
     */
    public static function getByName(string $name): ?self
    {
        return self::where('mt_name', $name)->first();
    }

    /**
     * Get all active tiers ordered by rank
     */
    public static function active()
    {
        return self::where('mt_is_active', true)->orderBy('mt_rank');
    }

    /**
     * Map rank to tier name (replaces hardcoded mapTier function)
     */
    public static function getTierNameByRank(int $rank): string
    {
        $tier = self::getByRank($rank);
        return $tier?->mt_name ?? 'Home Starter';
    }

    /**
     * Map tier name to rank (replaces hardcoded mapTierToRank function)
     */
    public static function getRankByTierName(string $tierName): int
    {
        $tier = self::getByName($tierName);
        return $tier?->mt_rank ?? 1;
    }
}
