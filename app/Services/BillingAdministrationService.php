<?php

namespace App\Services;

use App\Models\CommercialQuote;
use App\Models\Organization;
use App\Models\OrganizationHostingFee;
use App\Models\OrganizationSubscription;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class BillingAdministrationService
{
    public function __construct(
        private AuditLogger $auditLogger,
        private InAppNotificationService $notifications,
    ) {}

    /** @param array<string, mixed> $data */
    public function assignSubscription(array $data, User $actor): OrganizationSubscription
    {
        return DB::transaction(function () use ($data, $actor): OrganizationSubscription {
            $organization = Organization::query()->whereKey($data['organization_id'])->lockForUpdate()->firstOrFail();
            $startsAt = CarbonImmutable::parse($data['starts_at'])->startOfDay();
            $isPaid = $data['assignment_basis'] === 'paid';

            OrganizationSubscription::query()
                ->where('organization_id', $organization->id)
                ->where('status', 'active')
                ->update(['status' => 'cancelled', 'cancelled_at' => now()]);

            $quote = CommercialQuote::create([
                'organization_id' => $organization->id,
                'requested_by' => null,
                'issued_by' => $actor->id,
                'quote_number' => 'DFA-'.now()->format('ymd').'-'.Str::upper(Str::random(8)),
                'billing_type' => $data['billing_type'],
                'subscription_months' => $data['billing_type'] === 'subscription' ? $data['subscription_months'] : null,
                'amount' => $data['amount'],
                'currency' => 'UGX',
                'requested_scope' => 'Plan assigned directly by DigitoFina Super Admin.',
                'terms' => $data['notes'] ?? null,
                'status' => $isPaid ? 'paid' : 'assigned',
                'valid_until' => null,
                'issued_at' => now(),
                'paid_at' => $isPaid ? now() : null,
            ]);

            $subscription = OrganizationSubscription::create([
                'organization_id' => $organization->id,
                'commercial_quote_id' => $quote->id,
                'billing_type' => $data['billing_type'],
                'status' => 'active',
                'activation_source' => $isPaid ? 'manual_payment' : 'admin_assignment',
                'payment_status' => $isPaid ? 'paid' : 'assigned',
                'amount' => $data['amount'],
                'currency' => 'UGX',
                'provider' => $isPaid ? 'manual-payment' : 'admin-assignment',
                'provider_reference' => 'admin-'.Str::uuid(),
                'assigned_by' => $actor->id,
                'notes' => $data['notes'] ?? null,
                'starts_at' => $startsAt,
                'current_period_ends_at' => $data['billing_type'] === 'subscription' ? $startsAt->addMonthsNoOverflow((int) $data['subscription_months']) : null,
                'activated_at' => now(),
            ]);

            $organization->update(['is_demo' => false, 'demo_started_at' => null, 'demo_expires_at' => null, 'status' => 'active']);
            $this->ensureCurrentHostingFee($organization);
            $this->auditLogger->record('billing.subscription_assigned', $subscription, [
                'organization_id' => $organization->id,
                'billing_type' => $subscription->billing_type,
                'payment_status' => $subscription->payment_status,
            ], actor: $actor);
            $this->notifyOrganizationAdmins($organization, 'DigitoFina plan activated', $isPaid ? 'Your paid DigitoFina plan has been activated.' : 'A DigitoFina plan has been assigned to your organization.');

            return $subscription->load(['organization', 'quote']);
        }, 3);
    }

    public function ensureCurrentHostingFee(Organization $organization): OrganizationHostingFee
    {
        return DB::transaction(function () use ($organization): OrganizationHostingFee {
            $organization = Organization::query()->whereKey($organization->id)->lockForUpdate()->firstOrFail();
            [$periodStart, $periodEnd] = $this->hostingPeriod($organization);
            $existing = OrganizationHostingFee::query()
                ->where('organization_id', $organization->id)
                ->whereDate('period_starts_on', $periodStart)
                ->first();

            if ($existing) {
                return $existing->load('quote');
            }

            $amount = (float) config('billing.hosting.annual_amount', 200000);
            $currency = (string) config('billing.hosting.currency', 'UGX');
            $quote = CommercialQuote::create([
                'organization_id' => $organization->id,
                'requested_by' => null,
                'issued_by' => null,
                'quote_number' => 'DFH-'.now()->format('ymd').'-'.Str::upper(Str::random(8)),
                'billing_type' => 'hosting',
                'amount' => $amount,
                'currency' => $currency,
                'requested_scope' => "Annual hosting fee for {$periodStart->toDateString()} to {$periodEnd->toDateString()}.",
                'terms' => 'Required annual hosting, infrastructure, backups, and service availability fee.',
                'status' => 'issued',
                'valid_until' => $periodEnd,
                'issued_at' => now(),
            ]);

            return OrganizationHostingFee::create([
                'organization_id' => $organization->id,
                'commercial_quote_id' => $quote->id,
                'period_starts_on' => $periodStart,
                'period_ends_on' => $periodEnd,
                'due_on' => $periodStart->addDays((int) config('billing.hosting.payment_due_days', 30))->min($periodEnd),
                'amount' => $amount,
                'currency' => $currency,
                'status' => 'due',
            ])->load('quote');
        }, 3);
    }

    /** @param array<string, mixed> $data */
    public function markHostingFeePaid(OrganizationHostingFee $hostingFee, array $data, User $actor): OrganizationHostingFee
    {
        return DB::transaction(function () use ($hostingFee, $data, $actor): OrganizationHostingFee {
            $hostingFee = OrganizationHostingFee::query()->with(['quote', 'organization'])->whereKey($hostingFee->id)->lockForUpdate()->firstOrFail();
            $paidAt = CarbonImmutable::parse($data['paid_at'] ?? now());
            $reference = $data['provider_reference'] ?? 'manual-hosting-'.Str::uuid();
            $hostingFee->update([
                'status' => 'paid',
                'provider' => 'manual-payment',
                'provider_reference' => $reference,
                'recorded_by' => $actor->id,
                'paid_at' => $paidAt,
                'notes' => $data['notes'] ?? $hostingFee->notes,
            ]);
            $hostingFee->quote?->update(['status' => 'paid', 'paid_at' => $paidAt, 'nylon_payment_status' => 'manual']);
            $this->auditLogger->record('billing.hosting_fee_paid', $hostingFee, ['provider_reference' => $reference], actor: $actor);
            $this->notifyOrganizationAdmins($hostingFee->organization, 'Annual hosting fee paid', 'Your annual UGX 200,000 DigitoFina hosting fee has been marked paid.');

            return $hostingFee->refresh()->load('quote');
        }, 3);
    }

    /** @return array{CarbonImmutable, CarbonImmutable} */
    private function hostingPeriod(Organization $organization): array
    {
        $periodStart = CarbonImmutable::parse($organization->created_at)->startOfDay();

        while ($periodStart->addYear()->lessThanOrEqualTo(today())) {
            $periodStart = $periodStart->addYear();
        }

        return [$periodStart, $periodStart->addYear()->subDay()];
    }

    private function notifyOrganizationAdmins(Organization $organization, string $title, string $message): void
    {
        User::query()
            ->where('organization_id', $organization->id)
            ->where('status', 'active')
            ->whereHas('role', fn ($query) => $query->where('slug', 'organization-admin'))
            ->each(fn (User $user) => $this->notifications->notifyUser($user, $title, $message, route('commercial-quotes.index'), 'billing', 'success'));
    }
}
