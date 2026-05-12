<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreLoanCollateralRequest;
use App\Models\Loan;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;

class LoanCollateralController extends Controller
{
    public function store(StoreLoanCollateralRequest $request, Loan $loan, AuditLogger $auditLogger): RedirectResponse
    {
        $data = $request->validated();
        $collateral = $loan->collaterals()->create($data + [
            'organization_id' => $loan->organization_id,
            'client_id' => $loan->client_id,
            'valued_by' => auth()->id(),
            'verified_by' => $data['status'] === 'verified' ? auth()->id() : null,
        ]);

        if ($loan->status === 'pending_collateral_verification') {
            $loan->update(['status' => 'submitted']);
        }

        $auditLogger->record('loan.collateral_added', $collateral, $collateral->toArray());

        return back()->with('status', 'Collateral recorded.');
    }

    public function updateStatus(Loan $loan, int $collateral, AuditLogger $auditLogger): RedirectResponse
    {
        abort_unless(auth()->user()->canPerform('manage-loans'), 403);

        $data = request()->validate(['status' => ['required', 'in:pending_verification,verified,rejected,released,seized']]);
        $record = $loan->collaterals()->whereKey($collateral)->firstOrFail();
        $old = $record->toArray();
        $record->update($data + ['verified_by' => $data['status'] === 'verified' ? auth()->id() : $record->verified_by]);
        $auditLogger->record('loan.collateral_status_updated', $record, $record->fresh()->toArray(), $old);

        return back()->with('status', 'Collateral status updated.');
    }
}
