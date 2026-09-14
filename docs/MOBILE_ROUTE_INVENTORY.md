# Phase 1 route and report inventory

Generated from `php artisan route:list --except-vendor --json` on 2026-09-14. See [MOBILE_PARITY_MATRIX.md](MOBILE_PARITY_MATRIX.md) for capability status, acceptance criteria, roles and work packages. Each entry is a child acceptance checklist item, not proof of mobile implementation. Middleware below is route middleware only: controller guards, policies and services also apply.

Parameter names are normalized only for the static OpenAPI comparison. GET/HEAD is one route entry; PUT/PATCH expands to two operations. Public/infrastructure entries remain listed for explicit scope decisions.

## Web / non-API application routes

| Method | URI | Route name | Controller/action | Work package | Middleware |
| --- | --- | --- | --- | --- | --- |
| GET / HEAD | / | landing | LandingPageController | X03 | web, ResolveTenant |
| GET / HEAD | accounts | accounts.index | AccountController@index | A01 | web, Authenticate, ResolveTenant, EnsureDemoActive, EnsureModuleEnabled:auto |
| POST | accounts | accounts.store | AccountController@store | A01 | web, Authenticate, ResolveTenant, EnsureDemoActive, EnsureModuleEnabled:auto |
| GET / HEAD | accounts/create | accounts.create | AccountController@create | A01 | web, Authenticate, ResolveTenant, EnsureDemoActive, EnsureModuleEnabled:auto |
| GET / HEAD | accounts/{account} | accounts.show | AccountController@show | A01 | web, Authenticate, ResolveTenant, EnsureDemoActive, EnsureModuleEnabled:auto |
| GET / HEAD | arrears | arrears.index | ArrearsController@index | L07 | web, Authenticate, ResolveTenant, EnsureDemoActive, EnsureModuleEnabled:auto, EnsureModuleEnabled:loans |
| POST | arrears/apply-penalties | arrears.apply-penalties | ArrearsController@applyPenalties | L07 | web, Authenticate, ResolveTenant, EnsureDemoActive, EnsureModuleEnabled:auto, EnsureModuleEnabled:loans |
| POST | arrears/clear-penalties | arrears.clear-penalties | ArrearsController@clearPenalties | L07 | web, Authenticate, ResolveTenant, EnsureDemoActive, EnsureModuleEnabled:auto, EnsureModuleEnabled:loans |
| GET / HEAD | audit-logs | audit-logs.index | AuditLogController@index | O01 | web, Authenticate, ResolveTenant, EnsureDemoActive, EnsureModuleEnabled:auto |
| GET / HEAD | audit-logs/{auditLog} | audit-logs.show | AuditLogController@show | O01 | web, Authenticate, ResolveTenant, EnsureDemoActive, EnsureModuleEnabled:auto |
| PUT | billing/hosting-fees/{organizationHostingFee}/paid | organization-hosting-fees.paid | OrganizationHostingFeeController@update | B01 | web, Authenticate, ResolveTenant |
| PUT | billing/nylon-pay | nylon-pay.settings.update | NylonPaySettingController | B02 | web, Authenticate, ResolveTenant |
| GET / HEAD | billing/quotes | commercial-quotes.index | CommercialQuoteController@index | B01 | web, Authenticate, ResolveTenant |
| POST | billing/quotes | commercial-quotes.store | CommercialQuoteController@store | B01 | web, Authenticate, ResolveTenant, ThrottleRequests:6,1 |
| POST | billing/quotes/organizations | commercial-quotes.organizations.store | CommercialQuoteController@storeForOrganization | B01 | web, Authenticate, ResolveTenant, ThrottleRequests:6,1 |
| PUT | billing/quotes/{commercialQuote} | commercial-quotes.issue | CommercialQuoteController@issue | B01 | web, Authenticate, ResolveTenant |
| DELETE | billing/quotes/{commercialQuote} | commercial-quotes.destroy | CommercialQuoteController@destroy | B01 | web, Authenticate, ResolveTenant |
| POST | billing/quotes/{commercialQuote}/paid | commercial-quotes.paid | CommercialQuoteController@markPaid | B01 | web, Authenticate, ResolveTenant |
| POST | billing/quotes/{commercialQuote}/payment | commercial-quotes.pay | QuotePaymentController@store | B01/B02 | web, Authenticate, ResolveTenant, ThrottleRequests:6,1 |
| POST | billing/subscriptions | organization-subscriptions.store | OrganizationSubscriptionController@store | B01 | web, Authenticate, ResolveTenant |
| GET / HEAD | branches | branches.index | BranchController@index | S01 | web, Authenticate, ResolveTenant, EnsureDemoActive, EnsureModuleEnabled:auto, EnsureDemoCapacity:branches |
| POST | branches | branches.store | BranchController@store | S01 | web, Authenticate, ResolveTenant, EnsureDemoActive, EnsureModuleEnabled:auto, EnsureDemoCapacity:branches |
| GET / HEAD | branches/create | branches.create | BranchController@create | S01 | web, Authenticate, ResolveTenant, EnsureDemoActive, EnsureModuleEnabled:auto, EnsureDemoCapacity:branches |
| PUT / PATCH | branches/{branch} | branches.update | BranchController@update | S01 | web, Authenticate, ResolveTenant, EnsureDemoActive, EnsureModuleEnabled:auto, EnsureDemoCapacity:branches |
| GET / HEAD | branches/{branch}/edit | branches.edit | BranchController@edit | S01 | web, Authenticate, ResolveTenant, EnsureDemoActive, EnsureModuleEnabled:auto, EnsureDemoCapacity:branches |
| GET / HEAD | chat | chat.index | ChatController@index | N01 | web, Authenticate, ResolveTenant, EnsureDemoActive, EnsureModuleEnabled:auto |
| POST | chat | chat.store | ChatController@store | N01 | web, Authenticate, ResolveTenant, EnsureDemoActive, EnsureModuleEnabled:auto |
| GET / HEAD | chat/poll | chat.poll | ChatController@poll | N01 | web, Authenticate, ResolveTenant, EnsureDemoActive, EnsureModuleEnabled:auto |
| GET / HEAD | chat/{conversation} | chat.show | ChatController@show | N01 | web, Authenticate, ResolveTenant, EnsureDemoActive, EnsureModuleEnabled:auto |
| GET / HEAD | chat/{conversation}/messages | chat.messages | ChatController@messages | N01 | web, Authenticate, ResolveTenant, EnsureDemoActive, EnsureModuleEnabled:auto |
| POST | chat/{conversation}/messages | chat.messages.store | ChatController@message | N01 | web, Authenticate, ResolveTenant, EnsureDemoActive, EnsureModuleEnabled:auto |
| GET / HEAD | client | client.login | Auth\ClientLoginController@create | F01/M01 | web, ResolveTenant, RedirectIfAuthenticated |
| POST | client | client.login.store | Auth\ClientLoginController@store | F01/M01 | web, ResolveTenant, RedirectIfAuthenticated, ThrottleRequests:5,1 |
| GET / HEAD | client-directory | client-directory.index | ClientDirectoryController@index | C01 | web, Authenticate, ResolveTenant, EnsureDemoActive, EnsureModuleEnabled:auto |
| GET / HEAD | client-directory/{client} | client-directory.show | ClientDirectoryController@show | C01 | web, Authenticate, ResolveTenant, EnsureDemoActive, EnsureModuleEnabled:auto |
| GET / HEAD | client-groups | client-groups.index | ClientGroupController@index | C03 | web, Authenticate, ResolveTenant, EnsureDemoActive, EnsureModuleEnabled:auto, EnsureModuleEnabled:clients |
| POST | client-groups | client-groups.store | ClientGroupController@store | C03 | web, Authenticate, ResolveTenant, EnsureDemoActive, EnsureModuleEnabled:auto, EnsureModuleEnabled:clients |
| GET / HEAD | client-groups/create | client-groups.create | ClientGroupController@create | C03 | web, Authenticate, ResolveTenant, EnsureDemoActive, EnsureModuleEnabled:auto, EnsureModuleEnabled:clients |
| GET / HEAD | client-groups/{client_group} | client-groups.show | ClientGroupController@show | C03 | web, Authenticate, ResolveTenant, EnsureDemoActive, EnsureModuleEnabled:auto, EnsureModuleEnabled:clients |
| PUT / PATCH | client-groups/{client_group} | client-groups.update | ClientGroupController@update | C03 | web, Authenticate, ResolveTenant, EnsureDemoActive, EnsureModuleEnabled:auto, EnsureModuleEnabled:clients |
| GET / HEAD | client-groups/{client_group}/edit | client-groups.edit | ClientGroupController@edit | C03 | web, Authenticate, ResolveTenant, EnsureDemoActive, EnsureModuleEnabled:auto, EnsureModuleEnabled:clients |
| POST | client/login |  | Auth\ClientLoginController@store | F01/M01 | web, ResolveTenant, RedirectIfAuthenticated, ThrottleRequests:5,1 |
| GET / HEAD | client/login | client.login.legacy | Closure | X03 (infrastructure review) | web, ResolveTenant, RedirectIfAuthenticated |
| GET / HEAD | clients | clients.index | ClientController@index | C01/C03 | web, Authenticate, ResolveTenant, EnsureDemoActive, EnsureModuleEnabled:auto, EnsureModuleEnabled:clients, EnsureDemoCapacity:clients |
| POST | clients | clients.store | ClientController@store | C01/C03 | web, Authenticate, ResolveTenant, EnsureDemoActive, EnsureModuleEnabled:auto, EnsureModuleEnabled:clients, EnsureDemoCapacity:clients |
| GET / HEAD | clients/create | clients.create | ClientController@create | C01/C03 | web, Authenticate, ResolveTenant, EnsureDemoActive, EnsureModuleEnabled:auto, EnsureModuleEnabled:clients, EnsureDemoCapacity:clients |
| GET / HEAD | clients/{client} | clients.show | ClientController@show | C01/C03 | web, Authenticate, ResolveTenant, EnsureDemoActive, EnsureModuleEnabled:auto, EnsureModuleEnabled:clients, EnsureDemoCapacity:clients |
| PUT / PATCH | clients/{client} | clients.update | ClientController@update | C01/C03 | web, Authenticate, ResolveTenant, EnsureDemoActive, EnsureModuleEnabled:auto, EnsureModuleEnabled:clients, EnsureDemoCapacity:clients |
| DELETE | clients/{client} | clients.destroy | ClientController@destroy | C01/C03 | web, Authenticate, ResolveTenant, EnsureDemoActive, EnsureModuleEnabled:auto, EnsureModuleEnabled:clients, EnsureDemoCapacity:clients |
| GET / HEAD | clients/{client}/accounts | clients.accounts | ClientController@accounts | C01/C03 | web, Authenticate, ResolveTenant, EnsureDemoActive, EnsureModuleEnabled:auto, EnsureModuleEnabled:clients |
| POST | clients/{client}/assign-officer | clients.assign-officer | ClientController@assignOfficer | C01/C03 | web, Authenticate, ResolveTenant, EnsureDemoActive, EnsureModuleEnabled:auto |
| POST | clients/{client}/documents | clients.documents.store | ClientDocumentController@store | C02/F07 | web, Authenticate, ResolveTenant, EnsureDemoActive, EnsureModuleEnabled:auto, EnsureModuleEnabled:documents |
| GET / HEAD | clients/{client}/documents/{document} | clients.documents.download | ClientDocumentController@download | C02/F07 | web, Authenticate, ResolveTenant, EnsureDemoActive, EnsureModuleEnabled:auto, EnsureModuleEnabled:documents |
| PUT | clients/{client}/documents/{document}/verify | clients.documents.verify | ClientDocumentController@verify | C02/F07 | web, Authenticate, ResolveTenant, EnsureDemoActive, EnsureModuleEnabled:auto, EnsureModuleEnabled:documents |
| GET / HEAD | clients/{client}/edit | clients.edit | ClientController@edit | C01/C03 | web, Authenticate, ResolveTenant, EnsureDemoActive, EnsureModuleEnabled:auto, EnsureModuleEnabled:clients, EnsureDemoCapacity:clients |
| POST | clients/{client}/impersonate | clients.impersonate | ClientImpersonationController@store | F04 | web, Authenticate, ResolveTenant, ThrottleRequests:10,1 |
| PUT | clients/{client}/kyc | clients.kyc.update | ClientKycController@update | C02 | web, Authenticate, ResolveTenant, EnsureDemoActive, EnsureModuleEnabled:auto |
| GET / HEAD | clients/{client}/loans | clients.loans | ClientController@loans | C01/C03 | web, Authenticate, ResolveTenant, EnsureDemoActive, EnsureModuleEnabled:auto, EnsureModuleEnabled:loans |
| POST | clients/{client}/notes | clients.notes.store | ClientNoteController@store | C02 | web, Authenticate, ResolveTenant, EnsureDemoActive, EnsureModuleEnabled:auto |
| POST | clients/{client}/portal-access | clients.portal-access.store | ClientPortalAccessController@store | C03 | web, Authenticate, ResolveTenant, EnsureDemoActive, EnsureModuleEnabled:auto, EnsureModuleEnabled:clients |
| GET / HEAD | clients/{client}/savings-accounts | clients.savings-accounts | ClientController@savingsAccounts | C01/C03 | web, Authenticate, ResolveTenant, EnsureDemoActive, EnsureModuleEnabled:auto, EnsureModuleEnabled:savings |
| POST | clients/{client}/savings-accounts/generate | clients.savings-accounts.generate | SavingsAccountController@generate | A01/A03 | web, Authenticate, ResolveTenant, EnsureDemoActive, EnsureModuleEnabled:auto, EnsureModuleEnabled:savings |
| GET / HEAD | clients/{client}/statements | clients.statements | ClientStatementController@show | C04 | web, Authenticate, ResolveTenant, EnsureDemoActive, EnsureModuleEnabled:auto, EnsurePasswordChanged |
| GET / HEAD | currency-rates | currency-rates.index | CurrencyRateController@index | G02 | web, Authenticate, ResolveTenant, EnsureDemoActive, EnsureModuleEnabled:auto |
| POST | currency-rates | currency-rates.store | CurrencyRateController@store | G02 | web, Authenticate, ResolveTenant, EnsureDemoActive, EnsureModuleEnabled:auto |
| POST | currency-rates/refresh | currency-rates.refresh | CurrencyRateController@refresh | G02 | web, Authenticate, ResolveTenant, EnsureDemoActive, EnsureModuleEnabled:auto |
| GET / HEAD | dashboard | dashboard | DashboardController | U01 | web, Authenticate, ResolveTenant, EnsureDemoActive, EnsureModuleEnabled:auto |
| GET / HEAD | data-wipe | data-wipe.index | DataWipeController@index | O03 | web, Authenticate, ResolveTenant, EnsureDemoActive, EnsureModuleEnabled:auto |
| POST | data-wipe | data-wipe.store | DataWipeController@store | O03 | web, Authenticate, ResolveTenant, EnsureDemoActive, EnsureModuleEnabled:auto |
| GET / HEAD | demo | demo.create | DemoRegistrationController@create | S04/X03 | web, ResolveTenant, RedirectIfAuthenticated, EnsureDemoAvailable |
| POST | demo | demo.store | DemoRegistrationController@store | S04/X03 | web, ResolveTenant, RedirectIfAuthenticated, EnsureDemoAvailable, ThrottleRequests:demo-registration |
| GET / HEAD | demo/expired | demo.expired | DemoRegistrationController@expired | S04/X03 | web |
| GET / HEAD | due-repayments | due-repayments.index | DueRepaymentController@index | L07 | web, Authenticate, ResolveTenant, EnsureDemoActive, EnsureModuleEnabled:auto, EnsureModuleEnabled:loans |
| GET / HEAD | end-of-day | end-of-day.index | EndOfDayController@index | T05 | web, Authenticate, ResolveTenant, EnsureDemoActive, EnsureModuleEnabled:auto, EnsureModuleEnabled:teller |
| POST | end-of-day/close | end-of-day.close | EndOfDayController@close | T05 | web, Authenticate, ResolveTenant, EnsureDemoActive, EnsureModuleEnabled:auto |
| POST | end-of-day/lock | end-of-day.lock | EndOfDayController@lock | T05 | web, Authenticate, ResolveTenant, EnsureDemoActive, EnsureModuleEnabled:auto |
| POST | end-of-day/process | end-of-day.process | EndOfDayController@process | T05 | web, Authenticate, ResolveTenant, EnsureDemoActive, EnsureModuleEnabled:auto |
| POST | end-of-day/reconcile | end-of-day.reconcile | EndOfDayController@reconcile | T05 | web, Authenticate, ResolveTenant, EnsureDemoActive, EnsureModuleEnabled:auto |
| GET / HEAD | expenses | expenses.index | ExpenseController@index | T04 | web, Authenticate, ResolveTenant, EnsureDemoActive, EnsureModuleEnabled:auto, EnsureModuleEnabled:teller |
| DELETE | expenses/{expense} | expenses.destroy | ExpenseController@destroy | T04 | web, Authenticate, ResolveTenant, EnsureDemoActive, EnsureModuleEnabled:auto, EnsureModuleEnabled:teller |
| GET / HEAD | forgot-password | password.request | Auth\PasswordResetLinkController@create | F02 | web, ResolveTenant, RedirectIfAuthenticated |
| POST | forgot-password | password.email | Auth\PasswordResetLinkController@store | F02 | web, ResolveTenant, RedirectIfAuthenticated |
| GET / HEAD | health | health | Closure | X03 (infrastructure review) | web |
| DELETE | impersonation | impersonation.destroy | UserImpersonationController@destroy | F04 | web, Authenticate, ResolveTenant |
| GET / HEAD | impersonation/users | impersonation.users | UserImpersonationController@index | F04 | web, Authenticate, ResolveTenant |
| GET / HEAD | imports | imports.index | DataImportController@index | O02 | web, Authenticate, ResolveTenant, EnsureDemoActive, EnsureModuleEnabled:auto |
| POST | imports | imports.store | DataImportController@store | O02 | web, Authenticate, ResolveTenant, EnsureDemoActive, EnsureModuleEnabled:auto |
| GET / HEAD | imports/templates/{type} | imports.template | DataImportController@template | O02 | web, Authenticate, ResolveTenant, EnsureDemoActive, EnsureModuleEnabled:auto |
| GET / HEAD | imports/{import} | imports.show | DataImportController@show | O02 | web, Authenticate, ResolveTenant, EnsureDemoActive, EnsureModuleEnabled:auto |
| POST | imports/{import}/commit | imports.commit | DataImportController@commit | O02 | web, Authenticate, ResolveTenant, EnsureDemoActive, EnsureModuleEnabled:auto |
| POST | imports/{import}/rollback | imports.rollback | DataImportController@rollback | O02 | web, Authenticate, ResolveTenant, EnsureDemoActive, EnsureModuleEnabled:auto |
| PUT | imports/{import}/rows/{row} | imports.rows.update | DataImportController@updateRow | O02 | web, Authenticate, ResolveTenant, EnsureDemoActive, EnsureModuleEnabled:auto |
| GET / HEAD | ledger-accounts | ledger-accounts.index | LedgerAccountController@index | G02 | web, Authenticate, ResolveTenant, EnsureDemoActive, EnsureModuleEnabled:auto, EnsureModuleEnabled:accounting |
| POST | ledger-accounts | ledger-accounts.store | LedgerAccountController@store | G02 | web, Authenticate, ResolveTenant, EnsureDemoActive, EnsureModuleEnabled:auto, EnsureModuleEnabled:accounting |
| GET / HEAD | ledger-accounts/{chartAccount} | ledger-accounts.show | LedgerAccountController@show | G02 | web, Authenticate, ResolveTenant, EnsureDemoActive, EnsureModuleEnabled:auto, EnsureModuleEnabled:accounting |
| PUT | ledger-accounts/{chartAccount} | ledger-accounts.update | LedgerAccountController@update | G02 | web, Authenticate, ResolveTenant, EnsureDemoActive, EnsureModuleEnabled:auto, EnsureModuleEnabled:accounting |
| DELETE | ledger-accounts/{chartAccount} | ledger-accounts.destroy | LedgerAccountController@destroy | G02 | web, Authenticate, ResolveTenant, EnsureDemoActive, EnsureModuleEnabled:auto, EnsureModuleEnabled:accounting |
| POST | ledger-accounts/{chartAccount}/charges | ledger-accounts.charges | LedgerAccountController@postCharges | G02 | web, Authenticate, ResolveTenant, EnsureDemoActive, EnsureModuleEnabled:auto, EnsureModuleEnabled:accounting |
| POST | ledger-accounts/{chartAccount}/merge | ledger-accounts.merge | LedgerAccountController@merge | G02 | web, Authenticate, ResolveTenant, EnsureDemoActive, EnsureModuleEnabled:auto, EnsureModuleEnabled:accounting |
| POST | ledger-accounts/{chartAccount}/opening-balance | ledger-accounts.opening-balance | LedgerAccountController@postOpeningBalance | G02 | web, Authenticate, ResolveTenant, EnsureDemoActive, EnsureModuleEnabled:auto, EnsureModuleEnabled:accounting |
| GET / HEAD | link-preview.png | link-preview | LinkPreviewController | X03 | web |
| GET / HEAD | loan-products | loan-products.index | LoanProductController@index | S03/L02 | web, Authenticate, ResolveTenant, EnsureDemoActive, EnsureModuleEnabled:auto, EnsureModuleEnabled:loans |
| POST | loan-products | loan-products.store | LoanProductController@store | S03/L02 | web, Authenticate, ResolveTenant, EnsureDemoActive, EnsureModuleEnabled:auto, EnsureModuleEnabled:loans |
| GET / HEAD | loan-products/create | loan-products.create | LoanProductController@create | S03/L02 | web, Authenticate, ResolveTenant, EnsureDemoActive, EnsureModuleEnabled:auto, EnsureModuleEnabled:loans |
| PUT / PATCH | loan-products/{loan_product} | loan-products.update | LoanProductController@update | S03/L02 | web, Authenticate, ResolveTenant, EnsureDemoActive, EnsureModuleEnabled:auto, EnsureModuleEnabled:loans |
| GET / HEAD | loan-products/{loan_product}/edit | loan-products.edit | LoanProductController@edit | S03/L02 | web, Authenticate, ResolveTenant, EnsureDemoActive, EnsureModuleEnabled:auto, EnsureModuleEnabled:loans |
| GET / HEAD | loan-repayments/{loanRepayment}/receipt | loan-repayments.receipt | LoanRepaymentController@receipt | L05 | web, Authenticate, ResolveTenant, EnsureDemoActive, EnsureModuleEnabled:auto, EnsureModuleEnabled:loans |
| GET / HEAD | loans | loans.index | LoanController@index | L01/L02/L06 | web, Authenticate, ResolveTenant, EnsureDemoActive, EnsureModuleEnabled:auto, EnsureModuleEnabled:loans, EnsureDemoCapacity:loans |
| POST | loans | loans.store | LoanController@store | L01/L02/L06 | web, Authenticate, ResolveTenant, EnsureDemoActive, EnsureModuleEnabled:auto, EnsureModuleEnabled:loans, EnsureDemoCapacity:loans |
| GET / HEAD | loans/create | loans.create | LoanController@create | L01/L02/L06 | web, Authenticate, ResolveTenant, EnsureDemoActive, EnsureModuleEnabled:auto, EnsureModuleEnabled:loans, EnsureDemoCapacity:loans |
| POST | loans/schedule-preview | loans.schedule-preview | LoanController@schedulePreview | L01/L02/L06 | web, Authenticate, ResolveTenant, EnsureDemoActive, EnsureModuleEnabled:auto, EnsureModuleEnabled:loans |
| GET / HEAD | loans/{loan} | loans.show | LoanController@show | L01/L02/L06 | web, Authenticate, ResolveTenant, EnsureDemoActive, EnsureModuleEnabled:auto, EnsureModuleEnabled:loans, EnsureDemoCapacity:loans |
| PUT | loans/{loan}/application-fee | loans.application-fee.update | LoanFeeController@update | L06 | web, Authenticate, ResolveTenant, EnsureDemoActive, EnsureModuleEnabled:auto, EnsureModuleEnabled:loans |
| POST | loans/{loan}/approve | loans.approve | LoanController@approve | L01/L02/L06 | web, Authenticate, ResolveTenant, EnsureDemoActive, EnsureModuleEnabled:auto, EnsureModuleEnabled:loans |
| POST | loans/{loan}/assign-officer | loans.assign-officer | LoanController@assignOfficer | L01/L02/L06 | web, Authenticate, ResolveTenant, EnsureDemoActive, EnsureModuleEnabled:auto, EnsureModuleEnabled:loans |
| POST | loans/{loan}/cancel | loans.cancel | LoanController@cancel | L01/L02/L06 | web, Authenticate, ResolveTenant, EnsureDemoActive, EnsureModuleEnabled:auto, EnsureModuleEnabled:loans |
| POST | loans/{loan}/close-paid | loans.close-paid | LoanController@closePaid | L01/L02/L06 | web, Authenticate, ResolveTenant, EnsureDemoActive, EnsureModuleEnabled:auto, EnsureModuleEnabled:loans |
| POST | loans/{loan}/close-with-coverage | loans.close-with-coverage | LoanController@closeWithCoverage | L01/L02/L06 | web, Authenticate, ResolveTenant, EnsureDemoActive, EnsureModuleEnabled:auto, EnsureModuleEnabled:loans |
| POST | loans/{loan}/collaterals | loans.collaterals.store | LoanCollateralController@store | L03 | web, Authenticate, ResolveTenant, EnsureDemoActive, EnsureModuleEnabled:auto, EnsureModuleEnabled:loans |
| PUT | loans/{loan}/collaterals/{collateral} | loans.collaterals.status | LoanCollateralController@updateStatus | L03 | web, Authenticate, ResolveTenant, EnsureDemoActive, EnsureModuleEnabled:auto, EnsureModuleEnabled:loans |
| POST | loans/{loan}/disburse | loans.disburse | LoanController@disburse | L01/L02/L06 | web, Authenticate, ResolveTenant, EnsureDemoActive, EnsureModuleEnabled:auto, EnsureModuleEnabled:loans |
| POST | loans/{loan}/disbursements/{disbursement}/application-fee-refund | loans.application-fee.refund | LoanFeeController@refund | L06 | web, Authenticate, ResolveTenant, EnsureDemoActive, EnsureModuleEnabled:auto, EnsureModuleEnabled:loans |
| POST | loans/{loan}/draft-terms | loans.update-draft-terms | LoanController@updateDraftTerms | L01/L02/L06 | web, Authenticate, ResolveTenant, EnsureDemoActive, EnsureModuleEnabled:auto, EnsureModuleEnabled:loans |
| POST | loans/{loan}/fast-track-disbursement | loans.fast-track-disbursement | LoanController@fastTrackDisbursement | L01/L02/L06 | web, Authenticate, ResolveTenant, EnsureDemoActive, EnsureModuleEnabled:auto, EnsureModuleEnabled:loans |
| POST | loans/{loan}/generate-loan-account | loans.generate-loan-account | LoanController@generateLoanAccount | L01/L02/L06 | web, Authenticate, ResolveTenant, EnsureDemoActive, EnsureModuleEnabled:auto, EnsureModuleEnabled:loans |
| POST | loans/{loan}/generate-savings-account | loans.generate-savings-account | LoanController@generateSavingsAccount | L01/L02/L06 | web, Authenticate, ResolveTenant, EnsureDemoActive, EnsureModuleEnabled:auto, EnsureModuleEnabled:loans |
| POST | loans/{loan}/guarantors | loans.guarantors.store | LoanGuarantorController@store | L03 | web, Authenticate, ResolveTenant, EnsureDemoActive, EnsureModuleEnabled:auto, EnsureModuleEnabled:loans |
| PUT | loans/{loan}/guarantors/{guarantor} | loans.guarantors.status | LoanGuarantorController@updateStatus | L03 | web, Authenticate, ResolveTenant, EnsureDemoActive, EnsureModuleEnabled:auto, EnsureModuleEnabled:loans |
| POST | loans/{loan}/reject | loans.reject | LoanController@reject | L01/L02/L06 | web, Authenticate, ResolveTenant, EnsureDemoActive, EnsureModuleEnabled:auto, EnsureModuleEnabled:loans |
| POST | loans/{loan}/reopen | loans.reopen | LoanController@reopen | L01/L02/L06 | web, Authenticate, ResolveTenant, EnsureDemoActive, EnsureModuleEnabled:auto, EnsureModuleEnabled:loans |
| POST | loans/{loan}/repair-terms | loans.repair-terms | LoanController@repairTerms | L01/L02/L06 | web, Authenticate, ResolveTenant, EnsureDemoActive, EnsureModuleEnabled:auto, EnsureModuleEnabled:loans |
| POST | loans/{loan}/repayments | loans.repayments.store | LoanRepaymentController@store | L05 | web, Authenticate, ResolveTenant, EnsureDemoActive, EnsureModuleEnabled:auto, EnsureModuleEnabled:loans |
| POST | loans/{loan}/submit-appraisal | loans.submit-appraisal | LoanController@submitForAppraisal | L01/L02/L06 | web, Authenticate, ResolveTenant, EnsureDemoActive, EnsureModuleEnabled:auto, EnsureModuleEnabled:loans |
| POST | loans/{loan}/submit-approval | loans.submit-approval | LoanController@submitForApproval | L01/L02/L06 | web, Authenticate, ResolveTenant, EnsureDemoActive, EnsureModuleEnabled:auto, EnsureModuleEnabled:loans |
| GET / HEAD | login | login | Auth\LoginController@create | F01 | web, ResolveTenant, RedirectIfAuthenticated |
| POST | login |  | Auth\LoginController@store | F01 | web, ResolveTenant, RedirectIfAuthenticated |
| POST | logout | logout | Auth\LoginController@destroy | F01 | web, Authenticate, ResolveTenant, EnsureDemoActive, EnsureModuleEnabled:auto |
| GET / HEAD | member | member.dashboard | MemberPortalController | M01 | web, Authenticate, ResolveTenant, EnsureDemoActive, EnsureModuleEnabled:auto, EnsurePasswordChanged |
| POST | member/investment-accounts/{investmentAccount}/top-ups | member.investments.top-ups.store | MemberPaymentController@topUpInvestment | M02 | web, Authenticate, ResolveTenant, EnsureDemoActive, EnsureModuleEnabled:auto, EnsurePasswordChanged, EnsureModuleEnabled:investments |
| POST | member/loan-applications | member.loan-applications.store | MemberLoanApplicationController@store | M02 | web, Authenticate, ResolveTenant, EnsureDemoActive, EnsureModuleEnabled:auto, EnsurePasswordChanged, EnsureModuleEnabled:loans, ThrottleRequests:6,1 |
| POST | member/loans/{loan}/repayments | member.loans.repayments.store | MemberPaymentController@repayLoan | M02 | web, Authenticate, ResolveTenant, EnsureDemoActive, EnsureModuleEnabled:auto, EnsurePasswordChanged, EnsureModuleEnabled:loans |
| POST | member/savings-accounts/{savingsAccount}/deposits | member.savings.deposits.store | MemberPaymentController@depositSavings | M02 | web, Authenticate, ResolveTenant, EnsureDemoActive, EnsureModuleEnabled:auto, EnsurePasswordChanged, EnsureModuleEnabled:savings |
| GET / HEAD | member/statements | member.statements | ClientStatementController@member | C04 | web, Authenticate, ResolveTenant, EnsureDemoActive, EnsureModuleEnabled:auto, EnsurePasswordChanged |
| GET / HEAD | module-visibility | modules.visibility | ModuleManagementController@visibility | S04 | web, Authenticate, ResolveTenant, EnsureDemoActive, EnsureModuleEnabled:auto |
| GET / HEAD | modules | modules.index | ModuleManagementController@index | S04 | web, Authenticate, ResolveTenant, EnsureDemoActive, EnsureModuleEnabled:auto |
| POST | modules/{organization}/package | modules.package.assign | ModuleManagementController@assignPackage | S04 | web, Authenticate, ResolveTenant, EnsureDemoActive, EnsureModuleEnabled:auto |
| POST | modules/{organization}/{module} | modules.toggle | ModuleManagementController@toggle | S04 | web, Authenticate, ResolveTenant, EnsureDemoActive, EnsureModuleEnabled:auto |
| GET / HEAD | non-cash-transactions | non-cash.index | NonCashTransactionController@index | T04 | web, Authenticate, ResolveTenant, EnsureDemoActive, EnsureModuleEnabled:auto, EnsureModuleEnabled:teller |
| POST | non-cash-transactions | non-cash.store | NonCashTransactionController@store | T04 | web, Authenticate, ResolveTenant, EnsureDemoActive, EnsureModuleEnabled:auto, EnsureModuleEnabled:teller |
| GET / HEAD | notifications | notifications.index | NotificationController@index | N02 | web, Authenticate, ResolveTenant, EnsureDemoActive, EnsureModuleEnabled:auto |
| POST | notifications/read-all | notifications.read-all | NotificationController@markAllRead | N02 | web, Authenticate, ResolveTenant, EnsureDemoActive, EnsureModuleEnabled:auto |
| GET / HEAD | notifications/{notification}/open | notifications.open | NotificationController@open | N02 | web, Authenticate, ResolveTenant, EnsureDemoActive, EnsureModuleEnabled:auto |
| POST | notifications/{notification}/read | notifications.read | NotificationController@markRead | N02 | web, Authenticate, ResolveTenant, EnsureDemoActive, EnsureModuleEnabled:auto |
| GET / HEAD | operations-health | operations-health.index | OperationsHealthController | O01 | web, Authenticate, ResolveTenant, EnsureDemoActive, EnsureModuleEnabled:auto |
| GET / HEAD | organization | organization.current | OrganizationController@editCurrent | S01 | web, Authenticate, ResolveTenant, EnsureDemoActive, EnsureModuleEnabled:auto |
| PUT | organization | organization.current.update | OrganizationController@updateCurrent | S01 | web, Authenticate, ResolveTenant, EnsureDemoActive, EnsureModuleEnabled:auto |
| GET / HEAD | organization/nylon-pay | organization.nylon-pay.edit | OrganizationNylonPaySettingController@edit | B02 | web, Authenticate, ResolveTenant |
| PUT | organization/nylon-pay | organization.nylon-pay.update | OrganizationNylonPaySettingController@update | B02 | web, Authenticate, ResolveTenant |
| PUT | organization/{organization} | organization.update | OrganizationController@update | S01 | web, Authenticate, ResolveTenant, EnsureDemoActive, EnsureModuleEnabled:auto |
| GET / HEAD | organization/{organization}/edit | organization.edit | OrganizationController@edit | S01 | web, Authenticate, ResolveTenant, EnsureDemoActive, EnsureModuleEnabled:auto |
| GET / HEAD | organizations | organizations.index | OrganizationController@index | S01 | web, Authenticate, ResolveTenant, EnsureDemoActive, EnsureModuleEnabled:auto |
| POST | organizations | organizations.store | OrganizationController@store | S01 | web, Authenticate, ResolveTenant, EnsureDemoActive, EnsureModuleEnabled:auto |
| GET / HEAD | organizations/create | organizations.create | OrganizationController@create | S01 | web, Authenticate, ResolveTenant, EnsureDemoActive, EnsureModuleEnabled:auto |
| POST | organizations/switch/global | organizations.switch.global | OrganizationController@switch | S01 | web, Authenticate, ResolveTenant, EnsureDemoActive, EnsureModuleEnabled:auto |
| DELETE | organizations/{organization} | organizations.destroy | OrganizationController@destroy | S01 | web, Authenticate, ResolveTenant, EnsureDemoActive, EnsureModuleEnabled:auto |
| GET / HEAD | organizations/{organization}/financial-defaults | organizations.financial-defaults.index | OrganizationFinancialDefaultsController@index | S03 | web, Authenticate, ResolveTenant, EnsureDemoActive, EnsureModuleEnabled:auto |
| POST | organizations/{organization}/financial-defaults | organizations.financial-defaults.store | OrganizationFinancialDefaultsController@store | S03 | web, Authenticate, ResolveTenant, EnsureDemoActive, EnsureModuleEnabled:auto |
| DELETE | organizations/{organization}/financial-defaults | organizations.financial-defaults.destroy | OrganizationFinancialDefaultsController@destroy | S03 | web, Authenticate, ResolveTenant, EnsureDemoActive, EnsureModuleEnabled:auto |
| POST | organizations/{organization}/switch | organizations.switch | OrganizationController@switch | S01 | web, Authenticate, ResolveTenant, EnsureDemoActive, EnsureModuleEnabled:auto |
| GET / HEAD | organizations/{organization}/users | organizations.users | OrganizationController@users | S01 | web, Authenticate, ResolveTenant, EnsureDemoActive, EnsureModuleEnabled:auto |
| POST | organizations/{organization}/users | organizations.users.assign | OrganizationController@assignUser | S01 | web, Authenticate, ResolveTenant, EnsureDemoActive, EnsureModuleEnabled:auto |
| GET / HEAD | pricing | pricing | PricingController | X03 | web, ResolveTenant, EnsureDemoAvailable |
| GET / HEAD | profile | profile.edit | ProfileController@edit | F02 | web, Authenticate, ResolveTenant, EnsureDemoActive, EnsureModuleEnabled:auto |
| PUT | profile/password | profile.password.update | ProfileController@updatePassword | F02 | web, Authenticate, ResolveTenant, EnsureDemoActive, EnsureModuleEnabled:auto |
| GET / HEAD | receipts/{transaction} | receipts.show | ReceiptController@show | T01 | web, Authenticate, ResolveTenant, EnsureDemoActive, EnsureModuleEnabled:auto |
| POST | receipts/{transaction}/reversal-request | transactions.reversal-requests.store | TransactionReversalController@store | T03 | web, Authenticate, ResolveTenant, EnsureDemoActive, EnsureModuleEnabled:auto |
| GET / HEAD | reports | reports.index | ReportController@index | R01/R02/R03/R04 | web, Authenticate, ResolveTenant, EnsureDemoActive, EnsureModuleEnabled:auto |
| GET / HEAD | reports/{report} | reports.show | ReportController@show | R01/R02/R03/R04 | web, Authenticate, ResolveTenant, EnsureDemoActive, EnsureModuleEnabled:auto |
| POST | reset-password | password.store | Auth\NewPasswordController@store | F02 | web, ResolveTenant, RedirectIfAuthenticated |
| GET / HEAD | reset-password/{token} | password.reset | Auth\NewPasswordController@create | F02 | web, ResolveTenant, RedirectIfAuthenticated |
| GET / HEAD | roles | roles.index | RoleController@index | S02 | web, Authenticate, ResolveTenant, EnsureDemoActive, EnsureModuleEnabled:auto |
| POST | roles | roles.store | RoleController@store | S02 | web, Authenticate, ResolveTenant, EnsureDemoActive, EnsureModuleEnabled:auto |
| GET / HEAD | roles/create | roles.create | RoleController@create | S02 | web, Authenticate, ResolveTenant, EnsureDemoActive, EnsureModuleEnabled:auto |
| PUT / PATCH | roles/{role} | roles.update | RoleController@update | S02 | web, Authenticate, ResolveTenant, EnsureDemoActive, EnsureModuleEnabled:auto |
| GET / HEAD | roles/{role}/edit | roles.edit | RoleController@edit | S02 | web, Authenticate, ResolveTenant, EnsureDemoActive, EnsureModuleEnabled:auto |
| GET / HEAD | savings-accounts | savings-accounts.index | SavingsAccountController@index | A01/A03 | web, Authenticate, ResolveTenant, EnsureDemoActive, EnsureModuleEnabled:auto, EnsureModuleEnabled:savings |
| POST | savings-accounts | savings-accounts.store | SavingsAccountController@store | A01/A03 | web, Authenticate, ResolveTenant, EnsureDemoActive, EnsureModuleEnabled:auto, EnsureModuleEnabled:savings |
| GET / HEAD | savings-accounts/create | savings-accounts.create | SavingsAccountController@create | A01/A03 | web, Authenticate, ResolveTenant, EnsureDemoActive, EnsureModuleEnabled:auto, EnsureModuleEnabled:savings |
| GET / HEAD | savings-accounts/{savingsAccountId}/deposit | savings-transactions.deposit.create | SavingsTransactionController@depositForm | A02/A03 | web, Authenticate, ResolveTenant, EnsureDemoActive, EnsureModuleEnabled:auto, EnsureModuleEnabled:savings |
| POST | savings-accounts/{savingsAccountId}/deposit | savings-transactions.deposit.store | SavingsTransactionController@deposit | A02/A03 | web, Authenticate, ResolveTenant, EnsureDemoActive, EnsureModuleEnabled:auto, EnsureModuleEnabled:savings |
| GET / HEAD | savings-accounts/{savingsAccountId}/withdrawal | savings-transactions.withdrawal.create | SavingsTransactionController@withdrawalForm | A02/A03 | web, Authenticate, ResolveTenant, EnsureDemoActive, EnsureModuleEnabled:auto, EnsureModuleEnabled:savings |
| POST | savings-accounts/{savingsAccountId}/withdrawal | savings-transactions.withdrawal.store | SavingsTransactionController@withdrawal | A02/A03 | web, Authenticate, ResolveTenant, EnsureDemoActive, EnsureModuleEnabled:auto, EnsureModuleEnabled:savings |
| GET / HEAD | savings-accounts/{savingsAccount}/statement | savings-accounts.statement | SavingsAccountController@statement | A01/A03 | web, Authenticate, ResolveTenant, EnsureDemoActive, EnsureModuleEnabled:auto, EnsureModuleEnabled:savings |
| GET / HEAD | savings-accounts/{savings_account} | savings-accounts.show | SavingsAccountController@show | A01/A03 | web, Authenticate, ResolveTenant, EnsureDemoActive, EnsureModuleEnabled:auto, EnsureModuleEnabled:savings |
| DELETE | savings-accounts/{savings_account} | savings-accounts.destroy | SavingsAccountController@destroy | A01/A03 | web, Authenticate, ResolveTenant, EnsureDemoActive, EnsureModuleEnabled:auto, EnsureModuleEnabled:savings |
| GET / HEAD | savings-products | savings-products.index | SavingsProductController@index | S03 | web, Authenticate, ResolveTenant, EnsureDemoActive, EnsureModuleEnabled:auto, EnsureModuleEnabled:savings |
| POST | savings-products | savings-products.store | SavingsProductController@store | S03 | web, Authenticate, ResolveTenant, EnsureDemoActive, EnsureModuleEnabled:auto, EnsureModuleEnabled:savings |
| GET / HEAD | savings-products/create | savings-products.create | SavingsProductController@create | S03 | web, Authenticate, ResolveTenant, EnsureDemoActive, EnsureModuleEnabled:auto, EnsureModuleEnabled:savings |
| PUT / PATCH | savings-products/{savings_product} | savings-products.update | SavingsProductController@update | S03 | web, Authenticate, ResolveTenant, EnsureDemoActive, EnsureModuleEnabled:auto, EnsureModuleEnabled:savings |
| GET / HEAD | savings-products/{savings_product}/edit | savings-products.edit | SavingsProductController@edit | S03 | web, Authenticate, ResolveTenant, EnsureDemoActive, EnsureModuleEnabled:auto, EnsureModuleEnabled:savings |
| GET / HEAD | savings-transactions/{savingsTransactionId}/receipt | savings-transactions.receipt | SavingsTransactionController@receipt | A02/A03 | web, Authenticate, ResolveTenant, EnsureDemoActive, EnsureModuleEnabled:auto, EnsureModuleEnabled:savings |
| POST | savings-transfers | savings-transfers.store | SavingsTransactionController@transfer | A02/A03 | web, Authenticate, ResolveTenant, EnsureDemoActive, EnsureModuleEnabled:auto, EnsureModuleEnabled:savings |
| GET / HEAD | savings-transfers/create | savings-transfers.create | SavingsTransactionController@transferForm | A02/A03 | web, Authenticate, ResolveTenant, EnsureDemoActive, EnsureModuleEnabled:auto, EnsureModuleEnabled:savings |
| GET / HEAD | settings | settings.index | SystemSettingController@index | S03 | web, Authenticate, ResolveTenant, EnsureDemoActive, EnsureModuleEnabled:auto |
| PUT | settings | settings.update | SystemSettingController@update | S03 | web, Authenticate, ResolveTenant, EnsureDemoActive, EnsureModuleEnabled:auto |
| GET / HEAD | system-activity | system-activity.index | SystemActivityController@index | O01 | web, Authenticate, ResolveTenant, EnsureDemoActive, EnsureModuleEnabled:auto |
| GET / HEAD | system-activity/users/{user} | system-activity.users.show | SystemActivityController@show | O01 | web, Authenticate, ResolveTenant, EnsureDemoActive, EnsureModuleEnabled:auto |
| GET / HEAD | teller-drawer | teller.index | TellerDrawerController@index | T01/T02 | web, Authenticate, ResolveTenant, EnsureDemoActive, EnsureModuleEnabled:auto, EnsureModuleEnabled:teller |
| POST | teller-drawer/close | teller.close | TellerDrawerController@close | T01/T02 | web, Authenticate, ResolveTenant, EnsureDemoActive, EnsureModuleEnabled:auto |
| POST | teller-drawer/drawers | teller.drawers.store | TellerDrawerController@storeDrawer | T01/T02 | web, Authenticate, ResolveTenant, EnsureDemoActive, EnsureModuleEnabled:auto |
| POST | teller-drawer/managed-post | teller.managed-post | TellerDrawerController@postManagedDrawer | T01/T02 | web, Authenticate, ResolveTenant, EnsureDemoActive, EnsureModuleEnabled:auto |
| POST | teller-drawer/open | teller.open | TellerDrawerController@open | T01/T02 | web, Authenticate, ResolveTenant, EnsureDemoActive, EnsureModuleEnabled:auto |
| POST | teller-drawer/post | teller.post | TellerDrawerController@post | T01/T02 | web, Authenticate, ResolveTenant, EnsureDemoActive, EnsureModuleEnabled:auto |
| POST | teller-drawer/transfer | teller.transfer | TellerDrawerController@transfer | T01/T02 | web, Authenticate, ResolveTenant, EnsureDemoActive, EnsureModuleEnabled:auto |
| PUT | teller-drawer/{drawer} | teller.drawers.update | TellerDrawerController@updateDrawer | T01/T02 | web, Authenticate, ResolveTenant, EnsureDemoActive, EnsureModuleEnabled:auto |
| DELETE | teller-drawer/{drawer} | teller.drawers.destroy | TellerDrawerController@destroyDrawer | T01/T02 | web, Authenticate, ResolveTenant, EnsureDemoActive, EnsureModuleEnabled:auto |
| POST | teller-drawer/{drawer}/close | teller.drawers.close | TellerDrawerController@closeDrawer | T01/T02 | web, Authenticate, ResolveTenant, EnsureDemoActive, EnsureModuleEnabled:auto |
| POST | traffic/heartbeat | traffic.heartbeat | TrafficHeartbeatController | X03 | web, ThrottleRequests:12,1 |
| POST | transaction-preview | transactions.preview | TransactionPreviewController | T01 | web, Authenticate, ResolveTenant, EnsureDemoActive, EnsureModuleEnabled:auto, ThrottleRequests:60,1 |
| GET / HEAD | transaction-reversal-requests | transaction-reversal-requests.index | TransactionReversalController@index | T03 | web, Authenticate, ResolveTenant, EnsureDemoActive, EnsureModuleEnabled:auto, EnsureModuleEnabled:teller |
| POST | transaction-reversal-requests/{makerCheckerRequest}/approve | transactions.reversal-requests.approve | TransactionReversalController@approve | T03 | web, Authenticate, ResolveTenant, EnsureDemoActive, EnsureModuleEnabled:auto |
| POST | transaction-reversal-requests/{makerCheckerRequest}/reject | transactions.reversal-requests.reject | TransactionReversalController@reject | T03 | web, Authenticate, ResolveTenant, EnsureDemoActive, EnsureModuleEnabled:auto |
| DELETE | transactions/delete-by-reference | transactions.delete-by-reference | TransactionDeletionController@destroyByReference | T04 | web, Authenticate, ResolveTenant, EnsureDemoActive, EnsureModuleEnabled:auto |
| GET / HEAD | users | users.index | UserManagementController@index | S02 | web, Authenticate, ResolveTenant, EnsureDemoActive, EnsureModuleEnabled:auto, EnsureDemoCapacity:users |
| POST | users | users.store | UserManagementController@store | S02 | web, Authenticate, ResolveTenant, EnsureDemoActive, EnsureModuleEnabled:auto, EnsureDemoCapacity:users |
| GET / HEAD | users/create | users.create | UserManagementController@create | S02 | web, Authenticate, ResolveTenant, EnsureDemoActive, EnsureModuleEnabled:auto, EnsureDemoCapacity:users |
| GET / HEAD | users/form-options | users.form-options | UserFormOptionsController | S02 | web, Authenticate, ResolveTenant, EnsureDemoActive, EnsureModuleEnabled:auto |
| PUT / PATCH | users/{user} | users.update | UserManagementController@update | S02 | web, Authenticate, ResolveTenant, EnsureDemoActive, EnsureModuleEnabled:auto, EnsureDemoCapacity:users |
| GET / HEAD | users/{user}/edit | users.edit | UserManagementController@edit | S02 | web, Authenticate, ResolveTenant, EnsureDemoActive, EnsureModuleEnabled:auto, EnsureDemoCapacity:users |
| POST | users/{user}/impersonate | users.impersonate | UserImpersonationController@store | F04 | web, Authenticate, ResolveTenant |

