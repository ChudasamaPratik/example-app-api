<?php

namespace App\Http\Controllers\API;

use App\Events\UserOnlineStatusChanged;
use App\Events\UserTyping;
use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Exception;

class WebSocketController extends Controller
{
    /**
     * Get WebSocket authentication token for Reverb
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function getToken(Request $request)
    {
        try {
            $user = $request->user();

            // Generate WebSocket token for Reverb
            $token = $user->createToken('websocket')->plainTextToken;

            return app('api-response-helper')->success(200, [
                'token' => $token,
                'user_id' => $user->id,
            ], 'WebSocket token generated successfully');

        } catch (Exception $e) {
            Log::error('Get WebSocket token error: ' . $e->getMessage());
            return app('api-response-helper')->error('Failed to generate WebSocket token. Please try again.', [], 500);
        }
    }

    /**
     * Update user's online status
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function updateStatus(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'status' => 'required|string|in:online,offline',
            ]);

            if ($validator->fails()) {
                return app('api-response-helper')->error('Validation error', $validator->errors(), 422);
            }

            $user = $request->user();

            DB::beginTransaction();

            // Create activity log
            $activityLog = ActivityLog::create([
                'id' => (string) Str::uuid(),
                'user_id' => $user->id,
                'activity_type' => $request->status,
            ]);

            DB::commit();

            // Broadcast status update
            event(new UserOnlineStatusChanged($user->id, $request->status));

            return app('api-response-helper')->success(200, [
                'status' => $request->status,
            ], 'Status updated successfully');

        } catch (Exception $e) {
            DB::rollBack();
            Log::error('Update status error: ' . $e->getMessage());
            return app('api-response-helper')->error('Failed to update status. Please try again.', [], 500);
        }
    }

    /**
     * Get online users from user's contacts
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function getOnlineUsers(Request $request)
    {
        try {
            $user = $request->user();

            // Get all users that have conversations with the current user
            $userContacts = DB::table('conversation_user')
                ->join('conversations', 'conversation_user.conversation_id', '=', 'conversations.id')
                ->join('conversation_user as cu2', 'conversation_user.conversation_id', '=', 'cu2.conversation_id')
                ->join('users', 'cu2.user_id', '=', 'users.id')
                ->where('conversation_user.user_id', $user->id)
                ->where('cu2.user_id', '!=', $user->id)
                ->select('users.id', 'users.name', 'users.avatar')
                ->distinct()
                ->get();

            // Get recent activity logs for these users to determine online status
            $onlineUserIds = ActivityLog::where('activity_type', 'online')
                ->where('created_at', '>=', now()->subMinutes(5))
                ->whereIn('user_id', $userContacts->pluck('id'))
                ->select('user_id')
                ->distinct()
                ->pluck('user_id');

            $onlineUsers = $userContacts->filter(function ($contactUser) use ($onlineUserIds) {
                return $onlineUserIds->contains($contactUser->id);
            })->values();

            return app('api-response-helper')->success(200, $onlineUsers, 'Online users retrieved successfully');

        } catch (Exception $e) {
            Log::error('Get online users error: ' . $e->getMessage());
            return app('api-response-helper')->error('Failed to retrieve online users. Please try again.', [], 500);
        }
    }

    /**
     * Handle typing indicator
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function typing(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'conversation_id' => 'required|uuid|exists:conversations,id',
                'typing' => 'required|boolean',
            ]);

            if ($validator->fails()) {
                return app('api-response-helper')->error('Validation error', $validator->errors(), 422);
            }

            $user = $request->user();
            $conversationId = $request->conversation_id;

            // Check if user is part of this conversation
            $conversation = $user->conversations()->find($conversationId);

            if (!$conversation) {
                return app('api-response-helper')->error('Conversation not found or you are not a member', [], 404);
            }

            if ($request->typing) {
                // Create typing activity
                $activity = ActivityLog::create([
                    'id' => (string) Str::uuid(),
                    'user_id' => $user->id,
                    'activity_type' => 'typing',
                    'conversation_id' => $conversationId,
                ]);

                // Broadcast typing event
                event(new UserTyping($user->id, $user->name, $conversationId));

            } else {
                // User stopped typing, delete typing activities
                ActivityLog::where('user_id', $user->id)
                    ->where('conversation_id', $conversationId)
                    ->where('activity_type', 'typing')
                    ->delete();

                // No need to broadcast stopped typing, the client can infer this from lack of typing events
            }

            return app('api-response-helper')->success(200, [
                'conversation_id' => $conversationId,
                'typing' => $request->typing,
            ], 'Typing status updated');

        } catch (Exception $e) {
            Log::error('Typing status update error: ' . $e->getMessage());
            return app('api-response-helper')->error('Failed to update typing status. Please try again.', [], 500);
        }
    }

    /**
     * Get all user statuses for contacts
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function getAllUserStatuses(Request $request)
    {
        try {
            $user = $request->user();

            // Get all users that have conversations with the current user
            $userContactIds = DB::table('conversation_user')
                ->join('conversations', 'conversation_user.conversation_id', '=', 'conversations.id')
                ->join('conversation_user as cu2', 'conversation_user.conversation_id', '=', 'cu2.conversation_id')
                ->where('conversation_user.user_id', $user->id)
                ->where('cu2.user_id', '!=', $user->id)
                ->select('cu2.user_id')
                ->distinct()
                ->pluck('user_id');

            $userStatuses = [];

            // For each contact, get their current status
            foreach ($userContactIds as $contactId) {
                $latestActivity = ActivityLog::where('user_id', $contactId)
                    ->whereIn('activity_type', ['online', 'offline'])
                    ->orderBy('created_at', 'desc')
                    ->first();

                $status = 'offline';
                $lastSeen = null;

                if ($latestActivity) {
                    if ($latestActivity->activity_type === 'online' &&
                        $latestActivity->created_at->greaterThan(now()->subMinutes(5))) {
                        $status = 'online';
                    } else {
                        $lastSeen = $latestActivity->created_at;
                    }
                }

                $contactUser = User::select('id', 'name', 'avatar')->find($contactId);

                if ($contactUser) {
                    $userStatuses[] = [
                        'user_id' => $contactId,
                        'name' => $contactUser->name,
                        'avatar' => $contactUser->avatar,
                        'status' => $status,
                        'last_seen' => $lastSeen,
                    ];
                }
            }

            return app('api-response-helper')->success(200, $userStatuses, 'User statuses retrieved successfully');

        } catch (Exception $e) {
            Log::error('Get all user statuses error: ' . $e->getMessage());
            return app('api-response-helper')->error('Failed to retrieve user statuses. Please try again.', [], 500);
        }
    }

    /**
     * Get conversations with typing status
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function getConversationsWithTypingStatus(Request $request)
    {
        try {
            $user = $request->user();

            // Get all conversations with users who are currently typing
            $typingActivities = ActivityLog::where('activity_type', 'typing')
                ->where('created_at', '>=', now()->subSeconds(10))
                ->whereHas('user.conversations', function($query) use ($user) {
                    $query->whereHas('users', function($q) use ($user) {
                        $q->where('users.id', $user->id);
                    });
                })
                ->with(['user:id,name'])
                ->get()
                ->groupBy('conversation_id');

            $result = [];

            foreach ($typingActivities as $conversationId => $activities) {
                $typingUsers = $activities->pluck('user')->filter(function($typingUser) use ($user) {
                    return $typingUser->id !== $user->id;
                })->values();

                if ($typingUsers->isNotEmpty()) {
                    $result[] = [
                        'conversation_id' => $conversationId,
                        'typing_users' => $typingUsers,
                    ];
                }
            }

            return app('api-response-helper')->success(200, $result, 'Typing status for conversations retrieved successfully');

        } catch (Exception $e) {
            Log::error('Get conversations with typing status error: ' . $e->getMessage());
            return app('api-response-helper')->error('Failed to retrieve typing status. Please try again.', [], 500);
        }
    }

    /**
     * Ping to keep WebSocket connection alive
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function ping(Request $request)
    {
        try {
            $user = $request->user();

            // Update user's last activity timestamp
            $user->touch();

            // Optionally update user's online status
            if ($request->has('update_status') && $request->update_status) {
                ActivityLog::create([
                    'id' => (string) Str::uuid(),
                    'user_id' => $user->id,
                    'activity_type' => 'online',
                ]);
            }

            return app('api-response-helper')->success(200, [
                'timestamp' => now()->toIso8601String(),
            ], 'Pong');

        } catch (Exception $e) {
            Log::error('Ping error: ' . $e->getMessage());
            return app('api-response-helper')->error('Failed to process ping. Please try again.', [], 500);
        }
    }
}
