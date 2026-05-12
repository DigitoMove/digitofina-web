@extends('layouts.app')
@section('content')
<h1 class="mb-5 text-2xl font-semibold">Create Loan Product</h1>
<form method="POST" action="{{ route('loan-products.store') }}" class="rounded border bg-white p-5">
    @include('loan-products._form')
</form>
@endsection
