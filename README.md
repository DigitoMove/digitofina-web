# DigitoFina

DigitoFina is a Laravel-based microfinance and banking management system inspired by a teller/cash-drawer banking workflow.

## Stack

- Laravel backend
- MySQL database
- Blade + Tailwind CSS frontend
- Session authentication
- Role and permission tables for Admin, Management, Officer, Cashier, and Member

## Implemented Modules

- Authentication and logout
- Branch-aware users, roles, and permissions
- Member self-service login for savings balances, loan balances, repayment schedules, and recent transactions
- Dashboard cards for clients, accounts, savings, loans, deposits, withdrawals, drawer balance, approvals, and alerts
- Client registration, biodata, KYC status, next of kin, business details, profile, search, edit, activate/deactivate
- Savings, loan, shares, and fixed deposit account types
- Auto-generated client, account, and loan numbers
- Teller drawer opening, cash posting, closing, drawer movements, and receipt display
- Deposit and withdrawal posting through database transactions with drawer and account balance locking
- Loan product setup
- Loan application, approval, loan account creation, disbursement into savings, repayment schedule generation, and repayment allocation service
- Audit logs and basic report screens
- Seeded demo branch, users, client, savings account, loan product, transaction types, and teller drawer

## Important Financial Rules

- Account balances are not edited directly by UI routes.
- Cash deposits increase both account balance and drawer balance.
- Cash withdrawals decrease both account balance and drawer balance.
- Withdrawals validate account funds and drawer cash.
- Posting requires an open drawer session.
- Loan disbursement to savings creates a loan account, credits the client savings account, records loan receivable journal entries, and creates a repayment schedule.
- Loan repayment allocation order is penalties, fees, interest, then principal.

## Setup

```bash
composer install
npm install
cp .env.example .env
php artisan key:generate
```

Configure MySQL in `.env`:

```env
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=digitofina
DB_USERNAME=root
DB_PASSWORD=
```

Run migrations and seed sample data:

```bash
php artisan migrate --seed
npm run build
php artisan serve
```

Demo logins:

- `admin@digitofina.test` / `password`
- `cashier@digitofina.test` / `password`
- `officer@digitofina.test` / `password`
- `management@digitofina.test` / `password`
- `member@digitofina.test` / `password`

## MySQL Key Length Note

Older or constrained MySQL/InnoDB configurations can fail with:

```text
SQLSTATE[42000]: 1071 Specified key was too long
```

DigitoFina sets Laravel's default string length to `191` and uses `varchar(191)` for indexed email fields to avoid that failure.

## Testing

```bash
php artisan test --compact
npm run build
```

## Deployment

See [DEPLOYMENT.md](DEPLOYMENT.md) for production setup, release commands, queue worker notes, and the health-check endpoint.
