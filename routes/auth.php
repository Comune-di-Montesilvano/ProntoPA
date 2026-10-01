<?php

use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\CompletaProfiloController;
use App\Http\Controllers\Auth\EmailVerificationNotificationController;
use App\Http\Controllers\Auth\EmailVerificationPromptController;
use App\Http\Controllers\Auth\SpidController;
use App\Http\Controllers\Auth\VerifyEmailController;
use App\Http\Controllers\Auth\ConfirmablePasswordController;
use App\Http\Controllers\Auth\NewPasswordController;
use App\Http\Controllers\Auth\PasswordController;
use App\Http\Controllers\Auth\PasswordResetLinkController;
use App\Http\Controllers\Auth\RegisteredUserController;
use App\Http\Controllers\Auth\TwoFactorAuthenticationController;
use App\Http\Controllers\Auth\TwoFactorChallengeController;
use App\Http\Controllers\Auth\TwoFactorRecoveryCodesController;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    Route::get('register', [RegisteredUserController::class, 'create'])
        ->name('register');

    Route::post('register', [RegisteredUserController::class, 'store']);

    Route::get('login', [AuthenticatedSessionController::class, 'create'])
        ->name('login');

    Route::post('login', [AuthenticatedSessionController::class, 'store']);

    Route::get('forgot-password', [PasswordResetLinkController::class, 'create'])
        ->name('password.request');

    Route::post('forgot-password', [PasswordResetLinkController::class, 'store'])
        ->name('password.email');

    Route::get('reset-password/{token}', [NewPasswordController::class, 'create'])
        ->name('password.reset');

    Route::post('reset-password', [NewPasswordController::class, 'store'])
        ->name('password.store');

    Route::get('two-factor-challenge', [TwoFactorChallengeController::class, 'create'])
        ->name('two-factor.login');

    Route::post('two-factor-challenge', [TwoFactorChallengeController::class, 'store'])
        ->middleware('throttle:two-factor');

    Route::post('two-factor-challenge/reinvia', [TwoFactorChallengeController::class, 'reinvia'])
        ->middleware('throttle:1,1')
        ->name('two-factor.reinvia');

    Route::get('auth/spid', [SpidController::class, 'start'])
        ->middleware('throttle:20,1')
        ->name('spid.start');

    Route::get('auth/spid/callback', [SpidController::class, 'callback'])
        ->middleware('throttle:20,1')
        ->name('spid.callback');

    Route::get('auth/spid/completa-profilo', [CompletaProfiloController::class, 'show'])
        ->name('spid.completa-profilo');

    Route::post('auth/spid/completa-profilo', [CompletaProfiloController::class, 'store'])
        ->middleware('throttle:10,1')
        ->name('spid.completa-profilo.store');
});

Route::middleware('auth')->group(function () {
    Route::get('verify-email', EmailVerificationPromptController::class)
        ->name('verification.notice');

    Route::get('verify-email/{id}/{hash}', VerifyEmailController::class)
        ->middleware(['signed', 'throttle:6,1'])
        ->name('verification.verify');

    Route::post('email/verification-notification', [EmailVerificationNotificationController::class, 'store'])
        ->middleware('throttle:6,1')
        ->name('verification.send');

    Route::get('confirm-password', [ConfirmablePasswordController::class, 'show'])
        ->name('password.confirm');

    Route::post('confirm-password', [ConfirmablePasswordController::class, 'store']);

    Route::put('password', [PasswordController::class, 'update'])
        ->name('password.update');

    Route::post('logout', [AuthenticatedSessionController::class, 'destroy'])
        ->name('logout');

    Route::middleware('password.confirm')->group(function () {
        Route::post('user/two-factor-authentication', [TwoFactorAuthenticationController::class, 'store'])
            ->name('two-factor.enable');

        Route::post('user/confirmed-two-factor-authentication', [TwoFactorAuthenticationController::class, 'confirm'])
            ->name('two-factor.confirm');

        Route::delete('user/two-factor-authentication', [TwoFactorAuthenticationController::class, 'destroy'])
            ->name('two-factor.disable');

        Route::get('user/two-factor-recovery-codes', [TwoFactorRecoveryCodesController::class, 'index'])
            ->name('two-factor.recovery-codes');

        Route::post('user/two-factor-recovery-codes', [TwoFactorRecoveryCodesController::class, 'store'])
            ->name('two-factor.recovery-codes.regenerate');
    });
});
