<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Customer;
use App\Services\ConversationService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;

class CustomerConversationController extends Controller
{
    protected ConversationService $conversationService;

    public function __construct(ConversationService $conversationService)
    {
        $this->conversationService = $conversationService;
    }

    /**
     * Get all conversations for the authenticated customer
     */
    public function index(Request $request): JsonResponse
    {
        $customer = $request->user();
        if (!$customer instanceof Customer) {
            return response()->json(['message' => 'Only customers can access this resource.'], 403);
        }

        $perPage = (int) $request->query('per_page', 20);
        $status = $request->query('status'); // open, pending, resolved

        $query = Conversation::where('user_id', $customer->c_userid);

        if ($status) {
            $query->where('status', $status);
        }

        $conversations = $query->orderByDesc('updated_at')->paginate($perPage);

        return response()->json([
            'data' => $conversations->map(fn (Conversation $conv) => $this->formatConversation($conv))->values(),
            'meta' => [
                'current_page' => $conversations->currentPage(),
                'last_page' => $conversations->lastPage(),
                'per_page' => $conversations->perPage(),
                'total' => $conversations->total(),
            ],
        ]);
    }

    /**
     * Create a new conversation
     */
    public function store(Request $request): JsonResponse
    {
        $customer = $request->user();
        if (!$customer instanceof Customer) {
            return response()->json(['message' => 'Only customers can create conversations.'], 403);
        }

        $validated = $request->validate([
            'subject' => 'required|string|min:3|max:255',
            'description' => 'nullable|string|max:2000',
        ]);

        try {
            $conversation = $this->conversationService->createConversation(
                $customer,
                $validated['subject'],
                $validated['description'] ?? null
            );

            return response()->json([
                'message' => 'Conversation created successfully.',
                'data' => $this->formatConversation($conversation),
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to create conversation.',
                'error' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Get a specific conversation
     */
    public function show(Request $request, int $id): JsonResponse
    {
        $customer = $request->user();
        if (!$customer instanceof Customer) {
            return response()->json(['message' => 'Only customers can access this resource.'], 403);
        }

        $conversation = Conversation::where('id', $id)
            ->where('user_id', $customer->c_userid)
            ->first();

        if (!$conversation) {
            return response()->json(['message' => 'Conversation not found.'], 404);
        }

        // Mark messages as read
        $this->conversationService->markConversationAsRead($conversation, $customer->c_userid);

        return response()->json([
            'data' => $this->formatConversationDetail($conversation),
        ]);
    }

    /**
     * Send a message to a conversation
     */
    public function sendMessage(Request $request, int $conversationId): JsonResponse
    {
        $customer = $request->user();
        if (!$customer instanceof Customer) {
            return response()->json(['message' => 'Only customers can send messages.'], 403);
        }

        $conversation = Conversation::where('id', $conversationId)
            ->where('user_id', $customer->c_userid)
            ->first();

        if (!$conversation) {
            return response()->json(['message' => 'Conversation not found.'], 404);
        }

        $validated = $request->validate([
            'message' => 'required|string|min:1|max:5000',
            'attachment_url' => 'nullable|url|max:2048',
            'attachment_filename' => 'nullable|string|max:255',
        ]);

        try {
            $message = $this->conversationService->sendMessage(
                $conversation,
                $customer->c_userid,
                $validated['message'],
                false,
                $validated['attachment_url'] ?? null,
                $validated['attachment_filename'] ?? null
            );

            return response()->json([
                'message' => 'Message sent successfully.',
                'data' => $this->formatMessage($message),
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to send message.',
                'error' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Get messages for a conversation
     */
    public function getMessages(Request $request, int $conversationId): JsonResponse
    {
        $customer = $request->user();
        if (!$customer instanceof Customer) {
            return response()->json(['message' => 'Only customers can access this resource.'], 403);
        }

        $conversation = Conversation::where('id', $conversationId)
            ->where('user_id', $customer->c_userid)
            ->first();

        if (!$conversation) {
            return response()->json(['message' => 'Conversation not found.'], 404);
        }

        $perPage = (int) $request->query('per_page', 50);
        $messages = $conversation->messages()->paginate($perPage);

        return response()->json([
            'data' => $messages->map(fn (Message $msg) => $this->formatMessage($msg))->values(),
            'meta' => [
                'current_page' => $messages->currentPage(),
                'last_page' => $messages->lastPage(),
                'per_page' => $messages->perPage(),
                'total' => $messages->total(),
            ],
        ]);
    }

    /**
     * Close/resolve a conversation
     */
    public function closeConversation(Request $request, int $id): JsonResponse
    {
        $customer = $request->user();
        if (!$customer instanceof Customer) {
            return response()->json(['message' => 'Only customers can close conversations.'], 403);
        }

        $conversation = Conversation::where('id', $id)
            ->where('user_id', $customer->c_userid)
            ->first();

        if (!$conversation) {
            return response()->json(['message' => 'Conversation not found.'], 404);
        }

        try {
            $this->conversationService->closeConversation($conversation);

            return response()->json([
                'message' => 'Conversation closed successfully.',
                'data' => $this->formatConversation($conversation->fresh()),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to close conversation.',
                'error' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Reopen a conversation
     */
    public function reopenConversation(Request $request, int $id): JsonResponse
    {
        $customer = $request->user();
        if (!$customer instanceof Customer) {
            return response()->json(['message' => 'Only customers can reopen conversations.'], 403);
        }

        $conversation = Conversation::where('id', $id)
            ->where('user_id', $customer->c_userid)
            ->first();

        if (!$conversation) {
            return response()->json(['message' => 'Conversation not found.'], 404);
        }

        try {
            $this->conversationService->reopenConversation($conversation);

            return response()->json([
                'message' => 'Conversation reopened successfully.',
                'data' => $this->formatConversation($conversation->fresh()),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to reopen conversation.',
                'error' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Get unread conversation count
     */
    public function unreadCount(Request $request): JsonResponse
    {
        $customer = $request->user();
        if (!$customer instanceof Customer) {
            return response()->json(['message' => 'Only customers can access this resource.'], 403);
        }

        $unreadCount = $this->conversationService->getUnreadConversationCount($customer->c_userid);

        return response()->json([
            'unread_count' => $unreadCount,
        ]);
    }

    /**
     * Authenticate Pusher channel for customer conversations
     */
    public function pusherAuth(Request $request): JsonResponse
    {
        $customer = $request->user();
        if (!$customer instanceof Customer) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $validated = $request->validate([
            'socket_id' => 'required|string|max:100',
            'channel_name' => 'required|string|max:255',
        ]);

        $channelName = (string) $validated['channel_name'];
        if (!Str::startsWith($channelName, 'private-conversation-')) {
            return response()->json(['message' => 'Forbidden channel.'], 403);
        }

        // Extract conversation ID from channel name
        $conversationId = (int) str_replace('private-conversation-', '', $channelName);

        // Verify customer has access to this conversation
        $conversation = Conversation::where('id', $conversationId)
            ->where('user_id', $customer->c_userid)
            ->first();

        if (!$conversation) {
            return response()->json(['message' => 'Conversation not found or access denied.'], 403);
        }

        $key = (string) config('services.pusher.key', '');
        $secret = (string) config('services.pusher.secret', '');

        if ($key === '' || $secret === '') {
            return response()->json(['message' => 'Pusher is not configured.'], 503);
        }

        $socketId = (string) $validated['socket_id'];
        $signature = hash_hmac('sha256', $socketId . ':' . $channelName, $secret);

        return response()->json([
            'auth' => $key . ':' . $signature,
        ]);
    }

    /**
     * Format conversation for response
     */
    private function formatConversation(Conversation $conversation): array
    {
        $latestMessage = $conversation->messages()->latest()->first();

        return [
            'id' => (int) $conversation->id,
            'subject' => (string) $conversation->subject,
            'description' => $conversation->description,
            'status' => (string) $conversation->status,
            'assigned_agent_id' => $conversation->assigned_agent_id ? (int) $conversation->assigned_agent_id : null,
            'assigned_agent' => $conversation->assignedAgent ? [
                'id' => (int) $conversation->assignedAgent->id,
                'name' => (string) $conversation->assignedAgent->fname,
                'email' => (string) $conversation->assignedAgent->user_email,
            ] : null,
            'last_message' => $latestMessage ? [
                'message' => $latestMessage->message,
                'sent_at' => $latestMessage->created_at->toDateTimeString(),
                'sender_id' => (int) $latestMessage->sender_id,
            ] : null,
            'message_count' => $conversation->messages()->count(),
            'unread_count' => $conversation->messages()
                ->where('sender_id', '!=', auth()->id())
                ->whereNull('read_at')
                ->count(),
            'resolved_at' => $conversation->resolved_at?->toDateTimeString(),
            'created_at' => $conversation->created_at->toDateTimeString(),
            'updated_at' => $conversation->updated_at->toDateTimeString(),
        ];
    }

    /**
     * Format conversation with all details
     */
    private function formatConversationDetail(Conversation $conversation): array
    {
        return array_merge($this->formatConversation($conversation), [
            'user' => [
                'id' => (int) $conversation->user->c_userid,
                'name' => trim(implode(' ', array_filter([
                    $conversation->user->c_fname,
                    $conversation->user->c_mname,
                    $conversation->user->c_lname,
                ]))),
                'email' => (string) $conversation->user->c_email,
                'username' => (string) $conversation->user->c_username,
            ],
        ]);
    }

    /**
     * Format message for response
     */
    private function formatMessage(Message $message): array
    {
        return [
            'id' => (int) $message->id,
            'conversation_id' => (int) $message->conversation_id,
            'sender_id' => (int) $message->sender_id,
            'message' => (string) $message->message,
            'is_internal' => (bool) $message->is_internal,
            'attachment_url' => $message->attachment_url,
            'attachment_filename' => $message->attachment_filename,
            'is_read' => (bool) $message->read_at,
            'read_at' => $message->read_at?->toDateTimeString(),
            'created_at' => $message->created_at->toDateTimeString(),
            'updated_at' => $message->updated_at->toDateTimeString(),
        ];
    }
}
