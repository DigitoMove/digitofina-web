<!doctype html>
<html lang="en">
<head>
    @php($seo = \App\Support\SeoMetadata::forRequest(request()))
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <x-seo-meta :seo="$seo" />
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-[radial-gradient(circle_at_top_left,#f5d0fe_0%,#f8fafc_38%,#ede9fe_100%)] text-slate-950">
    <main class="mx-auto grid min-h-screen max-w-7xl items-center gap-8 px-5 py-8 sm:px-8 lg:grid-cols-[0.9fr_1.1fr] lg:px-10">
        <section class="rounded-[2rem] bg-violet-950 p-7 text-white shadow-2xl sm:p-10">
            <a href="{{ route('landing') }}"><img src="{{ asset('brand/digitofina-logo.svg') }}" alt="DigitoFina" class="h-16 w-auto rounded-2xl bg-white px-2"></a>
            <p class="mt-10 text-sm font-bold uppercase tracking-[0.22em] text-amber-300">{{ $trialDays }}-day guided trial</p>
            <h1 class="mt-3 text-4xl font-bold tracking-tight sm:text-5xl">Build a working demo around your business.</h1>
            <p class="mt-5 text-base leading-7 text-violet-100">We create a completely separate organization for you, enable every DigitoFina module, and sign you in as its demo administrator.</p>
            <div class="mt-8 grid gap-3 sm:grid-cols-2">
                @foreach([
                    'All system modules enabled',
                    ($limits['branches'] ?? 2).' demo branches',
                    ($limits['users'] ?? 3).' staff users',
                    ($limits['clients'] ?? 25).' client records',
                    ($limits['loans'] ?? 25).' loan records',
                    'Automatic expiry after '.$trialDays.' days',
                ] as $item)
                    <div class="flex items-center gap-3 rounded-2xl border border-white/10 bg-white/10 px-4 py-3 text-sm font-semibold">
                        <span class="grid h-7 w-7 shrink-0 place-items-center rounded-full bg-amber-300 text-violet-950">✓</span>
                        <span>{{ $item }}</span>
                    </div>
                @endforeach
            </div>
        </section>

        <section class="rounded-[2rem] border border-white/80 bg-white/95 p-6 shadow-panel sm:p-9">
            <div class="flex items-start justify-between gap-4">
                <div>
                    <h2 class="text-2xl font-bold">Create your free demo</h2>
                    <p class="mt-1 text-sm text-slate-500">No payment details required.</p>
                </div>
                <a href="{{ route('login') }}" class="text-sm font-semibold text-violet-700 hover:text-violet-950">Log in</a>
            </div>

            @if ($errors->any())
                <div class="mt-5 rounded-2xl border border-red-200 bg-red-50 p-4 text-sm font-medium text-red-700">{{ $errors->first() }}</div>
            @endif

            <form method="POST" action="{{ route('demo.store') }}" class="mt-7 grid gap-5">
                @csrf
                <label class="block text-sm font-semibold text-slate-700">Business or organization name <x-required-badge />
                    <input name="organization_name" value="{{ old('organization_name') }}" class="mt-1" required autofocus autocomplete="organization">
                </label>
                <div class="grid gap-5 sm:grid-cols-2">
                    <label class="block text-sm font-semibold text-slate-700">Your name <x-required-badge />
                        <input name="name" value="{{ old('name') }}" class="mt-1" required autocomplete="name">
                    </label>
                    <label class="block text-sm font-semibold text-slate-700">Phone number <x-required-badge />
                        <input name="phone" value="{{ old('phone') }}" class="mt-1" required autocomplete="tel">
                    </label>
                </div>
                <label class="block text-sm font-semibold text-slate-700">Email address <x-required-badge />
                    <input name="email" type="email" value="{{ old('email') }}" class="mt-1" required autocomplete="email">
                </label>
                <div class="grid gap-5 sm:grid-cols-2">
                    <label class="block text-sm font-semibold text-slate-700">Password <x-required-badge />
                        <input name="password" type="password" class="mt-1" required autocomplete="new-password">
                    </label>
                    <label class="block text-sm font-semibold text-slate-700">Confirm password <x-required-badge />
                        <input name="password_confirmation" type="password" class="mt-1" required autocomplete="new-password">
                    </label>
                </div>
                <button class="mt-2 inline-flex min-h-14 items-center justify-center rounded-2xl bg-violet-700 px-6 font-bold text-white shadow-lg shadow-violet-700/20 hover:-translate-y-0.5 hover:bg-violet-800">Create my {{ $trialDays }}-day demo</button>
                <p class="text-center text-xs leading-5 text-slate-500">Your demo data is isolated from every other organization and becomes inaccessible when the trial expires.</p>
            </form>
        </section>
    </main>
</body>
</html>
