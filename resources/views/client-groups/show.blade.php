@extends('layouts.app')

@section('content')
<div class="mb-5 flex flex-wrap items-end justify-between gap-3">
    <div>
        <h1 class="text-2xl font-semibold text-slate-950">{{ $group->name }}</h1>
        <p class="text-sm text-slate-500">{{ $group->group_number }} · {{ $group->branch->name }}</p>
    </div>
    <a href="{{ route('client-groups.edit', $group) }}" class="rounded bg-slate-900 px-4 py-2 text-sm font-semibold text-white">Edit Group</a>
</div>
<div class="grid gap-5 xl:grid-cols-3">
    <section class="rounded border border-slate-200 bg-white p-5 shadow-sm">
        <h2 class="font-semibold text-slate-950">Group Profile</h2>
        <dl class="mt-4 grid gap-3 text-sm">
            <div><dt class="text-slate-500">Registration</dt><dd>{{ $group->registration_number ?: 'N/A' }}</dd></div>
            <div><dt class="text-slate-500">Phone</dt><dd>{{ $group->phone ?: 'N/A' }}</dd></div>
            <div><dt class="text-slate-500">Meeting day</dt><dd>{{ $group->meeting_day ?: 'N/A' }}</dd></div>
            <div><dt class="text-slate-500">Status</dt><dd>{{ str($group->status)->headline() }}</dd></div>
            <div><dt class="text-slate-500">Address</dt><dd>{{ $group->address ?: 'N/A' }}</dd></div>
        </dl>
    </section>
    <section class="rounded border border-slate-200 bg-white p-5 shadow-sm">
        <h2 class="font-semibold text-slate-950">Committee</h2>
        <dl class="mt-4 grid gap-3 text-sm">
            <div><dt class="text-slate-500">Chairperson</dt><dd>{{ $group->chairperson?->full_name ?? 'Unassigned' }}</dd></div>
            <div><dt class="text-slate-500">Secretary</dt><dd>{{ $group->secretary?->full_name ?? 'Unassigned' }}</dd></div>
            <div><dt class="text-slate-500">Treasurer</dt><dd>{{ $group->treasurer?->full_name ?? 'Unassigned' }}</dd></div>
        </dl>
    </section>
    <section class="rounded border border-slate-200 bg-white p-5 shadow-sm">
        <h2 class="font-semibold text-slate-950">Group Savings</h2>
        <p class="mt-4 text-sm text-slate-500">Group savings account linkage will connect in the savings phase.</p>
    </section>
</div>
<section class="mt-5 rounded border border-slate-200 bg-white p-5 shadow-sm">
    <h2 class="font-semibold text-slate-950">Members</h2>
    <div class="mt-4 grid gap-3 md:grid-cols-2 xl:grid-cols-3">
        @forelse ($group->members as $member)
            <a href="{{ route('clients.show', $member) }}" class="rounded border border-slate-200 p-3 text-sm hover:bg-slate-50"><span class="font-semibold">{{ $member->client_number }}</span><span class="ml-2 text-slate-500">{{ $member->full_name }}</span><div class="mt-1 text-xs text-slate-500">{{ str($member->pivot->status)->headline() }}</div></a>
        @empty
            <p class="text-sm text-slate-500">No group members assigned.</p>
        @endforelse
    </div>
</section>
@endsection