## API v1 routes

| Method | URI | Route name | Controller/action | Work package | Middleware |
| --- | --- | --- | --- | --- | --- |
| GET / HEAD | api/v1/accounting/balance-sheet | api.v1.accounting.balance-sheet | Api\V1\AccountingController@balanceSheet | G01 | api, AuditApiMutation, Authenticate:sanctum, ImpersonateApiUser, ResolveTenant, EnsureDemoActive, ThrottleRequests:api, EnsureModuleEnabled:auto |
| GET / HEAD | api/v1/accounting/chart-of-accounts | api.v1.accounting.chart-of-accounts | Api\V1\AccountingController@chartOfAccounts | G01 | api, AuditApiMutation, Authenticate:sanctum, ImpersonateApiUser, ResolveTenant, EnsureDemoActive, ThrottleRequests:api, EnsureModuleEnabled:auto |
| GET / HEAD | api/v1/accounting/general-ledger | api.v1.accounting.general-ledger | Api\V1\AccountingController@generalLedger | G01 | api, AuditApiMutation, Authenticate:sanctum, ImpersonateApiUser, ResolveTenant, EnsureDemoActive, ThrottleRequests:api, EnsureModuleEnabled:auto |
| GET / HEAD | api/v1/accounting/income-statement | api.v1.accounting.income-statement | Api\V1\AccountingController@incomeStatement | G01 | api, AuditApiMutation, Authenticate:sanctum, ImpersonateApiUser, ResolveTenant, EnsureDemoActive, ThrottleRequests:api, EnsureModuleEnabled:auto |
| GET / HEAD | api/v1/accounting/journal-entries | api.v1.accounting.journal-entries | Api\V1\AccountingController@journalEntries | G01 | api, AuditApiMutation, Authenticate:sanctum, ImpersonateApiUser, ResolveTenant, EnsureDemoActive, ThrottleRequests:api, EnsureModuleEnabled:auto |
| POST | api/v1/accounting/journal-entries | api.v1.accounting.journal-entries.store | Api\V1\AccountingController@storeJournalEntry | G01 | api, AuditApiMutation, Authenticate:sanctum, ImpersonateApiUser, ResolveTenant, EnsureDemoActive, ThrottleRequests:api, EnsureModuleEnabled:auto, ThrottleRequests:api-sensitive, EnsureIdempotentFinancialRequest |
| GET / HEAD | api/v1/accounting/trial-balance | api.v1.accounting.trial-balance | Api\V1\AccountingController@trialBalance | G01 | api, AuditApiMutation, Authenticate:sanctum, ImpersonateApiUser, ResolveTenant, EnsureDemoActive, ThrottleRequests:api, EnsureModuleEnabled:auto |
| GET / HEAD | api/v1/admin/dashboard | api.v1.admin.dashboard | Api\V1\AdminWorkspaceController@dashboard | F03/S01/S02/S04 | api, AuditApiMutation, Authenticate:sanctum, ImpersonateApiUser, ResolveTenant, EnsureDemoActive, ThrottleRequests:api, EnsureModuleEnabled:auto |
| GET / HEAD | api/v1/admin/impersonation/users | api.v1.impersonation.users | Api\V1\ImpersonationController@index | F04 | api, AuditApiMutation, Authenticate:sanctum, ImpersonateApiUser, ResolveTenant, EnsureDemoActive, ThrottleRequests:api, EnsureModuleEnabled:auto |
| GET / HEAD | api/v1/admin/records/{section} | api.v1.admin.records.index | Api\V1\AdminWorkspaceController@index | F03/S01/S02/S04 | api, AuditApiMutation, Authenticate:sanctum, ImpersonateApiUser, ResolveTenant, EnsureDemoActive, ThrottleRequests:api, EnsureModuleEnabled:auto |
| GET / HEAD | api/v1/admin/records/{section}/{id} | api.v1.admin.records.show | Api\V1\AdminWorkspaceController@show | F03/S01/S02/S04 | api, AuditApiMutation, Authenticate:sanctum, ImpersonateApiUser, ResolveTenant, EnsureDemoActive, ThrottleRequests:api, EnsureModuleEnabled:auto |
| POST | api/v1/auth/forgot-password | api.v1.auth.forgot-password | Api\V1\AuthController@forgotPassword | F01/F02/F03 | api, AuditApiMutation, ThrottleRequests:api |
| POST | api/v1/auth/login | api.v1.auth.login | Api\V1\AuthController@login | F01/F02/F03 | api, AuditApiMutation, ResolveTenant, ThrottleRequests:api |
| POST | api/v1/auth/logout | api.v1.auth.logout | Api\V1\AuthController@logout | F01/F02/F03 | api, AuditApiMutation, Authenticate:sanctum, ImpersonateApiUser, ResolveTenant, EnsureDemoActive, ThrottleRequests:api, EnsureModuleEnabled:auto |
| GET / HEAD | api/v1/auth/me | api.v1.auth.me | Api\V1\AuthController@me | F01/F02/F03 | api, AuditApiMutation, Authenticate:sanctum, ImpersonateApiUser, ResolveTenant, EnsureDemoActive, ThrottleRequests:api, EnsureModuleEnabled:auto |
| PUT | api/v1/auth/password | api.v1.auth.password.change | Api\V1\AuthController@changePassword | F01/F02/F03 | api, AuditApiMutation, Authenticate:sanctum, ImpersonateApiUser, ResolveTenant, EnsureDemoActive, ThrottleRequests:api, EnsureModuleEnabled:auto |
| GET / HEAD | api/v1/auth/permissions | api.v1.auth.permissions | Api\V1\AuthController@permissions | F01/F02/F03 | api, AuditApiMutation, Authenticate:sanctum, ImpersonateApiUser, ResolveTenant, EnsureDemoActive, ThrottleRequests:api, EnsureModuleEnabled:auto |
| POST | api/v1/auth/refresh | api.v1.auth.refresh | Api\V1\AuthController@refresh | F01/F02/F03 | api, AuditApiMutation, Authenticate:sanctum, ImpersonateApiUser, ResolveTenant, EnsureDemoActive, ThrottleRequests:api, EnsureModuleEnabled:auto |
| GET / HEAD | api/v1/cashier/dashboard | api.v1.cashier.dashboard | Api\V1\CashierWorkspaceController@dashboard | T01/T02 | api, AuditApiMutation, Authenticate:sanctum, ImpersonateApiUser, ResolveTenant, EnsureDemoActive, ThrottleRequests:api, EnsureModuleEnabled:auto, EnsureModuleEnabled:teller |
| GET / HEAD | api/v1/cashier/lookup | api.v1.cashier.lookup | Api\V1\CashierWorkspaceController@lookup | T01/T02 | api, AuditApiMutation, Authenticate:sanctum, ImpersonateApiUser, ResolveTenant, EnsureDemoActive, ThrottleRequests:api, EnsureModuleEnabled:auto, EnsureModuleEnabled:teller |
| POST | api/v1/cashier/open | api.v1.cashier.open | Api\V1\CashierWorkspaceController@open | T01/T02 | api, AuditApiMutation, Authenticate:sanctum, ImpersonateApiUser, ResolveTenant, EnsureDemoActive, ThrottleRequests:api, EnsureModuleEnabled:auto, EnsureModuleEnabled:teller, ThrottleRequests:api-sensitive, EnsureIdempotentFinancialRequest |
| POST | api/v1/cashier/preview | api.v1.cashier.preview | Api\V1\CashierWorkspaceController@preview | T01/T02 | api, AuditApiMutation, Authenticate:sanctum, ImpersonateApiUser, ResolveTenant, EnsureDemoActive, ThrottleRequests:api, EnsureModuleEnabled:auto, EnsureModuleEnabled:teller, ThrottleRequests:api-sensitive |
| GET / HEAD | api/v1/cashier/sessions | api.v1.cashier.sessions.index | Api\V1\CashierWorkspaceController@sessions | T01/T02 | api, AuditApiMutation, Authenticate:sanctum, ImpersonateApiUser, ResolveTenant, EnsureDemoActive, ThrottleRequests:api, EnsureModuleEnabled:auto, EnsureModuleEnabled:teller |
| GET / HEAD | api/v1/cashier/sessions/{id} | api.v1.cashier.sessions.show | Api\V1\CashierWorkspaceController@showSession | T01/T02 | api, AuditApiMutation, Authenticate:sanctum, ImpersonateApiUser, ResolveTenant, EnsureDemoActive, ThrottleRequests:api, EnsureModuleEnabled:auto, EnsureModuleEnabled:teller |
| GET / HEAD | api/v1/cashier/sessions/{id}/movements | api.v1.cashier.movements.index | Api\V1\CashierWorkspaceController@movements | T01/T02 | api, AuditApiMutation, Authenticate:sanctum, ImpersonateApiUser, ResolveTenant, EnsureDemoActive, ThrottleRequests:api, EnsureModuleEnabled:auto, EnsureModuleEnabled:teller |
| GET / HEAD | api/v1/cashier/sessions/{id}/transactions | api.v1.cashier.transactions.index | Api\V1\CashierWorkspaceController@transactions | T01/T02 | api, AuditApiMutation, Authenticate:sanctum, ImpersonateApiUser, ResolveTenant, EnsureDemoActive, ThrottleRequests:api, EnsureModuleEnabled:auto, EnsureModuleEnabled:teller |
| GET / HEAD | api/v1/cashier/sessions/{id}/transactions/{transaction} | api.v1.cashier.transactions.show | Api\V1\CashierWorkspaceController@receipt | T01/T02 | api, AuditApiMutation, Authenticate:sanctum, ImpersonateApiUser, ResolveTenant, EnsureDemoActive, ThrottleRequests:api, EnsureModuleEnabled:auto, EnsureModuleEnabled:teller |
| POST | api/v1/cashier/transactions | api.v1.cashier.transactions.store | Api\V1\CashierWorkspaceController@post | T01/T02 | api, AuditApiMutation, Authenticate:sanctum, ImpersonateApiUser, ResolveTenant, EnsureDemoActive, ThrottleRequests:api, EnsureModuleEnabled:auto, EnsureModuleEnabled:teller, ThrottleRequests:api-sensitive, EnsureIdempotentFinancialRequest |
| GET / HEAD | api/v1/clients | api.v1.clients.index | Api\V1\ClientController@index | C01/C03 | api, AuditApiMutation, Authenticate:sanctum, ImpersonateApiUser, ResolveTenant, EnsureDemoActive, ThrottleRequests:api, EnsureModuleEnabled:auto, EnsureDemoCapacity:clients |
| POST | api/v1/clients | api.v1.clients.store | Api\V1\ClientController@store | C01/C03 | api, AuditApiMutation, Authenticate:sanctum, ImpersonateApiUser, ResolveTenant, EnsureDemoActive, ThrottleRequests:api, EnsureModuleEnabled:auto, EnsureDemoCapacity:clients |
| GET / HEAD | api/v1/clients/{client} | api.v1.clients.show | Api\V1\ClientController@show | C01/C03 | api, AuditApiMutation, Authenticate:sanctum, ImpersonateApiUser, ResolveTenant, EnsureDemoActive, ThrottleRequests:api, EnsureModuleEnabled:auto, EnsureDemoCapacity:clients |
| PUT / PATCH | api/v1/clients/{client} | api.v1.clients.update | Api\V1\ClientController@update | C01/C03 | api, AuditApiMutation, Authenticate:sanctum, ImpersonateApiUser, ResolveTenant, EnsureDemoActive, ThrottleRequests:api, EnsureModuleEnabled:auto, EnsureDemoCapacity:clients |
| GET / HEAD | api/v1/clients/{client}/accounts | api.v1.clients.accounts | Api\V1\ClientController@accounts | C01/C03 | api, AuditApiMutation, Authenticate:sanctum, ImpersonateApiUser, ResolveTenant, EnsureDemoActive, ThrottleRequests:api, EnsureModuleEnabled:auto |
| GET / HEAD | api/v1/clients/{client}/combined-statement | api.v1.clients.combined-statement | Api\V1\FinancialRelationshipController@combinedStatement | P04 | api, AuditApiMutation, Authenticate:sanctum, ImpersonateApiUser, ResolveTenant, EnsureDemoActive, ThrottleRequests:api, EnsureModuleEnabled:auto |
| GET / HEAD | api/v1/clients/{client}/documents | api.v1.clients.documents.index | Api\V1\ClientDocumentController@index | C02/F07 | api, AuditApiMutation, Authenticate:sanctum, ImpersonateApiUser, ResolveTenant, EnsureDemoActive, ThrottleRequests:api, EnsureModuleEnabled:auto |
| POST | api/v1/clients/{client}/documents | api.v1.clients.documents.store | Api\V1\ClientDocumentController@store | C02/F07 | api, AuditApiMutation, Authenticate:sanctum, ImpersonateApiUser, ResolveTenant, EnsureDemoActive, ThrottleRequests:api, EnsureModuleEnabled:auto |
| GET / HEAD | api/v1/clients/{client}/documents/{document}/download | api.v1.clients.documents.download | Api\V1\ClientDocumentController@download | C02/F07 | api, AuditApiMutation, Authenticate:sanctum, ImpersonateApiUser, ResolveTenant, EnsureDemoActive, ThrottleRequests:api, EnsureModuleEnabled:auto |
| GET / HEAD | api/v1/clients/{client}/financial-overview | api.v1.clients.financial-overview | Api\V1\FinancialRelationshipController@clientOverview | P04 | api, AuditApiMutation, Authenticate:sanctum, ImpersonateApiUser, ResolveTenant, EnsureDemoActive, ThrottleRequests:api, EnsureModuleEnabled:auto |
| GET / HEAD | api/v1/clients/{client}/loans | api.v1.clients.loans | Api\V1\ClientController@loans | C01/C03 | api, AuditApiMutation, Authenticate:sanctum, ImpersonateApiUser, ResolveTenant, EnsureDemoActive, ThrottleRequests:api, EnsureModuleEnabled:auto |
| POST | api/v1/clients/{client}/relationships | api.v1.clients.relationships.store | Api\V1\ClientController@storeRelationship | C01/C03 | api, AuditApiMutation, Authenticate:sanctum, ImpersonateApiUser, ResolveTenant, EnsureDemoActive, ThrottleRequests:api, EnsureModuleEnabled:auto, ThrottleRequests:api-sensitive |
| GET / HEAD | api/v1/clients/{client}/statements | api.v1.clients.statements | Api\V1\ClientController@statements | C01/C03 | api, AuditApiMutation, Authenticate:sanctum, ImpersonateApiUser, ResolveTenant, EnsureDemoActive, ThrottleRequests:api, EnsureModuleEnabled:auto |
| GET / HEAD | api/v1/docs/openapi.json | api.v1.docs.openapi | Api\V1\ApiDocumentationController@openapi | F06 | api, AuditApiMutation, ThrottleRequests:api |
| GET / HEAD | api/v1/financial-controls/approvals | api.v1.financial-controls.approvals.index | Api\V1\FinancialControlController@approvals | T03/L04 | api, AuditApiMutation, Authenticate:sanctum, ImpersonateApiUser, ResolveTenant, EnsureDemoActive, ThrottleRequests:api, EnsureModuleEnabled:auto |
| POST | api/v1/financial-controls/approvals/{makerCheckerRequest}/approve | api.v1.financial-controls.approvals.approve | Api\V1\FinancialControlController@approve | T03/L04 | api, AuditApiMutation, Authenticate:sanctum, ImpersonateApiUser, ResolveTenant, EnsureDemoActive, ThrottleRequests:api, EnsureModuleEnabled:auto, ThrottleRequests:api-sensitive |
| POST | api/v1/financial-controls/approvals/{makerCheckerRequest}/reject | api.v1.financial-controls.approvals.reject | Api\V1\FinancialControlController@reject | T03/L04 | api, AuditApiMutation, Authenticate:sanctum, ImpersonateApiUser, ResolveTenant, EnsureDemoActive, ThrottleRequests:api, EnsureModuleEnabled:auto, ThrottleRequests:api-sensitive |
| POST | api/v1/financial-relationships/investment-accounts/{investmentAccount}/returns | api.v1.financial-relationships.investment-returns | Api\V1\FinancialRelationshipController@postInvestmentReturn | P04 | api, AuditApiMutation, Authenticate:sanctum, ImpersonateApiUser, ResolveTenant, EnsureDemoActive, ThrottleRequests:api, EnsureModuleEnabled:auto, ThrottleRequests:api-sensitive, EnsureIdempotentFinancialRequest |
| POST | api/v1/financial-relationships/loans/{loan}/repay-from-investment-returns | api.v1.financial-relationships.loan-repayments.investment-returns | Api\V1\FinancialRelationshipController@repayLoanFromInvestmentReturns | P04 | api, AuditApiMutation, Authenticate:sanctum, ImpersonateApiUser, ResolveTenant, EnsureDemoActive, ThrottleRequests:api, EnsureModuleEnabled:auto, ThrottleRequests:api-sensitive, EnsureIdempotentFinancialRequest |
| POST | api/v1/financial-relationships/loans/{loan}/repay-from-savings | api.v1.financial-relationships.loan-repayments.savings | Api\V1\FinancialRelationshipController@repayLoanFromSavings | P04 | api, AuditApiMutation, Authenticate:sanctum, ImpersonateApiUser, ResolveTenant, EnsureDemoActive, ThrottleRequests:api, EnsureModuleEnabled:auto, ThrottleRequests:api-sensitive, EnsureIdempotentFinancialRequest |
| POST | api/v1/financial-relationships/loans/{loan}/security/investments | api.v1.financial-relationships.loan-security.investments | Api\V1\FinancialRelationshipController@lockInvestmentForLoan | P04 | api, AuditApiMutation, Authenticate:sanctum, ImpersonateApiUser, ResolveTenant, EnsureDemoActive, ThrottleRequests:api, EnsureModuleEnabled:auto, ThrottleRequests:api-sensitive, EnsureIdempotentFinancialRequest |
| POST | api/v1/financial-relationships/loans/{loan}/security/savings | api.v1.financial-relationships.loan-security.savings | Api\V1\FinancialRelationshipController@lockSavingsForLoan | P04 | api, AuditApiMutation, Authenticate:sanctum, ImpersonateApiUser, ResolveTenant, EnsureDemoActive, ThrottleRequests:api, EnsureModuleEnabled:auto, ThrottleRequests:api-sensitive, EnsureIdempotentFinancialRequest |
| POST | api/v1/financial-relationships/savings-to-investment | api.v1.financial-relationships.savings-to-investment | Api\V1\FinancialRelationshipController@transferSavingsToInvestment | P04 | api, AuditApiMutation, Authenticate:sanctum, ImpersonateApiUser, ResolveTenant, EnsureDemoActive, ThrottleRequests:api, EnsureModuleEnabled:auto, ThrottleRequests:api-sensitive, EnsureIdempotentFinancialRequest |
| GET / HEAD | api/v1/fixed-savings | api.v1.fixed-savings.index | Api\V1\FixedSavingsController@index | P02 | api, AuditApiMutation, Authenticate:sanctum, ImpersonateApiUser, ResolveTenant, EnsureDemoActive, ThrottleRequests:api, EnsureModuleEnabled:auto |
| POST | api/v1/fixed-savings | api.v1.fixed-savings.store | Api\V1\FixedSavingsController@store | P02 | api, AuditApiMutation, Authenticate:sanctum, ImpersonateApiUser, ResolveTenant, EnsureDemoActive, ThrottleRequests:api, EnsureModuleEnabled:auto |
| GET / HEAD | api/v1/group-savings | api.v1.group-savings.index | Api\V1\GroupSavingsController@index | P02 | api, AuditApiMutation, Authenticate:sanctum, ImpersonateApiUser, ResolveTenant, EnsureDemoActive, ThrottleRequests:api, EnsureModuleEnabled:auto |
| POST | api/v1/group-savings | api.v1.group-savings.store | Api\V1\GroupSavingsController@store | P02 | api, AuditApiMutation, Authenticate:sanctum, ImpersonateApiUser, ResolveTenant, EnsureDemoActive, ThrottleRequests:api, EnsureModuleEnabled:auto |
| POST | api/v1/group-savings/{groupSavingsAccount}/contributions | api.v1.group-savings.contributions.store | Api\V1\GroupSavingsController@contribute | P02 | api, AuditApiMutation, Authenticate:sanctum, ImpersonateApiUser, ResolveTenant, EnsureDemoActive, ThrottleRequests:api, EnsureModuleEnabled:auto, ThrottleRequests:api-sensitive, EnsureIdempotentFinancialRequest |
| GET / HEAD | api/v1/investment-accounts | api.v1.investment-accounts.index | Api\V1\InvestmentAccountController@index | P01 | api, AuditApiMutation, Authenticate:sanctum, ImpersonateApiUser, ResolveTenant, EnsureDemoActive, ThrottleRequests:api, EnsureModuleEnabled:auto |
| POST | api/v1/investment-accounts | api.v1.investment-accounts.store | Api\V1\InvestmentAccountController@store | P01 | api, AuditApiMutation, Authenticate:sanctum, ImpersonateApiUser, ResolveTenant, EnsureDemoActive, ThrottleRequests:api, EnsureModuleEnabled:auto, ThrottleRequests:api-sensitive, EnsureIdempotentFinancialRequest |
| POST | api/v1/investment-accounts/{investmentAccount}/approve | api.v1.investment-accounts.approve | Api\V1\InvestmentAccountController@approve | P01 | api, AuditApiMutation, Authenticate:sanctum, ImpersonateApiUser, ResolveTenant, EnsureDemoActive, ThrottleRequests:api, EnsureModuleEnabled:auto, ThrottleRequests:api-sensitive, EnsureIdempotentFinancialRequest |
| POST | api/v1/investment-accounts/{investmentAccount}/reinvestments | api.v1.investment-accounts.reinvestments.store | Api\V1\InvestmentTransactionController@reinvest | P01 | api, AuditApiMutation, Authenticate:sanctum, ImpersonateApiUser, ResolveTenant, EnsureDemoActive, ThrottleRequests:api, EnsureModuleEnabled:auto, ThrottleRequests:api-sensitive, EnsureIdempotentFinancialRequest |
| POST | api/v1/investment-accounts/{investmentAccount}/returns | api.v1.investment-accounts.returns.store | Api\V1\InvestmentTransactionController@postReturn | P01 | api, AuditApiMutation, Authenticate:sanctum, ImpersonateApiUser, ResolveTenant, EnsureDemoActive, ThrottleRequests:api, EnsureModuleEnabled:auto, ThrottleRequests:api-sensitive, EnsureIdempotentFinancialRequest |
| GET / HEAD | api/v1/investment-accounts/{investmentAccount}/statement | api.v1.investment-accounts.statement | Api\V1\InvestmentAccountController@statement | P01 | api, AuditApiMutation, Authenticate:sanctum, ImpersonateApiUser, ResolveTenant, EnsureDemoActive, ThrottleRequests:api, EnsureModuleEnabled:auto |
| POST | api/v1/investment-accounts/{investmentAccount}/top-ups | api.v1.investment-accounts.top-ups.store | Api\V1\InvestmentTransactionController@topUp | P01 | api, AuditApiMutation, Authenticate:sanctum, ImpersonateApiUser, ResolveTenant, EnsureDemoActive, ThrottleRequests:api, EnsureModuleEnabled:auto, ThrottleRequests:api-sensitive, EnsureIdempotentFinancialRequest |
| POST | api/v1/investment-accounts/{investmentAccount}/withdrawals | api.v1.investment-accounts.withdrawals.store | Api\V1\InvestmentTransactionController@withdraw | P01 | api, AuditApiMutation, Authenticate:sanctum, ImpersonateApiUser, ResolveTenant, EnsureDemoActive, ThrottleRequests:api, EnsureModuleEnabled:auto, ThrottleRequests:api-sensitive, EnsureIdempotentFinancialRequest |
| GET / HEAD | api/v1/investment-accounts/{investment_account} | api.v1.investment-accounts.show | Api\V1\InvestmentAccountController@show | P01 | api, AuditApiMutation, Authenticate:sanctum, ImpersonateApiUser, ResolveTenant, EnsureDemoActive, ThrottleRequests:api, EnsureModuleEnabled:auto |
| POST | api/v1/investment-deposits | api.v1.investment-deposits.store | Api\V1\InvestmentAccountController@store | P01 | api, AuditApiMutation, Authenticate:sanctum, ImpersonateApiUser, ResolveTenant, EnsureDemoActive, ThrottleRequests:api, EnsureModuleEnabled:auto, ThrottleRequests:api-sensitive, EnsureIdempotentFinancialRequest |
| GET / HEAD | api/v1/investment-plans | api.v1.investment-plans.index | Api\V1\InvestmentPlanController@index | P01 | api, AuditApiMutation, Authenticate:sanctum, ImpersonateApiUser, ResolveTenant, EnsureDemoActive, ThrottleRequests:api, EnsureModuleEnabled:auto |
| POST | api/v1/investment-plans | api.v1.investment-plans.store | Api\V1\InvestmentPlanController@store | P01 | api, AuditApiMutation, Authenticate:sanctum, ImpersonateApiUser, ResolveTenant, EnsureDemoActive, ThrottleRequests:api, EnsureModuleEnabled:auto |
| GET / HEAD | api/v1/investment-plans/{investment_plan} | api.v1.investment-plans.show | Api\V1\InvestmentPlanController@show | P01 | api, AuditApiMutation, Authenticate:sanctum, ImpersonateApiUser, ResolveTenant, EnsureDemoActive, ThrottleRequests:api, EnsureModuleEnabled:auto |
| GET / HEAD | api/v1/investor-accounts | api.v1.investor-accounts.index | Api\V1\InvestorAccountController@index | P01 | api, AuditApiMutation, Authenticate:sanctum, ImpersonateApiUser, ResolveTenant, EnsureDemoActive, ThrottleRequests:api, EnsureModuleEnabled:auto |
| POST | api/v1/investor-accounts | api.v1.investor-accounts.store | Api\V1\InvestorAccountController@store | P01 | api, AuditApiMutation, Authenticate:sanctum, ImpersonateApiUser, ResolveTenant, EnsureDemoActive, ThrottleRequests:api, EnsureModuleEnabled:auto |
| GET / HEAD | api/v1/investor-accounts/{investor_account} | api.v1.investor-accounts.show | Api\V1\InvestorAccountController@show | P01 | api, AuditApiMutation, Authenticate:sanctum, ImpersonateApiUser, ResolveTenant, EnsureDemoActive, ThrottleRequests:api, EnsureModuleEnabled:auto |
| GET / HEAD | api/v1/loan-products | api.v1.loan-products.index | Api\V1\LoanProductController@index | S03/L02 | api, AuditApiMutation, Authenticate:sanctum, ImpersonateApiUser, ResolveTenant, EnsureDemoActive, ThrottleRequests:api, EnsureModuleEnabled:auto |
| PUT | api/v1/loan-products/{loanProduct} | api.v1.loan-products.update | Api\V1\LoanProductController@update | S03/L02 | api, AuditApiMutation, Authenticate:sanctum, ImpersonateApiUser, ResolveTenant, EnsureDemoActive, ThrottleRequests:api, EnsureModuleEnabled:auto, ThrottleRequests:api-sensitive |
| GET / HEAD | api/v1/loans | api.v1.loans.index | Api\V1\LoanController@index | L01/L02/L06 | api, AuditApiMutation, Authenticate:sanctum, ImpersonateApiUser, ResolveTenant, EnsureDemoActive, ThrottleRequests:api, EnsureModuleEnabled:auto, EnsureDemoCapacity:loans |
| POST | api/v1/loans | api.v1.loans.store | Api\V1\LoanController@store | L01/L02/L06 | api, AuditApiMutation, Authenticate:sanctum, ImpersonateApiUser, ResolveTenant, EnsureDemoActive, ThrottleRequests:api, EnsureModuleEnabled:auto, EnsureDemoCapacity:loans |
| GET / HEAD | api/v1/loans/arrears/list | api.v1.loans.arrears | Api\V1\LoanWorkflowController@arrears | L03/L04/L05/L07 | api, AuditApiMutation, Authenticate:sanctum, ImpersonateApiUser, ResolveTenant, EnsureDemoActive, ThrottleRequests:api, EnsureModuleEnabled:auto |
| POST | api/v1/loans/schedule-preview | api.v1.loans.schedule-preview | Api\V1\LoanWorkflowController@schedulePreview | L03/L04/L05/L07 | api, AuditApiMutation, Authenticate:sanctum, ImpersonateApiUser, ResolveTenant, EnsureDemoActive, ThrottleRequests:api, EnsureModuleEnabled:auto |
| GET / HEAD | api/v1/loans/{loan} | api.v1.loans.show | Api\V1\LoanController@show | L01/L02/L06 | api, AuditApiMutation, Authenticate:sanctum, ImpersonateApiUser, ResolveTenant, EnsureDemoActive, ThrottleRequests:api, EnsureModuleEnabled:auto, EnsureDemoCapacity:loans |
| POST | api/v1/loans/{loan}/approve | api.v1.loans.approve | Api\V1\LoanWorkflowController@approve | L03/L04/L05/L07 | api, AuditApiMutation, Authenticate:sanctum, ImpersonateApiUser, ResolveTenant, EnsureDemoActive, ThrottleRequests:api, EnsureModuleEnabled:auto, ThrottleRequests:api-sensitive, EnsureIdempotentFinancialRequest |
| POST | api/v1/loans/{loan}/assign-officer | api.v1.loans.assign-officer | Api\V1\LoanWorkflowController@assignOfficer | L03/L04/L05/L07 | api, AuditApiMutation, Authenticate:sanctum, ImpersonateApiUser, ResolveTenant, EnsureDemoActive, ThrottleRequests:api, EnsureModuleEnabled:auto |
| POST | api/v1/loans/{loan}/cancel | api.v1.loans.cancel | Api\V1\LoanWorkflowController@cancel | L03/L04/L05/L07 | api, AuditApiMutation, Authenticate:sanctum, ImpersonateApiUser, ResolveTenant, EnsureDemoActive, ThrottleRequests:api, EnsureModuleEnabled:auto |
| POST | api/v1/loans/{loan}/close | api.v1.loans.close | Api\V1\LoanWorkflowController@close | L03/L04/L05/L07 | api, AuditApiMutation, Authenticate:sanctum, ImpersonateApiUser, ResolveTenant, EnsureDemoActive, ThrottleRequests:api, EnsureModuleEnabled:auto, EnsureIdempotentFinancialRequest |
| POST | api/v1/loans/{loan}/collaterals | api.v1.loans.collaterals.store | Api\V1\LoanWorkflowController@addCollateral | L03/L04/L05/L07 | api, AuditApiMutation, Authenticate:sanctum, ImpersonateApiUser, ResolveTenant, EnsureDemoActive, ThrottleRequests:api, EnsureModuleEnabled:auto |
| POST | api/v1/loans/{loan}/disburse | api.v1.loans.disburse | Api\V1\LoanWorkflowController@disburse | L03/L04/L05/L07 | api, AuditApiMutation, Authenticate:sanctum, ImpersonateApiUser, ResolveTenant, EnsureDemoActive, ThrottleRequests:api, EnsureModuleEnabled:auto, ThrottleRequests:api-sensitive, EnsureIdempotentFinancialRequest |
| POST | api/v1/loans/{loan}/generate-loan-account | api.v1.loans.generate-loan-account | Api\V1\LoanWorkflowController@generateLoanAccount | L03/L04/L05/L07 | api, AuditApiMutation, Authenticate:sanctum, ImpersonateApiUser, ResolveTenant, EnsureDemoActive, ThrottleRequests:api, EnsureModuleEnabled:auto |
| POST | api/v1/loans/{loan}/generate-savings-account | api.v1.loans.generate-savings-account | Api\V1\LoanWorkflowController@generateSavingsAccount | L03/L04/L05/L07 | api, AuditApiMutation, Authenticate:sanctum, ImpersonateApiUser, ResolveTenant, EnsureDemoActive, ThrottleRequests:api, EnsureModuleEnabled:auto |
| POST | api/v1/loans/{loan}/guarantors | api.v1.loans.guarantors.store | Api\V1\LoanWorkflowController@addGuarantor | L03/L04/L05/L07 | api, AuditApiMutation, Authenticate:sanctum, ImpersonateApiUser, ResolveTenant, EnsureDemoActive, ThrottleRequests:api, EnsureModuleEnabled:auto |
| POST | api/v1/loans/{loan}/penalties/waive | api.v1.loans.penalties.waive | Api\V1\LoanWorkflowController@waivePenalty | L03/L04/L05/L07 | api, AuditApiMutation, Authenticate:sanctum, ImpersonateApiUser, ResolveTenant, EnsureDemoActive, ThrottleRequests:api, EnsureModuleEnabled:auto, ThrottleRequests:api-sensitive, EnsureIdempotentFinancialRequest |
| POST | api/v1/loans/{loan}/reject | api.v1.loans.reject | Api\V1\LoanWorkflowController@reject | L03/L04/L05/L07 | api, AuditApiMutation, Authenticate:sanctum, ImpersonateApiUser, ResolveTenant, EnsureDemoActive, ThrottleRequests:api, EnsureModuleEnabled:auto |
| POST | api/v1/loans/{loan}/repayments | api.v1.loans.repayments.store | Api\V1\LoanRepaymentController@store | L05 | api, AuditApiMutation, Authenticate:sanctum, ImpersonateApiUser, ResolveTenant, EnsureDemoActive, ThrottleRequests:api, EnsureModuleEnabled:auto, ThrottleRequests:api-sensitive, EnsureIdempotentFinancialRequest |
| GET / HEAD | api/v1/loans/{loan}/schedule | api.v1.loans.schedule | Api\V1\LoanController@schedule | L01/L02/L06 | api, AuditApiMutation, Authenticate:sanctum, ImpersonateApiUser, ResolveTenant, EnsureDemoActive, ThrottleRequests:api, EnsureModuleEnabled:auto |
| POST | api/v1/loans/{loan}/submit | api.v1.loans.submit | Api\V1\LoanWorkflowController@submit | L03/L04/L05/L07 | api, AuditApiMutation, Authenticate:sanctum, ImpersonateApiUser, ResolveTenant, EnsureDemoActive, ThrottleRequests:api, EnsureModuleEnabled:auto |
| POST | api/v1/loans/{loan}/submit-appraisal | api.v1.loans.submit-appraisal | Api\V1\LoanWorkflowController@submitForAppraisal | L03/L04/L05/L07 | api, AuditApiMutation, Authenticate:sanctum, ImpersonateApiUser, ResolveTenant, EnsureDemoActive, ThrottleRequests:api, EnsureModuleEnabled:auto |
| POST | api/v1/loans/{loan}/write-off | api.v1.loans.write-off | Api\V1\LoanWorkflowController@writeOff | L03/L04/L05/L07 | api, AuditApiMutation, Authenticate:sanctum, ImpersonateApiUser, ResolveTenant, EnsureDemoActive, ThrottleRequests:api, EnsureModuleEnabled:auto, ThrottleRequests:api-sensitive, EnsureIdempotentFinancialRequest |
| GET / HEAD | api/v1/notifications | api.v1.notifications.index | Api\V1\NotificationController@notifications | N02 | api, AuditApiMutation, Authenticate:sanctum, ImpersonateApiUser, ResolveTenant, EnsureDemoActive, ThrottleRequests:api, EnsureModuleEnabled:auto |
| GET / HEAD | api/v1/notifications/devices | api.v1.notifications.devices.index | Api\V1\DeviceTokenController@index | N02 | api, AuditApiMutation, Authenticate:sanctum, ImpersonateApiUser, ResolveTenant, EnsureDemoActive, ThrottleRequests:api, EnsureModuleEnabled:auto |
| POST | api/v1/notifications/devices | api.v1.notifications.devices.store | Api\V1\DeviceTokenController@store | N02 | api, AuditApiMutation, Authenticate:sanctum, ImpersonateApiUser, ResolveTenant, EnsureDemoActive, ThrottleRequests:api, EnsureModuleEnabled:auto |
| DELETE | api/v1/notifications/devices/{deviceToken} | api.v1.notifications.devices.destroy | Api\V1\DeviceTokenController@destroy | N02 | api, AuditApiMutation, Authenticate:sanctum, ImpersonateApiUser, ResolveTenant, EnsureDemoActive, ThrottleRequests:api, EnsureModuleEnabled:auto |
| POST | api/v1/notifications/sms | api.v1.notifications.sms.send | Api\V1\NotificationController@sendSms | N02 | api, AuditApiMutation, Authenticate:sanctum, ImpersonateApiUser, ResolveTenant, EnsureDemoActive, ThrottleRequests:api, EnsureModuleEnabled:auto |
| GET / HEAD | api/v1/notifications/sms-logs | api.v1.notifications.sms-logs | Api\V1\NotificationController@smsLogs | N02 | api, AuditApiMutation, Authenticate:sanctum, ImpersonateApiUser, ResolveTenant, EnsureDemoActive, ThrottleRequests:api, EnsureModuleEnabled:auto |
| POST | api/v1/notifications/{notification}/read | api.v1.notifications.read | Api\V1\NotificationController@markRead | N02 | api, AuditApiMutation, Authenticate:sanctum, ImpersonateApiUser, ResolveTenant, EnsureDemoActive, ThrottleRequests:api, EnsureModuleEnabled:auto |
| POST | api/v1/payments/mobile-money/callback | api.v1.payments.callback | Api\V1\PaymentController@callback | B02 | api, AuditApiMutation, ThrottleRequests:api, EnsureIdempotentFinancialRequest |
| POST | api/v1/payments/mobile-money/collections | api.v1.payments.collections.store | Api\V1\PaymentController@initiateCollection | B02 | api, AuditApiMutation, Authenticate:sanctum, ImpersonateApiUser, ResolveTenant, EnsureDemoActive, ThrottleRequests:api, EnsureModuleEnabled:auto, EnsureIdempotentFinancialRequest |
| POST | api/v1/payments/reconcile | api.v1.payments.reconcile | Api\V1\PaymentController@reconcile | B02 | api, AuditApiMutation, Authenticate:sanctum, ImpersonateApiUser, ResolveTenant, EnsureDemoActive, ThrottleRequests:api, EnsureModuleEnabled:auto |
| POST | api/v1/payments/{paymentRequest}/match | api.v1.payments.match | Api\V1\PaymentController@matchPayment | B02 | api, AuditApiMutation, Authenticate:sanctum, ImpersonateApiUser, ResolveTenant, EnsureDemoActive, ThrottleRequests:api, EnsureModuleEnabled:auto |
| GET / HEAD | api/v1/payments/{paymentRequest}/verify | api.v1.payments.verify | Api\V1\PaymentController@verify | B02 | api, AuditApiMutation, Authenticate:sanctum, ImpersonateApiUser, ResolveTenant, EnsureDemoActive, ThrottleRequests:api, EnsureModuleEnabled:auto |
| GET / HEAD | api/v1/reports/arrears | api.v1.reports.arrears | Api\V1\ReportController@arrears | R01/R02/R03/R04 | api, AuditApiMutation, Authenticate:sanctum, ImpersonateApiUser, ResolveTenant, EnsureDemoActive, ThrottleRequests:api, EnsureModuleEnabled:auto |
| GET / HEAD | api/v1/reports/branch-performance | api.v1.reports.branch-performance | Api\V1\ReportController@branchPerformance | R01/R02/R03/R04 | api, AuditApiMutation, Authenticate:sanctum, ImpersonateApiUser, ResolveTenant, EnsureDemoActive, ThrottleRequests:api, EnsureModuleEnabled:auto |
| GET / HEAD | api/v1/reports/clients | api.v1.reports.clients | Api\V1\ReportController@clients | R01/R02/R03/R04 | api, AuditApiMutation, Authenticate:sanctum, ImpersonateApiUser, ResolveTenant, EnsureDemoActive, ThrottleRequests:api, EnsureModuleEnabled:auto |
| GET / HEAD | api/v1/reports/dashboard | api.v1.reports.dashboard | Api\V1\ReportController@dashboard | R01/R02/R03/R04 | api, AuditApiMutation, Authenticate:sanctum, ImpersonateApiUser, ResolveTenant, EnsureDemoActive, ThrottleRequests:api, EnsureModuleEnabled:auto |
| POST | api/v1/reports/exports | api.v1.reports.exports.store | Api\V1\ReportController@export | R01/R02/R03/R04 | api, AuditApiMutation, Authenticate:sanctum, ImpersonateApiUser, ResolveTenant, EnsureDemoActive, ThrottleRequests:api, EnsureModuleEnabled:auto, ThrottleRequests:api-sensitive |
| GET / HEAD | api/v1/reports/investments | api.v1.reports.investments | Api\V1\ReportController@investments | R01/R02/R03/R04 | api, AuditApiMutation, Authenticate:sanctum, ImpersonateApiUser, ResolveTenant, EnsureDemoActive, ThrottleRequests:api, EnsureModuleEnabled:auto |
| GET / HEAD | api/v1/reports/loans | api.v1.reports.loans | Api\V1\ReportController@loans | R01/R02/R03/R04 | api, AuditApiMutation, Authenticate:sanctum, ImpersonateApiUser, ResolveTenant, EnsureDemoActive, ThrottleRequests:api, EnsureModuleEnabled:auto |
| GET / HEAD | api/v1/reports/savings | api.v1.reports.savings | Api\V1\ReportController@savings | R01/R02/R03/R04 | api, AuditApiMutation, Authenticate:sanctum, ImpersonateApiUser, ResolveTenant, EnsureDemoActive, ThrottleRequests:api, EnsureModuleEnabled:auto |
| GET / HEAD | api/v1/reports/savings-analytics | api.v1.reports.savings-analytics | Api\V1\ReportController@savingsAnalytics | R01/R02/R03/R04 | api, AuditApiMutation, Authenticate:sanctum, ImpersonateApiUser, ResolveTenant, EnsureDemoActive, ThrottleRequests:api, EnsureModuleEnabled:auto |
| GET / HEAD | api/v1/reports/tellers | api.v1.reports.tellers | Api\V1\ReportController@tellers | R01/R02/R03/R04 | api, AuditApiMutation, Authenticate:sanctum, ImpersonateApiUser, ResolveTenant, EnsureDemoActive, ThrottleRequests:api, EnsureModuleEnabled:auto |
| GET / HEAD | api/v1/savings-accounts | api.v1.savings-accounts.index | Api\V1\SavingsAccountController@index | A01/A03 | api, AuditApiMutation, Authenticate:sanctum, ImpersonateApiUser, ResolveTenant, EnsureDemoActive, ThrottleRequests:api, EnsureModuleEnabled:auto |
| POST | api/v1/savings-accounts | api.v1.savings-accounts.store | Api\V1\SavingsAccountController@store | A01/A03 | api, AuditApiMutation, Authenticate:sanctum, ImpersonateApiUser, ResolveTenant, EnsureDemoActive, ThrottleRequests:api, EnsureModuleEnabled:auto |
| POST | api/v1/savings-accounts/{savingsAccountId}/deposit | api.v1.savings-accounts.deposit | Api\V1\SavingsTransactionController@deposit | A02/A03 | api, AuditApiMutation, Authenticate:sanctum, ImpersonateApiUser, ResolveTenant, EnsureDemoActive, ThrottleRequests:api, EnsureModuleEnabled:auto, ThrottleRequests:api-sensitive, EnsureIdempotentFinancialRequest |
| POST | api/v1/savings-accounts/{savingsAccountId}/withdraw | api.v1.savings-accounts.withdraw | Api\V1\SavingsTransactionController@withdraw | A02/A03 | api, AuditApiMutation, Authenticate:sanctum, ImpersonateApiUser, ResolveTenant, EnsureDemoActive, ThrottleRequests:api, EnsureModuleEnabled:auto, ThrottleRequests:api-sensitive, EnsureIdempotentFinancialRequest |
| GET / HEAD | api/v1/savings-accounts/{savingsAccount}/statement | api.v1.savings-accounts.statement | Api\V1\SavingsAccountController@statement | A01/A03 | api, AuditApiMutation, Authenticate:sanctum, ImpersonateApiUser, ResolveTenant, EnsureDemoActive, ThrottleRequests:api, EnsureModuleEnabled:auto |
| GET / HEAD | api/v1/savings-accounts/{savingsAccount}/statement/download | api.v1.savings-accounts.statement.download | Api\V1\SavingsAccountController@statement | A01/A03 | api, AuditApiMutation, Authenticate:sanctum, ImpersonateApiUser, ResolveTenant, EnsureDemoActive, ThrottleRequests:api, EnsureModuleEnabled:auto |
| GET / HEAD | api/v1/savings-accounts/{savings_account} | api.v1.savings-accounts.show | Api\V1\SavingsAccountController@show | A01/A03 | api, AuditApiMutation, Authenticate:sanctum, ImpersonateApiUser, ResolveTenant, EnsureDemoActive, ThrottleRequests:api, EnsureModuleEnabled:auto |
| GET / HEAD | api/v1/savings-goals | api.v1.savings-goals.index | Api\V1\SavingsGoalController@index | P02 | api, AuditApiMutation, Authenticate:sanctum, ImpersonateApiUser, ResolveTenant, EnsureDemoActive, ThrottleRequests:api, EnsureModuleEnabled:auto |
| POST | api/v1/savings-goals | api.v1.savings-goals.store | Api\V1\SavingsGoalController@store | P02 | api, AuditApiMutation, Authenticate:sanctum, ImpersonateApiUser, ResolveTenant, EnsureDemoActive, ThrottleRequests:api, EnsureModuleEnabled:auto |
| POST | api/v1/savings-goals/{savingsGoal}/transactions | api.v1.savings-goals.transactions.store | Api\V1\SavingsGoalController@transact | P02 | api, AuditApiMutation, Authenticate:sanctum, ImpersonateApiUser, ResolveTenant, EnsureDemoActive, ThrottleRequests:api, EnsureModuleEnabled:auto, ThrottleRequests:api-sensitive, EnsureIdempotentFinancialRequest |
| GET / HEAD | api/v1/savings-goals/{savings_goal} | api.v1.savings-goals.show | Api\V1\SavingsGoalController@show | P02 | api, AuditApiMutation, Authenticate:sanctum, ImpersonateApiUser, ResolveTenant, EnsureDemoActive, ThrottleRequests:api, EnsureModuleEnabled:auto |
| GET / HEAD | api/v1/savings-products | api.v1.savings-products.index | Api\V1\SavingsProductController@index | S03 | api, AuditApiMutation, Authenticate:sanctum, ImpersonateApiUser, ResolveTenant, EnsureDemoActive, ThrottleRequests:api, EnsureModuleEnabled:auto |
| PUT | api/v1/savings-products/{savingsProduct} | api.v1.savings-products.update | Api\V1\SavingsProductController@update | S03 | api, AuditApiMutation, Authenticate:sanctum, ImpersonateApiUser, ResolveTenant, EnsureDemoActive, ThrottleRequests:api, EnsureModuleEnabled:auto, ThrottleRequests:api-sensitive |
| POST | api/v1/savings-transactions/{savingsTransaction}/reverse | api.v1.savings-transactions.reverse | Api\V1\SavingsTransactionController@reverse | A02/A03 | api, AuditApiMutation, Authenticate:sanctum, ImpersonateApiUser, ResolveTenant, EnsureDemoActive, ThrottleRequests:api, EnsureModuleEnabled:auto, ThrottleRequests:api-sensitive, EnsureIdempotentFinancialRequest |
| POST | api/v1/savings-transfers | api.v1.savings-transfers.store | Api\V1\SavingsTransactionController@transfer | A02/A03 | api, AuditApiMutation, Authenticate:sanctum, ImpersonateApiUser, ResolveTenant, EnsureDemoActive, ThrottleRequests:api, EnsureModuleEnabled:auto, ThrottleRequests:api-sensitive, EnsureIdempotentFinancialRequest |
| GET / HEAD | api/v1/savings/accounts | api.v1.savings.accounts.index | Api\V1\SavingsAccountController@index | A01/A03 | api, AuditApiMutation, Authenticate:sanctum, ImpersonateApiUser, ResolveTenant, EnsureDemoActive, ThrottleRequests:api, EnsureModuleEnabled:auto |
| POST | api/v1/savings/accounts | api.v1.savings.accounts.store | Api\V1\SavingsAccountController@store | A01/A03 | api, AuditApiMutation, Authenticate:sanctum, ImpersonateApiUser, ResolveTenant, EnsureDemoActive, ThrottleRequests:api, EnsureModuleEnabled:auto |
| POST | api/v1/savings/accounts/{savingsAccountId}/deposit | api.v1.savings.accounts.deposit | Api\V1\SavingsTransactionController@deposit | A02/A03 | api, AuditApiMutation, Authenticate:sanctum, ImpersonateApiUser, ResolveTenant, EnsureDemoActive, ThrottleRequests:api, EnsureModuleEnabled:auto, ThrottleRequests:api-sensitive, EnsureIdempotentFinancialRequest |
| POST | api/v1/savings/accounts/{savingsAccountId}/withdraw | api.v1.savings.accounts.withdraw | Api\V1\SavingsTransactionController@withdraw | A02/A03 | api, AuditApiMutation, Authenticate:sanctum, ImpersonateApiUser, ResolveTenant, EnsureDemoActive, ThrottleRequests:api, EnsureModuleEnabled:auto, ThrottleRequests:api-sensitive, EnsureIdempotentFinancialRequest |
| GET / HEAD | api/v1/savings/accounts/{savingsAccount} | api.v1.savings.accounts.show | Api\V1\SavingsAccountController@show | A01/A03 | api, AuditApiMutation, Authenticate:sanctum, ImpersonateApiUser, ResolveTenant, EnsureDemoActive, ThrottleRequests:api, EnsureModuleEnabled:auto |
| GET / HEAD | api/v1/savings/accounts/{savingsAccount}/statement | api.v1.savings.accounts.statement | Api\V1\SavingsAccountController@statement | A01/A03 | api, AuditApiMutation, Authenticate:sanctum, ImpersonateApiUser, ResolveTenant, EnsureDemoActive, ThrottleRequests:api, EnsureModuleEnabled:auto |
| GET / HEAD | api/v1/savings/accounts/{savingsAccount}/statement/download | api.v1.savings.accounts.statement.download | Api\V1\SavingsAccountController@statement | A01/A03 | api, AuditApiMutation, Authenticate:sanctum, ImpersonateApiUser, ResolveTenant, EnsureDemoActive, ThrottleRequests:api, EnsureModuleEnabled:auto |
| POST | api/v1/savings/accounts/{savingsAccount}/statement/generate | api.v1.savings.accounts.statement.generate | Api\V1\SavingsAccountController@generateStatement | A01/A03 | api, AuditApiMutation, Authenticate:sanctum, ImpersonateApiUser, ResolveTenant, EnsureDemoActive, ThrottleRequests:api, EnsureModuleEnabled:auto, ThrottleRequests:api-sensitive |
| GET / HEAD | api/v1/savings/products | api.v1.savings.products.index | Api\V1\SavingsProductController@index | S03 | api, AuditApiMutation, Authenticate:sanctum, ImpersonateApiUser, ResolveTenant, EnsureDemoActive, ThrottleRequests:api, EnsureModuleEnabled:auto |
| PUT | api/v1/savings/products/{savingsProduct} | api.v1.savings.products.update | Api\V1\SavingsProductController@update | S03 | api, AuditApiMutation, Authenticate:sanctum, ImpersonateApiUser, ResolveTenant, EnsureDemoActive, ThrottleRequests:api, EnsureModuleEnabled:auto, ThrottleRequests:api-sensitive |
| POST | api/v1/savings/transactions/{savingsTransaction}/reverse | api.v1.savings.transactions.reverse | Api\V1\SavingsTransactionController@reverse | A02/A03 | api, AuditApiMutation, Authenticate:sanctum, ImpersonateApiUser, ResolveTenant, EnsureDemoActive, ThrottleRequests:api, EnsureModuleEnabled:auto, ThrottleRequests:api-sensitive, EnsureIdempotentFinancialRequest |
| POST | api/v1/savings/transfers | api.v1.savings.transfers.store | Api\V1\SavingsTransactionController@transfer | A02/A03 | api, AuditApiMutation, Authenticate:sanctum, ImpersonateApiUser, ResolveTenant, EnsureDemoActive, ThrottleRequests:api, EnsureModuleEnabled:auto, ThrottleRequests:api-sensitive, EnsureIdempotentFinancialRequest |
| GET / HEAD | api/v1/settings | api.v1.settings.index | Api\V1\SettingController@index | S03 | api, AuditApiMutation, Authenticate:sanctum, ImpersonateApiUser, ResolveTenant, EnsureDemoActive, ThrottleRequests:api, EnsureModuleEnabled:auto |
| PUT | api/v1/settings | api.v1.settings.update | Api\V1\SettingController@update | S03 | api, AuditApiMutation, Authenticate:sanctum, ImpersonateApiUser, ResolveTenant, EnsureDemoActive, ThrottleRequests:api, EnsureModuleEnabled:auto, ThrottleRequests:api-sensitive |
| GET / HEAD | api/v1/sync/clients | api.v1.sync.clients.pull | Api\V1\OfflineSyncController@pullClients | X01 | api, AuditApiMutation, Authenticate:sanctum, ImpersonateApiUser, ResolveTenant, EnsureDemoActive, ThrottleRequests:api, EnsureModuleEnabled:auto |
| POST | api/v1/sync/clients | api.v1.sync.clients.push | Api\V1\OfflineSyncController@pushClients | X01 | api, AuditApiMutation, Authenticate:sanctum, ImpersonateApiUser, ResolveTenant, EnsureDemoActive, ThrottleRequests:api, EnsureModuleEnabled:auto, ThrottleRequests:api-sensitive |
| GET / HEAD | api/v1/sync/manifest | api.v1.sync.manifest | Api\V1\OfflineSyncController@manifest | X01 | api, AuditApiMutation, Authenticate:sanctum, ImpersonateApiUser, ResolveTenant, EnsureDemoActive, ThrottleRequests:api, EnsureModuleEnabled:auto |
| POST | api/v1/webhooks/events/{webhookEvent}/retry | api.v1.webhooks.events.retry | Api\V1\WebhookController@retry | B02/O01 | api, AuditApiMutation, Authenticate:sanctum, ImpersonateApiUser, ResolveTenant, EnsureDemoActive, ThrottleRequests:api, EnsureModuleEnabled:auto, ThrottleRequests:api-sensitive |
| POST | api/v1/webhooks/nylon-pay | api.v1.webhooks.nylon-pay | Api\V1\NylonPayWebhookController | B02 | api, AuditApiMutation, ThrottleRequests:api |
| POST | api/v1/webhooks/nylon-pay/organizations/{webhookToken} | api.v1.webhooks.organization-nylon-pay | Api\V1\OrganizationNylonPayWebhookController | B02 | api, AuditApiMutation, ThrottleRequests:api |
| POST | api/v1/webhooks/{source}/{provider} | api.v1.webhooks.store | Api\V1\WebhookController@store | B02/O01 | api, AuditApiMutation, ThrottleRequests:api |

