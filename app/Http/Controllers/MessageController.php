<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Message;
use App\Models\Conversation;
use App\Models\MessageReaction;
use App\Models\MessageStatus;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Exception;

class MessageController extends Controller
{
    public function sendMessage(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'conversation_id' => 'required|uuid|exists:conversations,id',
                'body' => 'required_without:file|string|nullable',
                'file' => 'required_without:body|file|max:50000|nullable',
                'reply_to_id' => 'nullable|uuid|exists:messages,id',
            ]);

            if ($validator->fails()) {
                return app('api-response-helper')->error('Validation error', $validator->errors(), 422);
            }

            $user = $request->user();
            $conversationId = $request->conversation_id;

            $conversation = $user->conversations()->findOrFail($conversationId);

            DB::beginTransaction();

            $message = new Message();
            $message->id = (string) Str::uuid();
            $message->conversation_id = $conversationId;
            $message->sender_id = $user->id;
            $message->body = $request->body;
            $message->type = 'text';

            if ($request->has('reply_to_id')) {
                $message->reply_to_id = $request->reply_to_id;
            }

            if ($request->hasFile('file')) {
                $file = $request->file('file');
                $fileExtension = $file->getClientOriginalExtension();

                $type = $this->getFileType($fileExtension);
                $message->type = $type;

                $filePath = $file->store('messages/' . $type, 'public');
                $message->file_path = $filePath;
            }

            $message->save();

            $conversation->touch();

            $userIds = $conversation->users()
                ->where('users.id', '!=', $user->id)
                ->pluck('users.id');

            foreach ($userIds as $userId) {
                MessageStatus::create([
                    'id' => (string) Str::uuid(),
                    'message_id' => $message->id,
                    'user_id' => $userId,
                    'delivered' => false,
                    'seen' => false,
                ]);
            }

            DB::commit();

            $message = Message::with(['sender:id,name,avatar', 'replyTo'])->find($message->id);

            return app('api-response-helper')->success(201, $message, 'Message sent successfully');

        } catch (Exception $e) {
            DB::rollBack();
            Log::error('Message send error: ' . $e->getMessage());
            return app('api-response-helper')->error('Failed to send message. Please try again.', [], 500);
        }
    }

    public function getMessages(Request $request, $conversationId)
    {
        try {
            $validator = Validator::make(['conversationId' => $conversationId], [
                'conversationId' => 'required|uuid|exists:conversations,id',
            ]);

            if ($validator->fails()) {
                return app('api-response-helper')->error('Validation error', $validator->errors(), 422);
            }

            $user = $request->user();
            $page = $request->get('page', 1);
            $limit = $request->get('limit', 20);

            $conversation = $user->conversations()->findOrFail($conversationId);

            $messagesQuery = $conversation->messages()
                ->with(['sender:id,name,avatar', 'reactions.user:id,name', 'replyTo'])
                ->orderBy('created_at', 'desc');

            $paginatedMessages = app('api-response-helper')->getPagination($messagesQuery, $page, $limit);

            $this->markMessagesAsSeen($user, $conversation);

            return app('api-response-helper')->success(
                200,
                $paginatedMessages['data'],
                'Messages retrieved successfully',
                [
                    'total_counts' => $paginatedMessages['total_counts'],
                    'total_pages' => $paginatedMessages['total_pages'],
                ]
            );

        } catch (Exception $e) {
            Log::error('Get messages error: ' . $e->getMessage());
            return app('api-response-helper')->error('Failed to retrieve messages. Please try again.', [], 500);
        }
    }

    public function editMessage(Request $request, $id)
    {
        try {
            $validator = Validator::make($request->all(), [
                'body' => 'required|string',
            ]);

            if ($validator->fails()) {
                return app('api-response-helper')->error('Validation error', $validator->errors(), 422);
            }

            $user = $request->user();

            $message = Message::where('id', $id)
                ->where('sender_id', $user->id)
                ->first();

            if (!$message) {
                return app('api-response-helper')->error('Message not found or you are not authorized to edit it', [], 404);
            }

            if ($message->type !== 'text') {
                return app('api-response-helper')->error('Only text messages can be edited', [], 422);
            }

            $message->body = $request->body;
            $message->save();

            $message = Message::with(['sender:id,name,avatar', 'replyTo'])->find($id);

            return app('api-response-helper')->success(200, $message, 'Message updated successfully');

        } catch (Exception $e) {
            Log::error('Edit message error: ' . $e->getMessage());
            return app('api-response-helper')->error('Failed to edit message. Please try again.', [], 500);
        }
    }

    public function deleteMessage(Request $request, $id)
    {
        try {
            $validator = Validator::make($request->all(), [
                'delete_for_everyone' => 'nullable|boolean',
            ]);

            if ($validator->fails()) {
                return app('api-response-helper')->error('Validation error', $validator->errors(), 422);
            }

            $user = $request->user();
            $deleteForEveryone = $request->delete_for_everyone ?? false;

            $message = Message::find($id);

            if (!$message) {
                return app('api-response-helper')->error('Message not found', [], 404);
            }

            $conversation = $user->conversations()
                ->where('conversations.id', $message->conversation_id)
                ->first();

            if (!$conversation) {
                return app('api-response-helper')->error('You are not authorized to access this conversation', [], 403);
            }

            if ($deleteForEveryone && $message->sender_id !== $user->id) {
                if ($conversation->type === 'group' && $conversation->created_by !== $user->id) {
                    return app('api-response-helper')->error('Unauthorized to delete this message for everyone', [], 403);
                }
            }

            DB::beginTransaction();

            if ($deleteForEveryone) {
                $message->statuses()->delete();
                $message->reactions()->delete();

                if ($message->file_path) {
                    Storage::disk('public')->delete($message->file_path);
                }

                $message->delete();

                DB::commit();

                return app('api-response-helper')->success(200, null, 'Message deleted for everyone');
            } else {
                DB::commit();

                return app('api-response-helper')->success(200, null, 'Message deleted for you');
            }

        } catch (Exception $e) {
            DB::rollBack();
            Log::error('Delete message error: ' . $e->getMessage());
            return app('api-response-helper')->error('Failed to delete message. Please try again.', [], 500);
        }
    }

    public function addReaction(Request $request, $id)
    {
        try {
            $validator = Validator::make($request->all(), [
                'reaction' => 'required|string|in:❤️,😂,👍',
            ]);

            if ($validator->fails()) {
                return app('api-response-helper')->error('Validation error', $validator->errors(), 422);
            }

            $user = $request->user();

            $message = Message::find($id);

            if (!$message) {
                return app('api-response-helper')->error('Message not found', [], 404);
            }

            $conversation = $user->conversations()
                ->where('conversations.id', $message->conversation_id)
                ->first();

            if (!$conversation) {
                return app('api-response-helper')->error('You are not authorized to access this conversation', [], 403);
            }

            DB::beginTransaction();

            $existingReaction = MessageReaction::where('message_id', $id)
                ->where('user_id', $user->id)
                ->first();

            if ($existingReaction) {
                $existingReaction->reaction = $request->reaction;
                $existingReaction->save();

                $reaction = $existingReaction;
            } else {
                $reaction = new MessageReaction();
                $reaction->id = (string) Str::uuid();
                $reaction->message_id = $id;
                $reaction->user_id = $user->id;
                $reaction->reaction = $request->reaction;
                $reaction->save();
            }

            DB::commit();

            $reactions = MessageReaction::with('user:id,name')
                ->where('message_id', $id)
                ->get();

            return app('api-response-helper')->success(200, $reactions, 'Reaction added successfully');

        } catch (Exception $e) {
            DB::rollBack();
            Log::error('Add reaction error: ' . $e->getMessage());
            return app('api-response-helper')->error('Failed to add reaction. Please try again.', [], 500);
        }
    }

    public function removeReaction(Request $request, $id)
    {
        try {
            $user = $request->user();

            $message = Message::find($id);

            if (!$message) {
                return app('api-response-helper')->error('Message not found', [], 404);
            }

            $conversation = $user->conversations()
                ->where('conversations.id', $message->conversation_id)
                ->first();

            if (!$conversation) {
                return app('api-response-helper')->error('You are not authorized to access this conversation', [], 403);
            }

            DB::beginTransaction();

            MessageReaction::where('message_id', $id)
                ->where('user_id', $user->id)
                ->delete();

            DB::commit();

            $reactions = MessageReaction::with('user:id,name')
                ->where('message_id', $id)
                ->get();

            return app('api-response-helper')->success(200, $reactions, 'Reaction removed successfully');

        } catch (Exception $e) {
            DB::rollBack();
            Log::error('Remove reaction error: ' . $e->getMessage());
            return app('api-response-helper')->error('Failed to remove reaction. Please try again.', [], 500);
        }
    }

    public function forwardMessage(Request $request, $id)
    {
        try {
            $validator = Validator::make($request->all(), [
                'conversation_ids' => 'required|array|min:1',
                'conversation_ids.*' => 'required|uuid|exists:conversations,id',
            ]);

            if ($validator->fails()) {
                return app('api-response-helper')->error('Validation error', $validator->errors(), 422);
            }

            $user = $request->user();

            $originalMessage = Message::find($id);

            if (!$originalMessage) {
                return app('api-response-helper')->error('Message not found', [], 404);
            }

            $userInOriginalConversation = $user->conversations()
                ->where('conversations.id', $originalMessage->conversation_id)
                ->exists();

            if (!$userInOriginalConversation) {
                return app('api-response-helper')->error('You are not authorized to access this message', [], 403);
            }

            DB::beginTransaction();

            $forwardedMessages = [];

            foreach ($request->conversation_ids as $conversationId) {
                $targetConversation = $user->conversations()
                    ->where('conversations.id', $conversationId)
                    ->first();

                if (!$targetConversation) {
                    continue;
                }

                $message = new Message();
                $message->id = (string) Str::uuid();
                $message->conversation_id = $conversationId;
                $message->sender_id = $user->id;
                $message->body = $originalMessage->body;
                $message->type = $originalMessage->type;
                $message->file_path = $originalMessage->file_path;
                $message->save();

                $targetConversation->touch();

                $userIds = $targetConversation->users()
                    ->where('users.id', '!=', $user->id)
                    ->pluck('users.id');

                foreach ($userIds as $userId) {
                    MessageStatus::create([
                        'id' => (string) Str::uuid(),
                        'message_id' => $message->id,
                        'user_id' => $userId,
                        'delivered' => false,
                        'seen' => false,
                    ]);
                }

                $forwardedMessages[] = Message::with(['sender:id,name,avatar'])->find($message->id);
            }

            DB::commit();

            return app('api-response-helper')->success(201, $forwardedMessages, 'Message forwarded successfully');

        } catch (Exception $e) {
            DB::rollBack();
            Log::error('Forward message error: ' . $e->getMessage());
            return app('api-response-helper')->error('Failed to forward message. Please try again.', [], 500);
        }
    }

    public function markAsDelivered(Request $request, $conversationId)
    {
        try {
            $user = $request->user();

            $conversation = $user->conversations()->find($conversationId);

            if (!$conversation) {
                return app('api-response-helper')->error('Conversation not found or you are not a member', [], 404);
            }

            $messageIds = $conversation->messages()
                ->whereDoesntHave('statuses', function($query) use ($user) {
                    $query->where('user_id', $user->id)
                        ->where('delivered', true);
                })
                ->where('sender_id', '!=', $user->id)
                ->pluck('id');

            if ($messageIds->isEmpty()) {
                return app('api-response-helper')->success(200, [], 'No new messages to mark as delivered');
            }

            DB::beginTransaction();

            foreach ($messageIds as $messageId) {
                DB::table('message_statuses')
                    ->updateOrInsert(
                        [
                            'message_id' => $messageId,
                            'user_id' => $user->id,
                        ],
                        [
                            'delivered' => true,
                            'updated_at' => now(),
                        ]
                    );
            }

            DB::commit();

            return app('api-response-helper')->success(200, $messageIds, 'Messages marked as delivered');

        } catch (Exception $e) {
            DB::rollBack();
            Log::error('Mark as delivered error: ' . $e->getMessage());
            return app('api-response-helper')->error('Failed to mark messages as delivered. Please try again.', [], 500);
        }
    }

    public function markAsSeen(Request $request, $conversationId)
    {
        try {
            $user = $request->user();

            $conversation = $user->conversations()->find($conversationId);

            if (!$conversation) {
                return app('api-response-helper')->error('Conversation not found or you are not a member', [], 404);
            }

            $messageIds = $conversation->messages()
                ->whereDoesntHave('statuses', function($query) use ($user) {
                    $query->where('user_id', $user->id)
                        ->where('seen', true);
                })
                ->where('sender_id', '!=', $user->id)
                ->pluck('id');

            if ($messageIds->isEmpty()) {
                return app('api-response-helper')->success(200, [], 'No new messages to mark as seen');
            }

            DB::beginTransaction();

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

            DB::commit();

            return app('api-response-helper')->success(200, $messageIds, 'Messages marked as seen');

        } catch (Exception $e) {
            DB::rollBack();
            Log::error('Mark as seen error: ' . $e->getMessage());
            return app('api-response-helper')->error('Failed to mark messages as seen. Please try again.', [], 500);
        }
    }

    public function getTypingStatus(Request $request, $conversationId)
    {
        try {
            $user = $request->user();

            $conversation = $user->conversations()->find($conversationId);

            if (!$conversation) {
                return app('api-response-helper')->error('Conversation not found or you are not a member', [], 404);
            }

            $typingUsers = DB::table('activity_logs')
                ->join('users', 'activity_logs.user_id', '=', 'users.id')
                ->where('activity_logs.conversation_id', $conversationId)
                ->where('activity_logs.activity_type', 'typing')
                ->where('activity_logs.user_id', '!=', $user->id)
                ->where('activity_logs.created_at', '>=', now()->subSeconds(10))
                ->select('users.id', 'users.name')
                ->get();

            return app('api-response-helper')->success(200, $typingUsers, 'Typing status retrieved successfully');

        } catch (Exception $e) {
            Log::error('Get typing status error: ' . $e->getMessage());
            return app('api-response-helper')->error('Failed to get typing status. Please try again.', [], 500);
        }
    }

    public function setTypingStatus(Request $request, $conversationId)
    {
        try {
            $user = $request->user();

            $conversation = $user->conversations()->find($conversationId);

            if (!$conversation) {
                return app('api-response-helper')->error('Conversation not found or you are not a member', [], 404);
            }

            $user->activityLogs()->create([
                'id' => (string) Str::uuid(),
                'activity_type' => 'typing',
                'conversation_id' => $conversationId,
            ]);

            return app('api-response-helper')->success(200, null, 'Typing status updated');

        } catch (Exception $e) {
            Log::error('Set typing status error: ' . $e->getMessage());
            return app('api-response-helper')->error('Failed to update typing status. Please try again.', [], 500);
        }
    }

    public function uploadFile(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'file' => 'required|file|max:50000',
            ]);

            if ($validator->fails()) {
                return app('api-response-helper')->error('Validation error', $validator->errors(), 422);
            }

            $file = $request->file('file');
            $fileExtension = $file->getClientOriginalExtension();

            $type = $this->getFileType($fileExtension);

            $filePath = $file->store('temp', 'public');

            $fileInfo = [
                'path' => $filePath,
                'type' => $type,
                'name' => $file->getClientOriginalName(),
                'size' => $file->getSize(),
                'extension' => $fileExtension,
            ];

            return app('api-response-helper')->success(200, $fileInfo, 'File uploaded successfully');

        } catch (Exception $e) {
            Log::error('Upload file error: ' . $e->getMessage());
            return app('api-response-helper')->error('Failed to upload file. Please try again.', [], 500);
        }
    }

    private function getFileType($extension)
    {
        $imageExtensions = ['jpg', 'jpeg', 'png', 'gif', 'svg', 'webp'];
        $audioExtensions = ['mp3', 'wav', 'ogg', 'm4a', 'aac'];
        $videoExtensions = ['mp4', 'avi', 'mov', 'wmv', 'flv', 'webm', 'mkv'];

        $extension = strtolower($extension);

        if (in_array($extension, $imageExtensions)) {
            return 'image';
        } elseif (in_array($extension, $audioExtensions)) {
            return 'audio';
        } elseif (in_array($extension, $videoExtensions)) {
            return 'video';
        } else {
            return 'file';
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
    }
}
