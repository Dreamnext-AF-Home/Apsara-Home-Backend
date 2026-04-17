<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Admin;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Message;
use App\Services\ConversationService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;

class AdminConversationController extends Controller
{
    protected ConversationService $conversationService;

    public function __construct(ConversationService $conversationService)
    {
        $this->conversationService = $conversationService;
    }

    /**
     * Get all conversations (admin only)
     */
    public function index(Request $request): JsonResponse
    {
        $perPage = (int) $request->query('per_page', 25);
        $status = $request->query('status'); // open, pending, resolved
        $assignedToMe = $request->boolean('assigned_to_me', false);
        $search = $request->query('search'); // Search by customer name or subject

        $query = Conversation::query();

        if ($status) {
            $query->where('status', $status);
        }

        if ($assignedToMe) {
            $admin = $request->user();
            if ($admin instanceof Admin) {
                $query->where('assigned_agent_id', $admin->id);
            }
        }

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('subject', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%")
                    ->orWhereHas('user', function ($uq) use ($search) {
                        $uq->where('c_username', 'like', "%{$search}%")
                            ->orWhere('c_email', 'like', "%{$search}%")
                            ->orWhere('c_fname', 'like', "%{$search}%")
                            ->orWhere('c_lname', 'like', "%{$search}%");
                    });
            });
        }

        $conversations = $query->orderByDesc('updated_at')->paginate($perPage);

        return response()->json([
            'data' => $conversations->map(fn (Conversation $conv) => $this->formatConversationForAdmin($conv))->values(),
            'meta' => [
                'current_page' => $conversations->currentPage(),
                'last_page' => $conversations->lastPage(),
                'per_page' => $conversations->perPage(),
                'total' => $conversations->total(),
            ],
        ]);
    }

    /**
     * Get a specific conversation
     */
    public function show(int $id): JsonResponse
    {
        $conversation = Conversation::find($id);

        if (!$conversation) {
            return response()->json(['message' => 'Conversation not found.'], 404);
        }

        return response()->json([
            'data' => $this->formatConversationDetailForAdmin($conversation),
        ]);
    }

    /**
     * Assign conversation to agent
     */
    public function assignAgent(Request $request, int $id): JsonResponse
    {
        $conversation = Conversation::find($id);

        if (!$conversation) {
            return response()->json(['message' => 'Conversation not found.'], 404);
        }

        $validated = $request->validate([
            'agent_id' => 'required|integer|exists:tbl_admin,id',
        ]);

        $agent = Admin::find($validated['agent_id']);

        if (!$agent) {
            return response()->json(['message' => 'Agent not found.'], 404);
        }

        try {
            $this->conversationService->assignAgent($conversation, $agent);

            return response()->json([
                'message' => 'Agent assigned successfully.',
                'data' => $this->formatConversationForAdmin($conversation->fresh()),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to assign agent.',
                'error' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Unassign agent from conversation
     */
    public function unassignAgent(int $id): JsonResponse
    {
        $conversation = Conversation::find($id);

        if (!$conversation) {
            return response()->json(['message' => 'Conversation not found.'], 404);
        }

        try {
            $this->conversationService->unassignAgent($conversation);

            return response()->json([
                'message' => 'Agent unassigned successfully.',
                'data' => $this->formatConversationForAdmin($conversation->fresh()),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to unassign agent.',
                'error' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Send a message to conversation (agent/admin)
     */
    public function sendMessage(Request $request, int $conversationId): JsonResponse
    {
        $admin = $request->user();
        if (!$admin instanceof Admin) {
            return response()->json(['message' => 'Only admins can send messages.'], 403);
        }

        $conversation = Conversation::find($conversationId);

        if (!$conversation) {
            return response()->json(['message' => 'Conversation not found.'], 404);
        }

        $validated = $request->validate([
            'message' => 'required|string|min:1|max:5000',
            'is_internal' => 'boolean',
            'attachment_url' => 'nullable|url|max:2048',
            'attachment_filename' => 'nullable|string|max:255',
        ]);

        try {
            // Use admin ID for sender - you may need to adjust this based on your user structure
            $message = $this->conversationService->sendMessage(
                $conversation,
                $admin->id,
                $validated['message'],
                (bool) ($validated['is_internal'] ?? false),
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
        $conversation = Conversation::find($conversationId);

        if (!$conversation) {
            return response()->json(['message' => 'Conversation not found.'], 404);
        }

        $perPage = (int) $request->query('per_page', 50);
        $showInternal = $request->boolean('internal', false);

        $query = $conversation->messages();

        if (!$showInternal) {
            $query->where('is_internal', false);
        }

        $messages = $query->paginate($perPage);

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
     * Update conversation status
     */
    public function updateStatus(Request $request, int $id): JsonResponse
    {
        $conversation = Conversation::find($id);

        if (!$conversation) {
            return response()->json(['message' => 'Conversation not found.'], 404);
        }

        $validated = $request->validate([
            'status' => 'required|in:open,pending,resolved',
        ]);

        try {
            $this->conversationService->updateConversationStatus($conversation, $validated['status']);

            return response()->json([
                'message' => 'Conversation status updated.',
                'data' => $this->formatConversationForAdmin($conversation->fresh()),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to update status.',
                'error' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Get conversation statistics
     */
    public function statistics(): JsonResponse
    {
        $stats = $this->conversationService->getConversationStats();

        return response()->json([
            'data' => $stats,
        ]);
    }

    /**
     * Get open/unassigned conversations
     */
    public function openConversations(Request $request): JsonResponse
    {
        $perPage = (int) $request->query('per_page', 25);

        $conversations = $this->conversationService->getOpenConversations($perPage);

        return response()->json([
            'data' => $conversations->map(fn (Conversation $conv) => $this->formatConversationForAdmin($conv))->values(),
            'meta' => [
                'current_page' => $conversations->currentPage(),
                'last_page' => $conversations->lastPage(),
                'per_page' => $conversations->perPage(),
                'total' => $conversations->total(),
            ],
        ]);
    }

    /**
     * Authenticate Pusher channel for admin conversations
     */
    public function pusherAuth(Request $request): JsonResponse
    {
        $admin = $request->user();
        if (!$admin instanceof Admin) {
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

        // Verify admin has access to this conversation (assigned to them or they're a super_admin/admin)
        $conversation = Conversation::find($conversationId);

        if (!$conversation) {
            return response()->json(['message' => 'Conversation not found.'], 403);
        }

        // Allow access if admin is assigned to the conversation or is an admin/super_admin
        $hasAccess = $conversation->assigned_agent_id === $admin->id ||
            in_array($admin->role, ['super_admin', 'admin']);

        if (!$hasAccess) {
            return response()->json(['message' => 'Access denied.'], 403);
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
     * Format conversation for admin response
     */
    private function formatConversationForAdmin(Conversation $conversation): array
    {
        $latestMessage = $conversation->messages()->latest()->first();

        return [
            'id' => (int) $conversation->id,
            'customer' => [
                'id' => (int) $conversation->user->c_userid,
                'name' => trim(implode(' ', array_filter([
                    $conversation->user->c_fname,
                    $conversation->user->c_mname,
                    $conversation->user->c_lname,
                ]))),
                'username' => (string) $conversation->user->c_username,
                'email' => (string) $conversation->user->c_email,
                'mobile' => (string) $conversation->user->c_mobile,
            ],
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
                'is_internal' => (bool) $latestMessage->is_internal,
            ] : null,
            'message_count' => $conversation->messages()->count(),
            'unread_count' => $conversation->messages()
                ->where('is_internal', false)
                ->whereNull('read_at')
                ->count(),
            'resolved_at' => $conversation->resolved_at?->toDateTimeString(),
            'created_at' => $conversation->created_at->toDateTimeString(),
            'updated_at' => $conversation->updated_at->toDateTimeString(),
        ];
    }

    /**
     * Format conversation detail for admin
     */
    private function formatConversationDetailForAdmin(Conversation $conversation): array
    {
        return array_merge($this->formatConversationForAdmin($conversation), [
            'messages' => $conversation->messages()
                ->orderBy('created_at')
                ->get()
                ->map(fn (Message $msg) => $this->formatMessage($msg))
                ->values(),
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
