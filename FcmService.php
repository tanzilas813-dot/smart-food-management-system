<?php

namespace App\Services;

use App\Models\FoodItem;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class FcmService
{
    /**
     * Send a notification to a user, save it to the database, and trigger OS desktop & FCM push notifications.
     */
    public function sendNotification(User $user, string $type, string $message, ?FoodItem $foodItem = null): Notification
    {
        // 1. Store in database
        $notification = Notification::create([
            'user_id' => $user->id,
            'food_item_id' => $foodItem?->id,
            'type' => $type,
            'message' => $message,
            'is_read' => false,
        ]);

        // 2. Native OS Desktop Toast notification disabled to prevent laptop popup spam

        // 3. Dispatch FCM Push Notification if token exists
        if ($user->fcm_token) {
            $this->sendPushMessage(
                token: $user->fcm_token,
                title: 'FreshTrack Food Expiry Reminder',
                body: $message,
                data: [
                    'notification_id' => (string) $notification->id,
                    'food_item_id' => (string) ($foodItem?->id ?? ''),
                    'type' => $type,
                ]
            );
        }

        return $notification;
    }

    /**
     * Send a native OS Desktop Toast notification (Windows laptop popup).
     */
    public function sendDesktopToast(string $title, string $message): void
    {
        // OS desktop notifications disabled per user request

    }

    /**
     * Send FCM Push Message to a device token.
     */
    protected function sendPushMessage(string $token, string $title, string $body, array $data = []): void
    {
        try {
            $serverKey = env('FIREBASE_SERVER_KEY');

            if ($serverKey) {
                // Legacy FCM HTTP Send endpoint
                $response = Http::withHeaders([
                    'Authorization' => 'key='.$serverKey,
                    'Content-Type' => 'application/json',
                ])->post('https://fcm.googleapis.com/fcm/send', [
                    'to' => $token,
                    'notification' => [
                        'title' => $title,
                        'body' => $body,
                        'icon' => asset('assets/images/favicon.png'),
                        'sound' => 'default',
                    ],
                    'data' => $data,
                    'priority' => 'high',
                ]);

                if ($response->failed()) {
                    Log::error('FCM Push Notification failed:', [
                        'status' => $response->status(),
                        'body' => $response->body(),
                    ]);
                } else {
                    Log::info("FCM Push Notification sent successfully to token: {$token}");
                }
            } else {
                Log::info("FCM Notification simulated (FIREBASE_SERVER_KEY not set). Destination token: {$token}, Message: {$body}");
            }
        } catch (\Throwable $e) {
            Log::error('FCM Service Error: '.$e->getMessage(), [
                'exception' => $e,
            ]);
        }
    }
}
