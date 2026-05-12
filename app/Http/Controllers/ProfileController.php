<?php

namespace App\Http\Controllers;

use App\Http\Requests\ChangePasswordRequest;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Hash;
use Illuminate\View\View;

class ProfileController extends Controller
{
    public function edit(): View
    {
        return view('profile.edit', ['user' => auth()->user()->load(['branch', 'role'])]);
    }

    public function updatePassword(ChangePasswordRequest $request, AuditLogger $auditLogger): RedirectResponse
    {
        $request->user()->update(['password' => Hash::make($request->validated('password'))]);
        $auditLogger->record('auth.password_changed', $request->user());

        return redirect()->route('profile.edit')->with('status', 'Password changed.');
    }
}
