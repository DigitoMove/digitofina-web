<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreLoanGuarantorRequest;
use App\Models\Loan;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

class LoanGuarantorController extends Controller
{
    public function store(StoreLoanGuarantorRequest $request, Loan $loan, AuditLogger $auditLogger): RedirectResponse
    {
        $guarantor = $loan->loanGuarantors()->create($request->validated() + [
            'organization_id' => $loan->organization_id,
        ]);

        if ($loan->status === 'pending_guarantor_approval') {
            $loan->update(['status' => $loan->product->require_collateral ? 'pending_collateral_verification' : 'submitted']);
        }

        $auditLogger->record('loan.guarantor_added', $guarantor, $guarantor->toArray());

        return back()->with('status', 'Guarantor added.');
    }

    public function updateStatus(Loan $loan, int $guarantor, AuditLogger $auditLogger): RedirectResponse
    {
        Gate::authorize('view', $loan);
        abort_unless(auth()->user()->canPerform('manage-loans'), 403);

        $data = request()->validate(['status' => ['required', 'in:pending,accepted,rejected,released']]);
        $record = $loan->loanGuarantors()->whereKey($guarantor)->firstOrFail();
        $old = $record->toArray();
        $record->update($data);
        $auditLogger->record('loan.guarantor_status_updated', $record, $record->fresh()->toArray(), $old);

        return back()->with('status', 'Guarantor status updated.');
    }
}
