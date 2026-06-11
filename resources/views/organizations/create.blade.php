@extends('layouts.app')

@section('content')
<div class="mb-6">
    <h1 class="text-2xl font-semibold text-slate-950">Create Organization</h1>
    <p class="text-sm text-slate-500">Create a new tenant and optionally assign an initial package.</p>
</div>

<form method="POST" action="{{ route('organizations.store') }}" enctype="multipart/form-data" class="grid gap-6 xl:grid-cols-[1fr_22rem]">
    @csrf
    <section class="df-panel p-5">
        <div class="grid gap-4 md:grid-cols-2">
            <label class="block text-sm font-medium text-slate-700">Organization name <x-required-badge />
                <input name="name" value="{{ old('name') }}" class="mt-1 w-full rounded border-slate-300" required>
            </label>
            <label class="block text-sm font-medium text-slate-700">Slug <x-required-badge />
                <input name="slug" value="{{ old('slug') }}" class="mt-1 w-full rounded border-slate-300" required>
            </label>
            <label class="block text-sm font-medium text-slate-700">Email
                <input name="email" value="{{ old('email') }}" class="mt-1 w-full rounded border-slate-300">
            </label>
            <label class="block text-sm font-medium text-slate-700">Website
                <input name="website" type="url" value="{{ old('website') }}" class="mt-1 w-full rounded border-slate-300" placeholder="https://example.com">
            </label>
            <label class="block text-sm font-medium text-slate-700">Phone
                <input name="phone" value="{{ old('phone') }}" class="mt-1 w-full rounded border-slate-300">
            </label>
            <label class="block text-sm font-medium text-slate-700">Currency <x-required-badge />
                <input name="currency" value="{{ old('currency', 'UGX') }}" class="mt-1 w-full rounded border-slate-300" required>
            </label>
            <label class="block text-sm font-medium text-slate-700">Timezone <x-required-badge />
                <input name="timezone" value="{{ old('timezone', 'Africa/Kampala') }}" class="mt-1 w-full rounded border-slate-300" required>
            </label>
            <label class="block text-sm font-medium text-slate-700 md:col-span-2">Address
                <textarea name="address" rows="3" class="mt-1 w-full rounded border-slate-300">{{ old('address') }}</textarea>
            </label>
        </div>
    </section>
    <aside class="df-panel p-5">
        <label class="block text-sm font-medium text-slate-700">Logo
            <input name="logo_file" type="file" accept="image/*" class="mt-1 w-full rounded border-slate-300">
        </label>
        <label class="mt-4 block text-sm font-medium text-slate-700">Package
            <select name="package_id" class="mt-1 w-full rounded border-slate-300">
                <option value="">No package yet</option>
                @foreach($packages as $package)
                    <option value="{{ $package->id }}" @selected((string) old('package_id') === (string) $package->id)>{{ $package->name }}</option>
                @endforeach
            </select>
        </label>
        <label class="mt-4 block text-sm font-medium text-slate-700">Status
            <select name="status" class="mt-1 w-full rounded border-slate-300">
                @foreach(['active', 'suspended', 'disabled', 'inactive'] as $status)
                    <option value="{{ $status }}" @selected(old('status', 'active') === $status)>{{ str($status)->headline() }}</option>
                @endforeach
            </select>
        </label>
        <button class="mt-5 w-full rounded bg-blue-700 px-4 py-2.5 text-sm font-semibold text-white hover:bg-blue-800">Create organization</button>
    </aside>
</form>
@endsection
