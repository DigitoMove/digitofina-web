# DigitoFina API Documentation

This directory contains OpenAPI 3.1 documentation for DigitoFina v1 APIs.

- Machine-readable spec: [openapi.json](./openapi.json)
- Base path: `/api/v1`
- Authentication: Laravel Sanctum bearer tokens for protected routes
- Standard success envelope: `{ "success": true, "message": "...", "data": {}, "meta": {} }`
- Standard error envelope: `{ "success": false, "message": "...", "errors": {} }`
- Mobile list endpoints support `page`, `per_page`, `search`, `filter[...]`, `sort`, and `updated_since` where applicable.
- Financial operations require an `Idempotency-Key` header when marked in the OpenAPI operation parameters.

Import `docs/api/openapi.json` into Swagger UI, Stoplight, Postman, Insomnia, or a Flutter/OpenAPI client generator.

## Endpoint Index

| Method | URL | Module | Required permissions |
| --- | --- | --- | --- |
| GET | `/api/v1/accounting/balance-sheet` | Accounting | manage-accounting OR view-reports |
| GET | `/api/v1/accounting/chart-of-accounts` | Accounting | manage-accounting OR view-reports |
| GET | `/api/v1/accounting/general-ledger` | Accounting | manage-accounting OR view-reports |
| GET | `/api/v1/accounting/income-statement` | Accounting | manage-accounting OR view-reports |
| GET | `/api/v1/accounting/journal-entries` | Accounting | manage-accounting OR view-reports |
| POST | `/api/v1/accounting/journal-entries` | Accounting | post-journal-entries OR manage-accounting |
| GET | `/api/v1/accounting/trial-balance` | Accounting | manage-accounting OR view-reports |
| POST | `/api/v1/auth/forgot-password` | Authentication | public |
| POST | `/api/v1/auth/login` | Authentication | public |
| POST | `/api/v1/auth/logout` | Authentication | authenticated |
| GET | `/api/v1/auth/me` | Authentication | authenticated |
| PUT | `/api/v1/auth/password` | Authentication | authenticated |
| GET | `/api/v1/auth/permissions` | Authentication | authenticated |
| POST | `/api/v1/auth/refresh` | Authentication | authenticated |
| GET | `/api/v1/clients` | Clients and KYC | manage-clients OR view-reports OR branch access |
| POST | `/api/v1/clients` | Clients and KYC | manage-clients |
| GET | `/api/v1/clients/{client}` | Clients and KYC | manage-clients OR view-reports OR branch access |
| PUT | `/api/v1/clients/{client}` | Clients and KYC | manage-clients |
| PATCH | `/api/v1/clients/{client}` | Clients and KYC | manage-clients |
| GET | `/api/v1/clients/{client}/accounts` | Clients and KYC | manage-clients OR view-reports OR branch access |
| GET | `/api/v1/clients/{client}/documents` | Clients and KYC | manage-clients OR view-reports OR branch access |
| POST | `/api/v1/clients/{client}/documents` | Clients and KYC | manage-clients |
| GET | `/api/v1/clients/{client}/documents/{document}/download` | Clients and KYC | manage-clients OR view-reports OR branch access |
| GET | `/api/v1/clients/{client}/loans` | Clients and KYC | manage-clients OR view-reports OR branch access |
| GET | `/api/v1/clients/{client}/statements` | Clients and KYC | manage-clients OR view-reports OR branch access |
| GET | `/api/v1/loan-products` | Loans | authenticated |
| GET | `/api/v1/loans` | Loans | manage-loans OR view-own-accounts |
| POST | `/api/v1/loans` | Loans | manage-loans |
| GET | `/api/v1/loans/arrears/list` | Loans | manage-loans OR view-reports |
| POST | `/api/v1/loans/schedule-preview` | Loans | authenticated |
| GET | `/api/v1/loans/{loan}` | Loans | manage-loans OR view-own-accounts |
| POST | `/api/v1/loans/{loan}/approve` | Loans | approve-loans |
| POST | `/api/v1/loans/{loan}/close` | Loans | manage-loans |
| POST | `/api/v1/loans/{loan}/collaterals` | Loans | manage-loans |
| POST | `/api/v1/loans/{loan}/disburse` | Loans | disburse-loans OR manage-loans |
| POST | `/api/v1/loans/{loan}/guarantors` | Loans | manage-loans |
| POST | `/api/v1/loans/{loan}/reject` | Loans | approve-loans |
| POST | `/api/v1/loans/{loan}/repayments` | Loans | post-repayments |
| GET | `/api/v1/loans/{loan}/schedule` | Loans | manage-loans OR view-own-accounts |
| POST | `/api/v1/loans/{loan}/submit` | Loans | manage-loans |
| POST | `/api/v1/loans/{loan}/write-off` | Loans | write-off-loans |
| GET | `/api/v1/notifications` | Notifications | authenticated |
| POST | `/api/v1/notifications/sms` | Notifications | manage-settings OR manage-clients |
| GET | `/api/v1/notifications/sms-logs` | Notifications | view-reports OR manage-settings |
| POST | `/api/v1/notifications/{notification}/read` | Notifications | authenticated |
| POST | `/api/v1/payments/mobile-money/callback` | Payments | public |
| POST | `/api/v1/payments/mobile-money/collections` | Payments | post-transactions OR manage-accounts |
| POST | `/api/v1/payments/reconcile` | Payments | manage-accounting OR post-transactions |
| POST | `/api/v1/payments/{paymentRequest}/match` | Payments | authenticated OR organization access |
| GET | `/api/v1/payments/{paymentRequest}/verify` | Payments | authenticated OR organization access |
| GET | `/api/v1/reports/arrears` | Reports | view-reports |
| GET | `/api/v1/reports/branch-performance` | Reports | view-reports |
| GET | `/api/v1/reports/clients` | Clients and KYC | view-reports |
| GET | `/api/v1/reports/dashboard` | Reports | view-reports |
| POST | `/api/v1/reports/exports` | Reports | export-reports |
| GET | `/api/v1/reports/loans` | Loans | view-reports |
| GET | `/api/v1/reports/savings` | Savings | manage-accounts OR own account access |
| GET | `/api/v1/reports/tellers` | Reports | view-reports |
| GET | `/api/v1/savings-accounts` | Savings | manage-accounts OR view-own-accounts |
| POST | `/api/v1/savings-accounts` | Savings | manage-accounts |
| POST | `/api/v1/savings-accounts/{savingsAccount}/deposit` | Savings | post-transactions |
| GET | `/api/v1/savings-accounts/{savingsAccount}/statement` | Savings | manage-accounts OR own account access |
| GET | `/api/v1/savings-accounts/{savingsAccount}/statement/download` | Savings | manage-accounts OR own account access |
| POST | `/api/v1/savings-accounts/{savingsAccount}/withdraw` | Savings | post-transactions |
| GET | `/api/v1/savings-accounts/{savings_account}` | Savings | manage-accounts OR own account access |
| GET | `/api/v1/savings-products` | Savings | authenticated |
| POST | `/api/v1/savings-transactions/{savingsTransaction}/reverse` | Savings | post-transactions |
| POST | `/api/v1/savings-transfers` | Savings | post-transactions |
| GET | `/api/v1/savings/accounts` | Savings | manage-accounts OR view-own-accounts |
| POST | `/api/v1/savings/accounts` | Savings | manage-accounts |
| GET | `/api/v1/savings/accounts/{savingsAccount}` | Savings | manage-accounts OR own account access |
| POST | `/api/v1/savings/accounts/{savingsAccount}/deposit` | Savings | post-transactions |
| GET | `/api/v1/savings/accounts/{savingsAccount}/statement` | Savings | manage-accounts OR own account access |
| GET | `/api/v1/savings/accounts/{savingsAccount}/statement/download` | Savings | manage-accounts OR own account access |
| POST | `/api/v1/savings/accounts/{savingsAccount}/withdraw` | Savings | post-transactions |
| GET | `/api/v1/savings/products` | Savings | authenticated |
| POST | `/api/v1/savings/transactions/{savingsTransaction}/reverse` | Savings | post-transactions |
| POST | `/api/v1/savings/transfers` | Savings | post-transactions |
| GET | `/api/v1/settings` | Settings | manage-settings |
| PUT | `/api/v1/settings` | Settings | manage-settings |
| GET | `/api/v1/notifications/devices` | Notifications | authenticated |
| POST | `/api/v1/notifications/devices` | Notifications | authenticated |
| DELETE | `/api/v1/notifications/devices/{deviceToken}` | Notifications | authenticated |
