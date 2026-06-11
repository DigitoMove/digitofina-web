@extends('layouts.app')

@section('content')
<div class="mb-5 flex flex-wrap items-end justify-between gap-3">
    <div>
        <h1 class="df-title">{{ $otherParticipant?->name ?? 'Conversation' }}</h1>
        <p class="df-muted">
            {{ $otherParticipant?->role?->name ?? 'User' }}
            @if($conversation->loan)
                · {{ $conversation->loan->loan_number }} · {{ $conversation->loan->client?->full_name }}
            @endif
        </p>
    </div>
    <div class="flex flex-wrap gap-2">
        @if($conversation->loan)
            <a href="{{ route('loans.show', $conversation->loan) }}" class="df-action-secondary">Open Loan</a>
        @endif
        <a href="{{ route('chat.index') }}" class="df-action-secondary">Inbox</a>
    </div>
</div>

<section class="df-panel overflow-hidden" data-chat-conversation data-messages-url="{{ route('chat.messages', $conversation) }}" data-send-url="{{ route('chat.messages.store', $conversation) }}">
    <div class="max-h-[60vh] min-h-[26rem] overflow-y-auto bg-slate-50 p-4 sm:p-5" data-chat-messages>
        @foreach($messages as $message)
            @php($isMine = (int) $message->sender_id === (int) auth()->id())
            <div class="mb-3 flex {{ $isMine ? 'justify-end' : 'justify-start' }}" data-chat-message-id="{{ $message->id }}">
                <div class="max-w-[min(34rem,85%)] rounded-xl px-4 py-3 shadow-sm {{ $isMine ? 'bg-brand-700 text-white' : 'border border-slate-200 bg-white text-slate-900' }}">
                    <div class="text-xs font-semibold {{ $isMine ? 'text-brand-50' : 'text-slate-500' }}">{{ $message->sender?->name }}</div>
                    <div class="mt-1 whitespace-pre-wrap text-sm leading-6">{{ $message->body }}</div>
                    <div class="mt-2 text-[0.7rem] {{ $isMine ? 'text-brand-50/80' : 'text-slate-400' }}">{{ $message->created_at?->format('d M Y H:i') }}</div>
                </div>
            </div>
        @endforeach
    </div>
    <form method="POST" action="{{ route('chat.messages.store', $conversation) }}" class="grid gap-3 border-t border-slate-200 bg-white p-4 sm:grid-cols-[1fr_auto]" data-chat-form>
        @csrf
        <label class="sr-only" for="chat-body">Message</label>
        <textarea id="chat-body" name="body" rows="2" class="min-h-14" placeholder="Type your message..." required data-chat-input></textarea>
        <button class="df-action-primary self-end">Send</button>
    </form>
</section>
@endsection
