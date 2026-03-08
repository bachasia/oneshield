<?php

use App\Http\Controllers\Auth\AdminSetupController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Panel\DashboardController;
use App\Http\Controllers\Panel\ShieldSiteController;
use App\Http\Controllers\Panel\GroupController;
use App\Http\Controllers\Panel\TransactionController;
use App\Http\Controllers\Panel\SettingsController;
use App\Http\Controllers\SuperAdmin\SuperAdminController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Auth Routes
|--------------------------------------------------------------------------
*/

// Admin setup (only accessible when no admin exists)
Route::get('/account/admin', [AdminSetupController::class, 'show'])->name('admin.setup.show');
Route::post('/account/admin', [AdminSetupController::class, 'store'])->name('admin.setup.store');

// Login / Logout
Route::get('/', [LoginController::class, 'show'])->name('login');
Route::post('/login', [LoginController::class, 'store'])->name('login.store');
Route::post('/logout', [LoginController::class, 'destroy'])->name('logout');

/*
|--------------------------------------------------------------------------
| Super Admin Routes  (admin.oneshieldx.com)
|--------------------------------------------------------------------------
*/

Route::middleware(['auth', 'super_admin'])->prefix('admin')->name('admin.')->group(function () {
    Route::get('/',                                      [SuperAdminController::class, 'dashboard'])->name('dashboard');
    Route::get('/tenants',                               [SuperAdminController::class, 'tenants'])->name('tenants.index');
    Route::get('/tenants/create',                        [SuperAdminController::class, 'createTenant'])->name('tenants.create');
    Route::post('/tenants',                              [SuperAdminController::class, 'storeTenant'])->name('tenants.store');
    Route::get('/tenants/{tenant}',                      [SuperAdminController::class, 'showTenant'])->name('tenants.show');
    Route::patch('/tenants/{tenant}/subscription',       [SuperAdminController::class, 'updateSubscription'])->name('tenants.subscription.update');
    Route::patch('/tenants/{tenant}/profile',            [SuperAdminController::class, 'updateTenant'])->name('tenants.profile.update');
    Route::patch('/tenants/{tenant}/suspend',            [SuperAdminController::class, 'suspendTenant'])->name('tenants.suspend');
    Route::patch('/tenants/{tenant}/unsuspend',          [SuperAdminController::class, 'unsuspendTenant'])->name('tenants.unsuspend');
    Route::post('/tenants/{tenant}/impersonate',         [SuperAdminController::class, 'impersonate'])->name('tenants.impersonate');
});

Route::middleware(['auth'])->prefix('admin')->name('admin.')->group(function () {
    Route::delete('/impersonate',                        [SuperAdminController::class, 'stopImpersonating'])->name('impersonate.stop');
});

/*
|--------------------------------------------------------------------------
| Tenant Panel Routes (requires auth + active subscription)
|--------------------------------------------------------------------------
*/

Route::middleware(['auth', 'tenant.active'])->group(function () {
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');

    // Shield Sites — explicit routes only (no create/edit pages, UI uses modals)
    Route::get('sites', [ShieldSiteController::class, 'index'])->name('sites.index');
    Route::post('sites', [ShieldSiteController::class, 'store'])->name('sites.store');
    Route::patch('sites/reorder', [ShieldSiteController::class, 'reorder'])->name('sites.reorder');
    Route::put('sites/{site}', [ShieldSiteController::class, 'update'])->name('sites.update');
    Route::delete('sites/{site}', [ShieldSiteController::class, 'destroy'])->name('sites.destroy');
    Route::patch('sites/{site}/toggle', [ShieldSiteController::class, 'toggle'])->name('sites.toggle');
    Route::post('sites/{site}/check', [ShieldSiteController::class, 'check'])->name('sites.check');

    // Groups
    Route::resource('groups', GroupController::class);

    // Transactions — export MUST be before {transaction} to avoid route conflict
    Route::get('transactions', [TransactionController::class, 'index'])->name('transactions.index');
    Route::get('transactions/export/csv', [TransactionController::class, 'export'])->name('transactions.export');
    Route::get('transactions/{transaction}', [TransactionController::class, 'show'])->name('transactions.show');

    // Settings
    Route::get('settings', [SettingsController::class, 'index'])->name('settings.index');
    Route::post('settings/cors-origins', [SettingsController::class, 'updateCorsOrigins'])->name('settings.cors-origins');
    Route::post('settings/regenerate-token', [SettingsController::class, 'regenerateToken'])->name('settings.regenerate-token');
    Route::post('settings/tokens', [SettingsController::class, 'createToken'])->name('settings.tokens.create');
    Route::delete('settings/tokens/{token}', [SettingsController::class, 'revokeToken'])->name('settings.tokens.revoke');
});
