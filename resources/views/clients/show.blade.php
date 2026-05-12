@extends('layouts.app')

@section('content')
@php
    $kycChecks = [
        'identity_confirmed' => 'Identity confirmed',
        'address_confirmed' => 'Address confirmed',
        'photo_captured' => 'Photo captured',
        'signature_captured' => 'Signature captured',
        'next_of_kin_confirmed' => 'Next of kin confirmed',
    ];
    $documentTypes = [
        'profile_photo' => 'Profile Photo',
        'national_id_front' => 'National ID Front',
        'national_id_back' => 'National ID Back',
        'signature' => 'Signature',
        'loan_application' => 'Loan Application Form',
        'guarantor_form' => 'Guarantor Form',
        'collateral_document' => 'Collateral Document',
        'agreement' => 'Agreement',
        'receipt' => 'Receipt',
        'bank_slip' => 'Bank Slip',
        'mobile_money_proof' => 'Mobile Money Proof',
        'other' => 'Other',
    ];
@endphp

<div class="mb-5 flex flex-wrap items-end justify-between gap-3">
    <div>
        <h1 class="text-2xl font-semibold text-slate-950">{{ $client->full_name ?: $client->business_name }}</h1>
        <p class="text-sm text-slate-500">{{ $client->client_number }} · {{ $client->branch->name }} · {{ str($client->client_type)->headline() }}</p>
    </div>
    <div class="flex gap-2">
        <a href="{{ route('accounts.create', ['client_id' => $client->id]) }}" class="rounded border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700">Open Account</a>
        <a href="{{ route('clients.edit', $client) }}" class="rounded bg-slate-900 px-4 py-2 text-sm font-semibold text-white">Edit</a>
    </div>
</div>

<div class="grid gap-5 xl:grid-cols-3">
    <section class="rounded border border-slate-200 bg-white p-5 shadow-sm">
        <div class="flex items-start justify-between gap-4">
            <div>
                <h2 class="font-semibold text-slate-950">Biodata</h2>
                <p class="mt-1 text-xs text-slate-500">KYC completion {{ $client->kyc_completion_percentage }}%</p>
            </div>
            <span class="rounded px-2 py-1 text-xs font-semibold {{ $client->kyc_status === 'verified' ? 'bg-emerald-50 text-emerald-700' : 'bg-amber-50 text-amber-700' }}">{{ str($client->kyc_status)->headline() }}</span>
        </div>
        <dl class="mt-4 grid gap-3 text-sm">
            <div><dt class="text-slate-500">Phone</dt><dd class="font-medium">{{ $client->phone }}</dd></div>
            <div><dt class="text-slate-500">Alternative phone</dt><dd>{{ $client->alternative_phone ?: 'N/A' }}</dd></div>
            <div><dt class="text-slate-500">Email</dt><dd>{{ $client->email ?: 'N/A' }}</dd></div>
            <div><dt class="text-slate-500">National ID</dt><dd>{{ $client->national_id ?: 'N/A' }}</dd></div>
            <div><dt class="text-slate-500">DOB / Gender</dt><dd>{{ $client->date_of_birth?->format('d M Y') ?? 'N/A' }} · {{ $client->gender ? str($client->gender)->headline() : 'N/A' }}</dd></div>
            <div><dt class="text-slate-500">Officer</dt><dd>{{ $client->officer?->name ?? 'Unassigned' }}</dd></div>
            <div><dt class="text-slate-500">Risk rating</dt><dd>{{ $client->risk_rating ? str($client->risk_rating)->headline() : 'Unrated' }}</dd></div>
        </dl>
    </section>

    <section class="rounded border border-slate-200 bg-white p-5 shadow-sm xl:col-span-2">
        <h2 class="font-semibold text-slate-950">KYC Checklist</h2>
        <form method="POST" action="{{ route('clients.kyc.update', $client) }}" class="mt-4">
            @csrf
            @method('PUT')
            <div class="grid gap-2 md:grid-cols-5">
                @foreach ($kycChecks as $key => $label)
                    <label class="flex items-center gap-2 rounded border border-slate-200 px-3 py-2 text-sm"><input type="checkbox" name="kyc_checklist[]" value="{{ $key }}" class="rounded border-slate-300" @checked((bool) data_get($client->kyc_documents, $key))>{{ $label }}</label>
                @endforeach
            </div>
            <div class="mt-4 grid gap-3 md:grid-cols-[12rem_12rem_auto]">
                <select name="kyc_status" class="rounded border-slate-300">@foreach(['pending','verified','rejected'] as $status)<option value="{{ $status }}" @selected($client->kyc_status === $status)>{{ str($status)->headline() }}</option>@endforeach</select>
                <select name="risk_rating" class="rounded border-slate-300"><option value="">Unrated</option>@foreach(['low','medium','high'] as $rating)<option value="{{ $rating }}" @selected($client->risk_rating === $rating)>{{ str($rating)->headline() }}</option>@endforeach</select>
                <button class="rounded bg-blue-700 px-4 py-2 text-sm font-semibold text-white">Update KYC</button>
            </div>
        </form>
    </section>
</div>

