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
    $organizationLogo = old('logo', $organization->logo);
    $organizationLogoUrl = filled($organizationLogo)
        ? (\Illuminate\Support\Str::startsWith($organizationLogo, ['http://', 'https://', '/'])
            ? $organizationLogo
            : \Illuminate\Support\Facades\Storage::disk('public')->url($organizationLogo))
        : asset('brand/digitofina-mark.svg');
    $socialLinks = old('social_links', $organization->social_links ?? []);
@endphp

@unless ($canUpdateOrganization)
    <div class="mb-4 rounded border border-amber-200 bg-amber-50 px-4 py-3 text-sm font-medium text-amber-800">
        You can view this organization profile. Ask a system administrator to change these details.
    </div>
@endunless

<form method="POST" action="{{ $organizationFormAction }}" enctype="multipart/form-data" class="grid gap-6 xl:grid-cols-[1fr_22rem]">
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
            <label class="block text-sm font-medium text-slate-700">Website
                <input name="website" type="url" value="{{ old('website', $organization->website) }}" class="mt-1 w-full rounded border-slate-300 disabled:bg-slate-100 disabled:text-slate-500" placeholder="https://example.com" @disabled(! $canUpdateOrganization)>
            </label>
            <label class="block text-sm font-medium text-slate-700">Currency <x-required-badge />
                <select name="currency" class="mt-1 w-full rounded border-slate-300 disabled:bg-slate-100 disabled:text-slate-500" required @disabled(! $canUpdateOrganization)>
                    @foreach($currencies as $code => $currency)
                        <option value="{{ $code }}" @selected(old('currency', $organization->currency) === $code)>{{ $code }} · {{ $currency['name'] }}</option>
                    @endforeach
                </select>
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
            <div class="md:col-span-2">
                <h2 class="text-sm font-semibold uppercase tracking-wide text-slate-500">Social links</h2>
                <div class="mt-3 grid gap-4 md:grid-cols-2">
                    <label class="block text-sm font-medium text-slate-700">Facebook
                        <input name="social_links[facebook]" type="url" value="{{ $socialLinks['facebook'] ?? '' }}" class="mt-1 w-full rounded border-slate-300 disabled:bg-slate-100 disabled:text-slate-500" placeholder="https://facebook.com/..." @disabled(! $canUpdateOrganization)>
                    </label>
                    <label class="block text-sm font-medium text-slate-700">X / Twitter
                        <input name="social_links[x]" type="url" value="{{ $socialLinks['x'] ?? '' }}" class="mt-1 w-full rounded border-slate-300 disabled:bg-slate-100 disabled:text-slate-500" placeholder="https://x.com/..." @disabled(! $canUpdateOrganization)>
                    </label>
                    <label class="block text-sm font-medium text-slate-700">LinkedIn
                        <input name="social_links[linkedin]" type="url" value="{{ $socialLinks['linkedin'] ?? '' }}" class="mt-1 w-full rounded border-slate-300 disabled:bg-slate-100 disabled:text-slate-500" placeholder="https://linkedin.com/company/..." @disabled(! $canUpdateOrganization)>
                    </label>
                    <label class="block text-sm font-medium text-slate-700">WhatsApp
                        <input name="social_links[whatsapp]" value="{{ $socialLinks['whatsapp'] ?? '' }}" class="mt-1 w-full rounded border-slate-300 disabled:bg-slate-100 disabled:text-slate-500" placeholder="+256..." @disabled(! $canUpdateOrganization)>
                    </label>
                </div>
            </div>
        </div>
    </section>
    <aside class="rounded border border-slate-200 bg-white p-5 shadow-sm">
        <div class="mb-5">
            <div class="text-sm font-semibold text-slate-700">Organization logo</div>
            <div class="mt-3 flex items-center gap-3">
                <img src="{{ $organizationLogoUrl }}" alt="{{ $organization->name }} logo" class="h-16 w-16 rounded-xl object-cover ring-1 ring-slate-200">
                <div class="min-w-0 text-xs text-slate-500">Shown in the application sidebar and header for this organization.</div>
            </div>
            <input type="hidden" name="logo" value="{{ old('logo', $organization->logo) }}">
            <label class="mt-3 block text-sm font-medium text-slate-700">Upload new logo
                <input name="logo_file" type="file" accept="image/*" class="mt-1 w-full rounded border-slate-300 disabled:bg-slate-100 disabled:text-slate-500" @disabled(! $canUpdateOrganization)>
            </label>
        </div>
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
