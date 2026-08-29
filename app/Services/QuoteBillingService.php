<?php

namespace App\Services;

use App\Models\CommercialQuote;
use App\Models\Organization;
use App\Models\OrganizationHostingFee;
use App\Models\OrganizationSubscription;
use App\Models\User;
use App\Support\CurrencyDenomination;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

class QuoteBillingService
{
    public function __construct(
        private NylonPayClient $nylonPay,
        private AuditLogger $auditLogger,
        private InAppNotificationService $notifications,
    ) {}

    /** @param array{billing_type: string, subscription_months?: int|null, requested_scope: string} $data */
    public function request(Organization $organization, User $requester, array $data): CommercialQuote
    {
        $quote = CommercialQuote::create([
            'organization_id' => $organization->id,
            'requested_by' => $requester->id,
            'quote_number' => 'DFQ-'.now()->format('ymd').'-'.Str::upper(Str::random(8)),
            'billing_type' => $data['billing_type'],
            'subscription_months' => $data['billing_type'] === 'subscription' ? ($data['subscription_months'] ?? 1) : null,
            'currency' => 'UGX',
            'requested_scope' => $data['requested_scope'],
            'status' => 'requested',
        ]);

        $this->auditLogger->record('commercial_quote.requested', $quote, $quote->toArray(), actor: $requester);
        $this->superAdmins()->each(fn (User $user) => $this->notifications->notifyUser(
            $user,
            'New quote request',
            "{$organization->name} requested a DigitoFina quote.",
            route('commercial-quotes.index'),
            'billing',
            'info',
            ['commercial_quote_id' => $quote->id],
        ));

        return $quote;
    }

    /** @param array{billing_type: string, subscription_months?: int|null, amount: numeric-string|int|float, currency: string, valid_until: string, terms?: string|null} $data */
    public function issue(CommercialQuote $quote, User $issuer, array $data): CommercialQuote
    {
        abort_unless(in_array($quote->status, ['requested', 'issued'], true), 422, 'Only requested or unpaid issued quotes can be updated.');
        CurrencyDenomination::assertValid((float) $data['amount'], 'Quote amount');

        $old = $quote->toArray();
        $quote->update([
            'issued_by' => $issuer->id,
            'billing_type' => $data['billing_type'],
            'subscription_months' => $data['billing_type'] === 'subscription' ? ($data['subscription_months'] ?? 1) : null,
            'amount' => $data['amount'],
            'currency' => $data['currency'],
            'terms' => $data['terms'] ?? null,
            'status' => 'issued',
            'valid_until' => $data['valid_until'],
            'issued_at' => now(),
            'nylon_checkout_url' => null,
            'nylon_invoice_id' => null,
            'nylon_payment_status' => null,
        ]);

        $this->auditLogger->record('commercial_quote.issued', $quote, $quote->fresh()->toArray(), $old, $issuer);
        $this->organizationAdmins($quote->organization_id)->each(fn (User $user) => $this->notifications->notifyUser(
            $user,
            'Your DigitoFina quote is ready',
            "Quote {$quote->quote_number} is ready for review and payment.",
            route('commercial-quotes.index'),
            'billing',
            'success',
            ['commercial_quote_id' => $quote->id],
        ));

        return $quote->fresh();
    }

    public function checkout(CommercialQuote $quote): string
    {
        if (! $quote->isPayable()) {
            throw new InvalidArgumentException('This quote is not currently payable.');
        }

        if (filled($quote->nylon_checkout_url)) {
            return $quote->nylon_checkout_url;
        }

        $quote->update([
            'nylon_reference' => $quote->nylon_reference ?: $this->reference($quote),
            'status' => 'payment_pending',
            'payment_initiated_at' => now(),
        ]);

        try {
            $invoice = $this->nylonPay->createInvoice($quote->fresh(), route('commercial-quotes.index', ['payment_return' => $quote->id]));
        } catch (RuntimeException $exception) {
            $quote->update(['status' => 'issued']);

            throw $exception;
        }

        $quote->update([
            'nylon_invoice_id' => $invoice['id'],
            'nylon_checkout_url' => $invoice['url'],
            'nylon_payment_status' => $invoice['status'],
            'payment_metadata' => ['invoice' => $invoice],
        ]);
        $this->auditLogger->record('commercial_quote.payment_initiated', $quote, [
            'reference' => $quote->nylon_reference,
            'provider' => 'nylon-pay',
        ]);

        return $invoice['url'];
    }

    /** @param array<string, mixed> $payload */
    public function processWebhook(array $payload): ?CommercialQuote
    {
        $payment = $this->paymentDetails($payload);

        if ($payment['reference'] === null) {
            return null;
        }

        return DB::transaction(function () use ($payload, $payment): ?CommercialQuote {
            $quote = CommercialQuote::query()
                ->where('nylon_reference', $payment['reference'])
                ->lockForUpdate()
                ->first();

            if (! $quote) {
                return null;
            }

            if ($quote->status === 'paid') {
                return $quote;
            }

            $quote->update([
                'nylon_payment_status' => $payment['status'],
                'payment_metadata' => ['webhook' => $payload],
            ]);

            if ($payment['status'] !== 'successful') {
                return $quote;
            }

            if ($payment['amount'] === null || (float) $payment['amount'] !== (float) $quote->amount || $payment['currency'] !== $quote->currency) {
                throw new InvalidArgumentException('Nylon Pay webhook amount or currency does not match the quote.');
            }

            $this->activate($quote, $payment['provider_reference'] ?: $payment['reference']);

            return $quote->fresh();
        }, 3);
    }

