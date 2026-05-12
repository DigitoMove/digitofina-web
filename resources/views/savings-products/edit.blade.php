@extends('layouts.app')

@section('content')
<div class="mb-5">
    <h1 class="text-2xl font-semibold text-slate-950">Edit Savings Product</h1>
    <p class="text-sm text-slate-500">{{ $product->code }} · {{ $product->name }}</p>
</div>
<form method="POST" action="{{ route('savings-products.update', $product) }}" class="rounded border border-slate-200 bg-white p-5 shadow-sm">
    @method('PUT')
    @include('savings-products._form')
</form>
@endsection
