<?php

use App\Http\Controllers\Api\V1\AccountingController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\ClientController;
use App\Http\Controllers\Api\V1\ClientDocumentController;
use App\Http\Controllers\Api\V1\LoanController;
use App\Http\Controllers\Api\V1\LoanProductController;
use App\Http\Controllers\Api\V1\LoanRepaymentController;
use App\Http\Controllers\Api\V1\LoanWorkflowController;
use App\Http\Controllers\Api\V1\NotificationController;
use App\Http\Controllers\Api\V1\PaymentController;
use App\Http\Controllers\Api\V1\ReportController;
use App\Http\Controllers\Api\V1\SavingsAccountController;
use App\Http\Controllers\Api\V1\SavingsProductController;
use App\Http\Controllers\Api\V1\SavingsTransactionController;
use Illuminate\Support\Facades\Route;

Route::post('/auth/login', [AuthController::class, 'login'])->middleware('throttle:api')->name('auth.login');
Route::post('/auth/forgot-password', [AuthController::class, 'forgotPassword'])->middleware('throttle:api')->name('auth.forgot-password');
Route::post('/payments/mobile-money/callback', [PaymentController::class, 'callback'])->middleware('throttle:api')->name('payments.callback');

Route::middleware('auth:sanctum')->group(function (): void {
    Route::get('/auth/me', [AuthController::class, 'me'])->name('auth.me');
    Route::get('/auth/permissions', [AuthController::class, 'permissions'])->name('auth.permissions');
    Route::post('/auth/refresh', [AuthController::class, 'refresh'])->name('auth.refresh');
    Route::put('/auth/password', [AuthController::class, 'changePassword'])->name('auth.password.change');
    Route::post('/auth/logout', [AuthController::class, 'logout'])->name('auth.logout');

    Route::apiResource('clients', ClientController::class)->except(['destroy']);
    Route::get('/clients/{client}/documents', [ClientDocumentController::class, 'index'])->name('clients.documents.index');
    Route::post('/clients/{client}/documents', [ClientDocumentController::class, 'store'])->name('clients.documents.store');
    Route::get('/clients/{client}/documents/{document}/download', [ClientDocumentController::class, 'download'])->name('clients.documents.download');
    Route::get('/clients/{client}/accounts', [ClientController::class, 'accounts'])->name('clients.accounts');
    Route::get('/clients/{client}/loans', [ClientController::class, 'loans'])->name('clients.loans');
    Route::get('/clients/{client}/statements', [ClientController::class, 'statements'])->name('clients.statements');

    Route::get('/savings/products', [SavingsProductController::class, 'index'])->name('savings.products.index');
    Route::get('/savings/accounts', [SavingsAccountController::class, 'index'])->name('savings.accounts.index');
    Route::post('/savings/accounts', [SavingsAccountController::class, 'store'])->name('savings.accounts.store');
    Route::get('/savings/accounts/{savingsAccount}', [SavingsAccountController::class, 'show'])->name('savings.accounts.show');
    Route::get('/savings/accounts/{savingsAccount}/statement', [SavingsAccountController::class, 'statement'])->name('savings.accounts.statement');
    Route::get('/savings/accounts/{savingsAccount}/statement/download', [SavingsAccountController::class, 'statement'])->name('savings.accounts.statement.download');
    Route::post('/savings/accounts/{savingsAccount}/deposit', [SavingsTransactionController::class, 'deposit'])->name('savings.accounts.deposit');
    Route::post('/savings/accounts/{savingsAccount}/withdraw', [SavingsTransactionController::class, 'withdraw'])->name('savings.accounts.withdraw');
    Route::post('/savings/transfers', [SavingsTransactionController::class, 'transfer'])->name('savings.transfers.store');
    Route::post('/savings/transactions/{savingsTransaction}/reverse', [SavingsTransactionController::class, 'reverse'])->name('savings.transactions.reverse');

    Route::get('/savings-products', [SavingsProductController::class, 'index'])->name('savings-products.index');
    Route::apiResource('savings-accounts', SavingsAccountController::class)->only(['index', 'store', 'show']);
    Route::get('/savings-accounts/{savingsAccount}/statement', [SavingsAccountController::class, 'statement'])->name('savings-accounts.statement');
    Route::get('/savings-accounts/{savingsAccount}/statement/download', [SavingsAccountController::class, 'statement'])->name('savings-accounts.statement.download');
    Route::post('/savings-accounts/{savingsAccount}/deposit', [SavingsTransactionController::class, 'deposit'])->name('savings-accounts.deposit');
    Route::post('/savings-accounts/{savingsAccount}/withdraw', [SavingsTransactionController::class, 'withdraw'])->name('savings-accounts.withdraw');
    Route::post('/savings-transfers', [SavingsTransactionController::class, 'transfer'])->name('savings-transfers.store');
    Route::post('/savings-transactions/{savingsTransaction}/reverse', [SavingsTransactionController::class, 'reverse'])->name('savings-transactions.reverse');

    Route::get('/loan-products', [LoanProductController::class, 'index'])->name('loan-products.index');
    Route::apiResource('loans', LoanController::class)->only(['index', 'store', 'show']);
    Route::post('/loans/{loan}/submit', [LoanWorkflowController::class, 'submit'])->name('loans.submit');
    Route::post('/loans/{loan}/guarantors', [LoanWorkflowController::class, 'addGuarantor'])->name('loans.guarantors.store');
    Route::post('/loans/{loan}/collaterals', [LoanWorkflowController::class, 'addCollateral'])->name('loans.collaterals.store');
    Route::post('/loans/schedule-preview', [LoanWorkflowController::class, 'schedulePreview'])->name('loans.schedule-preview');
    Route::post('/loans/{loan}/approve', [LoanWorkflowController::class, 'approve'])->name('loans.approve');
    Route::post('/loans/{loan}/reject', [LoanWorkflowController::class, 'reject'])->name('loans.reject');
    Route::post('/loans/{loan}/disburse', [LoanWorkflowController::class, 'disburse'])->name('loans.disburse');
    Route::post('/loans/{loan}/repayments', [LoanRepaymentController::class, 'store'])->name('loans.repayments.store');
    Route::get('/loans/{loan}/schedule', [LoanController::class, 'schedule'])->name('loans.schedule');
    Route::get('/loans/arrears/list', [LoanWorkflowController::class, 'arrears'])->name('loans.arrears');
    Route::post('/loans/{loan}/close', [LoanWorkflowController::class, 'close'])->name('loans.close');

    Route::get('/accounting/chart-of-accounts', [AccountingController::class, 'chartOfAccounts'])->name('accounting.chart-of-accounts');
    Route::get('/accounting/journal-entries', [AccountingController::class, 'journalEntries'])->name('accounting.journal-entries');
    Route::get('/accounting/trial-balance', [AccountingController::class, 'trialBalance'])->name('accounting.trial-balance');
    Route::get('/accounting/general-ledger', [AccountingController::class, 'generalLedger'])->name('accounting.general-ledger');
    Route::get('/accounting/income-statement', [AccountingController::class, 'incomeStatement'])->name('accounting.income-statement');
    Route::get('/accounting/balance-sheet', [AccountingController::class, 'balanceSheet'])->name('accounting.balance-sheet');

    Route::get('/reports/dashboard', [ReportController::class, 'dashboard'])->name('reports.dashboard');
    Route::get('/reports/clients', [ReportController::class, 'clients'])->name('reports.clients');
    Route::get('/reports/savings', [ReportController::class, 'savings'])->name('reports.savings');
    Route::get('/reports/loans', [ReportController::class, 'loans'])->name('reports.loans');
    Route::get('/reports/arrears', [ReportController::class, 'arrears'])->name('reports.arrears');
    Route::get('/reports/tellers', [ReportController::class, 'tellers'])->name('reports.tellers');
    Route::get('/reports/branch-performance', [ReportController::class, 'branchPerformance'])->name('reports.branch-performance');
    Route::post('/reports/exports', [ReportController::class, 'export'])->name('reports.exports.store');

    Route::post('/payments/mobile-money/collections', [PaymentController::class, 'initiateCollection'])->name('payments.collections.store');
    Route::get('/payments/{paymentRequest}/verify', [PaymentController::class, 'verify'])->name('payments.verify');
    Route::post('/payments/reconcile', [PaymentController::class, 'reconcile'])->name('payments.reconcile');
    Route::post('/payments/{paymentRequest}/match', [PaymentController::class, 'matchPayment'])->name('payments.match');

    Route::post('/notifications/sms', [NotificationController::class, 'sendSms'])->name('notifications.sms.send');
    Route::get('/notifications/sms-logs', [NotificationController::class, 'smsLogs'])->name('notifications.sms-logs');
    Route::get('/notifications', [NotificationController::class, 'notifications'])->name('notifications.index');
    Route::post('/notifications/{notification}/read', [NotificationController::class, 'markRead'])->name('notifications.read');
});
