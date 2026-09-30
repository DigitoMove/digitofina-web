<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\CashierTransactionRequest;
use App\Http\Resources\CashierTransactionResource;
use App\Http\Resources\DrawerMovementResource;
use App\Models\Account;
use App\Models\ChartAccount;
use App\Models\DrawerSession;
use App\Models\TellerDrawer;
use App\Models\Transaction;
use App\Models\TransactionType;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\FinancialControlService;
use App\Services\LedgerChargeService;
use App\Services\ModuleService;
use App\Services\TellerService;
use App\Services\TransactionPostingService;
use App\Services\TransactionPreviewService;
use App\Support\ApiResponse;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class CashierWorkspaceController extends Controller
{
    private function cashier(Request $request): User
    {
        $user = $request->user();
        abort_unless($user->isTellerCashier() && $user->organization_id && $user->branch_id, 403);

        return $user;
    }

    private function sessionsQuery(User $user): Builder
    {
        return DrawerSession::query()->with('drawer.branch')->where('user_id', $user->id)
            ->whereHas('drawer', fn (Builder $query): Builder => $query->where('organization_id', $user->organization_id)->where('branch_id', $user->branch_id));
    }

    private function session(Request $request, int $id, bool $open = false): DrawerSession
    {
        $session = $this->sessionsQuery($this->cashier($request))->findOrFail($id);
        abort_if($open && ($session->status !== 'open' || $session->drawer->status !== 'open'), 422, 'This drawer session is closed. Refresh your workspace.');

        return $session;
    }

    /** @return array<string, mixed> */
    private function sessionData(DrawerSession $session): array
    {
        $totals = $session->movements()->selectRaw('type, SUM(amount) as total')->groupBy('type')->pluck('total', 'type');
        $cashIn = (float) ($totals['cash_in'] ?? 0);
        $cashOut = (float) ($totals['cash_out'] ?? 0);

        return $session->only(['id', 'status', 'opening_balance', 'closing_balance', 'variance']) + [
            'opened_at' => $session->opened_at?->toIso8601String(),
            'closed_at' => $session->closed_at?->toIso8601String(),
            'drawer' => $session->drawer->only(['id', 'name', 'code', 'currency', 'current_balance']),
            'branch' => $session->drawer->branch?->name,
            'cash_in' => $cashIn,
            'cash_out' => $cashOut,
            'expected_balance' => (float) $session->opening_balance + $cashIn - $cashOut,
        ];
    }

    public function dashboard(Request $request): JsonResponse
    {
        $user = $this->cashier($request);
        $session = $this->sessionsQuery($user)->where('status', 'open')->latest('id')->first();

        return ApiResponse::data([
            'session' => $session ? $this->sessionData($session) : null,
            'drawers' => TellerDrawer::query()->where('organization_id', $user->organization_id)->where('branch_id', $user->branch_id)
                ->where('assigned_user_id', $user->id)->get(['id', 'name', 'code', 'currency', 'current_balance', 'status']),
            'can_close' => false,
            'can_post_ledger' => $user->canPerform('post-transactions'),
            'business_date' => today()->toDateString(),
            'transaction_types' => TransactionType::query()->where('is_active', true)
                ->get(['id', 'name', 'code', 'direction'])->filter(fn (TransactionType $type): bool => $user->canPerform($type->code === 'loan_repayment' ? 'post-repayments' : 'post-transactions'))->values(),
        ]);
    }

    public function open(Request $request, TellerService $tellers, FinancialControlService $controls, AuditLogger $audit): JsonResponse
    {
        $user = $this->cashier($request);
        $data = $request->validate(['drawer_id' => ['required', 'integer', 'min:1'], 'idempotency_key' => ['required', 'uuid']]);
        try {
            $session = DB::transaction(function () use ($user, $data, $tellers, $controls, $audit): DrawerSession {
                User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
                $drawer = TellerDrawer::query()->where('organization_id', $user->organization_id)->where('branch_id', $user->branch_id)
                    ->where('assigned_user_id', $user->id)->lockForUpdate()->findOrFail($data['drawer_id']);
                $controls->ensureFinancialDayOpen($user, (int) $drawer->branch_id, today());
                $session = $tellers->openDrawer($drawer, $user, (float) $drawer->current_balance, $user);
                $audit->record('drawer.opened', $session, ['opened_by' => $user->id, 'source' => 'mobile']);

                return $session->load('drawer.branch');
            });

            return ApiResponse::data($this->sessionData($session), 'Drawer opened.', 201);
        } catch (InvalidArgumentException $exception) {
            return ApiResponse::error($exception->getMessage(), [], 422);
        }
    }

    public function sessions(Request $request): JsonResponse
    {
        $request->validate(['page' => ['nullable', 'integer', 'min:1']]);
        $sessions = $this->sessionsQuery($this->cashier($request))->latest('id')->paginate(20);

        return ApiResponse::data($sessions->getCollection()->map(fn (DrawerSession $session): array => $this->sessionData($session)), meta: [
            'pagination' => ['current_page' => $sessions->currentPage(), 'last_page' => $sessions->lastPage(), 'total' => $sessions->total()],
        ]);
    }

    public function showSession(Request $request, int $id): JsonResponse
    {
        return ApiResponse::data($this->sessionData($this->session($request, $id)));
    }

    private function accountsQuery(User $user, DrawerSession $session): Builder
    {
        return Account::query()->withoutGlobalScope('tenant')->with(['accountType', 'client' => fn (BelongsTo $query): BelongsTo => $query->withoutGlobalScope('tenant')->where('organization_id', $user->organization_id)])
            ->where('organization_id', $user->organization_id)->where('status', 'active')
            ->where(function (Builder $query) use ($user, $session): void {
                $query->where('branch_id', $session->drawer->branch_id);
                if ($user->canPerform('transact-savings-across-branches')) {
                    $query->orWhereHas('accountType', fn (Builder $query): Builder => $query->where('category', 'savings'));
                } else {
                    $query->orWhere(fn (Builder $query): Builder => $query->organizationWideSavings());
                }
            });
    }

    public function lookup(Request $request): JsonResponse
    {
        $user = $this->cashier($request);
        $data = $request->validate(['drawer_session_id' => ['required', 'integer', 'min:1'], 'search' => ['required', 'string', 'min:2', 'max:100'], 'scope' => ['required', 'in:client_account,general_ledger'], 'category' => ['nullable', 'in:savings,loan']]);
        $session = $this->session($request, $data['drawer_session_id'], true);
        $search = '%'.$data['search'].'%';
        if ($data['scope'] === 'general_ledger') {
            abort_unless($user->canPerform('post-transactions'), 403);
            $rows = $this->ledgersQuery($user, $session)->whereAny(['name', 'code'], 'like', $search)->orderBy('code')->limit(30)
                ->get(['id', 'code', 'name', 'type', 'drawer_direction']);
        } else {
            $rows = $this->accountsQuery($user, $session)
                ->when($data['category'] ?? null, fn (Builder $query, string $category): Builder => $query->whereHas('accountType', fn (Builder $query): Builder => $query->where('category', $category)))
                ->where(function (Builder $query) use ($search, $user): void {
                    $query->whereAny(['account_number', 'account_name'], 'like', $search)
                        ->orWhereHas('client', fn (Builder $query): Builder => $query->withoutGlobalScope('tenant')->where('organization_id', $user->organization_id)->whereAny(['client_number', 'first_name', 'last_name', 'business_name', 'phone'], 'like', $search));
                })->orderBy('account_number')->limit(30)->get()->map(fn (Account $account): array => [
                    'id' => $account->id, 'account_number' => $account->account_number, 'name' => $account->account_name ?: $account->client?->full_name,
                    'category' => $account->accountType?->category, 'balance' => $account->balance,
                    'client_id' => $account->client_id, 'client_name' => $account->client?->full_name,
                    'client_number' => $account->client?->client_number, 'client_phone' => $account->client?->phone,
                    'currency' => $session->drawer->currency,
                ]);
        }

        return ApiResponse::data($rows, 'Up to 30 matches. Refine your search if needed.');
    }

    private function ledgersQuery(User $user, DrawerSession $session): Builder
    {
        return ChartAccount::query()->where('organization_id', $user->organization_id)->where('is_active', true)->where('is_cash_account', false)
            ->whereIn('type', ['expense', 'income', 'asset', 'liability'])
            ->where(fn (Builder $query): Builder => $query->whereNull('drawer_direction')->orWhere('drawer_direction', '!=', 'none'))
            ->where(fn (Builder $query): Builder => $query->where('branch_id', $session->drawer->branch_id)->orWhereNull('branch_id'));
    }

    public function preview(CashierTransactionRequest $request, TransactionPreviewService $previews): JsonResponse
    {
        $this->session($request, $request->integer('drawer_session_id'), true);
        try {
            return ApiResponse::data($previews->preview(['kind' => 'teller'] + $request->validated(), $request->user()))->header('Cache-Control', 'no-store, private');
        } catch (InvalidArgumentException $exception) {
            return ApiResponse::error($exception->getMessage(), [], 422);
        }
    }

    public function post(CashierTransactionRequest $request, TransactionPostingService $posting): JsonResponse
    {
        $user = $this->cashier($request);
        $data = $request->validated();
        $session = $this->session($request, $data['drawer_session_id'], true);
        $date = CarbonImmutable::parse($data['transaction_date']);
        try {
            if ($data['account_scope'] === 'general_ledger') {
                abort_unless($user->canPerform('post-transactions'), 403);
                $ledger = $this->ledgersQuery($user, $session)->findOrFail($data['chart_account_id']);
                $entry = in_array($ledger->drawer_direction, ['cash_in', 'cash_out'], true) ? $ledger->drawerEntryType($ledger->drawer_direction) : ($data['gl_entry_type'] ?? null);
                if (! $entry) {
                    throw new InvalidArgumentException('Select whether money is coming in or going out.');
                }
                $journal = $posting->postGeneralLedgerCash($ledger, $session, $entry, (float) $data['amount'], $user, $data['description'] ?? null, $date, (float) ($data['charges'] ?? 0), LedgerChargeService::normalizeMode($data['charge_mode'] ?? null));
                $transaction = $this->transactionsQuery($session)->findOrFail($journal->transaction_id);
            } else {
                $account = $this->accountsQuery($user, $session)->where('account_number', $data['account_number'])->firstOrFail();
                $type = TransactionType::query()->where('is_active', true)->findOrFail($data['transaction_type_id']);
                abort_unless($user->canPerform($type->code === 'loan_repayment' ? 'post-repayments' : 'post-transactions'), 403);
                if ($type->code === 'loan_repayment') {
                    abort_unless(app(ModuleService::class)->isEnabled($user->organization, 'loans'), 403);
                }
                $transaction = $posting->postCash($account, $session, $type, (float) $data['amount'], $user, $data['description'] ?? null, $data['cheque_number'] ?? null, $date, (float) ($data['charges'] ?? 0));
            }

            return ApiResponse::resource(new CashierTransactionResource($this->transactionsQuery($session)->findOrFail($transaction->id)), 'Transaction posted.', 201);
        } catch (InvalidArgumentException $exception) {
            return ApiResponse::error($exception->getMessage(), [], 422);
        }
    }

    private function transactionsQuery(DrawerSession $session): Builder
    {
        return Transaction::query()->withoutGlobalScope('tenant')->where('organization_id', $session->drawer->organization_id)
            ->where(fn (Builder $query): Builder => $query->where('drawer_session_id', $session->id)
                ->orWhereHas('drawerMovements', fn (Builder $query): Builder => $query->where('drawer_session_id', $session->id)))
            ->with(['account' => fn ($query) => $query->withoutGlobalScope('tenant'), 'type', 'user',
                'drawerMovements' => fn ($query) => $query->where('drawer_session_id', $session->id)]);
    }

    public function transactions(Request $request, int $id): JsonResponse
    {
        $data = $request->validate(['page' => ['nullable', 'integer', 'min:1'], 'search' => ['nullable', 'string', 'max:100']]);
        $session = $this->session($request, $id);
        $query = $this->transactionsQuery($session);
        if ($search = $data['search'] ?? null) {
            $query->whereAny(['reference', 'description'], 'like', '%'.$search.'%');
        }

        return ApiResponse::collection(CashierTransactionResource::collection($query->latest('posted_at')->orderByDesc('id')->paginate(20)));
    }

    public function receipt(Request $request, int $id, int $transaction): JsonResponse
    {
        return ApiResponse::resource(new CashierTransactionResource($this->transactionsQuery($this->session($request, $id))->findOrFail($transaction)));
    }

    public function movements(Request $request, int $id): JsonResponse
    {
        $request->validate(['page' => ['nullable', 'integer', 'min:1']]);

        return ApiResponse::collection(DrawerMovementResource::collection($this->session($request, $id)->movements()->latest('id')->paginate(20)));
    }
}
