<?php

namespace App\Services;

use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Admin;
use App\Models\Message;
use Illuminate\Pagination\Paginator;
use Pusher\Pusher;
use Illuminate\Support\Facades\Log;

class ConversationService
{
    /**
     * Create a new conversation
     */
    public function createConversation(
        Customer $customer,
        string $subject,
        ?string $description = null
    ): Conversation {
        $conversation = Conversation::create([
            'user_id' => $customer->c_userid,
            'status' => 'open',
            'subject' => $subject,
            'description' => $description,
        ]);

        // Add user as participant
        $conversation->addParticipant($customer->c_userid);

        // Publish conversation created event
        $this->publishConversationUpdatedEvent($conversation, 'conversation.created', [
            'customer_id' => (int) $customer->c_userid,
            'subject' => (string) $subject,
            'description' => $description,
        ]);

        return $conversation;
    }

    /**
     * Send a message to a conversation
     */
    public function sendMessage(
        Conversation $conversation,
        int $senderId,
        string $messageText,
        bool $isInternal = false,
        ?string $attachmentUrl = null,
        ?string $attachmentFilename = null
    ): Message {
        // Ensure sender is a participant
        $conversation->addParticipant($senderId);

        $message = Message::create([
            'conversation_id' => $conversation->id,
            'sender_id' => $senderId,
            'message' => $messageText,
            'is_internal' => $isInternal,
            'attachment_url' => $attachmentUrl,
            'attachment_filename' => $attachmentFilename,
        ]);

        // Publish message event in real-time
        $this->publishMessageEvent($conversation, $message);

        return $message;
    }

    /**
     * Assign an agent to a conversation
     */
    public function assignAgent(Conversation $conversation, Admin $agent): void
    {
        $conversation->assignAgent($agent);
        $conversation->addParticipant($agent->user_id ?? $agent->id);

        // Publish agent assignment event
        $this->publishConversationUpdatedEvent($conversation, 'agent_assigned', [
            'agent_id' => (int) $agent->id,
            'agent_name' => (string) $agent->fname,
            'assigned_at' => now()->toDateTimeString(),
        ]);
    }

    /**
     * Unassign agent from conversation
     */
    public function unassignAgent(Conversation $conversation): void
    {
        $conversation->unassignAgent();

        // Publish agent unassignment event
        $this->publishConversationUpdatedEvent($conversation, 'agent_unassigned', [
            'unassigned_at' => now()->toDateTimeString(),
        ]);
    }

    /**
     * Get conversations for a customer
     */
    public function getCustomerConversations(int $customerId, int $perPage = 20): Paginator
    {
        return Conversation::where('user_id', $customerId)
            ->orderByDesc('updated_at')
            ->paginate($perPage);
    }

    /**
     * Get conversations assigned to an agent
     */
    public function getAgentConversations(int $agentId, int $perPage = 20): Paginator
    {
        return Conversation::where('assigned_agent_id', $agentId)
            ->orderByDesc('updated_at')
            ->paginate($perPage);
    }

    /**
     * Get open conversations (for agent assignment)
     */
    public function getOpenConversations(int $perPage = 20): Paginator
    {
        return Conversation::where('status', 'open')
            ->whereNull('assigned_agent_id')
            ->orderBy('created_at')
            ->paginate($perPage);
    }

    /**
     * Get all conversations (admin only)
     */
    public function getAllConversations(int $perPage = 20): Paginator
    {
        return Conversation::orderByDesc('updated_at')
            ->paginate($perPage);
    }

    /**
     * Get conversations by status
     */
    public function getConversationsByStatus(string $status, int $perPage = 20): Paginator
    {
        return Conversation::where('status', $status)
            ->orderByDesc('updated_at')
            ->paginate($perPage);
    }

    /**
     * Get messages for a conversation
     */
    public function getConversationMessages(Conversation $conversation, int $perPage = 50): Paginator
    {
        return $conversation->messages()
            ->paginate($perPage);
    }

