@extends('layouts.app')

@section('content')
<div class="mb-5">
    <h1 class="text-2xl font-semibold text-slate-950">Create Client Group</h1>
    <p class="text-sm text-slate-500">Register a group, committee roles, and members.</p>
</div>
<form method="POST" action="{{ route('client-groups.store') }}" class="rounded border border-slate-200 bg-white p-5 shadow-sm">
    @include('client-groups._form')
</form>
@endsection
