<?php

use App\Http\Controllers\AccountController;
use App\Http\Controllers\AuditLogController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\NewPasswordController;
use App\Http\Controllers\Auth\PasswordResetLinkController;
use App\Http\Controllers\BranchController;
use App\Http\Controllers\ClientController;
use App\Http\Controllers\ClientDocumentController;
use App\Http\Controllers\ClientGroupController;
use App\Http\Controllers\ClientKycController;
use App\Http\Controllers\ClientNoteController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\LinkPreviewController;
use App\Http\Controllers\LoanController;
use App\Http\Controllers\LoanProductController;
use App\Http\Controllers\MemberPortalController;
use App\Http\Controllers\OrganizationController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ReceiptController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\RoleController;
use App\Http\Controllers\SystemSettingController;
use App\Http\Controllers\TellerDrawerController;
use App\Http\Controllers\UserManagementController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return auth()->check() ? redirect()->route('dashboard') : redirect()->route('login');
});

Route::get('/health', fn () => response()->json([
    'status' => 'ok',
    'app' => config('app.name'),
    'time' => now()->toIso8601String(),
]))->name('health');

Route::get('/link-preview.svg', LinkPreviewController::class)->name('link-preview');

Route::middleware('guest')->group(function () {
    Route::get('/login', [LoginController::class, 'create'])->name('login');
    Route::post('/login', [LoginController::class, 'store']);
    Route::get('/forgot-password', [PasswordResetLinkController::class, 'create'])->name('password.request');
    Route::post('/forgot-password', [PasswordResetLinkController::class, 'store'])->name('password.email');
    Route::get('/reset-password/{token}', [NewPasswordController::class, 'create'])->name('password.reset');
    Route::post('/reset-password', [NewPasswordController::class, 'store'])->name('password.store');
});

Route::middleware('auth')->group(function () {
    Route::post('/logout', [LoginController::class, 'destroy'])->name('logout');
    Route::get('/dashboard', DashboardController::class)->name('dashboard');
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::put('/profile/password', [ProfileController::class, 'updatePassword'])->name('profile.password.update');
    Route::get('/member', MemberPortalController::class)->name('member.dashboard');
    Route::resource('branches', BranchController::class)->except(['show', 'destroy']);
    Route::resource('users', UserManagementController::class)->except(['show', 'destroy']);
    Route::resource('roles', RoleController::class)->except(['show', 'destroy']);
    Route::get('/organization/{organization}/edit', [OrganizationController::class, 'edit'])->name('organization.edit');
    Route::put('/organization/{organization}', [OrganizationController::class, 'update'])->name('organization.update');
    Route::get('/settings', [SystemSettingController::class, 'index'])->name('settings.index');
    Route::put('/settings', [SystemSettingController::class, 'update'])->name('settings.update');
    Route::get('/audit-logs', [AuditLogController::class, 'index'])->name('audit-logs.index');
    Route::get('/audit-logs/{auditLog}', [AuditLogController::class, 'show'])->name('audit-logs.show');
    Route::resource('clients', ClientController::class)->except('destroy');
    Route::resource('client-groups', ClientGroupController::class)->except('destroy');
    Route::post('/clients/{client}/documents', [ClientDocumentController::class, 'store'])->name('clients.documents.store');
    Route::get('/clients/{client}/documents/{document}', [ClientDocumentController::class, 'download'])->name('clients.documents.download');
    Route::put('/clients/{client}/documents/{document}/verify', [ClientDocumentController::class, 'verify'])->name('clients.documents.verify');
    Route::post('/clients/{client}/notes', [ClientNoteController::class, 'store'])->name('clients.notes.store');
    Route::put('/clients/{client}/kyc', [ClientKycController::class, 'update'])->name('clients.kyc.update');
    Route::resource('accounts', AccountController::class)->only(['index', 'create', 'store', 'show']);
    Route::resource('loan-products', LoanProductController::class)->only(['index', 'create', 'store']);
    Route::resource('loans', LoanController::class)->only(['index', 'create', 'store', 'show']);
    Route::post('/loans/{loan}/approve', [LoanController::class, 'approve'])->name('loans.approve');
    Route::post('/loans/{loan}/disburse', [LoanController::class, 'disburse'])->name('loans.disburse');
    Route::get('/teller-drawer', [TellerDrawerController::class, 'index'])->name('teller.index');
    Route::post('/teller-drawer/open', [TellerDrawerController::class, 'open'])->name('teller.open');
    Route::post('/teller-drawer/post', [TellerDrawerController::class, 'post'])->name('teller.post');
    Route::post('/teller-drawer/close', [TellerDrawerController::class, 'close'])->name('teller.close');
    Route::get('/receipts/{transaction}', [ReceiptController::class, 'show'])->name('receipts.show');
    Route::get('/reports', [ReportController::class, 'index'])->name('reports.index');
});
