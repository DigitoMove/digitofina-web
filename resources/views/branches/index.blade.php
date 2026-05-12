@extends('layouts.app')

@section('content')
<div class="mb-5 flex flex-wrap items-end justify-between gap-3">
    <div>
        <h1 class="text-2xl font-semibold text-slate-950">Branches</h1>
        <p class="text-sm text-slate-500">Manage institution locations and branch managers.</p>
    </div>
    <a href="{{ route('branches.create') }}" class="rounded bg-blue-700 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-800">New Branch</a>
</div>

<form class="mb-4"><input name="search" value="{{ request('search') }}" class="w-full rounded border-slate-300" placeholder="Search branch code, name, phone or address"></form>
<div class="overflow-hidden rounded border border-slate-200 bg-white shadow-sm">
    <table class="w-full text-left text-sm">
        <thead class="bg-slate-50 text-slate-600"><tr><th class="p-3">Branch</th><th>Manager</th><th>Phone</th><th>Status</th><th></th></tr></thead>
        <tbody>
            @forelse ($branches as $branch)
                <tr class="border-t">
                    <td class="p-3 font-medium text-slate-950">{{ $branch->code }}<div class="text-slate-500">{{ $branch->name }}</div></td>
                    <td>{{ $branch->manager?->name ?? 'Unassigned' }}</td>
                    <td>{{ $branch->phone ?? '-' }}</td>
                    <td><span class="rounded bg-slate-100 px-2 py-1 text-xs font-semibold">{{ str($branch->status)->headline() }}</span></td>
                    <td><a class="font-medium text-blue-700" href="{{ route('branches.edit', $branch) }}">Edit</a></td>
                </tr>
            @empty
                <tr><td class="p-4 text-slate-500" colspan="5">No branches found.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>
<div class="mt-4">{{ $branches->links() }}</div>
@endsection
