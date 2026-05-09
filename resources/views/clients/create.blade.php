@extends('layouts.app')
@section('content')
<h1 class="mb-5 text-2xl font-semibold">Register Client</h1>
<form method="POST" action="{{ route('clients.store') }}" class="rounded border border-slate-200 bg-white p-5 shadow-sm">@include('clients._form')</form>
@endsection
