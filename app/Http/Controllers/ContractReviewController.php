<?php

namespace App\Http\Controllers;

use App\Models\OrganizationContract;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ContractReviewController extends Controller
{
    public function show(OrganizationContract $contract, string $token): View
    {
        abort_unless(hash_equals($contract->public_token, $token), 404);

        return view('contracts.review', compact('contract'));
    }

    public function comment(Request $request, OrganizationContract $contract, string $token): RedirectResponse
    {
        abort_unless(hash_equals($contract->public_token, $token), 404);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['nullable', 'email', 'max:190'],
            'comment' => ['required', 'string', 'max:5000'],
        ]);
        $contract->comments()->create($data);
        $contract->update(['status' => 'changes_requested', 'reviewed_at' => now()]);

        return back()->with('status', 'Your comments were submitted for review.');
    }
}
