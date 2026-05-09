<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Login | DigitoFina</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-slate-100">
    <main class="grid min-h-screen place-items-center p-6">
        <form method="POST" action="{{ route('login') }}" class="w-full max-w-md rounded bg-white p-8 shadow-sm ring-1 ring-slate-200">
            @csrf
            <div class="mb-8 flex items-center gap-3">
                <div class="grid h-12 w-12 place-items-center rounded bg-blue-700 text-lg font-bold text-white">DF</div>
                <div>
                    <h1 class="text-xl font-semibold text-slate-950">DigitoFina</h1>
                    <p class="text-sm text-slate-500">Secure financial operations portal</p>
                </div>
            </div>
            @if ($errors->any()) <div class="mb-4 rounded bg-red-50 p-3 text-sm text-red-700">{{ $errors->first() }}</div> @endif
            <label class="block text-sm font-medium text-slate-700">Email</label>
            <input name="email" value="{{ old('email') }}" class="mt-1 h-11 w-full rounded border border-slate-300 px-3 focus:border-blue-600 focus:ring-blue-600" required autofocus>
            <label class="mt-4 block text-sm font-medium text-slate-700">Password</label>
            <input name="password" type="password" class="mt-1 h-11 w-full rounded border border-slate-300 px-3 focus:border-blue-600 focus:ring-blue-600" required>
            <label class="mt-4 flex items-center gap-2 text-sm text-slate-600"><input type="checkbox" name="remember" class="rounded border-slate-300"> Remember this workstation</label>
            <button class="mt-6 h-11 w-full rounded bg-blue-700 font-semibold text-white hover:bg-blue-800">Login</button>
            <p class="mt-5 text-center text-xs text-slate-500">Demo: admin@digitofina.test / password</p>
        </form>
    </main>
</body>
</html>
