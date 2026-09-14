# Mobile parity matrix — Phase 1

Inventory date: 2026-09-14. Source: current repository, not the deployed site.
Execution plan: [MOBILE_IMPLEMENTATION_PLAN.md](MOBILE_IMPLEMENTATION_PLAN.md).
Route-level evidence and static OpenAPI differences: [MOBILE_ROUTE_INVENTORY.md](MOBILE_ROUTE_INVENTORY.md).

## Assessment

Mobile is a partial operational client. Cashier posting is substantially more developed than most other modules. Administrator directories expose records but generally cannot create or edit them. Member, loan-officer, supervisor, and custom-role entry points fall back to `RoleDashboard`, whose module tiles have no navigation actions.

No entire module is certified complete by this inventory. Passing existing tests verifies their specific scenarios only. Physical devices, deployment behavior, full financial reconciliation, and historical reports were not tested in Phase 1.

Registered application surface: 230 non-API route entries and 153 API v1 entries (154 operations excluding HEAD). Counts include aliases, public/infrastructure routes, and resources; they are not feature counts. The static OpenAPI contains 85 normalized operations, omitting 69 registered operations. No stale operations were found after normalizing parameter names. Payload/schema fidelity remains unverified.

## Evidence references

Paths below are relative to the repository root.

- W: `routes/web.php`; corresponding `app/Http/Controllers/` controllers, `app/Policies/`, and `resources/views/` forms.
- A: `routes/api/v1.php`; corresponding `app/Http/Controllers/Api/V1/` controllers.
- M0: `mobile/lib/main.dart` (`workspace`, `RoleDashboard`, login/session).
- M1: `mobile/lib/core/network/api_client.dart` (JSON requests, secure token storage, impersonation).
- M2: `mobile/lib/features/admin/admin_workspace.dart` (admin dashboard/directories/details).
- M3: `mobile/lib/features/admin/impersonation_picker.dart`.
- M4: `mobile/lib/features/cashier/cashier_workspace.dart` (open, preview/post, history, sessions).
- M5: `mobile/lib/features/roles/role_workspaces.dart` (recovery and company-admin approval tools).
- S1: `app/Support/ApiSecurity.php`, `app/Services/TenantContext.php`, `app/Services/LoanVisibilityService.php`.
- S2: `app/Http/Middleware/EnsureIdempotentFinancialRequest.php`.
- S3: `app/Services/ReportCatalogService.php`, `ReportDocumentFormatter.php`, `ReportPdfRenderer.php`.
- S4: `app/Services/LoanApplicationService.php`, `LoanApplicationFeeService.php`, `LoanService.php`.
- S5: `app/Services/NylonPayClient.php`, `QuoteBillingService.php`; web `MemberPaymentController` and API `PaymentController`.

Statuses: **Partial** = implemented subset; **Missing mobile** = API present but workflow UI absent; **Missing API** = no complete mutation/read API counterpart found (mobile also needed); **Unverified** = exists or inferred but requires behavior/contract checks. These are deliberately conservative.

Role/scope codes: SA = platform administrator with explicit company/global context; CA = company administrator; ST = staff with the required permission and branch access; LO = assigned loan officer; RO = recovery officer within permitted scope; TC = cashier with drawer scope; ME = own-client member scope. Custom roles must follow permissions, not just these role names. All rows require company isolation and module checks unless public or explicitly platform-scoped.

## Capability work packages

Each row is a deliverable with an acceptance scenario. Each route action in the companion inventory is an additional child checklist item: do not mark a grouped row complete until all its actions, forms, filters, exports, and permissions are verified.

