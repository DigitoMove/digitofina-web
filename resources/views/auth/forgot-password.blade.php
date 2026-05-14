<!doctype html>
<html lang="en">
<head>
    @php($seo = \App\Support\SeoMetadata::forRequest(request(), ['title' => 'Reset Password']))
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <x-seo-meta :seo="$seo" />
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-[linear-gradient(180deg,#ecfeff_0%,#f8fafc_55%,#f0fdf4_100%)]">
    <main class="grid min-h-screen place-items-center p-6">
        <form method="POST" action="{{ route('password.email') }}" class="w-full max-w-md rounded-3xl bg-white/95 p-8 shadow-panel ring-1 ring-slate-200/80">
            @csrf
            <div class="mb-6">
                <h1 class="text-xl font-semibold text-slate-950">Reset password</h1>
                <p class="mt-1 text-sm text-cyan-700">Enter your staff email to receive a secure reset link.</p>
            </div>
            @if (session('status')) <div class="mb-4 rounded-2xl bg-emerald-50 p-3 text-sm text-emerald-700">{{ session('status') }}</div> @endif
            @if ($errors->any()) <div class="mb-4 rounded-2xl bg-red-50 p-3 text-sm text-red-700">{{ $errors->first() }}</div> @endif
            <label class="block text-sm font-medium text-slate-700">Email</label>
            <input name="email" value="{{ old('email') }}" class="mt-1 h-11 w-full rounded-xl border border-slate-200 px-3 focus:border-cyan-500 focus:ring-cyan-100" required autofocus>
            <button class="mt-6 h-11 w-full rounded-xl bg-slate-950 font-semibold text-white hover:bg-slate-800">Send reset link</button>
            <a href="{{ route('login') }}" class="mt-4 block text-center text-sm font-medium text-cyan-700">Back to login</a>
        </form>
    </main>
</body>
</html>
