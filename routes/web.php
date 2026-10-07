<?php

use App\Http\Controllers\Admin\DidController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\ApprovalController;
use App\Http\Controllers\AudioController;
use App\Http\Controllers\AuditLogController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\CampaignController;
use App\Http\Controllers\DashboardController;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:5,1');
});

Route::middleware(['auth', 'active'])->group(function () {
    Route::post('/logout', [AuthController::class, 'logout'])->name('logout');
    Route::get('/', DashboardController::class)->name('dashboard');

    Route::get('/reports', fn () => view('reports'))->name('reports');

    Route::get('campaigns/sample-numbers', [CampaignController::class, 'sampleNumbers'])->name('campaigns.sample');
    Route::resource('campaigns', CampaignController::class)->except([])->whereNumber('campaign');
    Route::prefix('campaigns/{campaign}')->whereNumber('campaign')->name('campaigns.')->group(function () {
        Route::post('import', [CampaignController::class, 'import'])->middleware('throttle:20,1')->name('import');
        Route::post('submit', [CampaignController::class, 'submit'])->name('submit');
        Route::post('start', [CampaignController::class, 'start'])->name('start');
        Route::post('pause', [CampaignController::class, 'pause'])->name('pause');
        Route::post('resume', [CampaignController::class, 'resume'])->name('resume');
        Route::post('cancel', [CampaignController::class, 'cancel'])->name('cancel');
    });

    Route::get('/audio', [AudioController::class, 'index'])->name('audio.index');
    Route::post('/audio', [AudioController::class, 'store'])->middleware('throttle:20,1')->name('audio.store');
    Route::post('/audio/{audio}/replace', [AudioController::class, 'replace'])->name('audio.replace');
    Route::get('/audio/{audio}/stream', [AudioController::class, 'stream'])->name('audio.stream');

    Route::middleware('role:super_admin')->prefix('admin')->name('admin.')->group(function () {
        Route::resource('users', UserController::class)->except('destroy');
        Route::post('users/{user}/toggle', [UserController::class, 'toggle'])->name('users.toggle');
        Route::post('users/{user}/reset-password', [UserController::class, 'resetPassword'])->name('users.reset');

        Route::get('dids/sync', [DidController::class, 'sync'])->name('dids.sync');
        Route::post('dids/sync', [DidController::class, 'importSynced'])->name('dids.sync.import');
        Route::resource('dids', DidController::class);
        Route::post('dids/{did}/toggle', [DidController::class, 'toggle'])->name('dids.toggle');
        Route::post('dids/{did}/assign', [DidController::class, 'assign'])->name('dids.assign');
        Route::delete('dids/{did}/users/{user}', [DidController::class, 'unassign'])->name('dids.unassign');

        Route::get('approvals', [ApprovalController::class, 'index'])->name('approvals');
        Route::post('approvals/{campaign}/approve', [ApprovalController::class, 'approve'])->name('approvals.approve');
        Route::post('approvals/{campaign}/reject', [ApprovalController::class, 'reject'])->name('approvals.reject');

        Route::get('audit', [AuditLogController::class, 'index'])->name('audit');
    });
});
