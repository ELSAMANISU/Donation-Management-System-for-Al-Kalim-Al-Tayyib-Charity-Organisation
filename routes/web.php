<?php

use App\Http\Controllers\Admin\DashboardController as AdminDashboardController;
use App\Http\Controllers\Admin\UserController as AdminUserController;
use App\Http\Controllers\DonationCaseController;
use App\Http\Controllers\ProfileController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/dashboard', function () {
    return view('dashboard');
})->middleware(['auth', 'verified'])->name('dashboard');

Route::get('/admin', AdminDashboardController::class)
    ->middleware(['auth', 'role:admin,super_admin'])
    ->name('admin.dashboard');

Route::get('/admin/users', [AdminUserController::class, 'index'])
    ->middleware(['auth', 'role:admin,super_admin'])
    ->name('admin.users.index');

Route::middleware(['auth', 'role:admin,super_admin', 'throttle:10,1'])->group(function () {
    Route::patch('/admin/users/{user}/disable', [AdminUserController::class, 'disable'])
        ->name('admin.users.disable');
    Route::patch('/admin/users/{user}/reactivate', [AdminUserController::class, 'reactivate'])
        ->name('admin.users.reactivate');
});

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

Route::get('/{locale}/cases', [DonationCaseController::class, 'index'])->name('cases.index');
Route::get('/{locale}/cases/{id}', [DonationCaseController::class, 'show'])->name('cases.show');

require __DIR__.'/auth.php';

// Enhanced modern Islamic Glassmorphism UI routes integrated successfully.
