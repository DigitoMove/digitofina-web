@csrf
<div class="grid gap-4 md:grid-cols-2">
    <label class="block text-sm font-medium text-slate-700">Role name <x-required-badge />
        <input name="name" value="{{ old('name', $role->name) }}" class="mt-1 w-full rounded border-slate-300" required>
    </label>
    <label class="block text-sm font-medium text-slate-700">Slug <x-required-badge />
        <input name="slug" value="{{ old('slug', $role->slug) }}" class="mt-1 w-full rounded border-slate-300" required>
    </label>
</div>

<div class="mt-6 grid gap-4 lg:grid-cols-2">
    @foreach ($permissions as $group => $items)
        <section class="rounded border border-slate-200 p-4">
            <h2 class="text-sm font-semibold text-slate-950">{{ str($group)->headline() }}</h2>
            <div class="mt-3 grid gap-2">
                @foreach ($items as $permission)
                    <label class="flex items-center gap-2 text-sm text-slate-700">
                        <input type="checkbox" name="permissions[]" value="{{ $permission->id }}" class="rounded border-slate-300" @checked(in_array($permission->id, old('permissions', $role->permissions?->pluck('id')->all() ?? []), true))>
                        <span>{{ $permission->name }}</span>
                    </label>
                @endforeach
            </div>
        </section>
    @endforeach
</div>
<div class="mt-6 flex justify-end gap-3">
    <a href="{{ route('roles.index') }}" class="rounded border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700">Cancel</a>
    <button class="rounded bg-blue-700 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-800">Save Role</button>
</div>
