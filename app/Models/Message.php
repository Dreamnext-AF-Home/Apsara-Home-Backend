<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Message extends Model
{
    use HasFactory;

    protected $table = 'tbl_messages';

    protected $fillable = [
        'conversation_id',
        'sender_id',
        'message',
        'is_internal',
        'attachment_url',
        'attachment_filename',
        'read_at',
    ];

    protected $casts = [
        'is_internal' => 'boolean',
        'read_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Get the conversation this message belongs to
     */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class, 'conversation_id');
    }

    /**
     * Get the user who sent this message (Customer or Admin)
     */
    public function sender(): BelongsTo
    {
        // sender_id references tbl_customer.c_userid
        return $this->belongsTo(Customer::class, 'sender_id', 'c_userid');
    }

    /**
     * Check if message is internal
     */
    public function isInternal(): bool
    {
        return $this->is_internal === true;
    }

    /**
     * Check if message is read
     */
    public function isRead(): bool
    {
        return $this->read_at !== null;
    }

    /**
     * Mark message as read
     */
    public function markAsRead(): void
    {
        if (!$this->isRead()) {
            $this->update(['read_at' => now()]);
        }
    }

    /**
     * Check if message has attachment
     */
    public function hasAttachment(): bool
    {
        return $this->attachment_url !== null;
    }

    /**
     * Get formatted message (sanitized for display)
     */
    public function getFormattedMessage(): string
    {
        return htmlspecialchars($this->message, ENT_QUOTES, 'UTF-8');
    }
}
