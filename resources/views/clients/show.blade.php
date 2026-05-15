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
        <h1 class="df-title">{{ $client->full_name ?: $client->business_name }}</h1>
        <p class="df-muted">{{ $client->client_number }} · {{ $client->branch->name }} · {{ str($client->client_type)->headline() }}</p>
    </div>
    <div class="flex flex-wrap gap-2">
        <a href="{{ route('accounts.create', ['client_id' => $client->id]) }}" class="df-action-secondary">Open Account</a>
        <a href="{{ route('clients.edit', $client) }}" class="df-action-primary">Edit Client</a>
    </div>
</div>

<div class="mb-5 grid gap-4 md:grid-cols-4">
    <div class="df-panel df-hover-lift p-4">
        <div class="text-xs font-semibold uppercase tracking-wide text-slate-500">KYC completion</div>
        <div class="mt-2 text-2xl font-semibold text-slate-950">{{ $client->kyc_completion_percentage }}%</div>
        <div class="mt-3 h-2 rounded-full bg-slate-100">
            <div class="h-2 rounded-full bg-brand-600" style="width: {{ $client->kyc_completion_percentage }}%"></div>
        </div>
    </div>
    <div class="df-panel df-hover-lift p-4">
        <div class="text-xs font-semibold uppercase tracking-wide text-slate-500">Status</div>
        <div class="mt-2 text-2xl font-semibold text-slate-950">{{ str($client->status)->headline() }}</div>
        <span class="df-badge mt-3 {{ $client->status === 'active' ? 'border-forest-500/20 bg-forest-50 text-forest-700' : 'border-sun-400/20 bg-sun-50 text-sun-600' }}">{{ str($client->kyc_status)->headline() }}</span>
    </div>
    <div class="df-panel df-hover-lift p-4">
        <div class="text-xs font-semibold uppercase tracking-wide text-slate-500">Accounts</div>
        <div class="mt-2 text-2xl font-semibold text-slate-950">{{ number_format($client->accounts->count()) }}</div>
        <p class="mt-3 text-sm text-slate-500">Savings and operating accounts</p>
    </div>
    <div class="df-panel df-hover-lift p-4">
        <div class="text-xs font-semibold uppercase tracking-wide text-slate-500">Loans</div>
        <div class="mt-2 text-2xl font-semibold text-slate-950">{{ number_format($client->loans->count()) }}</div>
        <p class="mt-3 text-sm text-slate-500">{{ $client->risk_rating ? str($client->risk_rating)->headline().' risk' : 'Risk not rated' }}</p>
    </div>
</div>

