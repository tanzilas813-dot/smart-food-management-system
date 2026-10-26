<?php

use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\RegisterController;
use App\Http\Controllers\FcmTokenController;
use App\Http\Controllers\FoodItemController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\ProfileController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
*/

// ── Serve root landing page or redirect to dashboard ────────────────────
Route::get('/', function () {
    if (Auth::check()) {
        return redirect()->route('dashboard');
    }

    return view('welcome');
});

Route::get('/privacy-policy', function () {
    return view('privacy-policy');
})->name('privacy');

Route::get('/terms-of-service', function () {
    return view('terms-of-service');
})->name('terms');

// ── Guest routes (only accessible when NOT logged in) ───────────────────
Route::middleware('guest')->group(function () {
    Route::get('/login', [LoginController::class,    'showLoginForm'])->name('login');
    Route::post('/login', [LoginController::class,    'login']);

    Route::get('/register', [RegisterController::class, 'showRegistrationForm'])->name('register');
    Route::post('/register', [RegisterController::class, 'register']);
});

// ── Authenticated routes ─────────────────────────────────────────────────
Route::middleware('auth')->group(function () {
    Route::match(['get', 'post'], '/dashboard', [FoodItemController::class, 'index'])->name('dashboard');
    Route::post('/food-items', [FoodItemController::class, 'store'])->name('food-items.store');
    Route::post('/food-items/{foodItem}/consume', [FoodItemController::class, 'consume'])->name('food-items.consume');
    Route::post('/food-items/{foodItem}/waste', [FoodItemController::class, 'waste'])->name('food-items.waste');
    Route::delete('/food-items/{foodItem}', [FoodItemController::class, 'destroy'])->name('food-items.destroy');

    Route::post('/fcm-token', [FcmTokenController::class, 'updateToken'])->name('fcm.token');

    Route::post('/notifications/{notification}/read', [NotificationController::class, 'markAsRead'])->name('notifications.read');
    Route::post('/notifications/read-all', [NotificationController::class, 'markAllAsRead'])->name('notifications.read-all');

    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::put('/profile', [ProfileController::class, 'update'])->name('profile.update');

    Route::match(['get', 'post'], '/logout', [LoginController::class, 'logout'])->name('logout');
});