    /**
     * Mark messages as read
     */
    public function markConversationAsRead(Conversation $conversation, int $userId): void
    {
        $unreadMessages = Message::where('conversation_id', $conversation->id)
            ->where('sender_id', '!=', $userId)
            ->whereNull('read_at')
            ->get();

        if ($unreadMessages->isNotEmpty()) {
            Message::where('conversation_id', $conversation->id)
                ->where('sender_id', '!=', $userId)
                ->whereNull('read_at')
                ->update(['read_at' => now()]);

            // Publish messages read event
            $this->publishMessagesReadEvent($conversation, $unreadMessages->pluck('id')->toArray());
        }
    }

    /**
     * Get unread conversation count for user
     */
    public function getUnreadConversationCount(int $userId): int
    {
        return Conversation::whereHas('messages', function ($query) use ($userId) {
            $query->where('sender_id', '!=', $userId)
                ->whereNull('read_at');
        })
            ->where(function ($query) use ($userId) {
                $query->where('user_id', $userId)
                    ->orWhere('assigned_agent_id', $userId)
                    ->orWhereHas('participants', function ($pQuery) use ($userId) {
                        $pQuery->where('user_id', $userId);
                    });
            })
            ->count();
    }

    /**
     * Close a conversation
     */
    public function closeConversation(Conversation $conversation): void
    {
        $conversation->markAsResolved();

        // Publish conversation closed event
        $this->publishConversationUpdatedEvent($conversation, 'conversation.closed', [
            'resolved_at' => $conversation->resolved_at?->toDateTimeString(),
        ]);
    }

    /**
     * Reopen a conversation
     */
    public function reopenConversation(Conversation $conversation): void
    {
        $conversation->markAsOpen();

        // Publish conversation reopened event
        $this->publishConversationUpdatedEvent($conversation, 'conversation.reopened', [
            'reopened_at' => now()->toDateTimeString(),
        ]);
    }

    /**
     * Update conversation status
     */
    public function updateConversationStatus(Conversation $conversation, string $status): void
    {
        if ($status === 'resolved') {
            $conversation->markAsResolved();
            $this->publishConversationUpdatedEvent($conversation, 'conversation.status_changed', [
                'status' => 'resolved',
                'resolved_at' => $conversation->resolved_at?->toDateTimeString(),
            ]);
        } elseif ($status === 'pending') {
            $conversation->markAsPending();
            $this->publishConversationUpdatedEvent($conversation, 'conversation.status_changed', [
                'status' => 'pending',
                'updated_at' => now()->toDateTimeString(),
            ]);
        } elseif ($status === 'open') {
            $conversation->markAsOpen();
            $this->publishConversationUpdatedEvent($conversation, 'conversation.status_changed', [
                'status' => 'open',
                'updated_at' => now()->toDateTimeString(),
            ]);
        }
    }

    /**
     * Search conversations by subject or content
     */
    public function searchConversations(string $query, int $perPage = 20): Paginator
    {
        return Conversation::where('subject', 'like', "%{$query}%")
            ->orWhere('description', 'like', "%{$query}%")
            ->orWhereHas('messages', function ($q) use ($query) {
                $q->where('message', 'like', "%{$query}%");
            })
            ->orderByDesc('updated_at')
            ->paginate($perPage);
    }

    /**
     * Get conversation statistics
     */
    public function getConversationStats(): array
    {
        return [
            'total' => Conversation::count(),
            'open' => Conversation::where('status', 'open')->count(),
            'pending' => Conversation::where('status', 'pending')->count(),
            'resolved' => Conversation::where('status', 'resolved')->count(),
            'unassigned' => Conversation::whereNull('assigned_agent_id')->count(),
            'avg_resolution_time' => $this->getAverageResolutionTime(),
        ];
    }

