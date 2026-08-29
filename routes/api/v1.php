<?php

use App\Http\Controllers\Api\V1\AccountingController;
use App\Http\Controllers\Api\V1\ApiDocumentationController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\ClientController;
use App\Http\Controllers\Api\V1\ClientDocumentController;
use App\Http\Controllers\Api\V1\DeviceTokenController;
use App\Http\Controllers\Api\V1\FinancialControlController;
use App\Http\Controllers\Api\V1\FinancialRelationshipController;
use App\Http\Controllers\Api\V1\FixedSavingsController;
use App\Http\Controllers\Api\V1\GroupSavingsController;
use App\Http\Controllers\Api\V1\InvestmentAccountController;
use App\Http\Controllers\Api\V1\InvestmentPlanController;
use App\Http\Controllers\Api\V1\InvestmentTransactionController;
use App\Http\Controllers\Api\V1\InvestorAccountController;
use App\Http\Controllers\Api\V1\LoanController;
use App\Http\Controllers\Api\V1\LoanProductController;
use App\Http\Controllers\Api\V1\LoanRepaymentController;
use App\Http\Controllers\Api\V1\LoanWorkflowController;
use App\Http\Controllers\Api\V1\NotificationController;
use App\Http\Controllers\Api\V1\NylonPayWebhookController;
use App\Http\Controllers\Api\V1\OfflineSyncController;
use App\Http\Controllers\Api\V1\PaymentController;
use App\Http\Controllers\Api\V1\ReportController;
use App\Http\Controllers\Api\V1\SavingsAccountController;
use App\Http\Controllers\Api\V1\SavingsGoalController;
use App\Http\Controllers\Api\V1\SavingsProductController;
use App\Http\Controllers\Api\V1\SavingsTransactionController;
use App\Http\Controllers\Api\V1\SettingController;
use App\Http\Controllers\Api\V1\WebhookController;
use Illuminate\Support\Facades\Route;

Route::post('/auth/login', [AuthController::class, 'login'])->middleware(['tenant', 'throttle:api'])->name('auth.login');
Route::post('/auth/forgot-password', [AuthController::class, 'forgotPassword'])->middleware('throttle:api')->name('auth.forgot-password');
Route::get('/docs/openapi.json', [ApiDocumentationController::class, 'openapi'])->middleware('throttle:api')->name('docs.openapi');
Route::post('/payments/mobile-money/callback', [PaymentController::class, 'callback'])->middleware(['throttle:api', 'idempotent.financial'])->name('payments.callback');
Route::post('/webhooks/nylon-pay', NylonPayWebhookController::class)->middleware('throttle:api')->name('webhooks.nylon-pay');
Route::post('/webhooks/{source}/{provider}', [WebhookController::class, 'store'])->middleware('throttle:api')->name('webhooks.store');

