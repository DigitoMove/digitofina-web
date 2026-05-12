@extends('layouts.app')

@section('content')
<div class="mb-5">
    <h1 class="text-2xl font-semibold text-slate-950">Edit Client Group</h1>
    <p class="text-sm text-slate-500">{{ $group->group_number }} · {{ $group->name }}</p>
</div>
<form method="POST" action="{{ route('client-groups.update', $group) }}" class="rounded border border-slate-200 bg-white p-5 shadow-sm">
    @method('PUT')
    @include('client-groups._form')
</form>
@endsection
