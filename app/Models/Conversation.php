<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Conversation extends Model
{
    use HasFactory;

    protected $table = 'tbl_conversations';

    protected $fillable = [
        'user_id',
        'assigned_agent_id',
        'status',
        'subject',
        'description',
        'resolved_at',
    ];

    protected $casts = [
        'resolved_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Get the customer who initiated this conversation
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'user_id', 'c_userid');
    }

    /**
     * Get the agent assigned to this conversation
     */
    public function assignedAgent(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'assigned_agent_id', 'id');
    }

    /**
     * Get all messages in this conversation
     */
    public function messages(): HasMany
    {
        return $this->hasMany(Message::class, 'conversation_id')->orderBy('created_at');
    }

    /**
     * Get latest message
     */
    public function latestMessage(): HasMany
    {
        return $this->messages()->latest();
    }

    /**
     * Get all conversation participants
     */
    public function participants()
    {
        return $this->hasMany(ConversationParticipant::class, 'conversation_id');
    }

    /**
     * Check if conversation is open
     */
    public function isOpen(): bool
    {
        return $this->status === 'open';
    }

    /**
     * Check if conversation is pending
     */
    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    /**
     * Check if conversation is resolved
     */
    public function isResolved(): bool
    {
        return $this->status === 'resolved';
    }

    /**
     * Mark conversation as resolved
     */
    public function markAsResolved(): void
    {
        $this->update([
            'status' => 'resolved',
            'resolved_at' => now(),
        ]);
    }

    /**
     * Mark conversation as pending
     */
    public function markAsPending(): void
    {
        $this->update([
            'status' => 'pending',
            'resolved_at' => null,
        ]);
    }

    /**
     * Mark conversation as open
     */
    public function markAsOpen(): void
    {
        $this->update([
            'status' => 'open',
            'resolved_at' => null,
        ]);
    }

    /**
     * Assign agent to conversation
     */
    public function assignAgent(Admin $agent): void
    {
        // Optionally add validation for agent role/permissions
        $this->update(['assigned_agent_id' => $agent->user_id ?? $agent->id]);
    }

    /**
     * Unassign agent from conversation
     */
    public function unassignAgent(): void
    {
        $this->update(['assigned_agent_id' => null]);
    }

    /**
     * Get unread message count
     */
    public function unreadMessageCount(): int
    {
        return $this->messages()
            ->whereNull('read_at')
            ->where('sender_id', '!=', auth()->id())
            ->count();
    }

    /**
     * Add participant to conversation
     */
    public function addParticipant(int $userId): void
    {
        ConversationParticipant::firstOrCreate([
            'conversation_id' => $this->id,
            'user_id' => $userId,
        ]);
    }

    /**
     * Remove participant from conversation
     */
    public function removeParticipant(int $userId): void
    {
        $this->participants()
            ->where('user_id', $userId)
            ->update(['left_at' => now()]);
    }
}
