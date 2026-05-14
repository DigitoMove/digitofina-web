<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreLoanRepaymentRequest;
use App\Http\Resources\LoanRepaymentResource;
use App\Models\Loan;
use App\Services\LoanRepaymentService;
use App\Support\ApiResponse;
use App\Support\ApiSecurity;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;

class LoanRepaymentController extends Controller
{
    public function store(StoreLoanRepaymentRequest $request, Loan $loan, LoanRepaymentService $repayments): JsonResponse
    {
        ApiSecurity::assertTenantAccess($request->user(), $loan);
        $data = $request->validated();
        $repayment = $repayments->repay(
            $loan,
            (float) $data['amount'],
            $request->user(),
            null,
            $data['payment_method'],
            $data['reference'] ?? null,
            Carbon::parse($data['paid_at'])
        );

        return ApiResponse::resource(new LoanRepaymentResource($repayment->load('receivedBy')), 'Loan repayment posted successfully.', 201);
    }
}
