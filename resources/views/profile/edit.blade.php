@extends('layouts.app')

@section('content')
<div class="mb-6">
    <h1 class="text-2xl font-semibold text-slate-950">Profile</h1>
    <p class="text-sm text-slate-500">Your staff identity, branch, role, and password.</p>
</div>
<div class="grid gap-5 lg:grid-cols-[1fr_28rem]">
    <section class="rounded border border-slate-200 bg-white p-5 shadow-sm">
        <h2 class="text-lg font-semibold text-slate-950">Staff details</h2>
        <dl class="mt-4 grid gap-3 text-sm">
            <div class="flex justify-between gap-4"><dt class="text-slate-500">Name</dt><dd class="font-medium">{{ $user->name }}</dd></div>
            <div class="flex justify-between gap-4"><dt class="text-slate-500">Email</dt><dd class="font-medium">{{ $user->email }}</dd></div>
            <div class="flex justify-between gap-4"><dt class="text-slate-500">Phone</dt><dd class="font-medium">{{ $user->phone ?? '-' }}</dd></div>
            <div class="flex justify-between gap-4"><dt class="text-slate-500">Role</dt><dd class="font-medium">{{ $user->role?->name ?? '-' }}</dd></div>
            <div class="flex justify-between gap-4"><dt class="text-slate-500">Branch</dt><dd class="font-medium">{{ $user->branch?->name ?? 'Global' }}</dd></div>
            <div class="flex justify-between gap-4"><dt class="text-slate-500">Status</dt><dd class="font-medium">{{ str($user->status)->headline() }}</dd></div>
        </dl>
    </section>
    <form method="POST" action="{{ route('profile.password.update') }}" class="rounded border border-slate-200 bg-white p-5 shadow-sm">
        @csrf
        @method('PUT')
        <h2 class="text-lg font-semibold text-slate-950">Change password</h2>
        <label class="mt-4 block text-sm font-medium text-slate-700">Current password <x-required-badge />
            <input name="current_password" type="password" class="mt-1 w-full rounded border-slate-300" required>
        </label>
        <label class="mt-4 block text-sm font-medium text-slate-700">New password <x-required-badge />
            <input name="password" type="password" class="mt-1 w-full rounded border-slate-300" required>
        </label>
        <label class="mt-4 block text-sm font-medium text-slate-700">Confirm new password <x-required-badge />
            <input name="password_confirmation" type="password" class="mt-1 w-full rounded border-slate-300" required>
        </label>
        <button class="mt-5 w-full rounded bg-blue-700 px-4 py-2.5 text-sm font-semibold text-white hover:bg-blue-800">Update Password</button>
    </form>
</div>
@endsection
