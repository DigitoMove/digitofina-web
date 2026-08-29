<?php

namespace App\Services;

use App\Models\Client;
use App\Models\Loan;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class MemberLoanApplicationService
{
    public function __construct(
        private LoanApplicationService $applications,
        private AuditLogger $auditLogger,
    ) {}

    /** @param array<string, mixed> $data */
    public function submit(User $member, array $data): Loan
    {
        return DB::transaction(function () use ($member, $data): Loan {
            $client = Client::query()->with('officer.role')->where('user_id', $member->id)->lockForUpdate()->firstOrFail();

            if ($client->status !== 'active' || $client->kyc_status !== 'verified') {
                throw ValidationException::withMessages(['loan' => 'Only active, approved clients can request a loan online.']);
            }

            if ($client->loans()->whereNotIn('status', ['fully_paid', 'closed', 'rejected', 'cancelled'])->exists()) {
                throw ValidationException::withMessages(['loan' => 'You already have an unpaid loan or an application in progress.']);
            }

            $officer = $this->loanOfficer($client);
            $loan = $this->applications->submit($data + [
                'client_id' => $client->id,
                'loan_type' => 'standard',
                'application_date' => today()->toDateString(),
            ], $officer);

            $this->auditLogger->record('loan.client_requested', $loan, [
                'client_id' => $client->id,
                'member_user_id' => $member->id,
                'channel' => 'client_portal',
            ], actor: $member);

            return $loan;
        }, 3);
    }

    private function loanOfficer(Client $client): User
    {
        $officer = $client->officer;

        if ($officer?->is_active && $officer->status === 'active' && in_array($officer->role?->slug, ['loan-officer', 'branch-manager'], true)) {
            return $officer;
        }

        $officer = User::query()
            ->where('organization_id', $client->organization_id)
            ->where('branch_id', $client->branch_id)
            ->where('is_active', true)
            ->where('status', 'active')
            ->whereHas('role', fn ($query) => $query->whereIn('slug', ['loan-officer', 'branch-manager']))
            ->oldest('id')
            ->first();

        if (! $officer) {
            throw ValidationException::withMessages(['loan' => 'No Loan Officer is available for your branch. Please contact the organization.']);
        }

        return $officer;
    }
}