## Operations absent from saved OpenAPI

Compared against `docs/api/openapi.json`, excluding HEAD. The dynamic docs endpoint is a different artifact; its own contract test currently fails as recorded in the parity matrix.

| Method | Registered URI |
| --- | --- |
| GET | api/v1/admin/dashboard |
| GET | api/v1/admin/impersonation/users |
| GET | api/v1/admin/records/{section} |
| GET | api/v1/admin/records/{section}/{id} |
| GET | api/v1/cashier/dashboard |
| GET | api/v1/cashier/lookup |
| POST | api/v1/cashier/open |
| POST | api/v1/cashier/preview |
| GET | api/v1/cashier/sessions |
| GET | api/v1/cashier/sessions/{id} |
| GET | api/v1/cashier/sessions/{id}/movements |
| GET | api/v1/cashier/sessions/{id}/transactions |
| GET | api/v1/cashier/sessions/{id}/transactions/{transaction} |
| POST | api/v1/cashier/transactions |
| GET | api/v1/clients/{client}/combined-statement |
| GET | api/v1/clients/{client}/financial-overview |
| POST | api/v1/clients/{client}/relationships |
| GET | api/v1/docs/openapi.json |
| GET | api/v1/financial-controls/approvals |
| POST | api/v1/financial-controls/approvals/{makerCheckerRequest}/approve |
| POST | api/v1/financial-controls/approvals/{makerCheckerRequest}/reject |
| POST | api/v1/financial-relationships/investment-accounts/{investmentAccount}/returns |
| POST | api/v1/financial-relationships/loans/{loan}/repay-from-investment-returns |
| POST | api/v1/financial-relationships/loans/{loan}/repay-from-savings |
| POST | api/v1/financial-relationships/loans/{loan}/security/investments |
| POST | api/v1/financial-relationships/loans/{loan}/security/savings |
| POST | api/v1/financial-relationships/savings-to-investment |
| GET | api/v1/fixed-savings |
| POST | api/v1/fixed-savings |
| GET | api/v1/group-savings |
| POST | api/v1/group-savings |
| POST | api/v1/group-savings/{groupSavingsAccount}/contributions |
| GET | api/v1/investment-accounts |
| POST | api/v1/investment-accounts |
| POST | api/v1/investment-accounts/{investmentAccount}/approve |
| POST | api/v1/investment-accounts/{investmentAccount}/reinvestments |
| POST | api/v1/investment-accounts/{investmentAccount}/returns |
| GET | api/v1/investment-accounts/{investmentAccount}/statement |
| POST | api/v1/investment-accounts/{investmentAccount}/top-ups |
| POST | api/v1/investment-accounts/{investmentAccount}/withdrawals |
| GET | api/v1/investment-accounts/{investment_account} |
| POST | api/v1/investment-deposits |
| GET | api/v1/investment-plans |
| POST | api/v1/investment-plans |
| GET | api/v1/investment-plans/{investment_plan} |
| GET | api/v1/investor-accounts |
| POST | api/v1/investor-accounts |
| GET | api/v1/investor-accounts/{investor_account} |
| PUT | api/v1/loan-products/{loanProduct} |
| POST | api/v1/loans/{loan}/assign-officer |
| POST | api/v1/loans/{loan}/cancel |
| POST | api/v1/loans/{loan}/generate-loan-account |
| POST | api/v1/loans/{loan}/generate-savings-account |
| POST | api/v1/loans/{loan}/penalties/waive |
| POST | api/v1/loans/{loan}/submit-appraisal |
| GET | api/v1/reports/investments |
| GET | api/v1/reports/savings-analytics |
| GET | api/v1/savings-goals |
| POST | api/v1/savings-goals |
| POST | api/v1/savings-goals/{savingsGoal}/transactions |
| GET | api/v1/savings-goals/{savings_goal} |
| PUT | api/v1/savings-products/{savingsProduct} |
| POST | api/v1/savings/accounts/{savingsAccount}/statement/generate |
| PUT | api/v1/savings/products/{savingsProduct} |
| GET | api/v1/sync/clients |
| POST | api/v1/sync/clients |
| GET | api/v1/sync/manifest |
| POST | api/v1/webhooks/nylon-pay |
| POST | api/v1/webhooks/nylon-pay/organizations/{webhookToken} |

