# DigitoFina Operations and Reporting Direction

DigitoFina should be treated as a microfinance banking core with two experiences:

- Staff operations: Admin, Management, Officer, and Cashier users operate clients, accounts, loans, cash drawers, approvals, and reports.
- Member self-service: ordinary account holders log in to view balances, savings history, loan status, repayment schedules, and receipts.

## Product Directions

### 1. Branch Operations Core

Best when the first goal is reliable daily branch work.

- Strong teller drawer workflows
- Cash vault transfers and cash reconciliation
- Client onboarding and KYC
- Account opening and statement generation
- Daily teller and branch reports

### 2. Loan-Centered Microfinance Core

Best when lending is the main business driver.

- Loan appraisal scoring
- Multi-level approval queues
- Disbursement controls
- Repayment schedule automation
- Arrears, PAR, aging, and officer performance reports

### 3. Member Digital Portal

Best when member transparency and retention matter.

- Member login
- Savings and loan progress
- Repayment reminders
- Downloadable statements and receipts
- Payment channel integrations

### 4. Accounting and Compliance Core

Best when management needs accurate books and auditability.

- Chart of Accounts-backed double-entry journals
- General ledger
- Trial balance
- Income and expense reports
- Full audit logs and reversal approvals

## Data Flow

### Client Onboarding

1. Officer registers client biodata and KYC details.
2. System creates a client number.
3. Optional member login is linked to the client.
4. Management verifies KYC.
5. Officer opens savings, shares, fixed deposit, or loan accounts.

### Cash Deposit

1. Cashier opens a drawer.
2. Cashier searches active account.
3. Cashier posts deposit.
4. System locks account and drawer rows.
5. Account balance increases.
6. Drawer balance increases.
7. Transaction, drawer movement, receipt, and audit log are created.

### Cash Withdrawal

1. Cashier opens a drawer.
2. Cashier searches active account.
3. System validates account balance and drawer cash.
4. Account balance decreases.
5. Drawer balance decreases.
6. Transaction, drawer movement, receipt, and audit log are created.

### Loan Application to Disbursement

1. Officer submits loan application.
2. Officer appraises savings history, income, collateral, guarantors, and risk.
3. Management approves or rejects the loan.
4. Approved loan creates or activates a loan account.
5. Disbursement credits the member savings account.
6. Loan receivable is recorded in the loan account and journal entries.
7. Repayment schedule is generated.

### Loan Repayment

1. Cashier or payment channel posts repayment.
2. Repayment is allocated to penalties, fees, interest, then principal.
3. Loan balances and repayment schedules update.
4. Journal entries, receipt, and audit log are created.
5. Loan closes when principal, interest, fees, and penalties are cleared.

## Fully Required Reports

- Daily teller report
- Drawer reconciliation report
- Client register report
- Account balances report
- Cash transactions report
- Non-cash transactions report
- Loan applications report
- Approved loans report
- Disbursed loans report
- Active loans report
- Loan arrears report
- PAR report
- Loan aging report
- Loan portfolio report
- Loan repayment report
- Loan officer performance report
- Income report
- Expenses report
- Trial balance
- General ledger
- Audit logs
