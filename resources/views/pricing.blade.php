<!doctype html>
<html lang="en">
<head>
    @php($seo = \App\Support\SeoMetadata::forRequest(request()))
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <x-seo-meta :seo="$seo" />
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-slate-950 text-white">
    <div class="min-h-screen bg-[radial-gradient(circle_at_top_left,#7e22ce_0%,#3b0764_34%,#09090b_75%)]">
        <header class="mx-auto flex max-w-7xl items-center justify-between gap-4 px-5 py-5 sm:px-8 lg:px-10">
            <a href="{{ route('landing') }}" aria-label="DigitoFina home">
                <img src="{{ asset('brand/digitofina-logo.svg') }}" alt="DigitoFina" class="h-14 w-auto rounded-2xl bg-white px-2 shadow-xl">
            </a>
            <div class="flex items-center gap-2 sm:gap-3">
                <a href="{{ route('login') }}" class="inline-flex min-h-11 items-center justify-center rounded-xl border border-white/20 bg-white/10 px-4 text-sm font-semibold hover:bg-white/20">Log in</a>
                <a href="{{ route('demo.create') }}" class="inline-flex min-h-11 items-center justify-center rounded-xl bg-amber-300 px-4 text-sm font-bold text-violet-950 hover:bg-amber-200">Start free demo</a>
            </div>
        </header>

        <main class="mx-auto max-w-7xl px-5 pb-20 pt-10 sm:px-8 lg:px-10 lg:pt-16">
            <section class="mx-auto max-w-4xl text-center">
                <p class="text-sm font-bold uppercase tracking-[0.22em] text-amber-300">Flexible DigitoFina pricing</p>
                <h1 class="mt-4 text-4xl font-bold tracking-tight sm:text-6xl">Choose monthly flexibility or permanent ownership.</h1>
                <p class="mx-auto mt-5 max-w-3xl text-base leading-7 text-violet-100 sm:text-lg">Your final price is based on organization size, branches, users, onboarding and the level of operational support required.</p>
            </section>

            <section class="mt-12 grid gap-6 lg:grid-cols-2">
                <article class="relative overflow-hidden rounded-[2rem] border border-amber-300/40 bg-white p-7 text-slate-950 shadow-2xl sm:p-9">
                    <div class="absolute right-0 top-0 rounded-bl-2xl bg-amber-300 px-5 py-2 text-xs font-bold uppercase tracking-wider text-violet-950">Most flexible</div>
                    <p class="text-sm font-bold uppercase tracking-[0.18em] text-violet-700">SaaS subscription</p>
                    <h2 class="mt-5 text-3xl font-bold">Pay monthly</h2>
                    <div class="mt-6 flex flex-wrap items-end gap-2">
                        <span class="text-sm font-bold text-slate-500">From</span>
                        <span class="text-4xl font-bold text-violet-950">{{ $currency }} {{ number_format($offers['subscription']['starting_at']) }}</span>
                        <span class="pb-1 text-sm font-semibold text-slate-500">/ month</span>
                    </div>
                    <p class="mt-3 text-sm leading-6 text-slate-600">Scales up to {{ $currency }} {{ number_format($offers['subscription']['up_to']) }} per month for larger operations.</p>
                    <ul class="mt-7 grid gap-3 text-sm font-semibold text-slate-700">
                        <li class="flex gap-3"><span class="text-violet-700">✓</span><span>Lower starting cost</span></li>
                        <li class="flex gap-3"><span class="text-violet-700">✓</span><span>Updates and ongoing system access</span></li>
                        <li class="flex gap-3"><span class="text-violet-700">✓</span><span>Suitable for one branch or growing teams</span></li>
                    </ul>
                    <a href="{{ route('demo.create') }}" class="mt-8 inline-flex min-h-13 w-full items-center justify-center rounded-2xl bg-violet-700 px-6 font-bold text-white hover:bg-violet-800">Try it free for 14 days</a>
                </article>

                <article class="rounded-[2rem] border border-white/15 bg-white/10 p-7 shadow-2xl backdrop-blur sm:p-9">
                    <p class="text-sm font-bold uppercase tracking-[0.18em] text-amber-300">One-time licence</p>
                    <h2 class="mt-5 text-3xl font-bold">Own your deployment</h2>
                    <div class="mt-6 flex flex-wrap items-end gap-2">
                        <span class="text-sm font-bold text-violet-200">From</span>
                        <span class="text-4xl font-bold">{{ $currency }} {{ number_format($offers['one_time']['starting_at']) }}</span>
                    </div>
                    <p class="mt-3 text-sm leading-6 text-violet-100">Typically ranges up to {{ $currency }} {{ number_format($offers['one_time']['up_to']) }}, depending on scale, setup and customization.</p>
                    <ul class="mt-7 grid gap-3 text-sm font-semibold text-violet-50">
                        <li class="flex gap-3"><span class="text-amber-300">✓</span><span>One agreed implementation price</span></li>
                        <li class="flex gap-3"><span class="text-amber-300">✓</span><span>Designed for established or larger operations</span></li>
                        <li class="flex gap-3"><span class="text-amber-300">✓</span><span>Deployment and onboarding scope agreed upfront</span></li>
                    </ul>
                    <a href="https://wa.me/256701822382?text={{ urlencode('Hello DigitoFina, I would like a quotation for a one-time licence.') }}" class="mt-8 inline-flex min-h-13 w-full items-center justify-center rounded-2xl bg-amber-300 px-6 font-bold text-violet-950 hover:bg-amber-200">Request a quotation</a>
                </article>
            </section>

            <section class="mt-8 rounded-[2rem] border border-white/15 bg-white/10 p-7 text-center backdrop-blur sm:p-9">
                <h2 class="text-2xl font-bold">Let us match the price to your operation.</h2>
                <p class="mx-auto mt-3 max-w-3xl text-sm leading-6 text-violet-100">Branch count, staff users, migration, training and customization affect the final package. Text, WhatsApp or call 0701 822 382 / 0777 522 214.</p>
                <p class="mt-4 text-sm font-bold text-amber-300">Create your demo at digitofina.digitomove.com</p>
            </section>
        </main>
    </div>
</body>
</html>
