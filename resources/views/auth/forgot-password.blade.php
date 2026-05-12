<!doctype html>
<html lang="en">
<head>
    @php($seo = \App\Support\SeoMetadata::forRequest(request(), ['title' => 'Reset Password']))
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <x-seo-meta :seo="$seo" />
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-slate-100">
    <main class="grid min-h-screen place-items-center p-6">
        <form method="POST" action="{{ route('password.email') }}" class="w-full max-w-md rounded bg-white p-8 shadow-sm ring-1 ring-slate-200">
            @csrf
            <div class="mb-6">
                <h1 class="text-xl font-semibold text-slate-950">Reset password</h1>
                <p class="mt-1 text-sm text-slate-500">Enter your staff email to receive a secure reset link.</p>
            </div>
            @if (session('status')) <div class="mb-4 rounded bg-emerald-50 p-3 text-sm text-emerald-700">{{ session('status') }}</div> @endif
            @if ($errors->any()) <div class="mb-4 rounded bg-red-50 p-3 text-sm text-red-700">{{ $errors->first() }}</div> @endif
            <label class="block text-sm font-medium text-slate-700">Email</label>
            <input name="email" value="{{ old('email') }}" class="mt-1 h-11 w-full rounded border border-slate-300 px-3 focus:border-blue-600 focus:ring-blue-600" required autofocus>
            <button class="mt-6 h-11 w-full rounded bg-blue-700 font-semibold text-white hover:bg-blue-800">Send reset link</button>
            <a href="{{ route('login') }}" class="mt-4 block text-center text-sm font-medium text-blue-700">Back to login</a>
        </form>
    </main>
</body>
</html>
