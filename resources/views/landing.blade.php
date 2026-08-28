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
    <div class="min-h-screen overflow-hidden bg-[radial-gradient(circle_at_top_left,#7e22ce_0%,#3b0764_32%,#09090b_72%)]">
        <header class="relative z-20 mx-auto flex max-w-7xl items-center justify-between gap-4 px-5 py-5 sm:px-8 lg:px-10">
            <a href="{{ route('landing') }}" aria-label="DigitoFina home">
                <img src="{{ asset('brand/digitofina-logo.svg') }}" alt="DigitoFina" class="h-14 w-auto rounded-2xl bg-white px-2 shadow-xl">
            </a>
            <div class="flex items-center gap-2 sm:gap-3">
                <a href="{{ route('pricing') }}" class="hidden min-h-11 items-center justify-center px-2 text-sm font-semibold text-violet-100 hover:text-white sm:inline-flex">Pricing</a>
                <a href="{{ route('login') }}" class="inline-flex min-h-11 items-center justify-center rounded-xl border border-white/20 bg-white/10 px-4 text-sm font-semibold text-white backdrop-blur hover:bg-white/20">Log in</a>
                <a href="{{ route('demo.create') }}" class="inline-flex min-h-11 items-center justify-center rounded-xl bg-amber-300 px-4 text-sm font-bold text-violet-950 shadow-lg shadow-amber-400/20 hover:-translate-y-0.5 hover:bg-amber-200">Start free demo</a>
            </div>
        </header>

        <main>
            <section class="relative mx-auto grid max-w-7xl items-center gap-10 px-5 pb-20 pt-8 sm:px-8 lg:grid-cols-[0.92fr_1.08fr] lg:px-10 lg:pb-28 lg:pt-16">
                <div class="relative z-10">
                    <div class="inline-flex items-center gap-2 rounded-full border border-fuchsia-300/30 bg-fuchsia-300/10 px-3 py-1.5 text-xs font-bold uppercase tracking-[0.2em] text-fuchsia-100">
                        Microfinance management, simplified
                    </div>
                    <h1 class="mt-6 max-w-3xl text-4xl font-bold leading-[1.06] tracking-tight sm:text-5xl lg:text-7xl">Run your loan business <span class="text-amber-300">without chasing records.</span></h1>
                    <p class="mt-6 max-w-2xl text-base leading-7 text-violet-100 sm:text-lg">See every client, repayment, expense and staff action across every branch—from your phone or computer. DigitoFina keeps the records organized, helps you spot errors early, and shows projected interest and profit.</p>
                    <div class="mt-8 flex flex-col gap-3 sm:flex-row">
                        <a href="{{ route('demo.create') }}" class="inline-flex min-h-14 items-center justify-center rounded-2xl bg-amber-300 px-6 text-base font-bold text-violet-950 shadow-xl shadow-amber-400/20 hover:-translate-y-0.5 hover:bg-amber-200">Create your 14-day demo</a>
                        <a href="{{ route('login') }}" class="inline-flex min-h-14 items-center justify-center rounded-2xl border border-white/20 bg-white/10 px-6 text-base font-semibold text-white backdrop-blur hover:bg-white/20">I already have an account</a>
                    </div>
                    <p class="mt-4 text-sm text-violet-200">All modules included. No payment required. Demo usage limits apply.</p>
                </div>

                <div class="relative min-h-[28rem] overflow-hidden rounded-[2rem] border border-white/15 bg-white/10 shadow-2xl shadow-fuchsia-950/40 lg:min-h-[38rem]">
                    <img src="{{ asset('marketing/digitofina-flyers/landscape-control-background.png') }}" alt="Business owner monitoring DigitoFina from a phone and computer" class="absolute inset-0 h-full w-full object-cover object-center">
                    <div class="absolute inset-x-5 bottom-5 grid grid-cols-2 gap-3 sm:inset-x-8 sm:bottom-8">
                        <div class="rounded-2xl border border-white/25 bg-violet-950/80 p-4 backdrop-blur">
                            <div class="text-xs font-semibold uppercase tracking-wider text-violet-200">Portfolio control</div>
                            <div class="mt-1 text-lg font-bold">Live loan visibility</div>
                        </div>
                        <div class="rounded-2xl border border-white/25 bg-violet-950/80 p-4 backdrop-blur">
                            <div class="text-xs font-semibold uppercase tracking-wider text-violet-200">Built to scale</div>
                            <div class="mt-1 text-lg font-bold">One branch or many</div>
                        </div>
                    </div>
                </div>
            </section>

            <section class="bg-white px-5 py-20 text-slate-950 sm:px-8 lg:px-10">
                <div class="mx-auto max-w-7xl">
                    <div class="max-w-3xl">
                        <p class="text-sm font-bold uppercase tracking-[0.22em] text-violet-700">What the owner gets</p>
                        <h2 class="mt-3 text-3xl font-bold tracking-tight sm:text-5xl">Know what is happening—even when you are away.</h2>
                        <p class="mt-4 max-w-2xl leading-7 text-slate-600">Spend less time collecting reports and correcting records. Use one reliable owner view to protect money, guide staff and make faster decisions.</p>
                    </div>
                    <div class="mt-10 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                        @foreach([
                            ['Protect every shilling', 'Trace repayments, expenses, other income and merchant-code payments from one place.'],
                            ['See branch performance', 'Compare one branch or many without waiting for separate reports from every location.'],
                            ['Hold staff accountable', 'Review the work recorded by each staff member and understand daily performance.'],
                            ['Know expected profit', 'Project interest income and compare it with the costs of running the business.'],
                            ['Find errors earlier', 'Use a documented trail to spot discrepancies before they become expensive problems.'],
                            ['Grow without losing control', 'Add clients, staff and branches while keeping each user’s access properly separated.'],
                        ] as [$benefit, $summary])
                            <article class="rounded-3xl border border-violet-100 bg-violet-50/60 p-6 shadow-sm">
                                <div class="grid h-11 w-11 place-items-center rounded-2xl bg-violet-700 text-lg font-bold text-white">✓</div>
                                <h3 class="mt-5 text-lg font-bold">{{ $benefit }}</h3>
                                <p class="mt-2 text-sm leading-6 text-slate-600">{{ $summary }}</p>
                            </article>
                        @endforeach
                    </div>
                </div>
            </section>

            <section class="bg-violet-50 px-5 py-20 text-slate-950 sm:px-8 lg:px-10">
                <div class="mx-auto grid max-w-7xl items-center gap-8 lg:grid-cols-[1fr_auto]">
                    <div>
                        <p class="text-sm font-bold uppercase tracking-[0.22em] text-violet-700">Pricing that fits your business</p>
                        <h2 class="mt-3 text-3xl font-bold tracking-tight sm:text-5xl">Subscribe monthly or choose a one-time licence.</h2>
                        <p class="mt-4 max-w-3xl leading-7 text-slate-600">Monthly SaaS access starts at UGX 200,000 and scales up to UGX 350,000. One-time deployments range from UGX 1,500,000 to UGX 3,000,000.</p>
                    </div>
                    <a href="{{ route('pricing') }}" class="inline-flex min-h-14 items-center justify-center rounded-2xl bg-violet-700 px-7 font-bold text-white shadow-lg shadow-violet-700/20 hover:-translate-y-0.5 hover:bg-violet-800">See pricing options</a>
                </div>
            </section>

            <section class="px-5 py-20 sm:px-8 lg:px-10">
                <div class="mx-auto grid max-w-7xl gap-8 rounded-[2rem] border border-white/15 bg-white/10 p-7 backdrop-blur sm:p-10 lg:grid-cols-[1fr_auto] lg:items-center">
                    <div>
                        <p class="text-sm font-bold uppercase tracking-[0.22em] text-amber-300">Ready to see it working?</p>
                        <h2 class="mt-3 text-3xl font-bold">Explore every module for 14 days.</h2>
                        <p class="mt-3 max-w-2xl text-violet-100">Create an isolated demo organization with sample-ready workflows. Your existing DigitoFina accounts and data are never mixed with the demo.</p>
                    </div>
                    <a href="{{ route('demo.create') }}" class="inline-flex min-h-14 items-center justify-center rounded-2xl bg-amber-300 px-7 font-bold text-violet-950 hover:-translate-y-0.5 hover:bg-amber-200">Start your demo</a>
                </div>
                <div class="mx-auto mt-8 flex max-w-7xl flex-col justify-between gap-3 text-sm text-violet-200 sm:flex-row">
                    <span>DigitoFina — Microfinance Management System</span>
                    <span>Text • WhatsApp • Call: 0701 822 382 / 0777 522 214</span>
                </div>
            </section>
        </main>
    </div>
</body>
</html>
