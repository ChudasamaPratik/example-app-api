<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\User;
use App\Models\Message;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Exception;

class ConversationController extends Controller
{

    public function index(Request $request)
    {
        try {
            $user = $request->user();

            // Get all conversations with the latest message and unread count
            $conversations = $user->conversations()
                ->with(['users' => function($query) use ($user) {
                    $query->where('users.id', '!=', $user->id);
                }])
                ->withCount(['messages as unread_count' => function($query) use ($user) {
                    $query->whereDoesntHave('statuses', function($q) use ($user) {
                        $q->where('user_id', $user->id)
                          ->where('seen', true);
                    })
                    ->where('sender_id', '!=', $user->id);
                }])
                ->with(['lastMessage' => function($query) {
                    $query->with('sender:id,name');
                }])
                ->get();

            return app('api-response-helper')->success(200, $conversations, 'Conversations retrieved successfully');

        } catch (Exception $e) {
            Log::error('Get conversations error: ' . $e->getMessage());
            return app('api-response-helper')->error('Failed to retrieve conversations. Please try again.', [], 500);
        }
    }

    public function createOneToOne(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'recipient_id' => 'required|uuid|exists:users,id',
            ]);

            if ($validator->fails()) {
                return app('api-response-helper')->error('Validation error', $validator->errors(), 422);
            }

            $user = $request->user();
            $recipientId = $request->recipient_id;

            // Check if user is trying to create a conversation with themselves
            if ($user->id === $recipientId) {
                return app('api-response-helper')->error('Cannot create a conversation with yourself', [], 422);
            }

            // Check if a one-to-one conversation already exists between these users
            $existingConversation = Conversation::whereHas('users', function($query) use ($user) {
                    $query->where('users.id', $user->id);
                })
                ->whereHas('users', function($query) use ($recipientId) {
                    $query->where('users.id', $recipientId);
                })
                ->where('type', 'one_to_one')
                ->first();

            if ($existingConversation) {
                return app('api-response-helper')->success(200, $existingConversation->load('users'), 'Conversation already exists');
            }

            DB::beginTransaction();

            // Create new conversation
            $conversation = new Conversation();
            $conversation->id = (string) Str::uuid();
            $conversation->type = 'one_to_one';
            $conversation->created_by = $user->id;
            $conversation->save();

            // Attach users to conversation
            $conversation->users()->attach([$user->id, $recipientId]);

            DB::commit();

            return app('api-response-helper')->success(
                201,
                $conversation->load('users'),
                'Conversation created successfully'
            );

        } catch (Exception $e) {
            DB::rollBack();
            Log::error('Create one-to-one conversation error: ' . $e->getMessage());
            return app('api-response-helper')->error('Failed to create conversation. Please try again.', [], 500);
        }
    }

    public function createGroup(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'name' => 'required|string|max:255',
                'user_ids' => 'required|array|min:1',
                'user_ids.*' => 'required|uuid|exists:users,id',
                'avatar' => 'nullable|image|mimes:jpeg,png,jpg|max:2048',
            ]);

            if ($validator->fails()) {
                return app('api-response-helper')->error('Validation error', $validator->errors(), 422);
            }

            $user = $request->user();

            // Include the creator in the group
            $userIds = array_unique(array_merge([$user->id], $request->user_ids));

            DB::beginTransaction();

            // Create new group conversation
            $conversation = new Conversation();
            $conversation->id = (string) Str::uuid();
            $conversation->type = 'group';
            $conversation->name = $request->name;
            $conversation->created_by = $user->id;

            // Handle avatar upload
            if ($request->hasFile('avatar')) {
                $avatarPath = $request->file('avatar')->store('conversations', 'public');
                $conversation->avatar = $avatarPath;
            }

            $conversation->save();

            // Attach users to the group
            $conversation->users()->attach($userIds);

            // Create system message
            $message = new Message();
            $message->id = (string) Str::uuid();
            $message->conversation_id = $conversation->id;
            $message->sender_id = $user->id;
            $message->body = 'Group created by ' . $user->name;
            $message->type = 'text';
            $message->save();

            DB::commit();

            return app('api-response-helper')->success(
                201,
                $conversation->load('users'),
                'Group conversation created successfully'
            );

        } catch (Exception $e) {
            DB::rollBack();
            Log::error('Create group conversation error: ' . $e->getMessage());
            return app('api-response-helper')->error('Failed to create group. Please try again.', [], 500);
        }
    }


    public function show(Request $request, $id)
    {
        try {
            $user = $request->user();

            // Check if the user is part of this conversation
            $conversation = $user->conversations()
                ->with(['users' => function($query) use ($user) {
                    $query->where('users.id', '!=', $user->id);
                }])
                ->find($id);

            if (!$conversation) {
                return app('api-response-helper')->error('Conversation not found or you are not a member', [], 404);
            }

            // Get messages with pagination
            $page = $request->get('page', 1);
            $limit = $request->get('limit', 20);

            $messagesQuery = $conversation->messages()
                ->with(['sender:id,name,avatar', 'reactions.user:id,name', 'replyTo'])
                ->orderBy('created_at', 'desc');

            $paginatedMessages = app('api-response-helper')->getPagination($messagesQuery, $page, $limit);

            // Mark messages as seen
            $this->markMessagesAsSeen($user, $conversation);

            $result = [
                'conversation' => $conversation,
                'messages' => $paginatedMessages['data'],
                'total_messages' => $paginatedMessages['total_counts'],
                'total_pages' => $paginatedMessages['total_pages']
            ];

            return app('api-response-helper')->success(200, $result, 'Conversation retrieved successfully');

        } catch (Exception $e) {
            Log::error('Get conversation error: ' . $e->getMessage());
            return app('api-response-helper')->error('Failed to retrieve conversation. Please try again.', [], 500);
        }
    }


    public function update(Request $request, $id)
    {
        try {
            $validator = Validator::make($request->all(), [
                'name' => 'sometimes|string|max:255',
                'avatar' => 'sometimes|image|mimes:jpeg,png,jpg|max:2048',
            ]);

            if ($validator->fails()) {
                return app('api-response-helper')->error('Validation error', $validator->errors(), 422);
            }

            $user = $request->user();

            // Get conversation and check if user is the creator
            $conversation = $user->conversations()->find($id);

            if (!$conversation) {
                return app('api-response-helper')->error('Conversation not found or you are not a member', [], 404);
            }

            // Only group conversations can be updated
            if ($conversation->type !== 'group') {
                return app('api-response-helper')->error('Only group conversations can be updated', [], 422);
            }

            // Only the creator can update the conversation
            if ($conversation->created_by !== $user->id) {
                return app('api-response-helper')->error('Only the group creator can update the group', [], 403);
            }

            $oldName = $conversation->name;
            $nameChanged = false;

            DB::beginTransaction();

            // Update conversation details
            if ($request->has('name') && $request->name !== $conversation->name) {
                $nameChanged = true;
                $conversation->name = $request->name;
            }

            // Handle avatar upload
            if ($request->hasFile('avatar')) {
                // Delete old avatar if exists
                if ($conversation->avatar) {
                    Storage::disk('public')->delete($conversation->avatar);
                }

                // Store new avatar
                $avatarPath = $request->file('avatar')->store('conversations', 'public');
                $conversation->avatar = $avatarPath;
            }

            $conversation->save();

            // Create system message about name change
            if ($nameChanged) {
                $message = new Message();
                $message->id = (string) Str::uuid();
                $message->conversation_id = $conversation->id;
                $message->sender_id = $user->id;
                $message->body = $user->name . ' changed the group name from "' . $oldName . '" to "' . $conversation->name . '"';
                $message->type = 'text';
                $message->save();
            }

            DB::commit();

            return app('api-response-helper')->success(
                200,
                $conversation->load('users'),
                'Conversation updated successfully'
            );

        } catch (Exception $e) {
            DB::rollBack();
            Log::error('Update conversation error: ' . $e->getMessage());
            return app('api-response-helper')->error('Failed to update conversation. Please try again.', [], 500);
        }
    }

    public function addUsers(Request $request, $id)
    {
        try {
            $validator = Validator::make($request->all(), [
                'user_ids' => 'required|array|min:1',
                'user_ids.*' => 'required|uuid|exists:users,id',
            ]);

            if ($validator->fails()) {
                return app('api-response-helper')->error('Validation error', $validator->errors(), 422);
            }

            $user = $request->user();

            // Get conversation and check if user is the creator
            $conversation = $user->conversations()->find($id);

            if (!$conversation) {
                return app('api-response-helper')->error('Conversation not found or you are not a member', [], 404);
            }

            // Only group conversations can have users added
            if ($conversation->type !== 'group') {
                return app('api-response-helper')->error('Only group conversations can have users added', [], 422);
            }

            // Only the creator can add users
            if ($conversation->created_by !== $user->id) {
                return app('api-response-helper')->error('Only the group creator can add users', [], 403);
            }

            // Get existing user IDs
            $existingUserIds = $conversation->users()->pluck('users.id')->toArray();

            // Filter out users that are already in the conversation
            $newUserIds = array_diff($request->user_ids, $existingUserIds);

            if (empty($newUserIds)) {
                return app('api-response-helper')->error('All users are already in this conversation', [], 422);
            }

            DB::beginTransaction();

            // Add new users to the conversation
            $conversation->users()->attach($newUserIds);

            // Create system message about new users
            $newUsers = User::whereIn('id', $newUserIds)->get();
            $names = $newUsers->pluck('name')->implode(', ');

            $message = new Message();
            $message->id = (string) Str::uuid();
            $message->conversation_id = $conversation->id;
            $message->sender_id = $user->id;
            $message->body = $user->name . ' added ' . $names . ' to the group';
            $message->type = 'text';
            $message->save();

            DB::commit();

            return app('api-response-helper')->success(
                200,
                $conversation->load('users'),
                'Users added to conversation successfully'
            );

        } catch (Exception $e) {
            DB::rollBack();
            Log::error('Add users to conversation error: ' . $e->getMessage());
            return app('api-response-helper')->error('Failed to add users to conversation. Please try again.', [], 500);
        }
    }


    public function removeUser(Request $request, $id, $userId)
    {
        try {
            $user = $request->user();

            // Get conversation and check if user is the creator
            $conversation = $user->conversations()->find($id);

            if (!$conversation) {
                return app('api-response-helper')->error('Conversation not found or you are not a member', [], 404);
            }

            // Only group conversations can have users removed
            if ($conversation->type !== 'group') {
                return app('api-response-helper')->error('Only group conversations can have users removed', [], 422);
            }

            // Only the creator can remove users
            if ($conversation->created_by !== $user->id) {
                return app('api-response-helper')->error('Only the group creator can remove users', [], 403);
            }

            // Creator cannot remove themselves
            if ($userId === $user->id) {
                return app('api-response-helper')->error('Group creator cannot be removed from the group', [], 422);
            }

            // Check if the user to be removed is in the conversation
            if (!$conversation->users()->where('users.id', $userId)->exists()) {
                return app('api-response-helper')->error('User is not in this conversation', [], 404);
            }

            DB::beginTransaction();

            // Remove the user from the conversation
            $conversation->users()->detach($userId);

            // Create system message about removed user
            $removedUser = User::findOrFail($userId);

            $message = new Message();
            $message->id = (string) Str::uuid();
            $message->conversation_id = $conversation->id;
            $message->sender_id = $user->id;
            $message->body = $user->name . ' removed ' . $removedUser->name . ' from the group';
            $message->type = 'text';
            $message->save();

            DB::commit();

            return app('api-response-helper')->success(
                200,
                $conversation->load('users'),
                'User removed from conversation successfully'
            );

        } catch (Exception $e) {
            DB::rollBack();
            Log::error('Remove user from conversation error: ' . $e->getMessage());
            return app('api-response-helper')->error('Failed to remove user from conversation. Please try again.', [], 500);
        }
    }

    public function leaveGroup(Request $request, $id)
    {
        try {
            $user = $request->user();

            // Get conversation
            $conversation = $user->conversations()->find($id);

            if (!$conversation) {
                return app('api-response-helper')->error('Conversation not found or you are not a member', [], 404);
            }

            // Only group conversations can be left
            if ($conversation->type !== 'group') {
                return app('api-response-helper')->error('Only group conversations can be left', [], 422);
            }

            // If user is the creator, they can't leave unless they transfer ownership
            if ($conversation->created_by === $user->id) {
                return app('api-response-helper')->error('As the creator, you must transfer ownership before leaving the group', [], 422);
            }

            DB::beginTransaction();

            // Remove the user from the conversation
            $conversation->users()->detach($user->id);

            // Create system message about user leaving
            $message = new Message();
            $message->id = (string) Str::uuid();
            $message->conversation_id = $conversation->id;
            $message->sender_id = $user->id;
            $message->body = $user->name . ' left the group';
            $message->type = 'text';
            $message->save();

            DB::commit();

            return app('api-response-helper')->success(200, null, 'You have left the conversation successfully');

        } catch (Exception $e) {
            DB::rollBack();
            Log::error('Leave group error: ' . $e->getMessage());
            return app('api-response-helper')->error('Failed to leave group. Please try again.', [], 500);
        }
    }

    public function transferOwnership(Request $request, $id)
    {
        try {
            $validator = Validator::make($request->all(), [
                'new_owner_id' => 'required|uuid|exists:users,id',
            ]);

            if ($validator->fails()) {
                return app('api-response-helper')->error('Validation error', $validator->errors(), 422);
            }

            $user = $request->user();
            $newOwnerId = $request->new_owner_id;

            // Get conversation and check if user is the creator
            $conversation = $user->conversations()->find($id);

            if (!$conversation) {
                return app('api-response-helper')->error('Conversation not found or you are not a member', [], 404);
            }

            // Only group conversations can have ownership transferred
            if ($conversation->type !== 'group') {
                return app('api-response-helper')->error('Only group conversations can have ownership transferred', [], 422);
            }

            // Only the creator can transfer ownership
            if ($conversation->created_by !== $user->id) {
                return app('api-response-helper')->error('Only the group creator can transfer ownership', [], 403);
            }

            // Check if the new owner is in the conversation
            if (!$conversation->users()->where('users.id', $newOwnerId)->exists()) {
                return app('api-response-helper')->error('New owner is not in this conversation', [], 404);
            }

            DB::beginTransaction();

            // Transfer ownership
            $conversation->created_by = $newOwnerId;
            $conversation->save();

            // Create system message about ownership transfer
            $newOwner = User::findOrFail($newOwnerId);

            $message = new Message();
            $message->id = (string) Str::uuid();
            $message->conversation_id = $conversation->id;
            $message->sender_id = $user->id;
            $message->body = $user->name . ' transferred group ownership to ' . $newOwner->name;
            $message->type = 'text';
            $message->save();

            DB::commit();

            return app('api-response-helper')->success(
                200,
                $conversation->load('users'),
                'Group ownership transferred successfully'
            );

        } catch (Exception $e) {
            DB::rollBack();
            Log::error('Transfer ownership error: ' . $e->getMessage());
            return app('api-response-helper')->error('Failed to transfer ownership. Please try again.', [], 500);
        }
    }

    public function destroy(Request $request, $id)
    {
        try {
            $user = $request->user();

            // Get conversation
            $conversation = $user->conversations()->find($id);

            if (!$conversation) {
                return app('api-response-helper')->error('Conversation not found or you are not a member', [], 404);
            }

            DB::beginTransaction();

            // For one-to-one conversations, just detach the user
            if ($conversation->type === 'one_to_one') {
                $conversation->users()->detach($user->id);

                // If no users are left, delete the conversation
                if ($conversation->users()->count() === 0) {
                    // Delete all messages, message statuses, message reactions
                    $messageIds = $conversation->messages()->pluck('id');

                    if ($messageIds->count() > 0) {
                        DB::table('message_statuses')->whereIn('message_id', $messageIds)->delete();
                        DB::table('message_reactions')->whereIn('message_id', $messageIds)->delete();
                    }

                    $conversation->messages()->delete();
                    $conversation->delete();
                }

                DB::commit();

                return app('api-response-helper')->success(200, null, 'Conversation deleted successfully');
            }

            // For group conversations, only the creator can delete
            if ($conversation->type === 'group') {
                if ($conversation->created_by !== $user->id) {
                    DB::rollBack();
                    return app('api-response-helper')->error('Only the group creator can delete the group', [], 403);
                }

                // Delete the conversation and all related data
                // Delete all messages, message statuses, message reactions
                $messageIds = $conversation->messages()->pluck('id');

                if ($messageIds->count() > 0) {
                    DB::table('message_statuses')->whereIn('message_id', $messageIds)->delete();
                    DB::table('message_reactions')->whereIn('message_id', $messageIds)->delete();
                }

                $conversation->messages()->delete();
                $conversation->users()->detach();
                $conversation->delete();

                DB::commit();

                return app('api-response-helper')->success(200, null, 'Group conversation deleted successfully');
            }

            DB::rollBack();
            return app('api-response-helper')->error('Invalid conversation type', [], 500);

        } catch (Exception $e) {
            DB::rollBack();
            Log::error('Delete conversation error: ' . $e->getMessage());
            return app('api-response-helper')->error('Failed to delete conversation. Please try again.', [], 500);
        }
    }


    public function search(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'query' => 'required|string|min:1',
            ]);

            if ($validator->fails()) {
                return app('api-response-helper')->error('Validation error', $validator->errors(), 422);
            }

            $user = $request->user();
            $query = $request->query('query');

            // Search in one-to-one conversations by user name
            $oneToOneConversations = $user->conversations()
                ->where('type', 'one_to_one')
                ->whereHas('users', function($q) use ($query, $user) {
                    $q->where('users.id', '!=', $user->id)
                      ->where('users.name', 'like', '%' . $query . '%');
                })
                ->with(['users' => function($q) use ($user) {
                    $q->where('users.id', '!=', $user->id);
                }])
                ->get();

            // Search in group conversations by group name
            $groupConversations = $user->conversations()
                ->where('type', 'group')
                ->where('name', 'like', '%' . $query . '%')
                ->with(['users' => function($q) use ($user) {
                    $q->where('users.id', '!=', $user->id);
                }])
                ->get();

            // Merge results
            $conversations = $oneToOneConversations->merge($groupConversations);

            return app('api-response-helper')->success(200, $conversations, 'Search results retrieved successfully');

        } catch (Exception $e) {
            Log::error('Search conversations error: ' . $e->getMessage());
            return app('api-response-helper')->error('Failed to search conversations. Please try again.', [], 500);
        }
    }


    public function getAddableUsers(Request $request, $id)
    {
        try {
            $user = $request->user();

            // Get conversation
            $conversation = $user->conversations()->find($id);

            if (!$conversation) {
                return app('api-response-helper')->error('Conversation not found or you are not a member', [], 404);
            }

            // Only group conversations can have users added
            if ($conversation->type !== 'group') {
                return app('api-response-helper')->error('Only group conversations can have users added', [], 422);
            }

            // Get existing user IDs in this conversation
            $existingUserIds = $conversation->users()->pluck('users.id')->toArray();

            // Get users from other conversations the current user is in
            // This assumes you want to add users that the current user already has conversations with
            $addableUsers = User::whereHas('conversations', function($query) use ($user) {
                    $query->whereHas('users', function($q) use ($user) {
                        $q->where('users.id', $user->id);
                    });
                })
                ->whereNotIn('id', $existingUserIds)
                ->where('id', '!=', $user->id)
                ->select('id', 'name', 'avatar', 'email')
                ->get();

            return app('api-response-helper')->success(200, $addableUsers, 'Addable users retrieved successfully');

        } catch (Exception $e) {
            Log::error('Get addable users error: ' . $e->getMessage());
            return app('api-response-helper')->error('Failed to retrieve addable users. Please try again.', [], 500);
        }
    }


    private function markMessagesAsSeen($user, $conversation)
    {
        $messageIds = $conversation->messages()
            ->whereDoesntHave('statuses', function($query) use ($user) {
                $query->where('user_id', $user->id)
                      ->where('seen', true);
            })
            ->where('sender_id', '!=', $user->id)
            ->pluck('id');

        if ($messageIds->isEmpty()) {
            return;
        }

        // Create or update message statuses
        foreach ($messageIds as $messageId) {
            DB::table('message_statuses')
                ->updateOrInsert(
                    [
                        'message_id' => $messageId,
                        'user_id' => $user->id,
                    ],
                    [
                        'delivered' => true,
                        'seen' => true,
                        'updated_at' => now(),
                    ]
                );
        }

        // Broadcast seen status update via WebSocket (will be implemented later)
        // event(new MessagesSeen($messageIds, $user->id, $conversation->id));
    }
}
