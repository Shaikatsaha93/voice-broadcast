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
        Route::post('retry', [CampaignController::class, 'retry'])->name('retry');
        Route::post('cancel', [CampaignController::class, 'cancel'])->name('cancel');
    });

    Route::get('/audio', [AudioController::class, 'index'])->name('audio.index');
    Route::get('/audio/status', [AudioController::class, 'status'])->name('audio.status');
    Route::post('/audio', [AudioController::class, 'store'])->middleware('throttle:20,1')->name('audio.store');
    Route::post('/audio/{audio}/replace', [AudioController::class, 'replace'])->name('audio.replace');
    Route::delete('/audio/{audio}', [AudioController::class, 'destroy'])->name('audio.destroy');
    Route::get('/audio/{audio}/stream', [AudioController::class, 'stream'])->name('audio.stream');

    // Super Admin and Admin: user accounts, DIDs / SIP trunks, approvals and the audit trail. An Admin only reaches their own users and DIDs (UserPolicy / DidPolicy).
    Route::middleware('role:super_admin,admin')->prefix('admin')->name('admin.')->group(function () {
        Route::resource('users', UserController::class)->except('destroy');
        Route::post('users/{user}/toggle', [UserController::class, 'toggle'])->name('users.toggle');
        Route::post('users/{user}/reset-password', [UserController::class, 'resetPassword'])->name('users.reset');
        Route::get('audit', [AuditLogController::class, 'index'])->middleware('role:super_admin')->name('audit'); // Super Admin only

        // Approvals: Super Admin all campaigns, Admin only those of the users they created (CampaignPolicy::approve).
        Route::get('approvals', [ApprovalController::class, 'index'])->name('approvals');
        Route::post('approvals/{campaign}/approve', [ApprovalController::class, 'approve'])->name('approvals.approve');
        Route::post('approvals/{campaign}/reject', [ApprovalController::class, 'reject'])->name('approvals.reject');

        Route::post('dids/{did}/sip/apply', [DidController::class, 'sipApply'])->name('dids.sip.apply');
        Route::get('dids/{did}/sip/status', [DidController::class, 'sipStatus'])->name('dids.sip.status');
        Route::resource('dids', DidController::class);
        Route::post('dids/{did}/toggle', [DidController::class, 'toggle'])->name('dids.toggle');
        Route::post('dids/{did}/balance', [DidController::class, 'adjustBalance'])->name('dids.balance');
        Route::post('dids/{did}/assign', [DidController::class, 'assign'])->name('dids.assign');
        Route::delete('dids/{did}/users/{user}', [DidController::class, 'unassign'])->name('dids.unassign');
    });

});
