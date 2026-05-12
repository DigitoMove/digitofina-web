@extends('layouts.app')

@section('content')
<div class="mb-5">
    <h1 class="text-2xl font-semibold text-slate-950">Create Branch</h1>
    <p class="text-sm text-slate-500">Add a new operating branch.</p>
</div>
<form method="POST" action="{{ route('branches.store') }}" class="rounded border border-slate-200 bg-white p-5 shadow-sm">
    @include('branches._form')
</form>
@endsection
