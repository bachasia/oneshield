<?php

use App\Http\Controllers\Auth\AdminSetupController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Panel\DashboardController;
use App\Http\Controllers\Panel\MeshSiteController;
use App\Http\Controllers\Panel\GroupController;
use App\Http\Controllers\Panel\TransactionController;
use App\Http\Controllers\Panel\SettingsController;
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
| Panel Routes (requires auth)
|--------------------------------------------------------------------------
*/

Route::middleware('auth')->group(function () {
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');

    // Mesh Sites
    Route::resource('sites', MeshSiteController::class);
    Route::patch('sites/{site}/toggle', [MeshSiteController::class, 'toggle'])->name('sites.toggle');

    // Groups
    Route::resource('groups', GroupController::class);

    // Transactions
    Route::get('transactions', [TransactionController::class, 'index'])->name('transactions.index');
    Route::get('transactions/{transaction}', [TransactionController::class, 'show'])->name('transactions.show');
    Route::get('transactions/export/csv', [TransactionController::class, 'export'])->name('transactions.export');

    // Settings
    Route::get('settings', [SettingsController::class, 'index'])->name('settings.index');
    Route::post('settings/regenerate-token', [SettingsController::class, 'regenerateToken'])->name('settings.regenerate-token');
});