| ID / phase | Capability and web reference | API evidence | Mobile evidence / status | Roles / scope | Acceptance scenario |
| --- | --- | --- | --- | --- | --- |
| F01 / 2 | Login, logout, session; W auth controllers | `auth/login`, `me`, `logout` | M0/M1; Partial | All / own session | Restore valid session; clear revoked session; logout clears credentials even on network failure. |
| F02 / 2 | Password recovery/change; W password/profile | `auth/forgot-password`, `auth/password`, `refresh` | M0 login only; Missing mobile | All / own session | Reset/change password and resume through correct role workspace. |
| F03 / 2 | Company switching and permission/module navigation; W organizations/modules | `auth/me`, `auth/permissions`, `admin/dashboard?organization_id=`; S1 | M2 directory scope only; Partial | SA/CA/ST | Switch scope, reject stale cross-company results, refresh allowed actions; custom-role navigation works. |
| F04 / 2 | Impersonation; W user/client impersonation | `admin/impersonation/users`, impersonation middleware | M1/M3 and banner; Partial | Authorized SA; verify CA web differences | Enter and exit target safely; denied target restores original context; no cache leakage. |
| F05 / 2 | Financial duplicate prevention and recovery | S2 accepts header or body keys; random fallback when absent | M4 body keys and in-memory pending payload; M5 approval has no explicit key; Partial | Financial ST | Repeat identical action/retry after lost response: one posting; app restart recovers pending status. |
| F06 / 2 | Search/filter/page and field errors | ApiQuery/ApiResponse across A | M1 returns first error; M2/M4/M5 limited list flows; Partial | Scoped by record | Show all field errors, stable paging, explicit retry, and no stale filter results. |
| F07 / 2 | Upload/download; W client documents/statements | Document upload/download and statements | M1 JSON-only request/response; Missing mobile | ST/ME as authorized | Upload real document, show validation, download authenticated file, handle expiry. |
| U01 / 3 | Navigation, shared forms, branding, responsive layouts | Capability contract needs expansion | M0–M5 mixed shells and static branding; Partial | All | Same roles see all authorized workflows on phone/tablet with accessible forms. |
| C01 / 4 | Clients register/detail/create/edit; W ClientController | `clients` resource, accounts/loans, financial-overview | M2 directory and M5 recovery detail; Partial | ST/LO/RO | Create/edit permitted client and see identical linked records on web. |
| C02 / 4 | KYC/document verification, notes; W ClientKyc/Document/Note controllers | Upload/read exists; full verify/note workflow endpoints not found | Missing API | Authorized ST | Record verification/note with actor/history; forbidden branch rejected. |
| C03 / 4 | Officer assignment, groups, portal access, deletion; W ClientController/Group/PortalAccess | Admin group read exists; matching full mutations absent | M2 read; Missing API | CA/ST per policy | Assign staff, maintain group, issue portal access and use permitted deletion rules. |
| C04 / 4 | Client statements; W ClientStatementController | `clients/{client}/statements`, combined-statement | Missing mobile | ST/ME | Identical opening/closing balance and filtered transactions; export same scope. |
| A01 / 4 | Accounts and savings register/open; W Account/SavingsAccount controllers | Savings account list/store/show; admin account directory | M2 read; Partial | ST/ME own read | Open account, validate product/client/branch, inspect current balance. |
| A02 / 4 | Savings deposits/withdrawals/transfers | Savings transaction endpoints and cashier posting | M4 covers cashier subset; Partial | TC/ST | Post each action and reconcile balances, drawer movement, journals, receipt. |
| A03 / 4 | Savings restrictions/delete/statements/receipts | Statements and reverse exist; lifecycle parity incomplete | Missing mobile / API gaps | Authorized ST/ME read | Restricted account rejects prohibited action; permitted lifecycle change audited. |
| T01 / 4 | Teller dashboard/open/post/preview/history | `cashier/*` | M4; Partial | TC/drawer | Open once, preview, post once, inspect receipt/history; reconcile totals. |
| T02 / 4 | Drawer create/edit/delete/close/transfer/managed-post; W TellerDrawerController | Cashier open/post/read only; closing/admin mutations absent | Missing API | CA/TC by action | Complete entire working day, transfers and close with discrepancy handling. |
| T03 / 4 | Reversal request/approve/reject; W TransactionReversalController | Savings reverse and financial-controls decisions only | M5 generic decision; Partial | Maker/checker ST | Request and approve reversal; prohibit self-approval; entries reconcile. |
| T04 / 4 | Expenses, non-cash transactions, delete by reference | Cashier posting subset; matching standalone workflows absent | Missing API | Authorized ST | Match permitted web creation/correction and audit behavior. |
| T05 / 4 | End-of-day reconcile/process/close/lock | No counterpart found | Missing API | Authorized ST | Reconcile then close/lock; prohibit late unauthorized postings. |
| L01 / 5 | Loan list/detail and assignment visibility | `loans`, schedule; S1 | M2/M5 list and recovery subset; Partial | CA/ST/LO/RO/ME | Assigned/owned loans only; read complete details, schedules and history. |
| L02 / 5 | Loan creation and schedule preview | `loans` POST shares StoreLoanApplicationRequest/S4; schedule-preview | Missing mobile | ST/LO; ME separate rules | Create application with identical server validation, fee and schedule. |
| L03 / 5 | Guarantors/collateral/appraisal/submit | Loan workflow endpoints exist | Missing mobile | Authorized ST | Complete securities/appraisal and advance allowed states; explicit product false overrides default. |
| L04 / 5 | Approve/reject/cancel/assign officer | Loan workflow endpoints exist | M5 approval only; Partial | Approvers/ST | Validate eligible state, permissions and selected officer including admin. |
| L05 / 5 | Generate accounts/disburse/repay/close/write-off | Loan workflow/repayment endpoints exist | Missing mobile except cashier subset | TC/ST by action | Complete disbursement and repayment; double retry produces one movement. |
| L06 / 5 | Draft/repair terms and application fee refunds; W LoanController/LoanFeeController | Shared creation rules exist; matching repair/refund routes absent | Missing API | Authorized ST | Custom/waived fees, fixed/percentage bands and partial refunds reconcile to ledger. |
| L07 / 5 | Arrears/due repayments/penalties/recovery | `loans/arrears/list`, penalty waiver; bulk/due workflows incomplete | M5 read; Partial | RO/LO/ST | Correct overdue list and authorized penalty/recovery actions with history. |
| R01 / 6 | Entire web catalog; W reports, S3 | Fixed report endpoints only; catalog/slugs absent | M5 dashboard summary; Missing API | Report-authorized ST | All enabled report definitions expose correct filters and numeric rows. |
| R02 / 6 | User Posted Transactions staff/branch/search | No matching catalog report API | Missing API | Report-authorized ST | Members excluded; same staff/branch filters on screen and export. |
| R03 / 6 | Arrears Rate date/lifetime/officer totals | No matching API; shared defects below | Missing API | Report-authorized ST | Distinct officer IDs, actual disbursements, valid period semantics and totals row; zero denominator safe. |
| R04 / 6 | Branded PDF/Excel exports | POST `reports/exports` queues job; complete status/download route lifecycle absent | Missing API / mobile | Export-authorized ST/ME where allowed | Poll status, download authorized file with correct branding/scope; retry failed job safely. |
| G01 / 7 | Ledgers/journals/statements; W ledger/report controllers | `accounting/*` read plus journal POST | M2 ledger read; Partial | Accounting ST | Authorized balanced journals and statements match web totals. |
| G02 / 7 | Ledger maintenance/merge/delete and currency rates | Complete maintenance/currency API absent | Missing API | Authorized ST/SA | Maintain accounts and rates with web constraints and audit records. |
| P01 / 7 | Investments/member top-ups and investment reports | Plans/accounts/transactions/relationships APIs exist | M2 read; Partial | Authorized ST/ME | Open/approve/top-up/withdraw/return/reinvest and reconcile balances. |
| P02 / 7 | Savings goals, fixed/group savings | APIs exist; direct web maintenance routes not found | Missing mobile; web scope unverified | Authorized ST/ME | Confirm supported product lifecycle before building; reconcile contributions/maturity. |
| P03 / 7 | Shares and other catalog financial products | Report/model references; complete web/API action surface unverified | Unverified | Product-authorized ST | Map actual web actions; avoid promising unsupported new product operations as parity. |
| P04 / 7 | Linked savings/investments/loan securities | `financial-relationships/*` | Missing mobile | Authorized ST | Lock/release/repay/transfer through shared service and verify ledger effects. |
| S01 / 8 | Company create/edit/delete/switch and branch maintenance | Admin GET records only for these actions | M2 read; Missing API | SA/CA by policy | Mutations, logo/contact settings and isolation match web policies. |
| S02 / 8 | Staff, roles and permissions maintenance | Admin records read; no matching resource mutations | M2 read; Missing API | SA/CA/ST permitted | Manage staff/custom roles; deny member inclusion and unauthorized promotion. |
| S03 / 8 | System/company financial settings, products/defaults | `settings` GET/PUT, product PUT; full defaults/provisioning parity absent | M2 product read; Partial | SA/CA/ST | Effective values and company overrides match web; no cross-company updates. |
| S04 / 8 | Module packages/toggle/visibility and demo lifecycle | Admin modules/packages read; demo middleware | M2 read; Missing API | SA/CA authorized subset | Assign package/toggle modules; enforce limits and show access status. |
| B01 / 8 | Request/create/issue/pay/delete quotes; subscriptions/hosting | Admin quote GET only; no billing mutations | M2 read; Missing API | SA/CA | Quote lifecycle and payment activation match web with audit trail. |
| B02 / 8 | Company/platform Mobile Money settings and checkout | Generic collections/verify + webhooks, not billing/member equivalence | Missing API; S5 needs separation review | SA/CA/ST/ME by purpose | Company collection cannot fall back to platform keys; quotes always use platform scope. |
| O01 / 8 | Audit logs/system activity/operations health | Admin activity subset; no complete audit/health API | M2 read activity; Partial | Authorized ST/SA | Filter actor/actions safely and inspect permitted operational results. |
| O02 / 8 | Import template/upload/preview/edit/commit/rollback | No import workflow API | Missing API | Import-authorized ST | Preview errors then commit/rollback with matching web safety rules. |
| O03 / 8 | Data wipe and destructive maintenance | No matching API | Missing API | Explicitly authorized admin | Preserve web scope validation and confirmation; no implementation-time data deletion. |
| M01 / 9 | Member dashboard/accounts/loans/statements | Own-account access exists in selected endpoints | M0 inert tiles; Missing mobile | ME / own client | Member opens each financial record without staff access. |
| M02 / 9 | Member applications/deposits/repayments/top-ups | Generic collection checks staff permissions; not equivalent to web member endpoints | Missing API | ME / own client | Member payment uses own company checkout and verified credit/repayment. |
| N01 / 9 | Chat and conversations/messages | No chat API found | Missing API | Permitted users | Authorized conversations, unread state, pagination, send/retry semantics. |
| N02 / 9 | Notifications, read/read-all/open; SMS/device tokens | Notification/SMS/device APIs; read-all/action parity incomplete | Missing mobile | Scoped users/ST for SMS | Authorized deep links, device registration/revocation and correct unread state. |
| X01 / 10 | Offline drafts, freshness, conflicts | Sync manifest/client pull/push exists | No sync UI/persistence found; Missing mobile | Client-authorized ST | Preserve local draft, detect server conflict and never silently duplicate client. |
| X02 / 10 | Device reliability, accessibility, secure cache, release | Infrastructure/testing work | Existing widget tests only; Unverified | All | Test physical devices, interruption/restart, large data, text scale and release configs. |
| X03 / 1 | Marketing/demo onboarding/health/traffic/link previews | Public/demo web only plus health/docs | Unverified product scope | Public/SA | Decide native onboarding versus browser handoff; keep operational endpoints in coverage inventory, not staff screens. |

