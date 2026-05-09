@csrf
<div class="grid gap-4 md:grid-cols-2">
    <label class="text-sm font-medium">Branch<select name="branch_id" class="mt-1 w-full rounded border-slate-300">@foreach($branches as $branch)<option value="{{ $branch->id }}" @selected(old('branch_id', $client->branch_id ?? '') == $branch->id)>{{ $branch->name }}</option>@endforeach</select></label>
    <label class="text-sm font-medium">Status<select name="status" class="mt-1 w-full rounded border-slate-300"><option value="active" @selected(old('status', $client->status ?? 'active') === 'active')>Active</option><option value="inactive" @selected(old('status', $client->status ?? '') === 'inactive')>Inactive</option></select></label>
    <label class="text-sm font-medium">First name<input name="first_name" value="{{ old('first_name', $client->first_name ?? '') }}" class="mt-1 w-full rounded border-slate-300" required></label>
    <label class="text-sm font-medium">Last name<input name="last_name" value="{{ old('last_name', $client->last_name ?? '') }}" class="mt-1 w-full rounded border-slate-300" required></label>
    <label class="text-sm font-medium">Phone<input name="phone" value="{{ old('phone', $client->phone ?? '') }}" class="mt-1 w-full rounded border-slate-300" required></label>
    <label class="text-sm font-medium">Email<input name="email" value="{{ old('email', $client->email ?? '') }}" class="mt-1 w-full rounded border-slate-300"></label>
    <label class="text-sm font-medium">National ID<input name="national_id" value="{{ old('national_id', $client->national_id ?? '') }}" class="mt-1 w-full rounded border-slate-300"></label>
    <label class="text-sm font-medium">KYC status<select name="kyc_status" class="mt-1 w-full rounded border-slate-300"><option value="pending" @selected(old('kyc_status', $client->kyc_status ?? 'pending') === 'pending')>Pending</option><option value="verified" @selected(old('kyc_status', $client->kyc_status ?? '') === 'verified')>Verified</option><option value="rejected" @selected(old('kyc_status', $client->kyc_status ?? '') === 'rejected')>Rejected</option></select></label>
    <label class="text-sm font-medium md:col-span-2">Address<textarea name="address" class="mt-1 w-full rounded border-slate-300">{{ old('address', $client->address ?? '') }}</textarea></label>
    <label class="text-sm font-medium">Next of kin<input name="next_of_kin_name" value="{{ old('next_of_kin_name', $client->next_of_kin_name ?? '') }}" class="mt-1 w-full rounded border-slate-300"></label>
    <label class="text-sm font-medium">Next of kin phone<input name="next_of_kin_phone" value="{{ old('next_of_kin_phone', $client->next_of_kin_phone ?? '') }}" class="mt-1 w-full rounded border-slate-300"></label>
    <label class="text-sm font-medium">Employment/business type<input name="employment_status" value="{{ old('employment_status', $client->employment_status ?? '') }}" class="mt-1 w-full rounded border-slate-300"></label>
    <label class="text-sm font-medium">Business name<input name="business_name" value="{{ old('business_name', $client->business_name ?? '') }}" class="mt-1 w-full rounded border-slate-300"></label>
    <label class="text-sm font-medium md:col-span-2">Business details<textarea name="business_details" class="mt-1 w-full rounded border-slate-300">{{ old('business_details', $client->business_details ?? '') }}</textarea></label>
</div>
<button class="mt-6 rounded bg-blue-700 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-800">Save client</button>
