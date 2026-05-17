@csrf
<div class="grid gap-4 md:grid-cols-2">
    <label class="block text-sm font-medium text-slate-700">Full name
        <input name="name" value="{{ old('name', $user->name) }}" class="mt-1 w-full rounded border-slate-300" required>
    </label>
    <label class="block text-sm font-medium text-slate-700">Email
        <input name="email" value="{{ old('email', $user->email) }}" class="mt-1 w-full rounded border-slate-300" required>
    </label>
    <label class="block text-sm font-medium text-slate-700">Phone
        <input name="phone" value="{{ old('phone', $user->phone) }}" class="mt-1 w-full rounded border-slate-300" required>
    </label>
    <label class="block text-sm font-medium text-slate-700">Position
        <input name="position" value="{{ old('position', $user->position) }}" class="mt-1 w-full rounded border-slate-300">
    </label>
    <label class="block text-sm font-medium text-slate-700">Role
        <select name="role_id" class="mt-1 w-full rounded border-slate-300" required>
            @foreach ($roles as $role)
                <option value="{{ $role->id }}" @selected((string) old('role_id', $user->role_id) === (string) $role->id)>{{ $role->name }}</option>
            @endforeach
        </select>
    </label>
    <label class="block text-sm font-medium text-slate-700">Branch
        <select name="branch_id" class="mt-1 w-full rounded border-slate-300" required>
            <option value="">Select branch</option>
            @foreach ($branches as $branch)
                <option value="{{ $branch->id }}" @selected((string) old('branch_id', $user->branch_id) === (string) $branch->id)>{{ $branch->name }}</option>
            @endforeach
        </select>
    </label>
    <label class="block text-sm font-medium text-slate-700">Status
        <select name="status" class="mt-1 w-full rounded border-slate-300">
            @foreach (['active', 'suspended', 'disabled'] as $status)
                <option value="{{ $status }}" @selected(old('status', $user->status ?? 'active') === $status)>{{ str($status)->headline() }}</option>
            @endforeach
        </select>
    </label>
    <div></div>
    <label class="block text-sm font-medium text-slate-700">Password
        <input name="password" type="password" class="mt-1 w-full rounded border-slate-300" @required(! $user->exists)>
    </label>
    <label class="block text-sm font-medium text-slate-700">Confirm password
        <input name="password_confirmation" type="password" class="mt-1 w-full rounded border-slate-300" @required(! $user->exists)>
    </label>
</div>
<div class="mt-6 flex justify-end gap-3">
    <a href="{{ route('users.index') }}" class="rounded border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700">Cancel</a>
    <button class="rounded bg-blue-700 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-800">Save User</button>
</div>