Missing operations: 69.

## Named web report definitions

Source: literal `report()` / `placeholder()` declarations in `app/Services/ReportCatalogService.php`. Availability is further filtered by modules, roles, and metadata. Every report needs its own filter/row/summary/export acceptance fixture under R01; User Posted Transactions and Arrears Rate have additional R02/R03 gates. Generated or conditional definitions must be checked when exposing the catalog API.

| Slug | Category | Title | Work package |
| --- | --- | --- | --- |
| general-income-statement | General Reports | Simplified Income Statement | R01/R04 |
| projected-general-income-statement | General Reports | Simplified Income Statement - Projected | R01/R04 |
| arrears-rate | General Reports | Arrears Rate | R03/R04 |
| all-posted-transactions | Posted Transactions | All Posted Transactions | R01/R04 |
| user-posted-transactions | Posted Transactions | User Posted Transactions | R02/R04 |
| system-posted-transactions | Posted Transactions | System Posted Transactions | R01/R04 |
| daily-teller-transactions | Posted Transactions | Daily Teller Transactions | R01/R04 |
| non-cash-transactions | Posted Transactions | Non-Cash Transactions | R01/R04 |
| exchange-rates | Treasury | Exchange Rates | R01/R04 |
| cash-balances | Treasury | Cash Balances | R01/R04 |
| drawer-balances-by-teller | Treasury | Drawer Balances By Teller | R01/R04 |
| branch-period-summary | Treasury | Branch Period Summary | R01/R04 |
| client-register | Clients | Client Register | R01/R04 |
| new-clients | Clients | New Clients | R01/R04 |
| officer-entries | Clients | Officer Entries | R01/R04 |
| active-clients | Clients | Active Clients | R01/R04 |
| chart-of-accounts | Accounting | Chart Of Accounts | R01/R04 |
| gl-account-transactions | Accounting | GL Account Transactions | R01/R04 |
| gl-daily-account-summary | Accounting | GL Daily Account Summary | R01/R04 |
| gl-transaction-analysis | Accounting | GL Transaction Analysis | R01/R04 |
| income-accounts | Accounting | Income Accounts | R01/R04 |
| expense-accounts | Accounting | Expense Accounts | R01/R04 |
| asset-accounts | Accounting | Asset Accounts | R01/R04 |
| liability-accounts | Accounting | Liability Accounts | R01/R04 |
| equity-accounts | Accounting | Equity Accounts | R01/R04 |
| income-statement | Accounting | Income Statement | R01/R04 |
| trial-balance | Accounting | Trial Balance | R01/R04 |
| balance-sheet | Accounting | Balance Sheet | R01/R04 |
| application-fees-income | Profitability | Application Fees Income | R01/R04 |
| loan-interest-income | Profitability | Loan Interest Accumulated | R01/R04 |
| profitability-summary | Profitability | Profitability Summary | R01/R04 |
| projected-profitability-summary | Profitability | Projected Profitability Summary | R01/R04 |
| branch-profitability-standing | Profitability | Branch Profitability Standing | R01/R04 |
| opened-savings-accounts | Savings | Opened Savings Accounts | R01/R04 |
| unfunded-savings-accounts | Savings | Unfunded Savings Accounts | R01/R04 |
| active-savings-accounts | Savings | Active Savings Accounts | R01/R04 |
| dormant-savings-accounts | Savings | Dormant Savings Accounts | R01/R04 |
| reactivated-savings-accounts | Savings | Reactivated Savings Accounts | R01/R04 |
| closed-savings-accounts | Savings | Closed Savings Accounts | R01/R04 |
| savings-accounts-by-balances | Savings | Savings Accounts By Balances | R01/R04 |
| accounts-below-minimum-balance | Savings | Accounts Below Minimum Balance | R01/R04 |
| savings-accounts-with-holds | Savings | Savings Accounts With Holds | R01/R04 |
| accounts-with-adjusted-minimum-balance | Savings | Accounts With Adjusted Minimum Balance | R01/R04 |
| accounts-with-accrued-interest | Savings | Accounts With Accrued Interest | R01/R04 |
| created-savings-standing-orders | Savings | Created Savings Standing Orders | R01/R04 |
| active-savings-standing-orders | Savings | Active Savings Standing Orders | R01/R04 |
| closed-savings-standing-orders | Savings | Closed Savings Standing Orders | R01/R04 |
| cancelled-savings-standing-orders | Savings | Cancelled Savings Standing Orders | R01/R04 |
| share-accounts | Shares | Share Accounts | R01/R04 |
| share-balances | Shares | Share Balances | R01/R04 |
| share-transactions | Shares | Share Transactions | R01/R04 |
| loans-application-by-date | Loan Application | Loans Application By Date | R01/R04 |
| appraisal-loan-applications | Loan Application | Appraisal Loan Applications | R01/R04 |
| approval-loan-applications | Loan Application | Approval Loan Applications | R01/R04 |
| approved-loan-applications | Loan Application | Approved Loan Applications | R01/R04 |
| cancelled-loan-applications | Loan Application | Cancelled Loan Applications | R01/R04 |
| rejected-loan-applications | Loan Application | Rejected Loan Applications | R01/R04 |
| created-loan-accounts | Loan Accounts | Created Loan Accounts | R01/R04 |
| loan-accounts-pending-disbursement | Loan Accounts | Loan Accounts Pending Disbursement | R01/R04 |
| loan-accounts-cancelled | Loan Accounts | Loan Accounts Cancelled | R01/R04 |
| loan-disbursements | Loan Accounts | Loan Disbursements | R01/R04 |
| loan-disbursement-reversals | Loan Accounts | Loan Disbursement Reversals | R01/R04 |
| new-loan-account-cases | Loan Accounts | New Loan Account Cases | R01/R04 |
| loan-accounts-in-good-standing | Loan Accounts | Loan Accounts In Good Standing | R01/R04 |
| loan-accounts-in-arrears | Loan Accounts | Loan Accounts In Arrears | R01/R04 |
| loan-aging-report | Loan Accounts | Loan Aging Report | R01/R04 |
| portfolio-summary | Loan Accounts | Portfolio Summary | R01/R04 |
| portfolio-at-risk | Loan Accounts | Portfolio At Risk | R01/R04 |
| loan-book | Loan Accounts | Loan Book | R01/R04 |
| loan-recovery | Loan Accounts | Loan Recovery | R01/R04 |
| loan-repayments-by-relations-officer | Loan Accounts | Loan Repayments By Relations Officer | R01/R04 |
| loan-accounts-paid-up | Loan Accounts | Loan Accounts Paid Up | R01/R04 |
| closed-rescheduled-loans | Loan Accounts | Closed Rescheduled Loans | R01/R04 |
| opened-rescheduled-loans | Loan Accounts | Opened Rescheduled Loans | R01/R04 |
| loans-written-off | Loan Accounts | Loans Written-Off | R01/R04 |
| expected-loan-payments | Loan Accounts | Expected Loan Payments | R01/R04 |
| unfunded-term-deposit-accounts | Term Deposits | Unfunded Term Deposit Accounts | R01/R04 |
| funded-term-deposit-accounts | Term Deposits | Funded Term Deposit Accounts | R01/R04 |
| active-term-deposit-accounts | Term Deposits | Active Term Deposit Accounts | R01/R04 |
| matured-term-deposit-accounts | Term Deposits | Matured Term Deposit Accounts | R01/R04 |
| liquidated-term-deposit-accounts | Term Deposits | Liquidated Term Deposit Accounts | R01/R04 |
| cancelled-term-deposit-accounts | Term Deposits | Cancelled Term Deposit Accounts | R01/R04 |
| term-deposits-maturity-report | Term Deposits | Term Deposits Maturity Report | R01/R04 |
| investment-accounts | Investments | Investment Accounts | R01/R04 |
| active-investments | Investments | Active Investments | R01/R04 |
| matured-investments | Investments | Matured Investments | R01/R04 |
| investment-maturities | Investments | Investment Maturities | R01/R04 |
| investment-contributions | Investments | Investment Contributions | R01/R04 |
| investment-withdrawals | Investments | Investment Withdrawals | R01/R04 |
| investment-returns | Investments | Investment Returns | R01/R04 |
| investment-plan-performance | Investments | Investment Plan Performance | R01/R04 |
| investment-branch-performance | Investments | Investment Branch Performance | R01/R04 |
| investment-officer-performance | Investments | Investment Officer Performance | R01/R04 |
| current-accounts-register | Current Accounts | Current Accounts Register | R01/R04 |
