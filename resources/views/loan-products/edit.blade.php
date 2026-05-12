@extends('layouts.app')
@section('content')
<div class="mb-5 flex items-center justify-between gap-3">
    <h1 class="text-2xl font-semibold">Edit Loan Product</h1>
    <a href="{{ route('loan-products.index') }}" class="rounded border px-4 py-2 text-sm font-semibold">Back</a>
</div>
<form method="POST" action="{{ route('loan-products.update', $product) }}" class="rounded border bg-white p-5">
    @method('PUT')
    @include('loan-products._form')
</form>
@endsection
