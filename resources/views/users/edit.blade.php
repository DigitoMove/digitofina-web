@extends('layouts.app')

@section('content')
<div class="mb-5">
    <h1 class="text-2xl font-semibold text-slate-950">Edit User</h1>
    <p class="text-sm text-slate-500">{{ $user->name }} · {{ $user->email }}</p>
</div>
<form method="POST" action="{{ route('users.update', $user) }}" class="rounded border border-slate-200 bg-white p-5 shadow-sm">
    @method('PUT')
    @include('users._form')
</form>
@endsection
