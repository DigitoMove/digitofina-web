<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreMemberLoanApplicationRequest;
use App\Services\MemberLoanApplicationService;
use Illuminate\Http\RedirectResponse;

class MemberLoanApplicationController extends Controller
{
    public function store(StoreMemberLoanApplicationRequest $request, MemberLoanApplicationService $applications): RedirectResponse
    {
        $loan = $applications->submit($request->user(), $request->validated());

        return redirect()->route('member.dashboard')->with('status', "Loan request {$loan->loan_number} was sent to your Loan Officer.");
    }
}
