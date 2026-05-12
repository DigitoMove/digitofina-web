@extends('layouts.app')

@section('content')
<div class="mb-5 flex flex-wrap items-end justify-between gap-3">
    <div>
        <h1 class="text-2xl font-semibold text-slate-950">Client Groups</h1>
        <p class="text-sm text-slate-500">Village savings groups, investment clubs, and group borrowers.</p>
    </div>
    <a href="{{ route('client-groups.create') }}" class="rounded bg-blue-700 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-800">New Group</a>
</div>
<form class="mb-4 grid gap-3 md:grid-cols-[1fr_14rem_12rem_auto]">
    <input name="search" value="{{ request('search') }}" class="rounded border-slate-300" placeholder="Search group name, number, registration or phone">
    <select name="branch_id" class="rounded border-slate-300"><option value="">All branches</option>@foreach($branches as $branch)<option value="{{ $branch->id }}" @selected(request('branch_id') == $branch->id)>{{ $branch->name }}</option>@endforeach</select>
    <select name="status" class="rounded border-slate-300"><option value="">All status</option>@foreach(['active','inactive','suspended','closed'] as $status)<option value="{{ $status }}" @selected(request('status') === $status)>{{ str($status)->headline() }}</option>@endforeach</select>
    <button class="rounded bg-slate-800 px-4 py-2 text-sm font-semibold text-white">Filter</button>
</form>
<div class="overflow-hidden rounded border border-slate-200 bg-white shadow-sm">
    <table class="w-full text-left text-sm">
        <thead class="bg-slate-50 text-slate-600"><tr><th class="p-3">Group</th><th>Branch</th><th>Chairperson</th><th>Members</th><th>Status</th><th></th></tr></thead>
        <tbody>
            @forelse ($groups as $group)
                <tr class="border-t"><td class="p-3 font-medium">{{ $group->group_number }}<div class="text-slate-500">{{ $group->name }}</div></td><td>{{ $group->branch->name }}</td><td>{{ $group->chairperson?->full_name ?? '-' }}</td><td>{{ $group->members->count() }}</td><td>{{ str($group->status)->headline() }}</td><td><a class="font-medium text-blue-700" href="{{ route('client-groups.show', $group) }}">View</a></td></tr>
            @empty
                <tr><td colspan="6" class="p-4 text-slate-500">No groups found.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>
<div class="mt-4">{{ $groups->links() }}</div>
@endsection
