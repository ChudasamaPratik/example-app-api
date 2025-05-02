<?php

namespace App\Events;

use App\Models\User;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class UserOnlineStatusChanged implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public string $userId;
    public string $status;

    /**
     * Create a new event instance.
     *
     * @param string $userId ID of the user whose status changed
     * @param string $status New status ('online', 'offline', etc.)
     */
    public function __construct(string $userId, string $status)
    {
        $this->userId = $userId;
        $this->status = $status;
    }

    /**
     * The channel the event should broadcast on.
     *
     * @return \Illuminate\Broadcasting\Channel
     */
    public function broadcastOn(): Channel
    {
        return new Channel('user.status');
    }

    /**
     * Data to broadcast with the event.
     *
     * @return array
     */
    public function broadcastWith(): array
    {
        // Try to load user details if needed
        try {
            $user = User::find($this->userId);

            return [
                'user_id' => $this->userId,
                'name' => $user ? $user->name : null,
                'status' => $this->status,
                'timestamp' => now()->toIso8601String(),
            ];
        } catch (\Exception $e) {
            // Fallback to basic info if user lookup fails
            return [
                'user_id' => $this->userId,
                'status' => $this->status,
                'timestamp' => now()->toIso8601String(),
            ];
        }
    }
}
