@extends('layouts.app')

@section('content')
<div class="mb-5">
    <h1 class="text-2xl font-semibold text-slate-950">Create Role</h1>
    <p class="text-sm text-slate-500">Create a custom access profile.</p>
</div>
<form method="POST" action="{{ route('roles.store') }}" class="rounded border border-slate-200 bg-white p-5 shadow-sm">
    @include('roles._form')
</form>
@endsection
