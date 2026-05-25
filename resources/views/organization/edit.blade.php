@extends('layouts.app')

@section('content')
<div class="mb-6">
    <h1 class="text-2xl font-semibold text-slate-950">Organization Setup</h1>
    <p class="text-sm text-slate-500">Institution profile, currency, financial year, and operating status.</p>
</div>

@php
    $canUpdateOrganization = $canUpdateOrganization ?? false;
    $organizationFormAction = ($isCurrentOrganization ?? false)
        ? route('organization.current.update')
        : route('organization.update', $organization);
@endphp

@unless ($canUpdateOrganization)
    <div class="mb-4 rounded border border-amber-200 bg-amber-50 px-4 py-3 text-sm font-medium text-amber-800">
        You can view this organization profile. Ask a system administrator to change these details.
    </div>
@endunless

<form method="POST" action="{{ $organizationFormAction }}" class="grid gap-6 xl:grid-cols-[1fr_22rem]">
    @csrf
    @method('PUT')
    <section class="rounded border border-slate-200 bg-white p-5 shadow-sm">
        <div class="grid gap-4 md:grid-cols-2">
            <label class="block text-sm font-medium text-slate-700">Organization name <x-required-badge />
                <input name="name" value="{{ old('name', $organization->name) }}" class="mt-1 w-full rounded border-slate-300 disabled:bg-slate-100 disabled:text-slate-500" required @disabled(! $canUpdateOrganization)>
            </label>
            <label class="block text-sm font-medium text-slate-700">Slug <x-required-badge />
                <input name="slug" value="{{ old('slug', $organization->slug) }}" class="mt-1 w-full rounded border-slate-300 disabled:bg-slate-100 disabled:text-slate-500" required @disabled(! $canUpdateOrganization)>
            </label>
            <label class="block text-sm font-medium text-slate-700">Registration number
                <input name="registration_number" value="{{ old('registration_number', $organization->registration_number) }}" class="mt-1 w-full rounded border-slate-300 disabled:bg-slate-100 disabled:text-slate-500" @disabled(! $canUpdateOrganization)>
            </label>
            <label class="block text-sm font-medium text-slate-700">Tax identification number
                <input name="tax_identification_number" value="{{ old('tax_identification_number', $organization->tax_identification_number) }}" class="mt-1 w-full rounded border-slate-300 disabled:bg-slate-100 disabled:text-slate-500" @disabled(! $canUpdateOrganization)>
            </label>
            <label class="block text-sm font-medium text-slate-700">Phone
                <input name="phone" value="{{ old('phone', $organization->phone) }}" class="mt-1 w-full rounded border-slate-300 disabled:bg-slate-100 disabled:text-slate-500" @disabled(! $canUpdateOrganization)>
            </label>
            <label class="block text-sm font-medium text-slate-700">Email
                <input name="email" value="{{ old('email', $organization->email) }}" class="mt-1 w-full rounded border-slate-300 disabled:bg-slate-100 disabled:text-slate-500" @disabled(! $canUpdateOrganization)>
            </label>
            <label class="block text-sm font-medium text-slate-700">Currency <x-required-badge />
                <input name="currency" value="{{ old('currency', $organization->currency) }}" class="mt-1 w-full rounded border-slate-300 disabled:bg-slate-100 disabled:text-slate-500" required @disabled(! $canUpdateOrganization)>
            </label>
            <label class="block text-sm font-medium text-slate-700">Financial year starts
                <select name="financial_year_start_month" class="mt-1 w-full rounded border-slate-300 disabled:bg-slate-100 disabled:text-slate-500" @disabled(! $canUpdateOrganization)>
                    @foreach (['january','february','march','april','may','june','july','august','september','october','november','december'] as $month)
                        <option value="{{ $month }}" @selected(old('financial_year_start_month', $organization->financial_year_start_month) === $month)>{{ str($month)->headline() }}</option>
                    @endforeach
                </select>
            </label>
            <label class="block text-sm font-medium text-slate-700 md:col-span-2">Address
                <textarea name="address" class="mt-1 w-full rounded border-slate-300 disabled:bg-slate-100 disabled:text-slate-500" rows="3" @disabled(! $canUpdateOrganization)>{{ old('address', $organization->address) }}</textarea>
            </label>
        </div>
    </section>
    <aside class="rounded border border-slate-200 bg-white p-5 shadow-sm">
        <label class="block text-sm font-medium text-slate-700">Status
            <select name="status" class="mt-1 w-full rounded border-slate-300 disabled:bg-slate-100 disabled:text-slate-500" @disabled(! $canUpdateOrganization)>
                @foreach (['active', 'suspended', 'disabled'] as $status)
                    <option value="{{ $status }}" @selected(old('status', $organization->status) === $status)>{{ str($status)->headline() }}</option>
                @endforeach
            </select>
        </label>
        @if ($canUpdateOrganization)
            <button class="mt-5 w-full rounded bg-blue-700 px-4 py-2.5 text-sm font-semibold text-white hover:bg-blue-800">Save organization</button>
        @endif
    </aside>
</form>
@endsection
