@extends('layouts.app')

@section('content')
@php
    $conversationClient = $conversation->loan?->client ?: $conversation->client;
    $clientName = $conversationClient?->full_name ?: $conversationClient?->business_name ?: $conversationClient?->client_number;
@endphp

<div class="mb-5 flex flex-wrap items-end justify-between gap-3">
    <div>
        <h1 class="df-title">{{ $otherParticipant?->name ?? 'Conversation' }}</h1>
        <p class="df-muted">
            {{ $otherParticipant?->role?->name ?? 'User' }}
            @if($conversation->loan)
                - {{ $conversation->loan->loan_number }} - {{ $clientName }}
            @elseif($conversationClient)
                - {{ $conversationClient->client_number }} - {{ $clientName }}
            @endif
        </p>
    </div>
    <div class="flex flex-wrap gap-2">
        @if($conversationClient)
            <a href="{{ route('client-directory.show', $conversationClient->id) }}" class="df-action-secondary">Client Profile</a>
        @endif
        @if($conversation->loan && (auth()->user()->canPerform('manage-loans') || auth()->user()->canPerform('view-reports') || auth()->user()->canPerform('view-arrears')))
            <a href="{{ route('loans.show', $conversation->loan) }}" class="df-action-secondary">Open Loan</a>
        @endif
        <a href="{{ route('chat.index') }}" class="df-action-secondary">Inbox</a>
    </div>
</div>

<section class="df-panel mx-auto max-w-6xl overflow-hidden" data-chat-conversation data-messages-url="{{ route('chat.messages', $conversation) }}" data-send-url="{{ route('chat.messages.store', $conversation) }}">
    <div class="max-h-[72vh] min-h-[34rem] overflow-y-auto bg-slate-50 p-4 sm:p-6" data-chat-messages>
        @foreach($messages as $message)
            @php
                $isMine = (int) $message->sender_id === (int) auth()->id();
                $attachmentUrl = $message->attachment_path
                    ? \Illuminate\Support\Facades\Storage::disk($message->attachment_disk ?: 'public')->url($message->attachment_path)
                    : null;
            @endphp
            <div class="mb-4 flex {{ $isMine ? 'justify-end' : 'justify-start' }}" data-chat-message-id="{{ $message->id }}">
                <div class="max-w-[min(44rem,92%)] rounded-xl px-4 py-3 shadow-sm {{ $isMine ? 'bg-brand-700 text-white' : 'border border-slate-200 bg-white text-slate-900' }}">
                    <div class="text-xs font-semibold {{ $isMine ? 'text-brand-50' : 'text-slate-500' }}">{{ $message->sender?->name }}</div>
                    @if(trim((string) $message->body) !== '')
                        <div class="mt-1 whitespace-pre-wrap text-sm leading-6">{{ $message->body }}</div>
                    @endif
                    @if($attachmentUrl)
                        @if(str_starts_with((string) $message->attachment_mime, 'image/'))
                            <a href="{{ $attachmentUrl }}" target="_blank" class="mt-3 block">
                                <img src="{{ $attachmentUrl }}" alt="{{ $message->attachment_name ?? 'Chat photo' }}" class="max-h-80 rounded-lg object-contain">
                            </a>
                        @else
                            <audio controls src="{{ $attachmentUrl }}" class="mt-3 w-full max-w-full"></audio>
                        @endif
                    @endif
                    <div class="mt-2 text-[0.7rem] {{ $isMine ? 'text-brand-50/80' : 'text-slate-400' }}">{{ $message->created_at?->format('d M Y H:i') }}</div>
                </div>
            </div>
        @endforeach
    </div>
    <form method="POST" action="{{ route('chat.messages.store', $conversation) }}" enctype="multipart/form-data" class="grid gap-3 border-t border-slate-200 bg-white p-4 sm:grid-cols-[1fr_auto] sm:p-5" data-chat-form>
        @csrf
        <div class="grid gap-3">
            <label class="sr-only" for="chat-body">Message</label>
            <textarea id="chat-body" name="body" rows="3" class="min-h-24 text-base" placeholder="Type your message..." data-chat-input></textarea>
            <div class="grid gap-3 lg:grid-cols-[1fr_auto_auto] lg:items-center">
                <label class="sr-only" for="chat-attachment">Attachment</label>
                <input id="chat-attachment" type="file" name="attachment" accept="image/*,audio/*,video/webm" data-chat-attachment>
                <button type="button" class="df-action-secondary justify-center" data-chat-record>Record Voice</button>
                <span class="text-xs font-medium text-slate-500" data-chat-record-status></span>
            </div>
        </div>
        <button class="df-action-primary min-h-12 self-end justify-center">Send</button>
    </form>
</section>
@endsection
