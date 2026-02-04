<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Core;

use App\Http\Controllers\Controller;
use App\Models\Core\Notification;
use App\Services\Core\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class NotificationController extends Controller
{
    public function __construct(
        protected NotificationService $notificationService
    ) {}

    /**
     * Get user's notifications.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $unreadOnly = $request->has('unread_only')
            ? $request->boolean('unread_only')
            : null;

        $notifications = $this->notificationService->getForUser(
            $user->id,
            $unreadOnly,
            $request->get('type'),
            (int) $request->get('limit', 50)
        );

        $unreadCount = $this->notificationService->getUnreadCount($user->id);

        return response()->json([
            'data' => $notifications,
            'unread_count' => $unreadCount,
        ]);
    }

    /**
     * Get unread count.
     */
    public function unreadCount(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'unread_count' => $this->notificationService->getUnreadCount($user->id),
        ]);
    }

    /**
     * Mark notification as read.
     */
    public function markAsRead(Request $request, string $id): JsonResponse
    {
        $user = $request->user();

        $success = $this->notificationService->markAsRead($id, $user->id);

        if (!$success) {
            return response()->json(['error' => 'Notification not found'], 404);
        }

        return response()->json([
            'message' => 'Notification marked as read',
            'unread_count' => $this->notificationService->getUnreadCount($user->id),
        ]);
    }

    /**
     * Mark all notifications as read.
     */
    public function markAllAsRead(Request $request): JsonResponse
    {
        $user = $request->user();

        $count = $this->notificationService->markAllAsRead(
            $user->id,
            $request->get('type')
        );

        return response()->json([
            'message' => "{$count} notifications marked as read",
            'unread_count' => 0,
        ]);
    }

    /**
     * Delete notification.
     */
    public function destroy(Request $request, string $id): JsonResponse
    {
        $user = $request->user();

        $success = $this->notificationService->delete($id, $user->id);

        if (!$success) {
            return response()->json(['error' => 'Notification not found'], 404);
        }

        return response()->json([
            'message' => 'Notification deleted',
        ]);
    }

    /**
     * Get notification preferences.
     */
    public function preferences(Request $request): JsonResponse
    {
        $user = $request->user();

        $preferences = $this->notificationService->getUserPreferences($user->id);
        $types = Notification::getTypes();
        $typesGrouped = Notification::getTypesGrouped();

        return response()->json([
            'data' => [
                'preferences' => $preferences,
                'available_types' => $types,
                'types_grouped' => $typesGrouped,
            ],
        ]);
    }

    /**
     * Update notification preferences.
     */
    public function updatePreferences(Request $request): JsonResponse
    {
        $user = $request->user();

        $validator = Validator::make($request->all(), [
            'preferences' => 'required|array',
            'preferences.*.notification_type' => 'required|string',
            'preferences.*.email_enabled' => 'boolean',
            'preferences.*.database_enabled' => 'boolean',
            'preferences.*.push_enabled' => 'boolean',
            'preferences.*.sms_enabled' => 'boolean',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        foreach ($request->get('preferences') as $pref) {
            $this->notificationService->updateUserPreference(
                $user->id,
                $pref['notification_type'],
                $pref['email_enabled'] ?? true,
                $pref['database_enabled'] ?? true,
                $pref['push_enabled'] ?? true,
                $pref['sms_enabled'] ?? false
            );
        }

        return response()->json([
            'message' => 'Preferences updated successfully',
            'data' => $this->notificationService->getUserPreferences($user->id),
        ]);
    }

    /**
     * Update single preference.
     */
    public function updatePreference(Request $request, string $type): JsonResponse
    {
        $user = $request->user();

        $validator = Validator::make($request->all(), [
            'email_enabled' => 'boolean',
            'database_enabled' => 'boolean',
            'push_enabled' => 'boolean',
            'sms_enabled' => 'boolean',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $preference = $this->notificationService->updateUserPreference(
            $user->id,
            $type,
            $request->boolean('email_enabled', true),
            $request->boolean('database_enabled', true),
            $request->boolean('push_enabled', true),
            $request->boolean('sms_enabled', false)
        );

        return response()->json([
            'message' => 'Preference updated',
            'data' => $preference,
        ]);
    }

    /**
     * Get available notification types.
     */
    public function types(Request $request): JsonResponse
    {
        return response()->json([
            'data' => [
                'types' => Notification::getTypes(),
                'grouped' => Notification::getTypesGrouped(),
            ],
        ]);
    }

    /**
     * Initialize default preferences for user.
     */
    public function initializePreferences(Request $request): JsonResponse
    {
        $user = $request->user();

        $this->notificationService->initializeUserPreferences($user);

        return response()->json([
            'message' => 'Preferences initialized',
            'data' => $this->notificationService->getUserPreferences($user->id),
        ]);
    }
}
