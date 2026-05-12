@csrf
@php
    $kycChecks = [
        'identity_confirmed' => 'Identity confirmed',
        'address_confirmed' => 'Address confirmed',
        'photo_captured' => 'Photo captured',
        'signature_captured' => 'Signature captured',
        'next_of_kin_confirmed' => 'Next of kin confirmed',
    ];
    $checkedKyc = old('kyc_checklist', collect($client->kyc_documents ?? [])->filter()->keys()->all());
@endphp

<div class="grid gap-5">
    <section>
        <h2 class="text-lg font-semibold text-slate-950">Client Type & Status</h2>
        <div class="mt-4 grid gap-4 md:grid-cols-4">
            <label class="text-sm font-medium text-slate-700">Client type
                <select name="client_type" class="mt-1 w-full rounded border-slate-300">
                    @foreach (['individual', 'group', 'business', 'institution'] as $type)
                        <option value="{{ $type }}" @selected(old('client_type', $client->client_type ?? 'individual') === $type)>{{ str($type)->headline() }}</option>
                    @endforeach
                </select>
            </label>
            <label class="text-sm font-medium text-slate-700">Branch
                <select name="branch_id" class="mt-1 w-full rounded border-slate-300">@foreach($branches as $branch)<option value="{{ $branch->id }}" @selected(old('branch_id', $client->branch_id ?? '') == $branch->id)>{{ $branch->name }}</option>@endforeach</select>
            </label>
            <label class="text-sm font-medium text-slate-700">Client status
                <select name="status" class="mt-1 w-full rounded border-slate-300">
                    @foreach (['pending', 'active', 'dormant', 'suspended', 'blacklisted', 'closed'] as $status)
                        <option value="{{ $status }}" @selected(old('status', $client->status ?? 'active') === $status)>{{ str($status)->headline() }}</option>
                    @endforeach
                </select>
            </label>
            <label class="text-sm font-medium text-slate-700">Risk rating
                <select name="risk_rating" class="mt-1 w-full rounded border-slate-300">
                    <option value="">Unrated</option>
                    @foreach (['low', 'medium', 'high'] as $rating)
                        <option value="{{ $rating }}" @selected(old('risk_rating', $client->risk_rating) === $rating)>{{ str($rating)->headline() }}</option>
                    @endforeach
                </select>
            </label>
        </div>
    </section>

    <section class="border-t border-slate-200 pt-5">
        <h2 class="text-lg font-semibold text-slate-950">Biodata</h2>
        <div class="mt-4 grid gap-4 md:grid-cols-3">
            <label class="text-sm font-medium text-slate-700">First name<input name="first_name" value="{{ old('first_name', $client->first_name) }}" class="mt-1 w-full rounded border-slate-300"></label>
            <label class="text-sm font-medium text-slate-700">Middle name<input name="middle_name" value="{{ old('middle_name', $client->middle_name) }}" class="mt-1 w-full rounded border-slate-300"></label>
            <label class="text-sm font-medium text-slate-700">Last name<input name="last_name" value="{{ old('last_name', $client->last_name) }}" class="mt-1 w-full rounded border-slate-300"></label>
            <label class="text-sm font-medium text-slate-700">Gender<select name="gender" class="mt-1 w-full rounded border-slate-300"><option value="">Not specified</option>@foreach(['male', 'female', 'other'] as $gender)<option value="{{ $gender }}" @selected(old('gender', $client->gender) === $gender)>{{ str($gender)->headline() }}</option>@endforeach</select></label>
            <label class="text-sm font-medium text-slate-700">Date of birth<input type="date" name="date_of_birth" value="{{ old('date_of_birth', $client->date_of_birth?->toDateString()) }}" class="mt-1 w-full rounded border-slate-300"></label>
            <label class="text-sm font-medium text-slate-700">Nationality<input name="nationality" value="{{ old('nationality', $client->nationality ?? 'Ugandan') }}" class="mt-1 w-full rounded border-slate-300"></label>
            <label class="text-sm font-medium text-slate-700">National ID / NIN<input name="national_id" value="{{ old('national_id', $client->national_id) }}" class="mt-1 w-full rounded border-slate-300"></label>
            <label class="text-sm font-medium text-slate-700">Phone<input name="phone" value="{{ old('phone', $client->phone) }}" class="mt-1 w-full rounded border-slate-300" required></label>
            <label class="text-sm font-medium text-slate-700">Alternative phone<input name="alternative_phone" value="{{ old('alternative_phone', $client->alternative_phone) }}" class="mt-1 w-full rounded border-slate-300"></label>
            <label class="text-sm font-medium text-slate-700">Email<input name="email" value="{{ old('email', $client->email) }}" class="mt-1 w-full rounded border-slate-300"></label>
            <label class="text-sm font-medium text-slate-700">Occupation<input name="occupation" value="{{ old('occupation', $client->occupation) }}" class="mt-1 w-full rounded border-slate-300"></label>
            <label class="text-sm font-medium text-slate-700">Marital status<input name="marital_status" value="{{ old('marital_status', $client->marital_status) }}" class="mt-1 w-full rounded border-slate-300"></label>
        </div>
    </section>

    <section class="border-t border-slate-200 pt-5">
        <h2 class="text-lg font-semibold text-slate-950">Address & Business</h2>
        <div class="mt-4 grid gap-4 md:grid-cols-3">
            <label class="text-sm font-medium text-slate-700 md:col-span-3">Physical address<textarea name="address" class="mt-1 w-full rounded border-slate-300" rows="2">{{ old('address', $client->address) }}</textarea></label>
            <label class="text-sm font-medium text-slate-700">Village / Zone<input name="village" value="{{ old('village', $client->village) }}" class="mt-1 w-full rounded border-slate-300"></label>
            <label class="text-sm font-medium text-slate-700">Parish<input name="parish" value="{{ old('parish', $client->parish) }}" class="mt-1 w-full rounded border-slate-300"></label>
            <label class="text-sm font-medium text-slate-700">Sub-county<input name="sub_county" value="{{ old('sub_county', $client->sub_county) }}" class="mt-1 w-full rounded border-slate-300"></label>
            <label class="text-sm font-medium text-slate-700">District<input name="district" value="{{ old('district', $client->district) }}" class="mt-1 w-full rounded border-slate-300"></label>
            <label class="text-sm font-medium text-slate-700">Region<input name="region" value="{{ old('region', $client->region) }}" class="mt-1 w-full rounded border-slate-300"></label>
            <label class="text-sm font-medium text-slate-700">Employer / business name<input name="employer_name" value="{{ old('employer_name', $client->employer_name) }}" class="mt-1 w-full rounded border-slate-300"></label>
            <label class="text-sm font-medium text-slate-700">Employment/business type<input name="employment_status" value="{{ old('employment_status', $client->employment_status) }}" class="mt-1 w-full rounded border-slate-300"></label>
            <label class="text-sm font-medium text-slate-700">Business name<input name="business_name" value="{{ old('business_name', $client->business_name) }}" class="mt-1 w-full rounded border-slate-300"></label>
            <label class="text-sm font-medium text-slate-700 md:col-span-3">Business details<textarea name="business_details" class="mt-1 w-full rounded border-slate-300" rows="2">{{ old('business_details', $client->business_details) }}</textarea></label>
        </div>
    </section>

    <section class="border-t border-slate-200 pt-5">
        <h2 class="text-lg font-semibold text-slate-950">Next of Kin & KYC</h2>
        <div class="mt-4 grid gap-4 md:grid-cols-3">
            <label class="text-sm font-medium text-slate-700">Next of kin<input name="next_of_kin_name" value="{{ old('next_of_kin_name', $client->next_of_kin_name) }}" class="mt-1 w-full rounded border-slate-300"></label>
            <label class="text-sm font-medium text-slate-700">Next of kin phone<input name="next_of_kin_phone" value="{{ old('next_of_kin_phone', $client->next_of_kin_phone) }}" class="mt-1 w-full rounded border-slate-300"></label>
            <label class="text-sm font-medium text-slate-700">KYC status<select name="kyc_status" class="mt-1 w-full rounded border-slate-300">@foreach(['pending', 'verified', 'rejected'] as $status)<option value="{{ $status }}" @selected(old('kyc_status', $client->kyc_status ?? 'pending') === $status)>{{ str($status)->headline() }}</option>@endforeach</select></label>
            <label class="text-sm font-medium text-slate-700">GPS latitude<input name="gps_latitude" value="{{ old('gps_latitude', $client->gps_latitude) }}" class="mt-1 w-full rounded border-slate-300"></label>
            <label class="text-sm font-medium text-slate-700">GPS longitude<input name="gps_longitude" value="{{ old('gps_longitude', $client->gps_longitude) }}" class="mt-1 w-full rounded border-slate-300"></label>
            <label class="text-sm font-medium text-slate-700">Profile photo<input type="file" name="profile_photo" class="mt-1 w-full rounded border border-slate-300 bg-white px-3 py-2 text-sm"></label>
        </div>
        <div class="mt-4 grid gap-2 md:grid-cols-5">
            @foreach ($kycChecks as $key => $label)
                <label class="flex items-center gap-2 rounded border border-slate-200 px-3 py-2 text-sm text-slate-700"><input type="checkbox" name="kyc_checklist[]" value="{{ $key }}" class="rounded border-slate-300" @checked(in_array($key, $checkedKyc, true))>{{ $label }}</label>
            @endforeach
        </div>
    </section>
</div>
<button class="mt-6 rounded bg-blue-700 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-800">Save client</button>
