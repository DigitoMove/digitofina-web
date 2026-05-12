@csrf
<div class="grid gap-4 md:grid-cols-2">
    <label class="text-sm font-medium text-slate-700">Group number<input name="group_number" value="{{ old('group_number', $group->group_number) }}" class="mt-1 w-full rounded border-slate-300" required></label>
    <label class="text-sm font-medium text-slate-700">Group name<input name="name" value="{{ old('name', $group->name) }}" class="mt-1 w-full rounded border-slate-300" required></label>
    <label class="text-sm font-medium text-slate-700">Branch<select name="branch_id" class="mt-1 w-full rounded border-slate-300">@foreach($branches as $branch)<option value="{{ $branch->id }}" @selected(old('branch_id', $group->branch_id) == $branch->id)>{{ $branch->name }}</option>@endforeach</select></label>
    <label class="text-sm font-medium text-slate-700">Registration number<input name="registration_number" value="{{ old('registration_number', $group->registration_number) }}" class="mt-1 w-full rounded border-slate-300"></label>
    <label class="text-sm font-medium text-slate-700">Phone<input name="phone" value="{{ old('phone', $group->phone) }}" class="mt-1 w-full rounded border-slate-300"></label>
    <label class="text-sm font-medium text-slate-700">Meeting day<input name="meeting_day" value="{{ old('meeting_day', $group->meeting_day) }}" class="mt-1 w-full rounded border-slate-300"></label>
    <label class="text-sm font-medium text-slate-700 md:col-span-2">Address<textarea name="address" class="mt-1 w-full rounded border-slate-300" rows="2">{{ old('address', $group->address) }}</textarea></label>
    <label class="text-sm font-medium text-slate-700">Chairperson<select name="chairperson_client_id" class="mt-1 w-full rounded border-slate-300"><option value="">Unassigned</option>@foreach($clients as $client)<option value="{{ $client->id }}" @selected(old('chairperson_client_id', $group->chairperson_client_id) == $client->id)>{{ $client->client_number }} · {{ $client->full_name }}</option>@endforeach</select></label>
    <label class="text-sm font-medium text-slate-700">Secretary<select name="secretary_client_id" class="mt-1 w-full rounded border-slate-300"><option value="">Unassigned</option>@foreach($clients as $client)<option value="{{ $client->id }}" @selected(old('secretary_client_id', $group->secretary_client_id) == $client->id)>{{ $client->client_number }} · {{ $client->full_name }}</option>@endforeach</select></label>
    <label class="text-sm font-medium text-slate-700">Treasurer<select name="treasurer_client_id" class="mt-1 w-full rounded border-slate-300"><option value="">Unassigned</option>@foreach($clients as $client)<option value="{{ $client->id }}" @selected(old('treasurer_client_id', $group->treasurer_client_id) == $client->id)>{{ $client->client_number }} · {{ $client->full_name }}</option>@endforeach</select></label>
    <label class="text-sm font-medium text-slate-700">Status<select name="status" class="mt-1 w-full rounded border-slate-300">@foreach(['active','inactive','suspended','closed'] as $status)<option value="{{ $status }}" @selected(old('status', $group->status ?? 'active') === $status)>{{ str($status)->headline() }}</option>@endforeach</select></label>
</div>
<section class="mt-5 rounded border border-slate-200 p-4">
    <h2 class="text-sm font-semibold text-slate-950">Members</h2>
    <div class="mt-3 grid max-h-72 gap-2 overflow-auto md:grid-cols-2">
        @foreach ($clients as $client)
            <label class="flex items-center gap-2 rounded border border-slate-200 px-3 py-2 text-sm"><input type="checkbox" name="member_ids[]" value="{{ $client->id }}" class="rounded border-slate-300" @checked(in_array($client->id, old('member_ids', $group->exists ? $group->members->pluck('id')->all() : []), true))>{{ $client->client_number }} · {{ $client->full_name }}</label>
        @endforeach
    </div>
</section>
<div class="mt-6 flex justify-end gap-3"><a href="{{ route('client-groups.index') }}" class="rounded border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700">Cancel</a><button class="rounded bg-blue-700 px-4 py-2 text-sm font-semibold text-white">Save Group</button></div>
