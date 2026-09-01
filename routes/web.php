<?php

use App\Http\Controllers\Admin\AdminController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\CheckoutController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\PasswordResetController;
use App\Http\Controllers\SubscriptionController;
use App\Http\Controllers\TransactionController;
use App\Http\Controllers\WebhookController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    if (! auth()->check()) {
        return redirect()->route('login');
    }

    return redirect()->route(auth()->user()->is_admin ? 'admin.index' : 'dashboard');
});

// ── Guest ───────────────────────────────────────────────────────────────────
Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:6,1');
    Route::get('/register', [AuthController::class, 'showRegister'])->name('register');
    Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:6,1');


    // Also how a guest donor first gets into the account created for them.
    Route::get('/forgot-password', [PasswordResetController::class, 'request'])->name('password.request');
    Route::post('/forgot-password', [PasswordResetController::class, 'email'])
        ->middleware('throttle:6,1')->name('password.email');
    Route::get('/reset-password/{token}', [PasswordResetController::class, 'reset'])->name('password.reset');
    Route::post('/reset-password', [PasswordResetController::class, 'update'])
        ->middleware('throttle:6,1')->name('password.update');
});

Route::post('/logout', [AuthController::class, 'logout'])->middleware('auth')->name('logout');

// ── Donor ───────────────────────────────────────────────────────────────────
// 'donor' bounces admins out: giving, subscriptions and receipts belong to
// donors, and an admin donating through their admin account is just noise in
// the very tables they are meant to be reading.
// Giving is open to guests: 'donor' only bounces admins, and a visitor with no
// account passes through it. The account is created after payment, not before.
Route::middleware('donor')->group(function () {
    Route::get('/give', [CheckoutController::class, 'choose'])->name('checkout.choose');
    Route::get('/give/details', [CheckoutController::class, 'details'])->name('checkout.details');
    Route::post('/give/start', [CheckoutController::class, 'start'])->name('checkout.start');

    // Gateways send the browser back here; some use GET, PayPal can use either.
    Route::match(['get', 'post'], '/give/{transaction}/return', [CheckoutController::class, 'return'])
        ->name('checkout.return');
    Route::match(['get', 'post'], '/give/{transaction}/cancel', [CheckoutController::class, 'cancel'])
        ->name('checkout.cancel');

    // Guests reach these for the donation they just made, authorised by session.
    Route::get('/transactions/{transaction}', [TransactionController::class, 'show'])
        ->name('transactions.show');
    Route::get('/transactions/{transaction}/receipt', [TransactionController::class, 'receipt'])
        ->name('transactions.receipt');
    Route::get('/transactions/{transaction}/receipt/preview', [TransactionController::class, 'receiptPreview'])
        ->name('transactions.receipt.preview');
});

Route::middleware(['auth', 'donor'])->group(function () {
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');
    Route::get('/profile', [DashboardController::class, 'profile'])->name('profile');
    Route::put('/profile', [DashboardController::class, 'updateProfile'])->name('profile.update');

    Route::get('/subscriptions', [SubscriptionController::class, 'index'])->name('subscriptions.index');
    Route::get('/subscriptions/{subscription}', [SubscriptionController::class, 'show'])
        ->name('subscriptions.show');
    Route::post('/subscriptions/{subscription}/cancel', [SubscriptionController::class, 'cancel'])
        ->name('subscriptions.cancel');

    Route::get('/transactions', [TransactionController::class, 'index'])->name('transactions.index');
});

// ── Admin ───────────────────────────────────────────────────────────────────
Route::middleware(['auth', 'admin'])->prefix('admin')->name('admin.')->group(function () {
    Route::get('/', [AdminController::class, 'index'])->name('index');
    Route::get('/transactions', [AdminController::class, 'transactions'])->name('transactions');
    Route::get('/transactions/{transaction}/receipt', [AdminController::class, 'receipt'])->name('receipt');
    Route::post('/transactions/{transaction}/resend', [AdminController::class, 'resendReceipt'])->name('receipt.resend');
    Route::get('/users', [AdminController::class, 'users'])->name('users');
    Route::get('/users/{user}', [AdminController::class, 'showUser'])->name('users.show');
    Route::get('/gateway-plans/{gateway}', [AdminController::class, 'gatewayPlans'])->name('gateway-plans');
    Route::get('/subscriptions', [AdminController::class, 'subscriptions'])->name('subscriptions');
    Route::get('/plans', [AdminController::class, 'plans'])->name('plans');
    Route::post('/plans', [AdminController::class, 'storePlan'])->name('plans.store');
    Route::put('/plans/{plan}', [AdminController::class, 'updatePlan'])->name('plans.update');
});

// ── Webhooks ────────────────────────────────────────────────────────────────
// Outside the auth and CSRF groups by necessity: gateways have no session and
// no token. Authenticity comes from the signature check in each driver instead.
Route::post('/webhooks/{gateway}', [WebhookController::class, 'handle'])
    ->name('webhooks')
    ->withoutMiddleware([\Illuminate\Foundation\Http\Middleware\VerifyCsrfToken::class]);