<div data-df-tabs class="grid gap-5">
    <div class="df-tablist" role="tablist">
        <button type="button" class="df-tab" data-df-tab-target="overview">Overview</button>
        <button type="button" class="df-tab" data-df-tab-target="kyc">KYC & Documents</button>
        <button type="button" class="df-tab" data-df-tab-target="accounts">Accounts</button>
        <button type="button" class="df-tab" data-df-tab-target="loans">Loans & Groups</button>
        <button type="button" class="df-tab" data-df-tab-target="notes">Notes</button>
    </div>

    <section data-df-tab-panel="overview" class="grid gap-5 xl:grid-cols-3">
        <div class="df-panel p-5 xl:col-span-2">
            <h2 class="font-semibold text-slate-950">Biodata</h2>
            <dl class="mt-4 grid gap-4 text-sm md:grid-cols-2">
                <div class="df-soft-panel p-3"><dt class="text-slate-500">Phone</dt><dd class="mt-1 font-semibold">{{ $client->phone }}</dd></div>
                <div class="df-soft-panel p-3"><dt class="text-slate-500">Alternative phone</dt><dd class="mt-1">{{ $client->alternative_phone ?: 'N/A' }}</dd></div>
                <div class="df-soft-panel p-3"><dt class="text-slate-500">Email</dt><dd class="mt-1">{{ $client->email ?: 'N/A' }}</dd></div>
                <div class="df-soft-panel p-3"><dt class="text-slate-500">National ID</dt><dd class="mt-1">{{ $client->national_id ?: 'N/A' }}</dd></div>
                <div class="df-soft-panel p-3"><dt class="text-slate-500">DOB / Gender</dt><dd class="mt-1">{{ $client->date_of_birth?->format('d M Y') ?? 'N/A' }} · {{ $client->gender ? str($client->gender)->headline() : 'N/A' }}</dd></div>
                <div class="df-soft-panel p-3"><dt class="text-slate-500">Officer</dt><dd class="mt-1">{{ $client->officer?->name ?? 'Unassigned' }}</dd></div>
                <div class="df-soft-panel p-3"><dt class="text-slate-500">Occupation</dt><dd class="mt-1">{{ $client->occupation ?: 'N/A' }}</dd></div>
                <div class="df-soft-panel p-3"><dt class="text-slate-500">Next of kin</dt><dd class="mt-1">{{ $client->next_of_kin ?: 'N/A' }} {{ $client->next_of_kin_phone ? '· '.$client->next_of_kin_phone : '' }}</dd></div>
            </dl>
        </div>

        <div class="df-panel p-5">
            <h2 class="font-semibold text-slate-950">Address</h2>
            <p class="mt-3 text-sm leading-6 text-slate-700">{{ $client->address ?: 'No physical address recorded.' }}</p>
            <div class="mt-4 grid gap-2 text-sm text-slate-600">
                <div>{{ trim($client->village.' '.$client->parish) ?: 'Village / parish not recorded' }}</div>
                <div>{{ trim($client->sub_county.' '.$client->district) ?: 'Sub-county / district not recorded' }}</div>
                <div>{{ $client->region ?: 'Region not recorded' }}</div>
            </div>
        </div>
    </section>

    <section data-df-tab-panel="kyc" class="grid gap-5 xl:grid-cols-[1fr_1.1fr]">
        <div class="df-panel p-5">
            <h2 class="font-semibold text-slate-950">KYC Checklist</h2>
            <form method="POST" action="{{ route('clients.kyc.update', $client) }}" class="mt-4 grid gap-4">
                @csrf
                @method('PUT')
                <div class="grid gap-2 sm:grid-cols-2">
                    @foreach ($kycChecks as $key => $label)
                        <label class="df-soft-panel flex cursor-pointer items-center gap-3 p-3 text-sm font-medium transition hover:border-brand-300 hover:bg-brand-50">
                            <input type="checkbox" name="kyc_checklist[]" value="{{ $key }}" @checked((bool) data_get($client->kyc_documents, $key))>
                            <span>{{ $label }}</span>
                        </label>
                    @endforeach
                </div>
                <div class="grid gap-3 md:grid-cols-[1fr_1fr_auto]">
                    <select name="kyc_status">
                        @foreach(['pending','verified','rejected'] as $status)
                            <option value="{{ $status }}" @selected($client->kyc_status === $status)>{{ str($status)->headline() }}</option>
                        @endforeach
                    </select>
                    <select name="risk_rating">
                        <option value="">Unrated</option>
                        @foreach(['low','medium','high'] as $rating)
                            <option value="{{ $rating }}" @selected($client->risk_rating === $rating)>{{ str($rating)->headline() }}</option>
                        @endforeach
                    </select>
                    <button class="df-action-primary">Update KYC</button>
                </div>
            </form>
        </div>

        <div class="df-panel p-5">
            <h2 class="font-semibold text-slate-950">Documents</h2>
            <form method="POST" action="{{ route('clients.documents.store', $client) }}" enctype="multipart/form-data" class="mt-4 grid gap-3 md:grid-cols-[12rem_1fr]">
                @csrf
                <select name="type">
                    @foreach($documentTypes as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </select>
                <input name="title" placeholder="Document title" required>
                <input name="document" type="file" class="md:col-span-2" required>
                <button class="df-action-secondary md:col-span-2">Upload Document</button>
            </form>
            <div class="mt-4 grid gap-3">
                @forelse ($client->documents as $document)
                    <div class="df-soft-panel p-3 text-sm">
                        <div class="flex items-center justify-between gap-3">
                            <div><span class="font-semibold">{{ $document->title }}</span><span class="ml-2 text-slate-500">{{ str($document->type)->headline() }}</span></div>
                            <a href="{{ route('clients.documents.download', [$client, $document]) }}" class="font-semibold text-blue-700">Download</a>
                        </div>
                        <div class="mt-3 flex flex-wrap items-center justify-between gap-3 text-xs text-slate-500">
                            <span>Status: {{ str($document->verification_status)->headline() }}</span>
                            <form method="POST" action="{{ route('clients.documents.verify', [$client, $document]) }}" class="flex items-center gap-2">
                                @csrf
                                @method('PUT')
                                <select name="verification_status" class="min-h-9 text-xs">
                                    <option value="verified">Verified</option>
                                    <option value="rejected">Rejected</option>
                                </select>
                                <button class="df-action-secondary min-h-9 px-3 py-1 text-xs">Save</button>
                            </form>
                        </div>
                    </div>
                @empty
                    <p class="text-sm text-slate-500">No documents uploaded.</p>
                @endforelse
            </div>
        </div>
    </section>

    <section data-df-tab-panel="accounts" class="df-panel p-5">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h2 class="font-semibold text-slate-950">Accounts</h2>
                <p class="df-muted">Savings and share-linked accounts registered to this client.</p>
            </div>
            <a class="df-action-secondary" href="{{ route('accounts.create', ['client_id' => $client->id]) }}">Open account</a>
        </div>
        <div class="mt-4 grid gap-3">
            @forelse($client->accounts as $account)
                <a class="df-soft-panel df-hover-lift flex flex-wrap items-center justify-between gap-3 p-4 text-sm" href="{{ route('accounts.show', $account) }}">
                    <span><span class="font-semibold text-slate-950">{{ $account->account_number }}</span><span class="ml-2 text-slate-500">{{ $account->accountType->name }}</span></span>
                    <span class="font-semibold">{{ number_format($account->balance, 2) }} UGX</span>
                </a>
            @empty
                <p class="text-sm text-slate-500">No accounts yet.</p>
            @endforelse
        </div>
    </section>

    <section data-df-tab-panel="loans" class="grid gap-5 lg:grid-cols-2">
        <div class="df-panel p-5">
            <h2 class="font-semibold text-slate-950">Loans</h2>
            <div class="mt-4 grid gap-3">
                @forelse($client->loans as $loan)
                    <a href="{{ route('loans.show', $loan) }}" class="df-soft-panel df-hover-lift flex flex-wrap items-center justify-between gap-3 p-4 text-sm">
                        <span><span class="font-semibold text-slate-950">{{ $loan->loan_number }}</span><span class="ml-2 text-slate-500">{{ $loan->product->name }}</span></span>
                        <span class="df-badge">{{ str($loan->status)->headline() }}</span>
                    </a>
                @empty
                    <p class="text-sm text-slate-500">No loans yet.</p>
                @endforelse
            </div>
        </div>
        <div class="df-panel p-5">
            <h2 class="font-semibold text-slate-950">Group Membership</h2>
            <div class="mt-4 grid gap-3">
                @forelse($client->groups as $group)
                    <a href="{{ route('client-groups.show', $group) }}" class="df-soft-panel df-hover-lift flex flex-wrap items-center justify-between gap-3 p-4 text-sm">
                        <span class="font-semibold text-slate-950">{{ $group->group_number }}</span>
                        <span class="text-slate-500">{{ $group->name }}</span>
                    </a>
                @empty
                    <p class="text-sm text-slate-500">Not assigned to any group.</p>
                @endforelse
            </div>
        </div>
    </section>

    <section data-df-tab-panel="notes" class="df-panel p-5">
        <h2 class="font-semibold text-slate-950">Notes</h2>
        <form method="POST" action="{{ route('clients.notes.store', $client) }}" class="mt-4 grid gap-3 md:grid-cols-[220px_1fr_auto]">
            @csrf
            <select name="category">
                @foreach(['general','kyc','risk','collections','field_visit'] as $category)
                    <option value="{{ $category }}">{{ str($category)->headline() }}</option>
                @endforeach
            </select>
            <textarea name="note" rows="2" placeholder="Add a client note" required></textarea>
            <button class="df-action-primary self-start">Add Note</button>
        </form>
        <div class="mt-4 grid gap-3">
            @forelse ($client->notes as $note)
                <div class="df-soft-panel p-4 text-sm">
                    <div class="font-semibold text-slate-950">{{ str($note->category)->headline() }} <span class="font-normal text-slate-500">by {{ $note->user?->name ?? 'System' }}</span></div>
                    <p class="mt-2 leading-6 text-slate-700">{{ $note->note }}</p>
                </div>
            @empty
                <p class="text-sm text-slate-500">No notes recorded.</p>
            @endforelse
        </div>
    </section>
</div>
@endsection
