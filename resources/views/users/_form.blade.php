@csrf
<div class="grid gap-4 md:grid-cols-2">
    <label class="block text-sm font-medium text-slate-700">Full name <x-required-badge />
        <input name="name" value="{{ old('name', $user->name) }}" autocomplete="off" class="mt-1 w-full rounded border-slate-300" required>
    </label>
    <label class="block text-sm font-medium text-slate-700">Email <x-required-badge />
        <input name="email" type="email" value="{{ old('email', $user->email) }}" autocomplete="off" class="mt-1 w-full rounded border-slate-300" required>
    </label>
    <label class="block text-sm font-medium text-slate-700">Phone <x-required-badge />
        <input name="phone" value="{{ old('phone', $user->phone) }}" autocomplete="off" class="mt-1 w-full rounded border-slate-300" required>
    </label>
    <label class="block text-sm font-medium text-slate-700">Position
        <input name="position" value="{{ old('position', $user->position) }}" autocomplete="off" class="mt-1 w-full rounded border-slate-300">
    </label>
    <label class="block text-sm font-medium text-slate-700">Role <x-required-badge />
        <select name="role_id" data-user-role class="mt-1 w-full rounded border-slate-300" required>
            @foreach ($roles as $role)
                <option value="{{ $role->id }}" @selected((string) old('role_id', $user->role_id) === (string) $role->id)>{{ $role->name }}</option>
            @endforeach
        </select>
    </label>
    @if(auth()->user()->hasGlobalOrganizationAccess())
        <label class="block text-sm font-medium text-slate-700">Organization
            <select name="organization_id" data-user-organization class="mt-1 w-full rounded border-slate-300">
                <option value="">Global / no organization</option>
                @foreach ($organizations as $organization)
                    <option value="{{ $organization->id }}" @selected((string) old('organization_id', $user->organization_id) === (string) $organization->id)>{{ $organization->name }}</option>
                @endforeach
            </select>
        </label>
    @endif
    <label class="block text-sm font-medium text-slate-700">Branch
        <select name="branch_id" data-user-branch class="mt-1 w-full rounded border-slate-300">
            <option value="">No branch / all branches</option>
            @foreach ($branches as $branch)
                <option value="{{ $branch->id }}" @selected((string) old('branch_id', $user->branch_id) === (string) $branch->id)>{{ $branch->organization?->name ? $branch->organization->name.' · ' : '' }}{{ $branch->name }}</option>
            @endforeach
        </select>
        <span class="mt-1 block text-xs text-slate-500">Leave blank to give this user access to all branches within their organization.</span>
    </label>
    <label class="block text-sm font-medium text-slate-700">Status
        <select name="status" class="mt-1 w-full rounded border-slate-300">
            @foreach (['active', 'suspended', 'disabled'] as $status)
                <option value="{{ $status }}" @selected(old('status', $user->status ?? 'active') === $status)>{{ str($status)->headline() }}</option>
            @endforeach
        </select>
    </label>
    @if($user->exists)
        <label class="flex items-center gap-2 text-sm font-medium text-slate-700 md:col-span-2">
            <input type="checkbox" name="change_password" value="1" data-user-password-toggle @checked(old('change_password'))>
            Change this user's password
        </label>
    @else
        <div></div>
    @endif
    <div data-user-password-fields class="grid gap-4 md:col-span-2 md:grid-cols-2 {{ $user->exists && ! old('change_password') ? 'hidden' : '' }}">
        <label class="block text-sm font-medium text-slate-700">Password @unless($user->exists)<x-required-badge label="Required on create" />@endunless
            <input name="password" type="password" autocomplete="new-password" class="mt-1 w-full rounded border-slate-300" @required(! $user->exists) @disabled($user->exists && ! old('change_password'))>
        </label>
        <label class="block text-sm font-medium text-slate-700">Confirm password @unless($user->exists)<x-required-badge label="Required on create" />@endunless
            <input name="password_confirmation" type="password" autocomplete="new-password" class="mt-1 w-full rounded border-slate-300" @required(! $user->exists) @disabled($user->exists && ! old('change_password'))>
        </label>
    </div>
</div>
<div class="mt-6 flex justify-end gap-3">
    <a href="{{ route('users.index') }}" class="rounded border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700">Cancel</a>
    <button class="rounded bg-blue-700 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-800">Save User</button>
</div>
