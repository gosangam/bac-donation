<?php

use App\Http\Controllers\Admin\AdminController;
use App\Http\Controllers\Admin\OwnershipController;
use App\Http\Controllers\Admin\TransactionController as AdminTransactionController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\CheckoutController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\PasswordResetController;
use App\Http\Controllers\SubscriptionController;
use App\Http\Controllers\TransactionController;
use App\Http\Controllers\WebhookController;
use Illuminate\Support\Facades\Route;

// The front door is the donation page, for guests, donors and admins alike.

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
// Giving is open to everyone, guests included. A guest's account is created
// after payment, not before. Admins give like any donor, from their own account.
Route::group([], function () {
    Route::get('/', [CheckoutController::class, 'choose'])->name('checkout.choose');

    // Kept so older links and bookmarks still land somewhere sensible.
    Route::get('/give', fn () => redirect()->route('checkout.choose', request()->query()));

    // POST, not GET: this writes to the session, and a currency switch
    // must not be something a prefetcher or a crawler can trigger.
    Route::post('/currency', [CheckoutController::class, 'setCurrency'])->name('checkout.currency');

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

Route::middleware('auth')->group(function () {
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
    Route::get('/transactions/{transaction}', [AdminTransactionController::class, 'show'])->name('transactions.show');
    // POST, so revealing a donor's ID is never a link a browser could prefetch.
    Route::post('/transactions/{transaction}/reveal-id', [AdminTransactionController::class, 'revealIdentity'])
        ->name('transactions.reveal-id');
    Route::get('/transactions/{transaction}/receipt', [AdminTransactionController::class, 'receipt'])->name('receipt');
    Route::post('/transactions/{transaction}/resend', [AdminController::class, 'resendReceipt'])->name('receipt.resend');
    Route::get('/users', [AdminController::class, 'users'])->name('users');
    // Before /users/{user}, or "create" would be read as a user id.
    Route::get('/users/create', [UserController::class, 'create'])->name('users.create');
    Route::post('/users', [UserController::class, 'store'])->name('users.store');
    Route::get('/users/{user}', [AdminController::class, 'showUser'])->name('users.show');
    Route::get('/users/{user}/edit', [UserController::class, 'edit'])->name('users.edit');
    Route::put('/users/{user}', [UserController::class, 'update'])->name('users.update');
    Route::post('/users/{user}/subscriptions', [OwnershipController::class, 'attachSubscription'])
        ->name('users.subscriptions.attach');
    Route::post('/users/{user}/transactions', [OwnershipController::class, 'attachTransaction'])
        ->name('users.transactions.attach');
    Route::put('/subscriptions/{subscription}/owner', [OwnershipController::class, 'subscription'])
        ->name('subscriptions.owner');
    Route::put('/transactions/{transaction}/owner', [OwnershipController::class, 'transaction'])
        ->name('transactions.owner');
    Route::get('/gateway-plans/{gateway}', [AdminController::class, 'gatewayPlans'])->name('gateway-plans');
    Route::get('/subscriptions', [AdminController::class, 'subscriptions'])->name('subscriptions');
    Route::get('/plans', [AdminController::class, 'plans'])->name('plans');
    Route::post('/plans', [AdminController::class, 'storePlan'])->name('plans.store');
    Route::put('/plans/{plan}', [AdminController::class, 'updatePlan'])->name('plans.update');
});

// ── Webhooks ────────────────────────────────────────────────────────────────
// Outside the auth and CSRF groups by necessity: gateways have no session and
// no token. Authenticity comes from the signature check in each driver instead.
// Gateways have no session and no CSRF token; authenticity comes from the
// signature check in each driver. CSRF is excluded in bootstrap/app.php.
//
// Dropping StartSession here as well looks tempting — every delivery writes a
// session row — but Laravel 13's PreventRequestForgery still runs in the 'web'
// group and reads the session, so removing it throws "Session store not set on
// request". Doing it properly means registering this route outside the group.
Route::post('/webhooks/{gateway}', [WebhookController::class, 'handle'])
    ->name('webhooks');
