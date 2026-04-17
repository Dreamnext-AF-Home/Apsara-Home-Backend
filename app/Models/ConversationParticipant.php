<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ConversationParticipant extends Model
{
    use HasFactory;

    protected $table = 'tbl_conversation_participants';

    protected $fillable = [
        'conversation_id',
        'user_id',
        'joined_at',
        'left_at',
    ];

    protected $casts = [
        'joined_at' => 'datetime',
        'left_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Get the conversation
     */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class, 'conversation_id');
    }

    /**
     * Get the user who participates in the conversation
     */
    public function user(): BelongsTo
    {
        // user_id references tbl_customer.c_userid
        return $this->belongsTo(Customer::class, 'user_id', 'c_userid');
    }

    /**
     * Check if participant is still in conversation
     */
    public function isActive(): bool
    {
        return $this->left_at === null;
    }

    /**
     * Mark participant as left
     */
    public function markAsLeft(): void
    {
        if ($this->isActive()) {
            $this->update(['left_at' => now()]);
        }
    }

    /**
     * Get participant's duration in conversation (in minutes)
     */
    public function getDurationInMinutes(): int
    {
        $endTime = $this->left_at ?? now();
        return (int) $this->joined_at->diffInMinutes($endTime);
    }
}
