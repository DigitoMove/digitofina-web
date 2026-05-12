<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\ClientController;
use App\Http\Controllers\Api\V1\LoanController;
use App\Http\Controllers\Api\V1\LoanRepaymentController;
use App\Http\Controllers\Api\V1\LoanWorkflowController;
use App\Http\Controllers\Api\V1\SavingsAccountController;
use App\Http\Controllers\Api\V1\SavingsTransactionController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->name('api.v1.')->group(function (): void {
    Route::post('/auth/login', [AuthController::class, 'login'])->middleware('throttle:api')->name('auth.login');

    Route::middleware('auth:sanctum')->group(function (): void {
        Route::get('/auth/me', [AuthController::class, 'me'])->name('auth.me');
        Route::post('/auth/logout', [AuthController::class, 'logout'])->name('auth.logout');

        Route::apiResource('clients', ClientController::class)->except(['destroy']);

        Route::apiResource('savings-accounts', SavingsAccountController::class)->only(['index', 'store', 'show']);
        Route::get('/savings-accounts/{savingsAccount}/statement', [SavingsAccountController::class, 'statement'])->name('savings-accounts.statement');
        Route::post('/savings-accounts/{savingsAccount}/deposit', [SavingsTransactionController::class, 'deposit'])->name('savings-accounts.deposit');
        Route::post('/savings-accounts/{savingsAccount}/withdraw', [SavingsTransactionController::class, 'withdraw'])->name('savings-accounts.withdraw');
        Route::post('/savings-transfers', [SavingsTransactionController::class, 'transfer'])->name('savings-transfers.store');

        Route::apiResource('loans', LoanController::class)->only(['index', 'store', 'show']);
        Route::post('/loans/{loan}/approve', [LoanWorkflowController::class, 'approve'])->name('loans.approve');
        Route::post('/loans/{loan}/reject', [LoanWorkflowController::class, 'reject'])->name('loans.reject');
        Route::post('/loans/{loan}/disburse', [LoanWorkflowController::class, 'disburse'])->name('loans.disburse');
        Route::post('/loans/{loan}/repayments', [LoanRepaymentController::class, 'store'])->name('loans.repayments.store');
    });
});
