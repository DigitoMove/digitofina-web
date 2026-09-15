# Mobile application implementation plan

Status: Phase 1 complete; Phase 2 packages P2-A and P2-B complete; P2-C in progress
Created: 2026-09-14
Scope: DigitoFina Flutter application, shared Laravel services, and API v1.

## Objective

Deliver the same authorized business capabilities on mobile as on the web: workflows, calculations, permissions, settings, reporting, exports, and audit records. Adapt interactions for phones and tablets rather than copying desktop layouts.

This is the execution and progress record. Update checkboxes and the progress log after each completed work package. Do not mark work complete based on screen presence alone.

## Execution rules

- Inventory the current web behavior and mobile implementation before estimating individual work packages.
- Use shared Laravel business services for financial calculations and mutations. Do not duplicate financial rules in Dart.
- Correct confirmed shared defects before making them the mobile reference behavior.
- Enforce company, branch, role, permission, and module access on the server. Mobile visibility is an additional usability layer.
- Preserve existing work and avoid dependency changes without approval.
- Complete one coherent vertical workflow at a time: API, mobile interface, validation, permissions, audit trail, and tests.
- Financial operations require stable idempotency keys across retries and reconciliation after uncertain responses.
- Do not expose provider secrets in API read responses or application bundles.
- Do not deploy, publish store releases, or migrate production data as part of ordinary implementation verification.

## Baseline and evidence

Initial inspection found a Flutter app under `mobile/`, an API client with secure token storage, role dashboards, admin directories and impersonation, cashier workspaces, recovery screens, and some approval/report interfaces. Existing mobile tests cover widgets, cashier/admin workspaces, and impersonation.

These findings establish a starting point, not verified end-to-end parity. Inspect the latest code during Phase 1. In particular, verify support for financial idempotency headers, report catalog coverage, uploads/downloads, and complete action workflows. The inspected API client did not expose an idempotency header parameter.

## Phase 1 — Capability inventory and acceptance baseline

- [x] Inventory web routes, controllers, policies, forms, services, report definitions, exports, and settings.
- [x] Inventory API routes and observed behavior; record OpenAPI differences against implemented endpoints. Contract repairs are Phase 2 work.
- [x] Inventory mobile screens, reachable actions, API calls, and existing tests.
- [x] Create `docs/MOBILE_PARITY_MATRIX.md` with capability rows and a route-level child checklist.
- [x] Record role coverage: super admin, company admin, supervisor, cashier, loan officer, recovery officer, member, and custom roles.
- [x] Record missing APIs, partial workflows, differences in calculations, and shared defects.
- [x] Assign matrix items work-package IDs, dependencies, priorities, acceptance scenarios, and grouped effort ranges.
- [x] Run existing Flutter analysis/tests and relevant API tests; record baseline failures separately from new failures.

Evidence: [parity matrix and baseline results](MOBILE_PARITY_MATRIX.md), [complete registered route inventory and named report list](MOBILE_ROUTE_INVENTORY.md). Inventory completion does not certify behavior parity. Device acceptance and detailed field/schema verification remain required in implementation packages.

Matrix columns: ID, module, capability, web reference, API reference, mobile reference, roles, company/branch scope, status, dependencies, acceptance scenario, verification evidence.

Statuses: unverified; missing API; missing mobile; partial; blocked; verified complete.

Exit: all web modules are represented and the first implementation work packages are concrete. Publish effort estimates only after this inventory.

## Phase 2 — Shared API and mobile foundations

Depends on Phase 1.

- [ ] Standardize company and branch context, permissions, enabled modules, and allowed record actions.
- [ ] Verify login, logout, password recovery/change, expired sessions, and server revocation behavior.
- [ ] Make company/user/impersonation changes clear cached records and in-flight results safely.
- [ ] Add typed models and module API services incrementally, retaining existing working screens.
- [ ] Standardize pagination, search, sorting, field validation, and API errors.
- [ ] Implement authenticated uploads, downloads, and export lifecycle handling.
- [ ] Add stable financial idempotency keys; retain keys for uncertain retries and prevent double submission.
- [ ] Define payment/transaction status recovery after timeout or application restart.
- [ ] Refresh OpenAPI contracts and add missing endpoint authorization tests.

Exit: cross-company access is rejected; invalid actions are rejected server-side; repeated requests cannot duplicate financial entries; connection errors preserve useful user input.

## Phase 3 — Mobile interface system

Depends on Phase 2 contracts; build components alongside the first operational workflows.

- [ ] Define role-based navigation, dashboards, and quick actions.
- [ ] Build reusable searchable lists, pagination, filters, detail tabs, and history views.
- [ ] Build selectors for clients, staff, branches, products, and accounts with correct server-side scope.
- [ ] Build form validation, multi-step forms, draft handling, and financial confirmation summaries.
- [ ] Standardize loading, empty, permission-denied, expired-session, and network-error states.
- [ ] Apply company branding and consistent amounts, currencies, dates, and statuses.
- [ ] Verify phone/tablet layouts, keyboard behavior, large text, and accessibility labels.

