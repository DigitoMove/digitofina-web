<!doctype html>
<html lang="en">
<head>
    @php($seo = \App\Support\SeoMetadata::forRequest(request()))
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <x-seo-meta :seo="$seo" />
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-[radial-gradient(circle_at_top_left,#f3e8ff_0,#faf5ff_24rem,transparent_40rem),linear-gradient(135deg,#fdf4ff_0%,#f8fafc_48%,#f5f3ff_100%)]">
    <main class="grid min-h-screen p-4 lg:grid-cols-[1.05fr_0.95fr] lg:p-6">
        <section class="hidden min-h-[calc(100vh-3rem)] overflow-hidden rounded-[2rem] bg-brand-950 p-8 text-white shadow-panel lg:flex lg:flex-col lg:justify-between">
            <div>
                <img src="{{ asset('brand/digitofina-logo.svg') }}" alt="DigitoFina" class="h-20 w-auto rounded-3xl bg-white p-2 shadow-lg shadow-brand-950/20">
                <div class="mt-12 max-w-xl">
                    <p class="text-sm font-semibold uppercase tracking-[0.28em] text-brand-200">Core banking operations</p>
                    <h1 class="mt-4 text-4xl font-semibold leading-tight text-white">A clearer command center for every branch decision.</h1>
                    <p class="mt-4 text-base leading-7 text-brand-100">Monitor clients, savings, loans, teller cash, approvals, and audit activity from one cohesive workspace.</p>
                </div>
            </div>

            <div class="grid gap-3">
                <div class="rounded-3xl border border-white/10 bg-white/10 p-4">
                    <div class="flex items-center justify-between gap-4">
                        <div>
                            <p class="text-sm text-brand-100">Today&apos;s focus</p>
                            <p class="mt-1 text-xl font-semibold">Cash flow, approvals, arrears</p>
                        </div>
                        <div class="grid h-12 w-12 place-items-center rounded-2xl bg-orchid-300 text-brand-950">
                            <svg class="h-6 w-6" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path d="M5 19V5m0 14h14M9 16v-5m4 5V8m4 8v-7"/></svg>
                        </div>
                    </div>
                </div>
                <div class="grid grid-cols-3 gap-3">
                    <div class="rounded-2xl bg-brand-400/20 p-4">
                        <p class="text-xs text-brand-100">Portfolio</p>
                        <p class="mt-2 text-lg font-semibold">Live</p>
                    </div>
                    <div class="rounded-2xl bg-orchid-400/20 p-4">
                        <p class="text-xs text-orchid-50">Audit</p>
                        <p class="mt-2 text-lg font-semibold">Traceable</p>
                    </div>
                    <div class="rounded-2xl bg-amber-300/15 p-4">
                        <p class="text-xs text-amber-100">Teller</p>
                        <p class="mt-2 text-lg font-semibold">Ready</p>
                    </div>
                </div>
            </div>
        </section>

        <section class="grid min-h-[calc(100vh-2rem)] place-items-center p-4 sm:p-8">
            <form method="POST" action="{{ route('login') }}" class="w-full max-w-md rounded-[2rem] bg-white/95 p-6 shadow-panel ring-1 ring-slate-200/80 sm:p-8">
                @csrf
                <div class="mb-8">
                    <img src="{{ asset('brand/digitofina-logo.svg') }}" alt="DigitoFina" class="h-16 w-auto">
                    <div class="mt-6">
                        <h1 class="text-2xl font-semibold text-slate-950">Welcome back</h1>
                        <p class="mt-1 text-sm text-slate-500">Sign in to continue managing branch operations.</p>
                    </div>
                </div>

                @if ($errors->any())
                    <div class="mb-4 rounded-2xl border border-red-100 bg-red-50 p-3 text-sm text-red-700">{{ $errors->first() }}</div>
                @endif

                <label class="block text-sm font-semibold text-slate-700">Email or phone</label>
                <input name="login" value="{{ old('login') }}" class="mt-1 h-12 w-full rounded-2xl border border-brand-100 bg-brand-50/60 px-4 focus:border-brand-500 focus:bg-white focus:ring-brand-100" required autofocus autocomplete="username">

                <label class="mt-4 block text-sm font-semibold text-slate-700">Password</label>
                <input name="password" type="password" class="mt-1 h-12 w-full rounded-2xl border border-brand-100 bg-brand-50/60 px-4 focus:border-brand-500 focus:bg-white focus:ring-brand-100" required autocomplete="current-password">

                <div class="mt-4 flex items-center justify-between gap-3 text-sm">
                    <label class="flex items-center gap-2 text-slate-600"><input type="checkbox" name="remember" class="rounded border-brand-200 text-brand-600"> Remember me</label>
                    <a href="{{ route('password.request') }}" class="font-semibold text-brand-700 hover:text-brand-900">Reset password</a>
                </div>

                <button class="mt-6 flex h-12 w-full items-center justify-center gap-2 rounded-2xl bg-gradient-to-r from-brand-700 via-brand-600 to-orchid-500 font-semibold text-white shadow-lg shadow-brand-200 hover:from-brand-800 hover:to-orchid-700">
                    Login
                    <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M5 12h14m-6-6 6 6-6 6"/></svg>
                </button>

                <div class="mt-6 rounded-2xl border border-brand-100 bg-brand-50 p-3 text-center text-xs text-brand-800">
                    Demo access: admin@digitofina.test / password
                </div>
            </form>
        </section>
    </main>
</body>
</html>
