<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Client Login · DigitoFina</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-[linear-gradient(135deg,#ecfdf5_0%,#f8fafc_55%,#eff6ff_100%)]">
    <main class="grid min-h-screen place-items-center p-4">
        <form method="POST" action="{{ route('client.login.store') }}" class="w-full max-w-md rounded-lg bg-white p-6 shadow-panel ring-1 ring-slate-200 sm:p-8" autocomplete="off">
            @csrf
            <img src="{{ asset('brand/digitofina-logo.svg') }}" alt="DigitoFina" class="h-16 w-auto">
            <p class="mt-7 text-sm font-semibold uppercase tracking-wide text-brand-700">Client self-service</p>
            <h1 class="mt-1 text-2xl font-semibold text-slate-950">Access your loan account</h1>
            <p class="mt-2 text-sm leading-6 text-slate-500">View loans and savings, request an eligible new loan, and pay securely through your organization.</p>

            @if ($errors->any())
                <div class="mt-5 rounded-xl border border-red-100 bg-red-50 p-3 text-sm text-red-700">{{ $errors->first() }}</div>
            @endif

            <label class="mt-6 block text-sm font-semibold text-slate-700">Phone number <x-required-badge />
                <input name="phone" value="{{ old('phone') }}" inputmode="tel" autocomplete="username" class="mt-1 h-12 w-full rounded-xl border-brand-100" placeholder="0701 822 382" required autofocus>
            </label>
            <label class="mt-4 block text-sm font-semibold text-slate-700">Password <x-required-badge />
                <input name="password" type="password" autocomplete="current-password" class="mt-1 h-12 w-full rounded-xl border-brand-100" required>
            </label>
            <label class="mt-4 flex items-center gap-2 text-sm text-slate-600"><input type="checkbox" name="remember" value="1" class="rounded border-brand-200 text-brand-600"> Remember me</label>
            <button class="df-action-primary mt-6 flex h-12 w-full items-center justify-center">Open my account</button>
            <div class="mt-6 text-center text-sm text-slate-500">Staff member? <a href="{{ route('login') }}" class="font-semibold text-brand-700">Use staff login</a></div>
        </form>
    </main>
</body>
</html>
