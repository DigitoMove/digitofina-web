@extends('layouts.app')

@section('content')
<div class="mb-5 flex flex-wrap items-end justify-between gap-3">
    <div>
        <h1 class="df-title">Team Chat</h1>
        <p class="df-muted">Coordinate privately with another staff user about a client, loan, or branch action.</p>
    </div>
    <a href="{{ route('client-directory.index') }}" class="df-action-secondary">Client Directory</a>
</div>

<div class="grid gap-5 xl:grid-cols-[34rem_1fr]">
    <aside class="df-panel p-6">
        <h2 class="text-xl font-semibold text-slate-950">Start Conversation</h2>
        <form method="POST" action="{{ route('chat.store') }}" enctype="multipart/form-data" class="mt-5 grid gap-5">
            @csrf
            <label class="text-sm font-semibold text-slate-700">Recipient <x-required-badge />
                <select name="recipient_id" class="mt-2 min-h-12 text-base" required>
                    <option value="">Choose a user</option>
                    @foreach($recipients as $recipient)
                        <option value="{{ $recipient->id }}" @selected((string) request('recipient_id') === (string) $recipient->id)>
                            {{ $recipient->name }} - {{ $recipient->role?->name ?? 'User' }} - {{ $recipient->branch?->name ?? 'All branches' }}
                        </option>
                    @endforeach
                </select>
            </label>
            <label class="text-sm font-semibold text-slate-700">Client context
                <select name="client_id" class="mt-2 min-h-12 text-base">
                    <option value="">No client selected</option>
                    @foreach($clients as $client)
                        @php($clientName = $client->full_name ?: $client->business_name ?: $client->client_number)
                        <option value="{{ $client->id }}" @selected((string) request('client_id') === (string) $client->id)>
                            {{ $client->client_number }} - {{ $clientName }} - {{ $client->branch?->name ?? 'No branch' }}
                        </option>
                    @endforeach
                </select>
            </label>
            <label class="text-sm font-semibold text-slate-700">Loan context
                <select name="loan_id" class="mt-2 min-h-12 text-base">
                    <option value="">No loan selected</option>
                    @foreach($loans as $loan)
                        <option value="{{ $loan->id }}" @selected((string) request('loan_id') === (string) $loan->id)>{{ $loan->loan_number }} - {{ $loan->client?->full_name }} - {{ $loan->branch?->name }}</option>
                    @endforeach
                </select>
            </label>
            <label class="text-sm font-semibold text-slate-700">Message
                <textarea name="body" rows="5" class="mt-2 text-base" placeholder="Write a clear message..."></textarea>
            </label>
            <label class="text-sm font-semibold text-slate-700">Attachment
                <input type="file" name="attachment" accept="image/*,audio/*,video/webm" class="mt-2">
            </label>
            <button class="df-action-primary min-h-12 justify-center">Send Message</button>
        </form>
    </aside>

    <section class="df-panel overflow-hidden">
        <div class="border-b border-slate-200 px-6 py-5">
            <h2 class="text-xl font-semibold text-slate-950">Conversations</h2>
            <p class="df-muted">Newest messages first.</p>
        </div>
        <div class="divide-y divide-slate-200">
            @forelse($conversations as $conversation)
                @php
                    $other = $conversation->otherParticipant(auth()->user());
                    $latestMessage = $conversation->latestMessage;
                    $context = $conversation->loan
                        ? $conversation->loan->loan_number.' - '.$conversation->loan->client?->full_name
                        : ($conversation->client ? $conversation->client->client_number.' - '.($conversation->client->full_name ?: $conversation->client->business_name) : 'Direct message');
                    $preview = trim((string) $latestMessage?->body) !== ''
                        ? $latestMessage?->body
                        : match ($latestMessage?->attachment_type) {
                            'photo' => 'Photo attachment',
                            'voice' => 'Voice note',
                            default => 'No messages yet.',
                        };
                @endphp
                <a href="{{ route('chat.show', $conversation) }}" class="block px-6 py-5 hover:bg-brand-50">
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <div class="truncate text-base font-semibold text-slate-950">{{ $other?->name ?? 'Unknown user' }}</div>
                            <div class="mt-1 text-xs text-slate-500">{{ $other?->role?->name ?? 'User' }} - {{ $context }}</div>
                            <div class="mt-2 line-clamp-2 text-sm text-slate-600">{{ $preview }}</div>
                        </div>
                        <div class="shrink-0 text-right">
                            @if($conversation->unread_messages_count > 0)
                                <span class="inline-flex min-h-6 min-w-6 items-center justify-center rounded-full bg-red-600 px-2 text-xs font-bold text-white">{{ $conversation->unread_messages_count }}</span>
                            @endif
                            <div class="mt-2 text-[0.7rem] text-slate-400">{{ $conversation->last_message_at?->diffForHumans() ?? $conversation->updated_at->diffForHumans() }}</div>
                        </div>
                    </div>
                </a>
            @empty
                <div class="p-8 text-center text-sm text-slate-500">No conversations yet.</div>
            @endforelse
        </div>
        <div class="border-t border-slate-200 px-6 py-4">{{ $conversations->links() }}</div>
    </section>
</div>
@endsection
