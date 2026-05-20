@csrf
<div class="grid gap-4 md:grid-cols-2">
    <label class="block text-sm font-medium text-slate-700">Branch code <x-required-badge />
        <input name="code" value="{{ old('code', $branch->code) }}" class="mt-1 w-full rounded border-slate-300" required>
    </label>
    <label class="block text-sm font-medium text-slate-700">Branch name <x-required-badge />
        <input name="name" value="{{ old('name', $branch->name) }}" class="mt-1 w-full rounded border-slate-300" required>
    </label>
    @if(auth()->user()->hasGlobalOrganizationAccess())
        <label class="block text-sm font-medium text-slate-700">Organization
            <select name="organization_id" class="mt-1 w-full rounded border-slate-300">
                <option value="">No organization</option>
                @foreach ($organizations as $organization)
                    <option value="{{ $organization->id }}" @selected((string) old('organization_id', $branch->organization_id) === (string) $organization->id)>{{ $organization->name }}</option>
                @endforeach
            </select>
        </label>
    @endif
    <label class="block text-sm font-medium text-slate-700">Branch manager
        <select name="manager_user_id" class="mt-1 w-full rounded border-slate-300">
            <option value="">Unassigned</option>
            @foreach ($managers as $manager)
                <option value="{{ $manager->id }}" @selected((string) old('manager_user_id', $branch->manager_user_id) === (string) $manager->id)>{{ $manager->name }}</option>
            @endforeach
        </select>
    </label>
    <label class="block text-sm font-medium text-slate-700">Phone
        <input name="phone" value="{{ old('phone', $branch->phone) }}" class="mt-1 w-full rounded border-slate-300">
    </label>
    <label class="block text-sm font-medium text-slate-700 md:col-span-2">Address
        <input name="address" value="{{ old('address', $branch->address) }}" class="mt-1 w-full rounded border-slate-300">
    </label>
    <label class="block text-sm font-medium text-slate-700">Status
        <select name="status" class="mt-1 w-full rounded border-slate-300">
            @foreach (['active', 'inactive', 'suspended'] as $status)
                <option value="{{ $status }}" @selected(old('status', $branch->status ?? 'active') === $status)>{{ str($status)->headline() }}</option>
            @endforeach
        </select>
    </label>
</div>
<div class="mt-6 flex justify-end gap-3">
    <a href="{{ route('branches.index') }}" class="rounded border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700">Cancel</a>
    <button class="rounded bg-blue-700 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-800">Save Branch</button>
</div>
