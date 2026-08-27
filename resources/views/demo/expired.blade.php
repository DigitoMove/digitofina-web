<!doctype html>
<html lang="en">
<head>
    @php($seo = \App\Support\SeoMetadata::forRequest(request()))
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <x-seo-meta :seo="$seo" />
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-[radial-gradient(circle_at_top,#7e22ce_0%,#3b0764_45%,#09090b_100%)] text-white">
    <main class="grid min-h-screen place-items-center px-5 py-10">
        <section class="w-full max-w-2xl rounded-[2rem] border border-white/15 bg-white/10 p-7 text-center shadow-2xl backdrop-blur sm:p-12">
            <img src="{{ asset('brand/digitofina-logo.svg') }}" alt="DigitoFina" class="mx-auto h-16 w-auto rounded-2xl bg-white px-2">
            <div class="mx-auto mt-10 grid h-16 w-16 place-items-center rounded-full bg-amber-300 text-3xl text-violet-950">⌛</div>
            <h1 class="mt-6 text-3xl font-bold sm:text-4xl">Your DigitoFina demo has ended.</h1>
            <p class="mx-auto mt-4 max-w-xl leading-7 text-violet-100">The 14-day trial expired automatically. Your demo records have not been added to or mixed with any live organization. Talk to us to continue with a full account.</p>
            <div class="mt-8 rounded-2xl bg-white p-5 text-violet-950">
                <p class="text-sm font-bold uppercase tracking-wider text-violet-700">Text • WhatsApp • Call</p>
                <p class="mt-2 text-2xl font-bold">0701 822 382 / 0777 522 214</p>
            </div>
            <div class="mt-7 flex flex-col justify-center gap-3 sm:flex-row">
                <a href="{{ route('login') }}" class="inline-flex min-h-12 items-center justify-center rounded-xl border border-white/20 px-5 font-semibold hover:bg-white/10">Log in to another account</a>
                <a href="{{ route('landing') }}" class="inline-flex min-h-12 items-center justify-center rounded-xl bg-amber-300 px-5 font-bold text-violet-950 hover:bg-amber-200">Return to DigitoFina</a>
            </div>
        </section>
    </main>
</body>
</html>