Exit: a complete client/account workflow demonstrates the components on a real device and in widget tests.

## Phase 4 — Clients, accounts, and teller operations

Depends on Phases 2–3.

- [ ] Client registration/editing, KYC, photos/documents, verification, assignments, and history.
- [ ] Client account/loan links and statements.
- [ ] Savings account creation, balances, restrictions, deposits, withdrawals, and transfers.
- [ ] Drawer allocation, session opening/closing, cash movements, reconciliation, and discrepancies.
- [ ] Transaction search, details, receipts, approvals, and authorized reversals.
- [ ] Refresh balances and histories after completed operations.

Exit: permitted staff can complete their daily cashier workflow; postings, reversals, drawer totals, and ledger entries agree with web results.

## Phase 5 — Lending and recovery

Depends on Phase 4 account foundations.

- [ ] Loan application creation/editing, product selection, and server schedule preview.
- [ ] Appraisal, guarantors, collateral, submission, approval, rejection, and cancellation.
- [ ] Assignment to eligible staff, including administrators acting as officers.
- [ ] Disbursement, repayment allocation, schedules, closure, and authorized repair/write-off workflows.
- [ ] Recovery portfolios, overdue schedules, client details, follow-ups, and penalty actions available on web.
- [ ] Effective product settings honor explicit false values over defaults for guarantor/collateral requirements.
- [ ] Fixed and percentage fee bands, server fee preview, and consistent rounding.
- [ ] Authorized custom application fees and fee waivers at creation/disbursement.
- [ ] Full/partial fee refunds with reasons, account credits, accounting entries, and audit history.

Exit: equivalent web/mobile workflows yield identical schedules, fees, balances, and journals; refunds cannot exceed the refundable charge; role and branch restrictions hold.

## Phase 6 — Reports and exports

Depends on shared report contracts and verified financial data.

- [ ] Expose the web report catalog and report-specific filter metadata through API v1.
- [ ] Reuse shared report generators and authorization rather than separate mobile formulas.
- [ ] Build report categories, filters, summaries, tables, totals, and applicable drill-downs.
- [ ] Support company/branch selection and only the filters relevant to each report.
- [ ] User Posted Transactions: company staff only in selectors, with staff/branch filtering reflected in results and exports.
- [ ] Arrears Rate: agree and document period semantics before implementation (disbursement cohort versus historical snapshot).
- [ ] Arrears Rate: lifetime option, assigned officers including admins, portfolio, principal, arrears, paid, balance, and final totals row.
- [ ] Arrears Rate: overall rate is total arrears divided by total portfolio; handle zero portfolio and distinct officers sharing a name.
- [ ] Verify historical calculations against repayment/reversal history if historical snapshots are supported; do not label current balances as historical.
- [ ] PDF/Excel generation, progress/failure handling, authenticated download, and native sharing.
- [ ] Export company logo with proportional sizing, company/address/contact details, branch/company scope, period/lifetime label, currency, and generation time.

Exit: same inputs yield the same web/mobile figures and export scope, including populated, empty, lifetime, date-range, and restricted-branch cases.

## Phase 7 — Accounting and other financial products

Depends on Phases 2–5; implement complete workflows in inventory priority order.

- [ ] Chart of accounts, journals, ledger activity, income/expenses, reconciliation, and financial statements.
- [ ] Shares, fixed deposits, savings goals, and group savings capabilities available on web.
- [ ] Investment accounts, contributions, returns, withdrawals, maturity, and performance workflows.
- [ ] Approvals, reversals/corrections, statements, and audit history for each product.

Exit: every inventoried workflow in these modules is covered, including permissions and accounting reconciliation.

## Phase 8 — Administration, settings, and billing

Depends on Phase 2; deliver alongside other modules where configuration is required.

- [ ] Company profile/branding, branches, staff, roles, permissions, and product settings.
- [ ] System defaults versus company overrides, with clear effective values and scope.
- [ ] Module visibility, demo limitations, subscriptions, and access status.
- [ ] Quote requests, issuance, payment, hosting charges, and billing history according to role.
- [ ] Company Mobile Money credentials apply only to client deposits/repayments.
- [ ] Platform Mobile Money credentials apply to company quote payments.
- [ ] Mobile Money checkout return, cancellation, delayed confirmation, and status refresh.
- [ ] Authorized credential updates preserve blank secrets and display only masked state.
- [ ] Audit logs, approval queues, operational administration, import/export, and other web administration capabilities identified in Phase 1.

Exit: staff cannot change platform settings; payment credential scopes are verified with different company/platform fixtures; admin capabilities match the parity matrix.

