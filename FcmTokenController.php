<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class FcmTokenController extends Controller
{
    /**
     * Store or update the user's FCM device token.
     */
    public function updateToken(Request $request): JsonResponse
    {
        $request->validate([
            'fcm_token' => ['required', 'string'],
        ]);

        $user = Auth::user();

        if ($user) {
            $user->update([
                'fcm_token' => $request->input('fcm_token'),
            ]);

            return response()->json([
                'status' => 'success',
                'message' => 'FCM Token updated successfully.',
            ]);
        }

        return response()->json([
            'status' => 'error',
            'message' => 'User not authenticated.',
        ], 401);
    }
}
