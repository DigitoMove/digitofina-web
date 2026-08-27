<?php

namespace App\Http\Controllers;

use Illuminate\View\View;

class PricingController extends Controller
{
    public function __invoke(): View
    {
        return view('pricing', [
            'currency' => config('billing.currency', 'UGX'),
            'offers' => config('billing.offers', []),
        ]);
    }
}
