@extends('layouts.app')
@section('content')
<h1 class="mb-5 text-2xl font-semibold">Edit Client</h1>
<form method="POST" action="{{ route('clients.update', $client) }}" class="rounded border border-slate-200 bg-white p-5 shadow-sm">@method('PUT')@include('clients._form')</form>
@endsection
