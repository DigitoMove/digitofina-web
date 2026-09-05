@extends('layouts.app')

@section('content')
<div class="mb-5">
    <h1 class="text-2xl font-semibold text-slate-950">Create Staff User</h1>
    <p class="text-sm text-slate-500">Add a staff account and assign operational access.</p>
</div>
<form method="POST" action="{{ route('users.store') }}" autocomplete="off" data-user-form data-user-options-url="{{ route('users.form-options') }}" class="rounded border border-slate-200 bg-white p-5 shadow-sm">
    @include('users._form')
</form>
@endsection