    /**
     * Get average resolution time in hours
     */
    private function getAverageResolutionTime(): ?float
    {
        $resolved = Conversation::whereNotNull('resolved_at')
            ->get()
            ->map(function (Conversation $conv) {
                return $conv->resolved_at->diffInMinutes($conv->created_at) / 60;
            });

        return $resolved->isEmpty() ? null : round($resolved->average(), 2);
    }

    /**
     * Publish message event to Pusher for real-time updates
     */
    private function publishMessageEvent(Conversation $conversation, Message $message): void
    {
        $appId = (string) config('services.pusher.app_id', '');
        $key = (string) config('services.pusher.key', '');
        $secret = (string) config('services.pusher.secret', '');

        if ($appId === '' || $key === '' || $secret === '') {
            return;
        }

        try {
            $pusher = new Pusher(
                $key,
                $secret,
                $appId,
                [
                    'cluster' => (string) config('services.pusher.cluster', 'ap1'),
                    'useTLS' => (bool) config('services.pusher.use_tls', true),
                ]
            );

            $channelName = "private-conversation-{$conversation->id}";

            $messageData = [
                'id' => (int) $message->id,
                'conversation_id' => (int) $message->conversation_id,
                'sender_id' => (int) $message->sender_id,
                'message' => (string) $message->message,
                'is_internal' => (bool) $message->is_internal,
                'attachment_url' => $message->attachment_url,
                'attachment_filename' => $message->attachment_filename,
                'is_read' => (bool) $message->read_at,
                'created_at' => $message->created_at->toDateTimeString(),
            ];

            $pusher->trigger($channelName, 'message.sent', $messageData);
        } catch (\Throwable $e) {
            Log::warning('Failed to publish conversation message event.', [
                'conversation_id' => (int) $conversation->id,
                'message_id' => (int) $message->id,
                'sender_id' => (int) $message->sender_id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Publish conversation updated event for status or assignment changes
     */
    private function publishConversationUpdatedEvent(Conversation $conversation, string $eventType, array $data): void
    {
        $appId = (string) config('services.pusher.app_id', '');
        $key = (string) config('services.pusher.key', '');
        $secret = (string) config('services.pusher.secret', '');

        if ($appId === '' || $key === '' || $secret === '') {
            return;
        }

        try {
            $pusher = new Pusher(
                $key,
                $secret,
                $appId,
                [
                    'cluster' => (string) config('services.pusher.cluster', 'ap1'),
                    'useTLS' => (bool) config('services.pusher.use_tls', true),
                ]
            );

            $channelName = "private-conversation-{$conversation->id}";

            $eventData = array_merge([
                'conversation_id' => (int) $conversation->id,
                'event_type' => $eventType,
                'updated_at' => now()->toDateTimeString(),
            ], $data);

            $pusher->trigger($channelName, 'conversation.updated', $eventData);
        } catch (\Throwable $e) {
            Log::warning('Failed to publish conversation updated event.', [
                'conversation_id' => (int) $conversation->id,
                'event_type' => $eventType,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Publish messages read event
     */
    private function publishMessagesReadEvent(Conversation $conversation, array $messageIds): void
    {
        $appId = (string) config('services.pusher.app_id', '');
        $key = (string) config('services.pusher.key', '');
        $secret = (string) config('services.pusher.secret', '');

        if ($appId === '' || $key === '' || $secret === '') {
            return;
        }

        try {
            $pusher = new Pusher(
                $key,
                $secret,
                $appId,
                [
                    'cluster' => (string) config('services.pusher.cluster', 'ap1'),
                    'useTLS' => (bool) config('services.pusher.use_tls', true),
                ]
            );

            $channelName = "private-conversation-{$conversation->id}";

            $eventData = [
                'conversation_id' => (int) $conversation->id,
                'message_ids' => $messageIds,
                'read_at' => now()->toDateTimeString(),
            ];

            $pusher->trigger($channelName, 'messages.read', $eventData);
        } catch (\Throwable $e) {
            Log::warning('Failed to publish messages read event.', [
                'conversation_id' => (int) $conversation->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
