<?php

use App\Http\Controllers\API\AuthController;
use App\Http\Controllers\API\ConversationController;
use App\Http\Controllers\API\MessageController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::group(['prefix' => 'auth'], function () {
    // Public routes (no authentication required)
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/login', [AuthController::class, 'login']);
    Route::post('/forgot-password', [AuthController::class, 'forgotPassword']);
    Route::post('/reset-password', [AuthController::class, 'resetPassword']);


    // Protected routes (authentication required)
    Route::group(['middleware' => ['auth:sanctum']], function () {
        Route::post('/logout', [AuthController::class, 'logout']);
        Route::get('/profile', [AuthController::class, 'profile']);
        Route::put('/profile', [AuthController::class, 'updateProfile']);
        Route::post('/change-password', [AuthController::class, 'changePassword']);
        Route::get('/devices', [AuthController::class, 'getDevices']);
        Route::delete('/devices/{deviceId}', [AuthController::class, 'removeDevice']);
        Route::post('/refresh-token', [AuthController::class, 'refreshToken']);
        Route::post('/update-device-token', [AuthController::class, 'updateDeviceToken']);
        Route::get('/user-status/{userId}', [AuthController::class, 'getUserStatus']);

        // New WebSocket related route
        Route::get('/websocket-config', [AuthController::class, 'getWebSocketConfig']);
    });
});


Route::group(['prefix' => 'messages', 'middleware' => ['auth:sanctum']], function () {
    // Send a message
    Route::post('/', [MessageController::class, 'sendMessage']);

    // Get messages for a conversation with pagination
    Route::get('/conversation/{conversationId}', [MessageController::class, 'getMessages']);

    // Edit a message
    Route::put('/{id}', [MessageController::class, 'editMessage']);

    // Delete a message
    Route::delete('/{id}', [MessageController::class, 'deleteMessage']);

    // Forward a message
    Route::post('/{id}/forward', [MessageController::class, 'forwardMessage']);

    // Add reaction to a message
    Route::post('/{id}/reaction', [MessageController::class, 'addReaction']);

    // Remove reaction from a message
    Route::delete('/{id}/reaction', [MessageController::class, 'removeReaction']);

    // Mark messages as delivered for a conversation
    Route::post('/delivered/{conversationId}', [MessageController::class, 'markAsDelivered']);

    // Mark messages as seen for a conversation
    Route::post('/seen/{conversationId}', [MessageController::class, 'markAsSeen']);

    // Get typing status for a conversation
    Route::get('/typing/{conversationId}', [MessageController::class, 'getTypingStatus']);

    // Set typing status for a conversation
    Route::post('/typing/{conversationId}', [MessageController::class, 'setTypingStatus']);

    // Upload file for a message
    Route::post('/upload', [MessageController::class, 'uploadFile']);
});



Route::group(['prefix' => 'conversations', 'middleware' => ['auth:sanctum']], function () {
    // Get all conversations for the authenticated user
    Route::get('/', [ConversationController::class, 'index']);

    // Create a new one-to-one conversation
    Route::post('/one-to-one', [ConversationController::class, 'createOneToOne']);

    // Create a new group conversation
    Route::post('/group', [ConversationController::class, 'createGroup']);

    // Get a specific conversation with messages
    Route::get('/{id}', [ConversationController::class, 'show']);

    // Update a conversation (for groups)
    Route::put('/{id}', [ConversationController::class, 'update']);

    // Add users to a group conversation
    Route::post('/{id}/users', [ConversationController::class, 'addUsers']);

    // Remove a user from a group conversation
    Route::delete('/{id}/users/{userId}', [ConversationController::class, 'removeUser']);

    // Leave a group conversation
    Route::post('/{id}/leave', [ConversationController::class, 'leaveGroup']);

    // Transfer group ownership to another user
    Route::post('/{id}/transfer-ownership', [ConversationController::class, 'transferOwnership']);

    // Delete a conversation
    Route::delete('/{id}', [ConversationController::class, 'destroy']);

    // Search for conversations
    Route::get('/search', [ConversationController::class, 'search']);

    // Get users that can be added to a conversation
    Route::get('/{id}/addable-users', [ConversationController::class, 'getAddableUsers']);
});