Route::middleware(['auth:sanctum', 'tenant', 'demo.active', 'throttle:api', 'module:auto'])->group(function (): void {
    Route::get('/auth/me', [AuthController::class, 'me'])->name('auth.me');
    Route::get('/auth/permissions', [AuthController::class, 'permissions'])->name('auth.permissions');
    Route::post('/auth/refresh', [AuthController::class, 'refresh'])->name('auth.refresh');
    Route::put('/auth/password', [AuthController::class, 'changePassword'])->name('auth.password.change');
    Route::post('/auth/logout', [AuthController::class, 'logout'])->name('auth.logout');

    Route::get('/sync/manifest', [OfflineSyncController::class, 'manifest'])->name('sync.manifest');
    Route::get('/sync/clients', [OfflineSyncController::class, 'pullClients'])->name('sync.clients.pull');
    Route::post('/sync/clients', [OfflineSyncController::class, 'pushClients'])->middleware('throttle:api-sensitive')->name('sync.clients.push');

    Route::apiResource('clients', ClientController::class)->except(['destroy'])->middleware('demo.capacity:clients');
    Route::get('/clients/{client}/documents', [ClientDocumentController::class, 'index'])->name('clients.documents.index');
    Route::post('/clients/{client}/documents', [ClientDocumentController::class, 'store'])->name('clients.documents.store');
    Route::get('/clients/{client}/documents/{document}/download', [ClientDocumentController::class, 'download'])->name('clients.documents.download');
    Route::get('/clients/{client}/accounts', [ClientController::class, 'accounts'])->name('clients.accounts');
    Route::get('/clients/{client}/loans', [ClientController::class, 'loans'])->name('clients.loans');
    Route::get('/clients/{client}/statements', [ClientController::class, 'statements'])->name('clients.statements');
    Route::post('/clients/{client}/relationships', [ClientController::class, 'storeRelationship'])->middleware('throttle:api-sensitive')->name('clients.relationships.store');
    Route::get('/clients/{client}/financial-overview', [FinancialRelationshipController::class, 'clientOverview'])->name('clients.financial-overview');
    Route::get('/clients/{client}/combined-statement', [FinancialRelationshipController::class, 'combinedStatement'])->name('clients.combined-statement');

    Route::get('/savings/products', [SavingsProductController::class, 'index'])->name('savings.products.index');
    Route::put('/savings/products/{savingsProduct}', [SavingsProductController::class, 'update'])->middleware('throttle:api-sensitive')->name('savings.products.update');
    Route::get('/savings/accounts', [SavingsAccountController::class, 'index'])->name('savings.accounts.index');
    Route::post('/savings/accounts', [SavingsAccountController::class, 'store'])->name('savings.accounts.store');
    Route::get('/savings/accounts/{savingsAccount}', [SavingsAccountController::class, 'show'])->name('savings.accounts.show');
    Route::get('/savings/accounts/{savingsAccount}/statement', [SavingsAccountController::class, 'statement'])->name('savings.accounts.statement');
    Route::get('/savings/accounts/{savingsAccount}/statement/download', [SavingsAccountController::class, 'statement'])->name('savings.accounts.statement.download');
    Route::post('/savings/accounts/{savingsAccount}/statement/generate', [SavingsAccountController::class, 'generateStatement'])->middleware('throttle:api-sensitive')->name('savings.accounts.statement.generate');
    Route::post('/savings/accounts/{savingsAccountId}/deposit', [SavingsTransactionController::class, 'deposit'])->middleware(['throttle:api-sensitive', 'idempotent.financial'])->name('savings.accounts.deposit');
    Route::post('/savings/accounts/{savingsAccountId}/withdraw', [SavingsTransactionController::class, 'withdraw'])->middleware(['throttle:api-sensitive', 'idempotent.financial'])->name('savings.accounts.withdraw');
    Route::post('/savings/transfers', [SavingsTransactionController::class, 'transfer'])->middleware(['throttle:api-sensitive', 'idempotent.financial'])->name('savings.transfers.store');
    Route::post('/savings/transactions/{savingsTransaction}/reverse', [SavingsTransactionController::class, 'reverse'])->middleware(['throttle:api-sensitive', 'idempotent.financial'])->name('savings.transactions.reverse');

    Route::get('/savings-products', [SavingsProductController::class, 'index'])->name('savings-products.index');
    Route::put('/savings-products/{savingsProduct}', [SavingsProductController::class, 'update'])->middleware('throttle:api-sensitive')->name('savings-products.update');
    Route::apiResource('savings-accounts', SavingsAccountController::class)->only(['index', 'store', 'show']);
    Route::get('/savings-accounts/{savingsAccount}/statement', [SavingsAccountController::class, 'statement'])->name('savings-accounts.statement');
    Route::get('/savings-accounts/{savingsAccount}/statement/download', [SavingsAccountController::class, 'statement'])->name('savings-accounts.statement.download');
    Route::post('/savings-accounts/{savingsAccountId}/deposit', [SavingsTransactionController::class, 'deposit'])->middleware(['throttle:api-sensitive', 'idempotent.financial'])->name('savings-accounts.deposit');
    Route::post('/savings-accounts/{savingsAccountId}/withdraw', [SavingsTransactionController::class, 'withdraw'])->middleware(['throttle:api-sensitive', 'idempotent.financial'])->name('savings-accounts.withdraw');
    Route::post('/savings-transfers', [SavingsTransactionController::class, 'transfer'])->middleware(['throttle:api-sensitive', 'idempotent.financial'])->name('savings-transfers.store');
    Route::post('/savings-transactions/{savingsTransaction}/reverse', [SavingsTransactionController::class, 'reverse'])->middleware(['throttle:api-sensitive', 'idempotent.financial'])->name('savings-transactions.reverse');
    Route::apiResource('savings-goals', SavingsGoalController::class)->only(['index', 'store', 'show']);
    Route::post('/savings-goals/{savingsGoal}/transactions', [SavingsGoalController::class, 'transact'])->middleware(['throttle:api-sensitive', 'idempotent.financial'])->name('savings-goals.transactions.store');
    Route::apiResource('fixed-savings', FixedSavingsController::class)->only(['index', 'store']);
    Route::apiResource('group-savings', GroupSavingsController::class)->only(['index', 'store']);
    Route::post('/group-savings/{groupSavingsAccount}/contributions', [GroupSavingsController::class, 'contribute'])->middleware(['throttle:api-sensitive', 'idempotent.financial'])->name('group-savings.contributions.store');

    Route::get('/loan-products', [LoanProductController::class, 'index'])->name('loan-products.index');
    Route::put('/loan-products/{loanProduct}', [LoanProductController::class, 'update'])->middleware('throttle:api-sensitive')->name('loan-products.update');
    Route::apiResource('loans', LoanController::class)->only(['index', 'store', 'show'])->middleware('demo.capacity:loans');
    Route::post('/loans/{loan}/generate-savings-account', [LoanWorkflowController::class, 'generateSavingsAccount'])->name('loans.generate-savings-account');
    Route::post('/loans/{loan}/generate-loan-account', [LoanWorkflowController::class, 'generateLoanAccount'])->name('loans.generate-loan-account');
    Route::post('/loans/{loan}/submit-appraisal', [LoanWorkflowController::class, 'submitForAppraisal'])->name('loans.submit-appraisal');
    Route::post('/loans/{loan}/submit', [LoanWorkflowController::class, 'submit'])->name('loans.submit');
    Route::post('/loans/{loan}/guarantors', [LoanWorkflowController::class, 'addGuarantor'])->name('loans.guarantors.store');
    Route::post('/loans/{loan}/collaterals', [LoanWorkflowController::class, 'addCollateral'])->name('loans.collaterals.store');
    Route::post('/loans/schedule-preview', [LoanWorkflowController::class, 'schedulePreview'])->name('loans.schedule-preview');
    Route::post('/loans/{loan}/approve', [LoanWorkflowController::class, 'approve'])->middleware(['throttle:api-sensitive', 'idempotent.financial'])->name('loans.approve');
    Route::post('/loans/{loan}/reject', [LoanWorkflowController::class, 'reject'])->name('loans.reject');
    Route::post('/loans/{loan}/cancel', [LoanWorkflowController::class, 'cancel'])->name('loans.cancel');
    Route::post('/loans/{loan}/assign-officer', [LoanWorkflowController::class, 'assignOfficer'])->name('loans.assign-officer');
    Route::post('/loans/{loan}/disburse', [LoanWorkflowController::class, 'disburse'])->middleware(['throttle:api-sensitive', 'idempotent.financial'])->name('loans.disburse');
    Route::post('/loans/{loan}/repayments', [LoanRepaymentController::class, 'store'])->middleware(['throttle:api-sensitive', 'idempotent.financial'])->name('loans.repayments.store');
    Route::get('/loans/{loan}/schedule', [LoanController::class, 'schedule'])->name('loans.schedule');
    Route::get('/loans/arrears/list', [LoanWorkflowController::class, 'arrears'])->name('loans.arrears');
    Route::post('/loans/{loan}/close', [LoanWorkflowController::class, 'close'])->middleware('idempotent.financial')->name('loans.close');
    Route::post('/loans/{loan}/write-off', [LoanWorkflowController::class, 'writeOff'])->middleware(['throttle:api-sensitive', 'idempotent.financial'])->name('loans.write-off');
    Route::post('/loans/{loan}/penalties/waive', [LoanWorkflowController::class, 'waivePenalty'])->middleware(['throttle:api-sensitive', 'idempotent.financial'])->name('loans.penalties.waive');

    Route::apiResource('investment-plans', InvestmentPlanController::class)->only(['index', 'store', 'show']);
    Route::apiResource('investor-accounts', InvestorAccountController::class)->only(['index', 'store', 'show']);
    Route::apiResource('investment-accounts', InvestmentAccountController::class)->only(['index', 'show']);
    Route::post('/investment-accounts', [InvestmentAccountController::class, 'store'])->middleware(['throttle:api-sensitive', 'idempotent.financial'])->name('investment-accounts.store');
    Route::post('/investment-deposits', [InvestmentAccountController::class, 'store'])->middleware(['throttle:api-sensitive', 'idempotent.financial'])->name('investment-deposits.store');
    Route::post('/investment-accounts/{investmentAccount}/approve', [InvestmentAccountController::class, 'approve'])->middleware(['throttle:api-sensitive', 'idempotent.financial'])->name('investment-accounts.approve');
    Route::get('/investment-accounts/{investmentAccount}/statement', [InvestmentAccountController::class, 'statement'])->name('investment-accounts.statement');
    Route::post('/investment-accounts/{investmentAccount}/top-ups', [InvestmentTransactionController::class, 'topUp'])->middleware(['throttle:api-sensitive', 'idempotent.financial'])->name('investment-accounts.top-ups.store');
    Route::post('/investment-accounts/{investmentAccount}/withdrawals', [InvestmentTransactionController::class, 'withdraw'])->middleware(['throttle:api-sensitive', 'idempotent.financial'])->name('investment-accounts.withdrawals.store');
    Route::post('/investment-accounts/{investmentAccount}/returns', [InvestmentTransactionController::class, 'postReturn'])->middleware(['throttle:api-sensitive', 'idempotent.financial'])->name('investment-accounts.returns.store');
    Route::post('/investment-accounts/{investmentAccount}/reinvestments', [InvestmentTransactionController::class, 'reinvest'])->middleware(['throttle:api-sensitive', 'idempotent.financial'])->name('investment-accounts.reinvestments.store');

    Route::post('/financial-relationships/savings-to-investment', [FinancialRelationshipController::class, 'transferSavingsToInvestment'])->middleware(['throttle:api-sensitive', 'idempotent.financial'])->name('financial-relationships.savings-to-investment');
    Route::post('/financial-relationships/investment-accounts/{investmentAccount}/returns', [FinancialRelationshipController::class, 'postInvestmentReturn'])->middleware(['throttle:api-sensitive', 'idempotent.financial'])->name('financial-relationships.investment-returns');
    Route::post('/financial-relationships/loans/{loan}/security/savings', [FinancialRelationshipController::class, 'lockSavingsForLoan'])->middleware(['throttle:api-sensitive', 'idempotent.financial'])->name('financial-relationships.loan-security.savings');
    Route::post('/financial-relationships/loans/{loan}/security/investments', [FinancialRelationshipController::class, 'lockInvestmentForLoan'])->middleware(['throttle:api-sensitive', 'idempotent.financial'])->name('financial-relationships.loan-security.investments');
    Route::post('/financial-relationships/loans/{loan}/repay-from-savings', [FinancialRelationshipController::class, 'repayLoanFromSavings'])->middleware(['throttle:api-sensitive', 'idempotent.financial'])->name('financial-relationships.loan-repayments.savings');
    Route::post('/financial-relationships/loans/{loan}/repay-from-investment-returns', [FinancialRelationshipController::class, 'repayLoanFromInvestmentReturns'])->middleware(['throttle:api-sensitive', 'idempotent.financial'])->name('financial-relationships.loan-repayments.investment-returns');

    Route::get('/financial-controls/approvals', [FinancialControlController::class, 'approvals'])->name('financial-controls.approvals.index');
    Route::post('/financial-controls/approvals/{makerCheckerRequest}/approve', [FinancialControlController::class, 'approve'])->middleware('throttle:api-sensitive')->name('financial-controls.approvals.approve');
    Route::post('/financial-controls/approvals/{makerCheckerRequest}/reject', [FinancialControlController::class, 'reject'])->middleware('throttle:api-sensitive')->name('financial-controls.approvals.reject');

    Route::get('/accounting/chart-of-accounts', [AccountingController::class, 'chartOfAccounts'])->name('accounting.chart-of-accounts');
    Route::get('/accounting/journal-entries', [AccountingController::class, 'journalEntries'])->name('accounting.journal-entries');
    Route::post('/accounting/journal-entries', [AccountingController::class, 'storeJournalEntry'])->middleware(['throttle:api-sensitive', 'idempotent.financial'])->name('accounting.journal-entries.store');
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
    Route::get('/reports/investments', [ReportController::class, 'investments'])->name('reports.investments');
    Route::get('/reports/savings-analytics', [ReportController::class, 'savingsAnalytics'])->name('reports.savings-analytics');
    Route::post('/reports/exports', [ReportController::class, 'export'])->middleware('throttle:api-sensitive')->name('reports.exports.store');

    Route::get('/settings', [SettingController::class, 'index'])->name('settings.index');
    Route::put('/settings', [SettingController::class, 'update'])->middleware('throttle:api-sensitive')->name('settings.update');

    Route::post('/payments/mobile-money/collections', [PaymentController::class, 'initiateCollection'])->middleware('idempotent.financial')->name('payments.collections.store');
    Route::get('/payments/{paymentRequest}/verify', [PaymentController::class, 'verify'])->name('payments.verify');
    Route::post('/payments/reconcile', [PaymentController::class, 'reconcile'])->name('payments.reconcile');
    Route::post('/payments/{paymentRequest}/match', [PaymentController::class, 'matchPayment'])->name('payments.match');

    Route::post('/notifications/sms', [NotificationController::class, 'sendSms'])->name('notifications.sms.send');
    Route::get('/notifications/sms-logs', [NotificationController::class, 'smsLogs'])->name('notifications.sms-logs');
    Route::get('/notifications', [NotificationController::class, 'notifications'])->name('notifications.index');
    Route::post('/notifications/{notification}/read', [NotificationController::class, 'markRead'])->name('notifications.read');

    Route::get('/notifications/devices', [DeviceTokenController::class, 'index'])->name('notifications.devices.index');
    Route::post('/notifications/devices', [DeviceTokenController::class, 'store'])->name('notifications.devices.store');
    Route::delete('/notifications/devices/{deviceToken}', [DeviceTokenController::class, 'destroy'])->name('notifications.devices.destroy');

    Route::post('/webhooks/events/{webhookEvent}/retry', [WebhookController::class, 'retry'])->middleware('throttle:api-sensitive')->name('webhooks.events.retry');
});