    private function activate(CommercialQuote $quote, string $providerReference): void
    {
        if ($quote->billing_type === 'hosting') {
            $this->activateHostingFee($quote, $providerReference);

            return;
        }

        $startsAt = now();
        $periodEndsAt = $quote->billing_type === 'subscription'
            ? $startsAt->copy()->addMonthsNoOverflow($quote->subscription_months ?: 1)
            : null;

        OrganizationSubscription::firstOrCreate(
            ['commercial_quote_id' => $quote->id],
            [
                'organization_id' => $quote->organization_id,
                'billing_type' => $quote->billing_type,
                'status' => 'active',
                'activation_source' => 'nylon_pay',
                'payment_status' => 'paid',
                'amount' => $quote->amount,
                'currency' => $quote->currency,
                'provider' => 'nylon-pay',
                'provider_reference' => $providerReference,
                'starts_at' => $startsAt,
                'current_period_ends_at' => $periodEndsAt,
                'activated_at' => $startsAt,
            ],
        );

        $quote->update(['status' => 'paid', 'paid_at' => $startsAt]);
        $quote->organization()->update([
            'is_demo' => false,
            'demo_started_at' => null,
            'demo_expires_at' => null,
            'status' => 'active',
        ]);
        $this->auditLogger->record('commercial_quote.paid', $quote, [
            'provider' => 'nylon-pay',
            'reference' => $quote->nylon_reference,
            'amount' => $quote->amount,
            'currency' => $quote->currency,
        ]);
        $this->organizationAdmins($quote->organization_id)->each(fn (User $user) => $this->notifications->notifyUser(
            $user,
            'Payment confirmed',
            "Payment for quote {$quote->quote_number} was confirmed and your DigitoFina access is active.",
            route('commercial-quotes.index'),
            'billing',
            'success',
            ['commercial_quote_id' => $quote->id],
        ));
    }

    private function activateHostingFee(CommercialQuote $quote, string $providerReference): void
    {
        $hostingFee = OrganizationHostingFee::query()
            ->where('commercial_quote_id', $quote->id)
            ->lockForUpdate()
            ->firstOrFail();

        if ($hostingFee->status !== 'paid') {
            $hostingFee->update([
                'status' => 'paid',
                'provider' => 'nylon-pay',
                'provider_reference' => $providerReference,
                'paid_at' => now(),
            ]);
        }

        $quote->update(['status' => 'paid', 'paid_at' => now()]);
        $this->auditLogger->record('billing.hosting_fee_paid', $hostingFee, [
            'provider' => 'nylon-pay',
            'reference' => $providerReference,
        ]);
        $this->organizationAdmins($quote->organization_id)->each(fn (User $user) => $this->notifications->notifyUser(
            $user,
            'Annual hosting fee paid',
            'Your annual DigitoFina hosting fee has been confirmed.',
            route('commercial-quotes.index'),
            'billing',
            'success',
            ['organization_hosting_fee_id' => $hostingFee->id],
        ));
    }

    /** @param array<string, mixed> $payload
     * @return array{reference: ?string, provider_reference: ?string, status: string, amount: mixed, currency: ?string}
     */
    private function paymentDetails(array $payload): array
    {
        $data = is_array($payload['data'] ?? null) ? $payload['data'] : $payload;
        $transaction = is_array($data['transaction'] ?? null) ? $data['transaction'] : $data;
        $event = Str::lower((string) ($payload['event'] ?? ''));
        $status = Str::lower((string) ($transaction['status'] ?? $data['status'] ?? $event));

        return [
            'reference' => $transaction['reference'] ?? $data['reference'] ?? $payload['reference'] ?? null,
            'provider_reference' => $transaction['id'] ?? $data['id'] ?? null,
            'status' => in_array($status, ['success', 'successful', 'paid', 'completed'], true) ? 'successful' : $status,
            'amount' => $transaction['amount'] ?? $data['amount'] ?? null,
            'currency' => isset($transaction['currency']) ? Str::upper((string) $transaction['currency']) : (isset($data['currency']) ? Str::upper((string) $data['currency']) : null),
        ];
    }

    /** @return Collection<int, User> */
    private function superAdmins(): Collection
    {
        return User::query()
            ->where('status', 'active')
            ->whereHas('role', fn (Builder $query): Builder => $query->where('slug', 'super-admin'))
            ->get();
    }

    /** @return Collection<int, User> */
    private function organizationAdmins(int $organizationId): Collection
    {
        return User::query()
            ->where('organization_id', $organizationId)
            ->where('status', 'active')
            ->whereHas('role', fn (Builder $query): Builder => $query->where('slug', 'organization-admin'))
            ->get();
    }

    private function reference(CommercialQuote $quote): string
    {
        return 'dfq'.str_pad((string) $quote->id, 10, '0', STR_PAD_LEFT);
    }
}
