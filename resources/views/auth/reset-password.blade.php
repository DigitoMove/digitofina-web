<!doctype html>
<html lang="en">
<head>
    @php($seo = \App\Support\SeoMetadata::forRequest(request(), ['title' => 'Set New Password']))
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <x-seo-meta :seo="$seo" />
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-[linear-gradient(180deg,#ecfeff_0%,#f8fafc_55%,#f0fdf4_100%)]">
    <main class="grid min-h-screen place-items-center p-6">
        <form method="POST" action="{{ route('password.store') }}" class="w-full max-w-md rounded-3xl bg-white/95 p-8 shadow-panel ring-1 ring-slate-200/80">
            @csrf
            <input type="hidden" name="token" value="{{ $request->route('token') }}">
            <div class="mb-6">
                <h1 class="text-xl font-semibold text-slate-950">Set new password</h1>
                <p class="mt-1 text-sm text-cyan-700">Choose a strong password for your DigitoFina staff account.</p>
            </div>
            @if ($errors->any()) <div class="mb-4 rounded-2xl bg-red-50 p-3 text-sm text-red-700">{{ $errors->first() }}</div> @endif
            <label class="block text-sm font-medium text-slate-700">Email</label>
            <input name="email" value="{{ old('email', $request->email) }}" class="mt-1 h-11 w-full rounded-xl border border-slate-200 px-3 focus:border-cyan-500 focus:ring-cyan-100" required>
            <label class="mt-4 block text-sm font-medium text-slate-700">New password</label>
            <input name="password" type="password" class="mt-1 h-11 w-full rounded-xl border border-slate-200 px-3 focus:border-cyan-500 focus:ring-cyan-100" required>
            <label class="mt-4 block text-sm font-medium text-slate-700">Confirm password</label>
            <input name="password_confirmation" type="password" class="mt-1 h-11 w-full rounded-xl border border-slate-200 px-3 focus:border-cyan-500 focus:ring-cyan-100" required>
            <button class="mt-6 h-11 w-full rounded-xl bg-slate-950 font-semibold text-white hover:bg-slate-800">Update password</button>
        </form>
    </main>
</body>
</html>
