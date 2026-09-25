<!doctype html>
<html lang="en">
<head>
    @php($seo = \App\Support\SeoMetadata::forRequest(request(), [
        'title' => 'Mobile Money Collections for Loans and SACCOs',
        'description' => 'Collect loan repayments, savings deposits and member contributions by mobile money, then automatically post confirmed payments in DigitoFina.',
    ]))
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
                <a href="{{ route('client.login') }}" class="hidden min-h-11 items-center justify-center px-2 text-sm font-semibold text-violet-100 hover:text-white lg:inline-flex">Client login</a>
                <a href="{{ route('login') }}" class="inline-flex min-h-11 items-center justify-center rounded-xl border border-white/20 bg-white/10 px-4 text-sm font-semibold text-white backdrop-blur hover:bg-white/20">Log in</a>
                <a href="{{ route('demo.create') }}" class="inline-flex min-h-11 items-center justify-center rounded-xl bg-amber-300 px-4 text-sm font-bold text-violet-950 shadow-lg shadow-amber-400/20 hover:-translate-y-0.5 hover:bg-amber-200">Start free demo</a>
            </div>
        </header>

        <main>
            <section class="relative mx-auto grid max-w-7xl items-center gap-10 px-5 pb-20 pt-8 sm:px-8 lg:grid-cols-[0.92fr_1.08fr] lg:px-10 lg:pb-28 lg:pt-16">
                <div class="relative z-10">
                    <div class="inline-flex items-center gap-2 rounded-full border border-fuchsia-300/30 bg-fuchsia-300/10 px-3 py-1.5 text-xs font-bold uppercase tracking-[0.2em] text-fuchsia-100">
                        Mobile-money collections, connected to your records
                    </div>
                    <h1 class="mt-6 max-w-3xl text-4xl font-bold leading-[1.06] tracking-tight sm:text-5xl lg:text-7xl">Let clients pay by mobile money. <span class="text-amber-300">See every collection in one place.</span></h1>
                    <p class="mt-6 max-w-2xl text-base leading-7 text-violet-100 sm:text-lg">DigitoFina helps loan businesses, SACCOs and investment groups collect repayments, savings deposits and member contributions from a client’s phone. Each confirmed payment is posted to the right client or member account, giving your team clearer records and less manual follow-up.</p>
                    <div class="mt-8 flex flex-col gap-3 sm:flex-row">
                        <a href="{{ route('demo.create') }}" class="inline-flex min-h-14 items-center justify-center rounded-2xl bg-amber-300 px-6 text-base font-bold text-violet-950 shadow-xl shadow-amber-400/20 hover:-translate-y-0.5 hover:bg-amber-200">Create your 14-day demo</a>
                        <a href="{{ route('login') }}" class="inline-flex min-h-14 items-center justify-center rounded-2xl border border-white/20 bg-white/10 px-6 text-base font-semibold text-white backdrop-blur hover:bg-white/20">I already have an account</a>
                    </div>
                    <div class="mt-5 flex flex-wrap gap-2 text-xs font-semibold text-violet-100">
                        <span class="rounded-full border border-white/15 bg-white/10 px-3 py-2">Your organization’s payment account</span>
                        <span class="rounded-full border border-white/15 bg-white/10 px-3 py-2">Verified payment posting</span>
                        <span class="rounded-full border border-white/15 bg-white/10 px-3 py-2">Loans, savings and contributions</span>
                    </div>
                </div>

                <div class="relative min-h-[28rem] overflow-hidden rounded-[2rem] border border-white/15 bg-black shadow-2xl shadow-fuchsia-950/40 lg:min-h-[38rem]">
                    <video autoplay muted loop playsinline controls preload="metadata" poster="{{ asset('marketing/digitofina-flyers/landscape-repayment-background.png') }}" class="absolute inset-0 h-full w-full object-cover object-center" aria-label="DigitoFina advertisement">
                        <source src="{{ asset('marketing/digitofina-ad.mp4') }}" type="video/mp4">
                        Your browser does not support embedded video. <a href="{{ asset('marketing/digitofina-ad.mp4') }}" class="underline">Watch the DigitoFina advertisement</a>.
                    </video>
                    <div class="pointer-events-none absolute inset-x-5 top-5 sm:inset-x-8 sm:top-8">
                        <span class="inline-flex rounded-full border border-white/25 bg-violet-950/80 px-3 py-1.5 text-xs font-bold uppercase tracking-[0.18em] text-amber-200 backdrop-blur">DigitoFina in action</span>
                    </div>
                    <div class="absolute inset-x-5 bottom-5 grid grid-cols-2 gap-3 sm:inset-x-8 sm:bottom-8">
                        <div class="rounded-2xl border border-white/25 bg-violet-950/80 p-4 backdrop-blur">
                            <div class="text-xs font-semibold uppercase tracking-wider text-violet-200">Loan repayments</div>
                            <div class="mt-1 text-base font-bold sm:text-lg">Clients pay from their phones</div>
                        </div>
                        <div class="rounded-2xl border border-white/25 bg-violet-950/80 p-4 backdrop-blur">
                            <div class="text-xs font-semibold uppercase tracking-wider text-violet-200">Savings & contributions</div>
                            <div class="mt-1 text-base font-bold sm:text-lg">Posted to the right account</div>
                        </div>
                    </div>
                </div>
            </section>

            <section class="bg-white px-5 py-20 text-slate-950 sm:px-8 lg:px-10">
                <div class="mx-auto max-w-7xl">
                    <div class="max-w-3xl">
                        <p class="text-sm font-bold uppercase tracking-[0.22em] text-violet-700">A simpler collection flow</p>
                        <h2 class="mt-3 text-3xl font-bold tracking-tight sm:text-5xl">Collect funds directly. Update records automatically.</h2>
                        <p class="mt-4 max-w-2xl leading-7 text-slate-600">Move away from cash-heavy follow-up. Members approve payments from their own phones, while verified confirmations update the matching loan, savings or contribution record.</p>
                    </div>
                    <div class="mt-10 grid gap-4 lg:grid-cols-3">
                        @foreach([
                            ['01', 'For loan clients', 'Repay an active loan by mobile money without travelling to the branch.'],
                            ['02', 'For SACCOs & investment groups', 'Receive savings deposits and member contributions digitally into the organization’s configured payment account.'],
                            ['03', 'For your team', 'Send payment requests, receive verified confirmations and reconcile collections without re-entering every transaction.'],
                        ] as [$number, $audience, $summary])
                            <article class="rounded-3xl border border-violet-100 bg-violet-50/60 p-6 shadow-sm">
                                <span class="text-sm font-black text-violet-700">{{ $number }}</span>
                                <h3 class="mt-4 text-xl font-bold">{{ $audience }}</h3>
                                <p class="mt-3 text-sm leading-6 text-slate-600">{{ $summary }}</p>
                            </article>
                        @endforeach
                    </div>
                    <p class="mt-5 text-sm text-slate-500">Every payer authorizes their own mobile-money transaction. “Automatic collection” means verified payments are captured and posted automatically—not that funds are withdrawn without permission.</p>
                </div>
            </section>

            <section class="bg-violet-50 px-5 py-20 text-slate-950 sm:px-8 lg:px-10">
                <div class="mx-auto max-w-7xl">
                    <div class="max-w-3xl">
                        <p class="text-sm font-bold uppercase tracking-[0.22em] text-violet-700">What the owner gets</p>
                        <h2 class="mt-3 text-3xl font-bold tracking-tight sm:text-5xl">Know what is happening—even when you are away.</h2>
                        <p class="mt-4 max-w-2xl leading-7 text-slate-600">Spend less time collecting reports and correcting records. Use one reliable owner view to protect money, guide staff and make faster decisions.</p>
                    </div>
                    <div class="mt-10 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                        @foreach([
                            ['Collect without chasing cash', 'Let clients repay loans or deposit into savings from their phones while confirmed payments flow into the correct records.'],
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

            <section class="bg-white px-5 py-20 text-slate-950 sm:px-8 lg:px-10">
                <div class="mx-auto max-w-7xl">
                    <div class="max-w-3xl">
                        <p class="text-sm font-bold uppercase tracking-[0.22em] text-violet-700">Made for real financial businesses</p>
                        <h2 class="mt-3 text-3xl font-bold tracking-tight sm:text-5xl">One system for collection, control and growth.</h2>
                        <p class="mt-4 max-w-2xl leading-7 text-slate-600">Explore how DigitoFina helps owners stay in control from a phone or computer.</p>
                    </div>
                    <div class="mt-10 grid items-start gap-5 lg:grid-cols-[1fr_1fr_0.66fr]">
                        <img src="{{ asset('marketing/digitofina-flyers/digitofina-landscape-one-owner-benefits.png') }}" alt="DigitoFina owner benefits for monitoring a loan business" loading="lazy" class="w-full rounded-3xl border border-slate-200 shadow-lg">
                        <img src="{{ asset('marketing/digitofina-flyers/digitofina-landscape-two-owner-benefits.png') }}" alt="DigitoFina loan repayment and business management flyer" loading="lazy" class="w-full rounded-3xl border border-slate-200 shadow-lg">
                        <img src="{{ asset('marketing/digitofina-flyers/digitofina-portrait-owner-benefits.png') }}" alt="DigitoFina mobile microfinance management flyer" loading="lazy" class="mx-auto w-full max-w-sm rounded-3xl border border-slate-200 shadow-lg">
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
                        <p class="mt-3 max-w-2xl text-violet-100">Create an isolated demo organization and test loan, savings and mobile-money collection workflows. Your existing DigitoFina accounts and data are never mixed with the demo.</p>
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