## Baseline checks

Executed before implementation changes. PHP tests use repository `phpunit.xml`: SQLite in memory, array cache, synchronous queue. No production database tests or device tests were run.

| Check | Result | Interpretation |
| --- | --- | --- |
| `flutter analyze` in `mobile/` | Passed; no issues | Static analysis, not workflow parity. Dependencies resolved without source/lockfile changes. |
| `flutter test --no-pub` in `mobile/` | 10 passed | Login, admin workspace, cashier dashboard, impersonation/client behavior; no full live-device workflow coverage. |
| Selected 11 Laravel feature test files | 38 tests, 30 passed, 8 failed, 568 assertions | Baseline failures below; test names indicate intended coverage, not blanket certification. |
| `php artisan route:list --except-vendor --json` | Passed | Expanded route inventory saved in companion document. |
| Static OpenAPI method/path comparison | 69 missing, 0 stale normalized operations | Normalize parameter names and ignore HEAD; schema correctness not established. |

Laravel command:

```sh
php artisan test --compact tests/Feature/ApiAuthenticationTest.php tests/Feature/ApiSecurityTest.php tests/Feature/ApiIdempotencyTest.php tests/Feature/ApiDocumentationTest.php tests/Feature/MobileApiSupportTest.php tests/Feature/MobileAdminWorkspaceTest.php tests/Feature/MobileCashierWorkspaceTest.php tests/Feature/MobileImpersonationTest.php tests/Feature/OfflineSyncApiTest.php tests/Feature/PaymentCallbackApiTest.php tests/Feature/ReportTenantIsolationTest.php
```