## Phase 9 — Member services and communication

Depends on the relevant financial module APIs.

- [ ] Member accounts, balances, statements, loans, schedules, documents, and permitted applications.
- [ ] Member deposits/payments and confirmed transaction status.
- [ ] Applicable chat, announcements, SMS workflows, and notification history.
- [ ] Device registration, notification delivery, read state, and links to authorized records.
- [ ] Recheck record access when following notification links or restoring sessions.

Exit: members can complete their permitted self-service workflows and cannot access staff or other members' data.

## Phase 10 — Reliability and release readiness

Apply reliability checks during every phase; consolidate before release.

- [ ] Test slow connections, lost responses, repeated taps, expired tokens, backgrounding, and restarts.
- [ ] Display cache freshness and preserve suitable drafts; require server confirmation for completed financial actions.
- [ ] Keep offline financial posting outside the initial release unless synchronization/conflict rules are separately approved and implemented.
- [ ] Test large portfolios and transaction histories with pagination and realistic fixtures.
- [ ] Confirm sensitive information is absent from logs and inaccessible after logout/company switches.
- [ ] Run Flutter analysis, unit/widget/integration tests, API tests, and physical Android/iOS checks.
- [ ] Conduct acceptance sessions for each role and resolve critical defects.
- [ ] Prepare release configuration, signing, distribution instructions, monitoring, rollback, and API compatibility checks.

Exit: every parity item is verified complete or has an explicitly agreed scope exception; no unresolved critical financial, isolation, or workflow defects remain. Production/store release is a separate action.

## Definition of done for each work package

1. API and mobile workflow implemented, including validation and failure states.
2. Roles, company/branch scope, module access, and record ownership tested.
3. Financial calculations, idempotency, journals, and audit trail verified where applicable.
4. Matching web/mobile acceptance scenario passes with representative populated data.
5. Relevant regression tests, Flutter analysis, and required formatting pass.
6. Parity matrix and progress log updated with evidence and remaining limitations.

## Progress log

| Date | Work package | Result | Next action |
| --- | --- | --- | --- |
| 2026-09-14 | Planning | Saved implementation sequence, acceptance criteria, and completion gates. No implementation phase is marked complete. | Execute Phase 1: route/action inventory and parity matrix. |
| 2026-09-14 | Phase 1 | Saved capability matrix, 230 non-API routes, 153 API routes, 94 named reports, role/scoping evidence, and prioritized estimates. Flutter analysis clean; 10 mobile tests pass. API baseline: 30/38 tests pass, 8 existing failures recorded. Static OpenAPI omits 69 operations. No runtime code changed. | P2-A: resolve baseline fixtures/contracts, then P2-B context and P2-C financial retries. |
| 2026-09-14 | P2-A | Resolved all eight baseline failures through valid tenant/module fixtures and assertions matching current API contracts. Expanded suite: 42 tests pass, 1,148 assertions. Added unassigned-staff and disabled-module denial cases, positive/negative branch/permission checks, and all-route documentation metadata checks. Runtime access controls unchanged. | P2-B: context, permission and session contracts. Static OpenAPI regeneration remains P2-D. |
| 2026-09-14 | P2-B | Added explicit API organization scope (`X-Organization-Id`) with validated super-admin company/global selection, query/header agreement checks, tenant and inactive-user guards, effective permission/capability context, and mobile scope propagation. Mobile now clears profile/cache markers on scope/impersonation changes, rejects stale responses, restores failed scope changes, and clears failed login sessions. API context suite: 18 tests pass; Flutter suite: 14 tests pass; Pint passed. | P2-C: reusable financial retry/recovery and persisted pending-operation handling. |
| 2026-09-14 | P2-C | In progress: mobile `ApiClient.request` accepts explicit idempotency keys and promotes body keys to the `Idempotency-Key` header. Financial operations now persist method, path, query, body, stable key, and creation time in secure storage; failed operations remain recoverable and can be retried with the same key. Cashier drawer opening and transaction posting use this path. Added authenticated `GET /financial/idempotency/{key}` verification, scoped to the posting user, with Flutter and API coverage. Server replay already rejects changed payloads; broader financial mutation coverage remains open. | Finish P2-C with verification integration, changed-payload protection coverage, and every mobile financial mutation. |

## Immediate next work session

Continue P2-C: bind verification to the exact original request before integrating it into mobile recovery, and cover every mobile financial mutation. Mobile pending requests now reject changed payloads, destinations, keys, companies, impersonation targets, and login sessions; unchanged retries survive an app restart. Legacy pending records without context remain stored but cannot be automatically retried. Recovery after signing out still requires a reconciliation flow. Header promotion and secure pending-operation persistence are implemented. P2-D retains static OpenAPI regeneration and upload/download/export contract work.
