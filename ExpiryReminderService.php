<?php

namespace App\Services;

use App\Models\FoodItem;
use App\Models\Notification;
use App\Models\User;
use Carbon\Carbon;

class ExpiryReminderService
{
    public function __construct(
        protected FcmService $fcmService
    ) {}

    /**
     * Check food items for a specific user and create/return expiry reminder notifications.
     */
    public function checkAndCreateRemindersForUser(User $user, bool $force = false, ?callable $onNotificationFound = null): array
    {
        $today = Carbon::today();
        $in3Days = Carbon::today()->addDays(3);
        $in1Day = Carbon::today()->addDays(1);

        $activeItems = FoodItem::where('user_id', $user->id)
            ->where('status', 'active')
            ->get();

        $alerts = [];
        $newCount = 0;

        foreach ($activeItems as $item) {
            $expiryDate = Carbon::parse($item->expiry_date)->startOfDay();

            $type = null;
            $message = null;

            if ($expiryDate->equalTo($in3Days)) {
                $type = 'expiry_3_days';
                $message = "⚠️ '{$item->name}' will expire in 3 days ({$expiryDate->format('M d, Y')}). Plan to use it soon!";
            } elseif ($expiryDate->equalTo($in1Day)) {
                $type = 'expiry_1_day';
                $message = "🚨 '{$item->name}' expires tomorrow! Make sure to consume or cook it today.";
            } elseif ($expiryDate->equalTo($today)) {
                $type = 'expiry_today';
                $message = "🔥 '{$item->name}' expires TODAY! Don't let it go to waste.";
            } elseif ($expiryDate->lt($today)) {
                $type = 'expired';
                $message = "❌ '{$item->name}' has expired on {$expiryDate->format('M d, Y')}. Please inspect or clear from inventory.";
            }

            if ($type && $message) {
                $alreadyNotified = Notification::where('user_id', $user->id)
                    ->where('food_item_id', $item->id)
                    ->where('type', $type)
                    ->exists();

                $isNew = false;

                if (! $alreadyNotified || $force) {
                    $this->fcmService->sendNotification($user, $type, $message, $item);
                    $newCount++;
                    $isNew = true;
                }

                $alertData = [
                    'user' => $user,
                    'food_item' => $item,
                    'type' => $type,
                    'message' => $message,
                    'is_new' => $isNew,
                ];

                $alerts[] = $alertData;

                if ($onNotificationFound) {
                    $onNotificationFound($user, $item, $type, $message, $isNew);
                }
            }
        }

        return [
            'total_alerts' => count($alerts),
            'new_count' => $newCount,
            'alerts' => $alerts,
        ];
    }

    /**
     * Check all users' food items (used by scheduled command).
     */
    public function checkAndCreateRemindersForAllUsers(bool $force = false, ?callable $onNotificationFound = null): array
    {
        $users = User::all();
        $totalAlerts = 0;
        $totalNew = 0;

        foreach ($users as $user) {
            $result = $this->checkAndCreateRemindersForUser($user, $force, $onNotificationFound);
            $totalAlerts += $result['total_alerts'];
            $totalNew += $result['new_count'];
        }

        return [
            'total_alerts' => $totalAlerts,
            'total_new' => $totalNew,
        ];
    }
}
