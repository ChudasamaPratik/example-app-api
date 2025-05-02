<?php

namespace App\Http\Controllers\API;

use App\Events\UserOnlineStatusChanged;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Device;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Exception;

class AuthController extends Controller
{
    /**
     * User login - Updated with WebSocket status changes
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function login(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'email' => 'required|string|email',
                'password' => 'required|string',
                'device_name' => 'required|string',
                'device_token' => 'nullable|string',
            ]);

            if ($validator->fails()) {
                return app('api-response-helper')->error('Validation error', $validator->messages()->first(), 422);
            }

            // Check credentials
            if (!Auth::attempt($request->only('email', 'password'))) {
                return app('api-response-helper')->error('Invalid login credentials', [], 401);
            }

            $user = User::where('email', $request->email)->first();

            if (!$user) {
                return app('api-response-helper')->error('User not found', [], 404);
            }

            // Check if user is active
            if ($user->status !== 'active') {
                return app('api-response-helper')->error('Your account is not active', [], 403);
            }

            DB::beginTransaction();

            // Update or create device
            $device = Device::updateOrCreate(
                [
                    'user_id' => $user->id,
                    'device_name' => $request->device_name
                ],
                [
                    'device_token' => $request->device_token,
                    'ip_address' => $request->ip(),
                ]
            );

            // Create token
            $token = $user->createToken($request->device_name)->plainTextToken;

            // Update user activity status
            $user->activityLogs()->create([
                'id' => (string) Str::uuid(),
                'activity_type' => 'online',
            ]);

            // Broadcast user online status for WebSocket clients
            broadcast(new UserOnlineStatusChanged($user->id, 'online'));

            DB::commit();

            return app('api-response-helper')->success(200, [
                'user' => $user,
                'token' => $token,
                // Add WebSocket configuration for frontend with your env settings
                'websocket' => [
                    'host' => env('REVERB_HOST', 'localhost'),
                    'port' => env('REVERB_PORT', 8080),
                    'scheme' => env('REVERB_SCHEME', 'http'),
                    'app_id' => env('REVERB_APP_ID'),
                    'app_key' => env('REVERB_APP_KEY'),
                ]
            ], 'Login successful');

        } catch (Exception $e) {
            DB::rollBack();
            Log::error('Login error: ' . $e->getMessage());
            return app('api-response-helper')->error('Login failed. Please try again.', [], 500);
        }
    }

    /**
     * User logout - Updated with WebSocket status changes
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function logout(Request $request)
    {
        try {
            DB::beginTransaction();

            $user = $request->user();

            // Update user activity status to offline
            $user->activityLogs()->create([
                'id' => (string) Str::uuid(),
                'activity_type' => 'offline',
            ]);

            // Broadcast user offline status for WebSocket clients
            broadcast(new UserOnlineStatusChanged($user->id, 'offline'));

            // Get user and delete current token
            $user->currentAccessToken()->delete();

            DB::commit();

            return app('api-response-helper')->success(200, null, 'Logged out successfully');

        } catch (Exception $e) {
            DB::rollBack();
            Log::error('Logout error: ' . $e->getMessage());
            return app('api-response-helper')->error('Logout failed. Please try again.', [], 500);
        }
    }

    /**
     * Get WebSocket configuration for authenticated user
     * This is a new method to provide WebSocket connection details
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function getWebSocketConfig(Request $request)
    {
        try {
            $user = $request->user();

            $wsToken = $user->createToken('websocket')->plainTextToken;

            $config = [
                'user_id' => $user->id,
                'token' => $wsToken,
                'host' => env('REVERB_HOST', 'localhost'),
                'port' => (int)env('REVERB_PORT', 8080),
                'scheme' => env('REVERB_SCHEME', 'http'),
                'app_id' => env('REVERB_APP_ID'),
                'app_key' => env('REVERB_APP_KEY'),
                'auth_endpoint' => '/api/broadcasting/auth',
                'channels' => [
                    'user' => 'user.' . $user->id,
                    'status' => 'user.status'
                ]
            ];

            return app('api-response-helper')->success(200, $config, 'WebSocket configuration retrieved successfully');

        } catch (Exception $e) {
            Log::error('Get WebSocket config error: ' . $e->getMessage());
            return app('api-response-helper')->error('Failed to retrieve WebSocket configuration.', [], 500);
        }
    }

    /**
     * Update user profile - Updated to broadcast profile changes
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function updateProfile(Request $request)
    {
        try {
            $user = $request->user();

            $validator = Validator::make($request->all(), [
                'name' => 'sometimes|string|max:255',
                'avatar' => 'sometimes|image|mimes:jpeg,png,jpg|max:2048',
                'status' => 'sometimes|string|in:active,inactive,busy,away',
            ]);

            if ($validator->fails()) {
                return app('api-response-helper')->error('Validation error', $validator->errors(), 422);
            }

            DB::beginTransaction();

            $oldStatus = $user->status;
            $statusChanged = false;

            // Update user details
            if ($request->has('name')) {
                $user->name = $request->name;
            }

            if ($request->has('status') && $request->status !== $oldStatus) {
                $user->status = $request->status;
                $statusChanged = true;
            }

            // Handle avatar upload
            if ($request->hasFile('avatar')) {
                // Delete old avatar if exists
                if ($user->avatar) {
                    // Logic to delete old file
                    if (file_exists(public_path('storage/' . $user->avatar))) {
                        unlink(public_path('storage/' . $user->avatar));
                    }
                }

                // Store new avatar
                $avatarPath = $request->file('avatar')->store('avatars', 'public');
                $user->avatar = $avatarPath;
            }

            $user->save();

            // If user status changed, create activity log and broadcast
            if ($statusChanged) {
                $user->activityLogs()->create([
                    'id' => (string) Str::uuid(),
                    'activity_type' => $user->status === 'active' ? 'online' : 'offline',
                ]);

                // Broadcast user status change
                $broadcastStatus = ($user->status === 'active') ? 'online' : 'offline';
                broadcast(new UserOnlineStatusChanged($user->id, $broadcastStatus));
            }

            DB::commit();

            return app('api-response-helper')->success(200, $user, 'Profile updated successfully');

        } catch (Exception $e) {
            DB::rollBack();
            Log::error('Profile update error: ' . $e->getMessage());
            return app('api-response-helper')->error('Failed to update profile. Please try again.', [], 500);
        }
    }

    /**
     * Register a new user
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function register(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'name' => 'required|string|max:255',
                'email' => 'required|string|email|max:255|unique:users',
                'password' => 'required|string|min:8|confirmed',
                'device_name' => 'required|string',
                'device_token' => 'nullable|string',
            ]);

            if ($validator->fails()) {
                return app('api-response-helper')->error('Validation error', $validator->errors(), 422);
            }

            DB::beginTransaction();

            $user = User::create([
                'id' => (string) Str::uuid(),
                'name' => $request->name,
                'email' => $request->email,
                'password' => Hash::make($request->password),
                'status' => 'active',
            ]);

            // Create device
            if ($request->has('device_name')) {
                Device::create([
                    'id' => (string) Str::uuid(),
                    'user_id' => $user->id,
                    'device_name' => $request->device_name,
                    'device_token' => $request->device_token,
                    'ip_address' => $request->ip(),
                ]);
            }

            // Create initial activity log
            $user->activityLogs()->create([
                'id' => (string) Str::uuid(),
                'activity_type' => 'registration',
            ]);

            // Create token
            $token = $user->createToken($request->device_name)->plainTextToken;

            DB::commit();

            return app('api-response-helper')->success(201, [
                'user' => $user,
                'token' => $token,
            ], 'Registration successful');

        } catch (Exception $e) {
            DB::rollBack();
            Log::error('Registration error: ' . $e->getMessage());
            return app('api-response-helper')->error('Registration failed. Please try again.', [], 500);
        }
    }

    /**
     * Change user password
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function changePassword(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'current_password' => 'required|string',
                'password' => 'required|string|min:8|confirmed|different:current_password',
            ]);

            if ($validator->fails()) {
                return app('api-response-helper')->error('Validation error', $validator->errors(), 422);
            }

            $user = $request->user();

            // Check if current password is correct
            if (!Hash::check($request->current_password, $user->password)) {
                return app('api-response-helper')->error('Current password is incorrect', [], 422);
            }

            DB::beginTransaction();

            // Update password
            $user->password = Hash::make($request->password);
            $user->save();

            // Log the activity
            $user->activityLogs()->create([
                'id' => (string) Str::uuid(),
                'activity_type' => 'password_change',
            ]);

            DB::commit();

            return app('api-response-helper')->success(200, null, 'Password changed successfully');

        } catch (Exception $e) {
            DB::rollBack();
            Log::error('Password change error: ' . $e->getMessage());
            return app('api-response-helper')->error('Failed to change password. Please try again.', [], 500);
        }
    }

    /**
     * Refresh user token
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function refreshToken(Request $request)
    {
        try {
            $user = $request->user();

            // Delete current token
            $user->currentAccessToken()->delete();

            // Create new token
            $token = $user->createToken($request->header('User-Agent', 'Unknown Device'))->plainTextToken;

            return app('api-response-helper')->success(200, [
                'token' => $token
            ], 'Token refreshed successfully');

        } catch (Exception $e) {
            Log::error('Token refresh error: ' . $e->getMessage());
            return app('api-response-helper')->error('Failed to refresh token. Please try again.', [], 500);
        }
    }


    /**
     * Get user profile
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function profile(Request $request)
    {
        try {
            $user = $request->user();

            return app('api-response-helper')->success(200, $user, 'User profile retrieved successfully');

        } catch (Exception $e) {
            Log::error('Profile retrieval error: ' . $e->getMessage());
            return app('api-response-helper')->error('Failed to retrieve profile. Please try again.', [], 500);
        }
    }

    /**
     * Request password reset link
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function forgotPassword(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'email' => 'required|string|email|exists:users',
            ]);

            if ($validator->fails()) {
                return app('api-response-helper')->error('Validation error', $validator->errors(), 422);
            }

            // Send password reset link
            $status = Password::sendResetLink(
                $request->only('email')
            );

            if ($status === Password::RESET_LINK_SENT) {
                return app('api-response-helper')->success(200, null, 'Password reset link has been sent to your email');
            } else {
                return app('api-response-helper')->error('Unable to send password reset link', [], 500);
            }

        } catch (Exception $e) {
            Log::error('Forgot password error: ' . $e->getMessage());
            return app('api-response-helper')->error('Failed to process password reset request. Please try again.', [], 500);
        }
    }

    /**
     * Reset password
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function resetPassword(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'token' => 'required|string',
                'email' => 'required|string|email|exists:users',
                'password' => 'required|string|min:8|confirmed',
            ]);

            if ($validator->fails()) {
                return app('api-response-helper')->error('Validation error', $validator->errors(), 422);
            }

            // Reset password
            $status = Password::reset(
                $request->only('email', 'password', 'password_confirmation', 'token'),
                function ($user, $password) {
                    $user->password = Hash::make($password);
                    $user->save();
                }
            );

            if ($status === Password::PASSWORD_RESET) {
                return app('api-response-helper')->success(200, null, 'Password has been reset successfully');
            } else {
                return app('api-response-helper')->error('Invalid or expired token', [], 422);
            }

        } catch (Exception $e) {
            Log::error('Reset password error: ' . $e->getMessage());
            return app('api-response-helper')->error('Failed to reset password. Please try again.', [], 500);
        }
    }

    /**
     * Get user's active devices
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function getDevices(Request $request)
    {
        try {
            $user = $request->user();
            $devices = $user->devices;

            return app('api-response-helper')->success(200, $devices, 'User devices retrieved successfully');

        } catch (Exception $e) {
            Log::error('Get devices error: ' . $e->getMessage());
            return app('api-response-helper')->error('Failed to retrieve devices. Please try again.', [], 500);
        }
    }

    /**
     * Remove device (logout from specific device)
     *
     * @param Request $request
     * @param string $deviceId
     * @return \Illuminate\Http\JsonResponse
     */
    public function removeDevice(Request $request, $deviceId)
    {
        try {
            $user = $request->user();

            $device = $user->devices()->where('id', $deviceId)->first();

            if (!$device) {
                return app('api-response-helper')->error('Device not found', [], 404);
            }

            DB::beginTransaction();

            // Delete tokens associated with this device
            $user->tokens()->where('name', $device->device_name)->delete();

            // Delete device
            $device->delete();

            DB::commit();

            return app('api-response-helper')->success(200, null, 'Device removed successfully');

        } catch (Exception $e) {
            DB::rollBack();
            Log::error('Remove device error: ' . $e->getMessage());
            return app('api-response-helper')->error('Failed to remove device. Please try again.', [], 500);
        }
    }

