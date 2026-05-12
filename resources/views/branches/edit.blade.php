@extends('layouts.app')

@section('content')
<div class="mb-5">
    <h1 class="text-2xl font-semibold text-slate-950">Edit Branch</h1>
    <p class="text-sm text-slate-500">{{ $branch->code }} · {{ $branch->name }}</p>
</div>
<form method="POST" action="{{ route('branches.update', $branch) }}" class="rounded border border-slate-200 bg-white p-5 shadow-sm">
    @method('PUT')
    @include('branches._form')
</form>
@endsection
