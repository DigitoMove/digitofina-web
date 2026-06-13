<?php

namespace App\Http\Controllers;

use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Models\Client;
use App\Models\Loan;
use App\Models\User;
use App\Services\LoanVisibilityService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class ChatController extends Controller
{
    public function index(Request $request, LoanVisibilityService $visibility): View
    {
        abort_if($request->user()->isMember(), 403);

        $user = $request->user();
        $conversations = $this->conversationQuery($user)
            ->with(['participantOne.role', 'participantTwo.role', 'loan.client', 'client', 'latestMessage'])
            ->withCount(['messages as unread_messages_count' => fn (Builder $query): Builder => $query
                ->where('sender_id', '!=', $user->id)
                ->whereNull('read_at')])
            ->orderByDesc('last_message_at')
            ->orderByDesc('updated_at')
            ->paginate(12);

        $loans = Loan::query()
            ->with(['client', 'branch'])
            ->tap(fn (Builder $query): Builder => $visibility->scope($query, $user))
            ->when(
                $user->isTellerCashier() || $user->isLoanOfficer() || $user->isLoanRecoveryOfficer(),
                fn (Builder $query): Builder => $query->forOperationalListing()
            )
            ->latest('application_date')
            ->limit(150)
            ->get();

        $clients = $this->visibleClientQuery($user)
            ->with(['branch', 'officer'])
            ->latest('registered_at')
            ->limit(250)
            ->get();
        $selectedClient = $this->visibleClient($user, $request->integer('client_id'), false);

        if ($selectedClient && ! $clients->contains(fn (Client $client): bool => (int) $client->id === (int) $selectedClient->id)) {
            $clients->prepend($selectedClient);
        }

        return view('chat.index', [
            'conversations' => $conversations,
            'recipients' => $this->eligibleRecipientQuery($user)->with(['role', 'branch'])->orderBy('name')->limit(200)->get(),
            'loans' => $loans,
            'clients' => $clients,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        abort_if($request->user()->isMember(), 403);

        $data = $request->validate($this->conversationValidationRules());

        $recipient = $this->eligibleRecipientQuery($request->user())->whereKey($data['recipient_id'])->firstOrFail();
        $loan = $this->visibleLoan($request->user(), $data['loan_id'] ?? null);
        $client = $loan?->client ?: $this->visibleClient($request->user(), $data['client_id'] ?? null);
        $conversation = $this->conversationFor($request->user(), $recipient, $loan, $client);
        $this->createMessage($conversation, $request->user(), $data['body'] ?? null, $request->file('attachment'));

        return redirect()->route('chat.show', $conversation)->with('status', 'Message sent.');
    }

    public function show(Request $request, ChatConversation $conversation): View
    {
        $this->authorizeParticipant($request->user(), $conversation);
        $conversation->load(['participantOne.role', 'participantTwo.role', 'loan.client', 'client']);
        $this->markRead($conversation, $request->user());

        return view('chat.show', [
            'conversation' => $conversation,
            'otherParticipant' => $conversation->otherParticipant($request->user()),
            'messages' => $conversation->messages()->with('sender')->oldest('id')->limit(200)->get(),
        ]);
    }

    public function message(Request $request, ChatConversation $conversation): JsonResponse|RedirectResponse
    {
        $this->authorizeParticipant($request->user(), $conversation);

        $data = $request->validate($this->messageValidationRules());
        $message = $this->createMessage($conversation, $request->user(), $data['body'] ?? null, $request->file('attachment'))->load('sender');

        if ($request->expectsJson()) {
            return response()->json([
                'message' => $this->serializeMessage($message, $request->user()),
                'unread_count' => $this->unreadCountFor($request->user()),
            ], 201);
        }

        return back()->with('status', 'Message sent.');
    }

    public function messages(Request $request, ChatConversation $conversation): JsonResponse
    {
        $this->authorizeParticipant($request->user(), $conversation);

        $data = $request->validate([
            'after_id' => ['nullable', 'integer', 'min:0'],
        ]);

        $messages = $conversation->messages()
            ->with('sender')
            ->when($data['after_id'] ?? null, fn (Builder $query, int $messageId): Builder => $query->where('id', '>', $messageId))
            ->oldest('id')
            ->limit(100)
            ->get();

        $this->markRead($conversation, $request->user());

        return response()->json([
            'messages' => $messages->map(fn (ChatMessage $message): array => $this->serializeMessage($message, $request->user()))->values(),
            'unread_count' => $this->unreadCountFor($request->user()),
        ]);
    }

    public function poll(Request $request): JsonResponse
    {
        abort_if($request->user()->isMember(), 403);

        $data = $request->validate([
            'after_id' => ['nullable', 'integer', 'min:0'],
        ]);
        $user = $request->user();
        $messages = ChatMessage::query()
            ->with(['sender', 'conversation.participantOne', 'conversation.participantTwo', 'conversation.loan.client', 'conversation.client'])
            ->whereHas('conversation', fn (Builder $query): Builder => $this->conversationQuery($user, $query))
            ->where('sender_id', '!=', $user->id)
            ->when($data['after_id'] ?? null, fn (Builder $query, int $messageId): Builder => $query->where('id', '>', $messageId))
            ->oldest('id')
            ->limit(10)
            ->get();

        $latestMessageId = (int) (ChatMessage::query()
            ->whereHas('conversation', fn (Builder $query): Builder => $this->conversationQuery($user, $query))
            ->max('id') ?? 0);

        return response()->json([
            'latest_message_id' => $latestMessageId,
            'unread_count' => $this->unreadCountFor($user),
            'messages' => $messages->map(fn (ChatMessage $message): array => [
                'id' => $message->id,
                'sender' => $message->sender?->name ?? 'Unknown user',
                'body' => $this->messagePreview($message),
                'conversation_url' => route('chat.show', $message->conversation),
                'context' => $this->conversationContext($message->conversation),
                'time' => $message->created_at?->diffForHumans(),
            ])->values(),
        ]);
    }

    /**
     * @return Builder<ChatConversation>
     */
    private function conversationQuery(User $user, ?Builder $query = null): Builder
    {
        return ($query ?: ChatConversation::query())
            ->where(function (Builder $query) use ($user): void {
                $query->where('participant_one_id', $user->id)
                    ->orWhere('participant_two_id', $user->id);
            });
    }

    /**
     * @return Builder<User>
     */
    private function eligibleRecipientQuery(User $user): Builder
    {
        $organizationId = $user->organization_id ?: $user->branch?->organization_id;

        return User::query()
            ->whereKeyNot($user->id)
            ->where('status', 'active')
            ->whereDoesntHave('role', fn (Builder $query): Builder => $query->where('slug', 'member'))
            ->when(! $user->hasRole('super-admin'), fn (Builder $query): Builder => $query->whereDoesntHave('role', fn (Builder $roleQuery): Builder => $roleQuery->where('slug', 'super-admin')))
            ->when(! $user->hasRole('super-admin') && $organizationId, fn (Builder $query): Builder => $query->where('organization_id', $organizationId))
            ->when(! $user->hasRole('super-admin') && ! $organizationId && $user->branch_id, fn (Builder $query): Builder => $query->where('branch_id', $user->branch_id));
    }

    /**
     * @return Builder<Client>
     */
    private function visibleClientQuery(User $user): Builder
    {
        $organizationId = $user->organization_id ?: $user->branch?->organization_id;

        return Client::withoutGlobalScope('tenant')
            ->when(! $user->hasRole('super-admin') && $organizationId, fn (Builder $query): Builder => $query->where('organization_id', $organizationId))
            ->when(! $user->hasRole('super-admin') && ! $organizationId && $user->branch_id, fn (Builder $query): Builder => $query->where('branch_id', $user->branch_id))
            ->when(! $user->hasRole('super-admin') && ! $organizationId && ! $user->branch_id, fn (Builder $query): Builder => $query->whereRaw('1 = 0'));
    }

    private function visibleClient(User $user, int|string|null $clientId, bool $fail = true): ?Client
    {
        if (! $clientId) {
            return null;
        }

        $query = $this->visibleClientQuery($user)
            ->with(['branch', 'officer'])
            ->whereKey($clientId);

        return $fail ? $query->firstOrFail() : $query->first();
    }

    private function visibleLoan(User $user, int|string|null $loanId): ?Loan
    {
        if (! $loanId) {
            return null;
        }

        return Loan::query()
            ->with(['client', 'branch'])
            ->tap(fn (Builder $query): Builder => app(LoanVisibilityService::class)->scope($query, $user))
            ->when(
                $user->isTellerCashier() || $user->isLoanOfficer() || $user->isLoanRecoveryOfficer(),
                fn (Builder $query): Builder => $query->forOperationalListing()
            )
            ->whereKey($loanId)
            ->firstOrFail();
    }

    private function conversationFor(User $sender, User $recipient, ?Loan $loan, ?Client $client): ChatConversation
    {
        $client = $loan?->client ?: $client;
        [$participantOneId, $participantTwoId] = collect([$sender->id, $recipient->id])->sort()->values()->all();

        $conversation = ChatConversation::query()
            ->where('participant_one_id', $participantOneId)
            ->where('participant_two_id', $participantTwoId)
            ->where('loan_id', $loan?->id)
            ->where('client_id', $client?->id)
            ->first();

        if ($conversation) {
            return $conversation;
        }

        if ($loan) {
            $conversation = ChatConversation::query()
                ->where('participant_one_id', $participantOneId)
                ->where('participant_two_id', $participantTwoId)
                ->where('loan_id', $loan->id)
                ->whereNull('client_id')
                ->first();

            if ($conversation) {
                $conversation->update(['client_id' => $client?->id]);

                return $conversation;
            }
        }

        return ChatConversation::create([
            'organization_id' => $loan?->organization_id ?? $client?->organization_id ?? $sender->organization_id ?? $recipient->organization_id,
            'branch_id' => $loan?->branch_id ?? $client?->branch_id ?? $sender->branch_id ?? $recipient->branch_id,
            'participant_one_id' => $participantOneId,
            'participant_two_id' => $participantTwoId,
            'initiated_by' => $sender->id,
            'loan_id' => $loan?->id,
            'client_id' => $client?->id,
            'last_message_at' => now(),
        ]);
    }

    private function createMessage(ChatConversation $conversation, User $sender, ?string $body, ?UploadedFile $attachment = null): ChatMessage
    {
        $body = trim((string) $body);

        if ($body === '' && ! $attachment) {
            throw ValidationException::withMessages(['body' => 'Message cannot be empty.']);
        }

        $message = $conversation->messages()->create([
            'sender_id' => $sender->id,
            'body' => $body,
            ...$this->attachmentData($conversation, $attachment),
        ]);
        $conversation->update(['last_message_at' => Carbon::now()]);

        return $message;
    }

    /**
     * @return array<string, mixed>
     */
    private function attachmentData(ChatConversation $conversation, ?UploadedFile $attachment): array
    {
        if (! $attachment) {
            return [];
        }

        $mime = $attachment->getMimeType() ?: $attachment->getClientMimeType();
        $path = $attachment->store("chat/{$conversation->id}", 'public');

        return [
            'attachment_disk' => 'public',
            'attachment_path' => $path,
            'attachment_name' => $attachment->getClientOriginalName(),
            'attachment_mime' => $mime,
            'attachment_size' => $attachment->getSize(),
            'attachment_type' => str_starts_with((string) $mime, 'image/') ? 'photo' : 'voice',
        ];
    }

    private function authorizeParticipant(User $user, ChatConversation $conversation): void
    {
        abort_if($user->isMember(), 403);
        abort_unless($conversation->hasParticipant($user), 403);
    }

    private function markRead(ChatConversation $conversation, User $user): void
    {
        $conversation->messages()
            ->where('sender_id', '!=', $user->id)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);
    }

    private function unreadCountFor(User $user): int
    {
        return ChatMessage::query()
            ->whereHas('conversation', fn (Builder $query): Builder => $this->conversationQuery($user, $query))
            ->where('sender_id', '!=', $user->id)
            ->whereNull('read_at')
            ->count();
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeMessage(ChatMessage $message, User $user): array
    {
        return [
            'id' => $message->id,
            'body' => $message->body,
            'sender' => $message->sender?->name ?? 'Unknown user',
            'is_mine' => (int) $message->sender_id === (int) $user->id,
            'time' => $message->created_at?->format('d M Y H:i'),
            'created_at' => $message->created_at?->toIso8601String(),
            'attachment' => $this->attachmentPayload($message),
        ];
    }

    /**
     * @return array<string, string|int|null>|null
     */
    private function attachmentPayload(ChatMessage $message): ?array
    {
        if (! $message->attachment_path) {
            return null;
        }

        $disk = $message->attachment_disk ?: 'public';

        return [
            'url' => Storage::disk($disk)->url($message->attachment_path),
            'name' => $message->attachment_name,
            'mime' => $message->attachment_mime,
            'size' => $message->attachment_size,
            'type' => $message->attachment_type,
        ];
    }

    private function messagePreview(ChatMessage $message): string
    {
        $body = trim($message->body);

        if ($body !== '') {
            return $body;
        }

        return match ($message->attachment_type) {
            'photo' => 'Photo attachment',
            'voice' => 'Voice note',
            default => 'Attachment',
        };
    }

    private function conversationContext(ChatConversation $conversation): string
    {
        if ($conversation->loan) {
            return $conversation->loan->loan_number.' - '.$this->clientName($conversation->loan->client);
        }

        if ($conversation->client) {
            return $conversation->client->client_number.' - '.$this->clientName($conversation->client);
        }

        return 'Direct message';
    }

    private function clientName(?Client $client): string
    {
        return $client?->full_name ?: $client?->business_name ?: $client?->client_number ?: 'Client';
    }

    /**
     * @return array<string, array<int, string>>
     */
    private function conversationValidationRules(): array
    {
        return [
            'recipient_id' => ['required', 'integer', 'exists:users,id'],
            'client_id' => ['nullable', 'integer', 'exists:clients,id'],
            'loan_id' => ['nullable', 'integer', 'exists:loans,id'],
            ...$this->messageValidationRules(),
        ];
    }

    /**
     * @return array<string, array<int, string>>
     */
    private function messageValidationRules(): array
    {
        return [
            'body' => ['nullable', 'required_without:attachment', 'string', 'max:2000'],
            'attachment' => ['nullable', 'file', 'max:12288', 'mimetypes:image/jpeg,image/png,image/webp,audio/mpeg,audio/mp4,audio/aac,audio/wav,audio/x-wav,audio/ogg,audio/webm,video/webm'],
        ];
    }
}
