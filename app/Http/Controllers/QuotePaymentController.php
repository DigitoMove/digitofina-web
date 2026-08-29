<?php

namespace App\Http\Controllers;

use App\Models\CommercialQuote;
use App\Services\QuoteBillingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use RuntimeException;

class QuotePaymentController extends Controller
{
    public function store(Request $request, CommercialQuote $commercialQuote, QuoteBillingService $billing): RedirectResponse
    {
        Gate::authorize('pay', $commercialQuote);

        try {
            return redirect()->away($billing->checkout($commercialQuote));
        } catch (InvalidArgumentException|RuntimeException $exception) {
            throw ValidationException::withMessages(['payment' => $exception->getMessage()]);
        }
    }
}