<div class="mt-5 grid gap-5 xl:grid-cols-3">
    <section class="rounded border border-slate-200 bg-white p-5 shadow-sm xl:col-span-2">
        <div class="flex justify-between"><h2 class="font-semibold text-slate-950">Accounts</h2><a class="text-sm font-medium text-blue-700" href="{{ route('accounts.create', ['client_id' => $client->id]) }}">Open account</a></div>
        <div class="mt-4 grid gap-3">@forelse($client->accounts as $account)<a class="rounded border border-slate-200 p-3 text-sm hover:bg-slate-50" href="{{ route('accounts.show', $account) }}"><span class="font-semibold">{{ $account->account_number }}</span><span class="ml-2 text-slate-500">{{ $account->accountType->name }}</span><span class="float-right">{{ number_format($account->balance, 2) }} UGX</span></a>@empty<p class="text-sm text-slate-500">No accounts yet.</p>@endforelse</div>
    </section>
    <section class="rounded border border-slate-200 bg-white p-5 shadow-sm">
        <h2 class="font-semibold text-slate-950">Address</h2>
        <p class="mt-3 text-sm text-slate-700">{{ $client->address ?: 'No physical address recorded.' }}</p>
        <div class="mt-4 grid gap-2 text-sm text-slate-600">
            <div>{{ $client->village }} {{ $client->parish }}</div>
            <div>{{ $client->sub_county }} {{ $client->district }}</div>
            <div>{{ $client->region }}</div>
        </div>
    </section>
</div>

<div class="mt-5 grid gap-5 xl:grid-cols-2">
    <section class="rounded border border-slate-200 bg-white p-5 shadow-sm">
        <h2 class="font-semibold text-slate-950">Documents</h2>
        <form method="POST" action="{{ route('clients.documents.store', $client) }}" enctype="multipart/form-data" class="mt-4 grid gap-3 md:grid-cols-[12rem_1fr]">
            @csrf
            <select name="type" class="rounded border-slate-300">@foreach($documentTypes as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach</select>
            <input name="title" class="rounded border-slate-300" placeholder="Document title" required>
            <input name="document" type="file" class="rounded border border-slate-300 px-3 py-2 text-sm md:col-span-2" required>
            <button class="rounded bg-slate-800 px-4 py-2 text-sm font-semibold text-white md:col-span-2">Upload Document</button>
        </form>
        <div class="mt-4 grid gap-2">
            @forelse ($client->documents as $document)
                <div class="rounded border border-slate-200 p-3 text-sm">
                    <div class="flex items-center justify-between gap-3">
                        <div><span class="font-semibold">{{ $document->title }}</span><span class="ml-2 text-slate-500">{{ str($document->type)->headline() }}</span></div>
                        <a href="{{ route('clients.documents.download', [$client, $document]) }}" class="font-medium text-blue-700">Download</a>
                    </div>
                    <div class="mt-2 flex items-center justify-between gap-3 text-xs text-slate-500">
                        <span>Status: {{ str($document->verification_status)->headline() }}</span>
                        <form method="POST" action="{{ route('clients.documents.verify', [$client, $document]) }}" class="flex gap-2">@csrf @method('PUT')<select name="verification_status" class="rounded border-slate-300 text-xs"><option value="verified">Verified</option><option value="rejected">Rejected</option></select><button class="rounded bg-blue-700 px-2 py-1 font-semibold text-white">Save</button></form>
                    </div>
                </div>
            @empty
                <p class="text-sm text-slate-500">No documents uploaded.</p>
            @endforelse
        </div>
    </section>

    <section class="rounded border border-slate-200 bg-white p-5 shadow-sm">
        <h2 class="font-semibold text-slate-950">Notes</h2>
        <form method="POST" action="{{ route('clients.notes.store', $client) }}" class="mt-4 grid gap-3">
            @csrf
            <select name="category" class="rounded border-slate-300">@foreach(['general','kyc','risk','collections','field_visit'] as $category)<option value="{{ $category }}">{{ str($category)->headline() }}</option>@endforeach</select>
            <textarea name="note" class="rounded border-slate-300" rows="3" placeholder="Add a client note" required></textarea>
            <button class="rounded bg-slate-800 px-4 py-2 text-sm font-semibold text-white">Add Note</button>
        </form>
        <div class="mt-4 grid gap-3">
            @forelse ($client->notes as $note)
                <div class="rounded border border-slate-200 p-3 text-sm"><div class="font-semibold">{{ str($note->category)->headline() }} <span class="font-normal text-slate-500">by {{ $note->user?->name ?? 'System' }}</span></div><p class="mt-1 text-slate-700">{{ $note->note }}</p></div>
            @empty
                <p class="text-sm text-slate-500">No notes recorded.</p>
            @endforelse
        </div>
    </section>
</div>

<section class="mt-5 rounded border border-slate-200 bg-white p-5 shadow-sm">
    <h2 class="font-semibold text-slate-950">Loans & Group Membership</h2>
    <div class="mt-4 grid gap-4 lg:grid-cols-2">
        <div class="grid gap-2">@forelse($client->loans as $loan)<a href="{{ route('loans.show', $loan) }}" class="rounded border border-slate-200 p-3 text-sm hover:bg-slate-50"><span class="font-semibold">{{ $loan->loan_number }}</span><span class="ml-2 text-slate-500">{{ $loan->product->name }}</span><span class="float-right">{{ str($loan->status)->headline() }}</span></a>@empty<p class="text-sm text-slate-500">No loans yet.</p>@endforelse</div>
        <div class="grid gap-2">@forelse($client->groups as $group)<a href="{{ route('client-groups.show', $group) }}" class="rounded border border-slate-200 p-3 text-sm hover:bg-slate-50"><span class="font-semibold">{{ $group->group_number }}</span><span class="ml-2 text-slate-500">{{ $group->name }}</span></a>@empty<p class="text-sm text-slate-500">Not assigned to any group.</p>@endforelse</div>
    </div>
</section>
@endsection
