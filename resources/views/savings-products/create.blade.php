@extends('layouts.app')

@section('content')
<div class="mb-5">
    <h1 class="text-2xl font-semibold text-slate-950">Create Savings Product</h1>
    <p class="text-sm text-slate-500">Set terms for a deposit product.</p>
</div>
<form method="POST" action="{{ route('savings-products.store') }}" class="rounded border border-slate-200 bg-white p-5 shadow-sm">
    @include('savings-products._form')
</form>
@endsection