### Existing failures to resolve in Phase 2

| ID | Test / failing scenario | Observed failure | Next investigation |
| --- | --- | --- | --- |
| D01 | ApiAuthenticationTest: issues token/profile | 403: account not assigned to organization | Fixture creates branch/user without explicit company. Establish valid tenant fixture and retain negative unassigned-user case. |
| D02 | ApiSecurityTest: cross-branch savings read | Expected 403, received 404 | Record is hidden by scope. Decide and document forbidden/not-found contract; do not weaken isolation to satisfy test. |
| D03 | ApiIdempotencyTest: replay and changed-payload tests (2) | Initial deposit receives 403, savings module disabled | Enable legitimate fixture entitlements so tests reach posting; then verify duplicate prevention itself. |
| D04 | ApiDocumentationTest: versioned documentation | Expected idempotency flag true, got null | Test references `{savingsAccount}` while registered deposit uses `{savingsAccountId}`; inspect dynamic generator and contract expectations. |
| D05 | MobileApiSupportTest: device token registration/revocation | 403, SMS module disabled | Decide whether push registration should depend on SMS entitlement; align fixtures with intended policy. |
| D06 | OfflineSyncApiTest: capture and stale-update conflict (2) | 403, API-access module disabled | Establish enabled sync fixture; retest capture and conflict logic. |

