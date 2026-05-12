@extends('layouts.app')

@section('content')
<div class="mb-5">
    <h1 class="text-2xl font-semibold text-slate-950">Edit Role</h1>
    <p class="text-sm text-slate-500">{{ $role->name }} · {{ $role->slug }}</p>
</div>
<form method="POST" action="{{ route('roles.update', $role) }}" class="rounded border border-slate-200 bg-white p-5 shadow-sm">
    @method('PUT')
    @include('roles._form')
</form>
@endsection
