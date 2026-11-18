<?php

use App\Http\Controllers\AdminReportController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\CreditController;
use App\Http\Controllers\GenerationController;
use App\Http\Controllers\WebhookController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return redirect()->route(auth()->check() ? 'generations.create' : 'login');
});

Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:login');
Route::get('/register', [AuthController::class, 'showRegister'])->name('register');
Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:login');

Route::post('/webhooks/pixelforge', WebhookController::class)->name('webhooks.pixelforge');

Route::middleware('auth')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout'])->name('logout');

    Route::get('/generate', [GenerationController::class, 'create'])->name('generations.create');
    Route::post('/generations/estimate', [GenerationController::class, 'estimate'])
        ->middleware('throttle:estimate')
        ->name('generations.estimate');
    Route::post('/generations', [GenerationController::class, 'store'])
        ->middleware('throttle:generate')
        ->name('generations.store');
    Route::get('/generations', [GenerationController::class, 'index'])->name('generations.index');
    Route::post('/generations/{generation}/refresh', [GenerationController::class, 'refresh'])
        ->name('generations.refresh');

    Route::post('/credits/purchase', [CreditController::class, 'purchase'])->name('credits.purchase');
});

Route::middleware(['auth', 'admin'])->prefix('admin')->group(function () {
    Route::get('/report', [AdminReportController::class, 'index'])->name('admin.report');
});