No failures were repaired during this inventory. Several appear to be fixture/contract drift; that is a diagnosis to verify, not evidence that production logic is correct.

## Shared risks and corrections before parity certification

1. **Retry behavior (F05):** cashier body `idempotency_key` is supported by S2; opening keys and pending transaction payload are retained in memory. Generic API requests lack a header facility, and M5 loan approval sends no explicit key. S2 generates a new UUID when the client omits a key. Standardize keys and persistence without removing the working cashier behavior.
2. **Arrears Rate (R03):** S3 groups by officer name (same-name staff merge), includes approved loans in lifetime mode via LISTING_STATUSES, and uses current schedule paid/outstanding values alongside a historical due-date cutoff. Totals are summary values rather than an appended table row. Define correct cohort/as-of meaning, use officer IDs and actual disbursements, and write populated historical/zero/unassigned fixtures before exposure on mobile. The prior `$to` closure fix alone is not calculation verification.
3. **Payment separation (B02):** S5 resolves platform and organization records separately, but its credential fields fall back to `services.nylon_pay.*` config even for an absent/incomplete organization credential. Review and test fail-closed company collections; never assume the UI wording proves isolation. Generic API collections are not automatically equivalent to member Nylon Pay checkout.
4. **Report lifecycle (R01/R04):** web catalog generation and API fixed report endpoints use different entry points. Export creation returns a queued record; the registered API has no complete export status/download lifecycle. Reuse services and expose the entire lifecycle.
5. **Permissions (F03):** mobile entry routing relies on role slugs and fallback tiles. Compare actual policies, ApiSecurity, controller guards, and enabled modules per capability. An endpoint's existence or a dashboard's visibility is insufficient authorization evidence.