    /**
     * Update device token (for push notifications)
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function updateDeviceToken(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'device_name' => 'required|string',
                'device_token' => 'required|string',
            ]);

            if ($validator->fails()) {
                return app('api-response-helper')->error('Validation error', $validator->errors(), 422);
            }

            $user = $request->user();

            // Update or create device
            $device = Device::updateOrCreate(
                [
                    'user_id' => $user->id,
                    'device_name' => $request->device_name
                ],
                [
                    'device_token' => $request->device_token,
                    'ip_address' => $request->ip(),
                ]
            );

            return app('api-response-helper')->success(200, [
                'device' => $device
            ], 'Device token updated successfully');

        } catch (Exception $e) {
            Log::error('Update device token error: ' . $e->getMessage());
            return app('api-response-helper')->error('Failed to update device token. Please try again.', [], 500);
        }
    }

    /**
     * Get user status (online/offline)
     *
     * @param Request $request
     * @param string $userId
     * @return \Illuminate\Http\JsonResponse
     */
    public function getUserStatus(Request $request, $userId)
    {
        try {
            // Check if user exists
            $userExists = User::where('id', $userId)->exists();

            if (!$userExists) {
                return app('api-response-helper')->error('User not found', [], 404);
            }

            // Get latest activity
            $latestActivity = DB::table('activity_logs')
                ->where('user_id', $userId)
                ->whereIn('activity_type', ['online', 'offline'])
                ->orderBy('created_at', 'desc')
                ->first();

            $status = 'offline';
            $lastSeen = null;

            if ($latestActivity) {
                // If recent activity is online and within last 5 minutes, user is online
                if ($latestActivity->activity_type === 'online' &&
                    strtotime($latestActivity->created_at) > strtotime('-5 minutes')) {
                    $status = 'online';
                } else {
                    $lastSeen = $latestActivity->created_at;
                }
            }

            return app('api-response-helper')->success(200, [
                'user_id' => $userId,
                'status' => $status,
                'last_seen' => $lastSeen,
            ], 'User status retrieved successfully');

        } catch (Exception $e) {
            Log::error('Get user status error: ' . $e->getMessage());
            return app('api-response-helper')->error('Failed to get user status. Please try again.', [], 500);
        }
    }
}
