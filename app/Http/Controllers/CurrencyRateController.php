<?php

namespace App\Http\Controllers;

use App\CurrencyRateService;
use App\CurrencyRegistry;
use App\Models\CurrencyRate;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class CurrencyRateController extends Controller
{
    public function index(Request $request): View
    {
        abort_unless($request->user()->hasRole('super-admin'), 403);

        return view('currency-rates.index', [
            'currencies' => CurrencyRegistry::all(),
            'rates' => CurrencyRate::query()->orderBy('base_currency')->orderBy('quote_currency')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless($request->user()->hasRole('super-admin'), 403);
        $data = $request->validate([
            'base_currency' => ['required', 'string', 'size:3'],
            'quote_currency' => ['required', 'string', 'size:3', 'different:base_currency'],
            'rate' => ['required', 'numeric', 'gt:0'],
        ]);
        abort_unless(CurrencyRegistry::supports($data['base_currency']) && CurrencyRegistry::supports($data['quote_currency']), 422);
        CurrencyRate::query()->updateOrCreate(
            ['base_currency' => strtoupper($data['base_currency']), 'quote_currency' => strtoupper($data['quote_currency'])],
            ['rate' => $data['rate'], 'source' => 'manual', 'fetched_at' => null],
        );

        return back()->with('status', 'Exchange rate saved.');
    }

    public function refresh(Request $request, CurrencyRateService $rates): RedirectResponse
    {
        abort_unless($request->user()->hasRole('super-admin'), 403);
        $base = strtoupper((string) $request->input('base_currency', 'UGX'));
        abort_unless(CurrencyRegistry::supports($base), 422);
        $count = $rates->refresh($base);

        return back()->with('status', "{$count} current exchange rates refreshed for {$base}.");
    }
}