## Prioritized implementation order and effort

Estimates are engineering working days for one developer familiar with Laravel/Flutter, including relevant tests, excluding deployment and stakeholder/device acceptance delays. These are initial work-package estimates with uncertainty, not delivery-date promises. Foundation estimates have higher confidence; later packages require form/field decomposition and re-estimation before implementation.

| Priority | Package | Dependencies | Effort | Release gate |
| --- | --- | --- | --- | --- |
| P0 | P2-A: baseline fixtures and API contract repair (D01–D06) | Phase 1 | 2–4 days | All 38 selected tests pass for legitimate reasons; retain negative security tests. |
| P0 | P2-B: context/permissions/session contracts (F01–F04/F06) | P2-A | 3–6 days | Cross-company, branch, custom-role, expiry and impersonation scenarios. |
| P0 | P2-C: reusable financial retry/recovery (F05) | P2-A; coordinate P2-B | 3–5 days | Same-operation retry and restart produce one movement; changed payload rejected. |
| P1 | P2-D: uploads/downloads/export contracts and OpenAPI (F07/R04) | P2-B | 3–6 days | Real file lifecycle, permissions and documented contract. |
| P1 | Phase 3 components (U01) | P2-B | 4–7 days | Phone/tablet client workflow verified. |
| P1 | Phase 4 clients/accounts/teller (C01–T05) | P2-C/D, U01 | 15–25 days | Complete operational day and accounting reconciliation. |
| P1 | Phase 5 lending/recovery (L01–L07) | Phase 4 foundations | 15–25 days | Whole loan lifecycle including fee override/refund verified. |
| P1 | Phase 6 reports (R01–R04) | Shared report corrections, P2-D | 10–18 days | Catalog and exports match scope/figures across interfaces. |
| P2 | Phase 7 accounting/products (G01–P04) | Financial foundations | 12–22 days | Actual web/API product scope reconciled and workflows tested. |
| P1/P2 | Phase 8 admin/billing/operations (S01–O03) | P2-B/C/D | 12–22 days | Settings isolation, quote checkout, maintenance controls. |
| P2 | Phase 9 member/communications (M01–N02) | Relevant financial APIs | 10–18 days | Owned records/payments and authorized communication. |
| P1 ongoing | Phase 10 reliability/release (X01/X02) | Continuous; final gate all phases | 5–10 days final hardening | Device/regression acceptance and documented release readiness. |

Do not sum these into a committed schedule: packages overlap and late-phase unknowns remain. First implementation package is **P2-A**, followed by P2-B and P2-C before expanding money-moving screens.

## Role acceptance checklist for every package

- SA: global browsing only where explicitly allowed; selected company applies to all linked actions.
- CA: own company only, eligible branches, no platform credential/privilege escalation.
- Supervisor/custom ST: exact permissions and branch access; no reliance on admin role names.
- TC: own authorized drawers/branches and allowed postings; cannot perform prohibited loan administration.
- LO: assigned/created loans and client visibility follow LoanVisibilityService and policy rules.
- RO: recovery scope and allowed actions match web; no assumed general reporting access.
- ME: own linked client/financial records, no staff directories or staff-only mutations.
- Disabled module/account/demo: server rejects prohibited action, mobile displays actionable state.

## Phase 1 completion and limits

Route/action inventory, mobile screen mapping, module coverage, role/scoping references, baseline tests, known defects, initial effort ranges and next package are recorded. Route appendix preserves every registered entry and catalog report so less-visible actions are not lost.

Detailed field-by-field acceptance, schema correctness, historical accounting proof and physical-device behavior remain implementation/verification work. No runtime code, dependencies, or production data were changed during Phase 1.
