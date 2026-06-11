<?php

namespace App\Http\Controllers;

use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Models\Loan;
use App\Models\User;
use App\Services\LoanVisibilityService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class ChatController extends Controller
{
    public function index(Request $request, LoanVisibilityService $visibility): View
    {
        abort_if($request->user()->isMember(), 403);

        $user = $request->user();
        $conversations = $this->conversationQuery($user)
            ->with(['participantOne.role', 'participantTwo.role', 'loan.client', 'latestMessage'])
            ->withCount(['messages as unread_messages_count' => fn (Builder $query): Builder => $query
                ->where('sender_id', '!=', $user->id)
                ->whereNull('read_at')])
            ->orderByDesc('last_message_at')
            ->orderByDesc('updated_at')
            ->paginate(12);

        $loans = Loan::query()
            ->with(['client', 'branch'])
            ->tap(fn (Builder $query): Builder => $visibility->scope($query, $user))
            ->whereIn('status', Loan::LISTING_STATUSES)
            ->latest('application_date')
            ->limit(150)
            ->get();

        return view('chat.index', [
            'conversations' => $conversations,
            'recipients' => $this->eligibleRecipientQuery($user)->with(['role', 'branch'])->orderBy('name')->limit(200)->get(),
            'loans' => $loans,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        abort_if($request->user()->isMember(), 403);

        $data = $request->validate([
            'recipient_id' => ['required', 'integer', 'exists:users,id'],
            'loan_id' => ['nullable', 'integer', 'exists:loans,id'],
            'body' => ['required', 'string', 'max:2000'],
        ]);

        $recipient = $this->eligibleRecipientQuery($request->user())->whereKey($data['recipient_id'])->firstOrFail();
        $loan = $this->visibleLoan($request->user(), $data['loan_id'] ?? null);
        $conversation = $this->conversationFor($request->user(), $recipient, $loan);
        $this->createMessage($conversation, $request->user(), $data['body']);

        return redirect()->route('chat.show', $conversation)->with('status', 'Message sent.');
    }

    public function show(Request $request, ChatConversation $conversation): View
    {
        $this->authorizeParticipant($request->user(), $conversation);
        $conversation->load(['participantOne.role', 'participantTwo.role', 'loan.client']);
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

        $data = $request->validate([
            'body' => ['required', 'string', 'max:2000'],
        ]);
        $message = $this->createMessage($conversation, $request->user(), $data['body'])->load('sender');

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
            ->with(['sender', 'conversation.participantOne', 'conversation.participantTwo', 'conversation.loan.client'])
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
                'body' => $message->body,
                'conversation_url' => route('chat.show', $message->conversation),
                'context' => $message->conversation->loan
                    ? $message->conversation->loan->loan_number.' · '.$message->conversation->loan->client?->full_name
                    : 'Direct message',
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

    private function visibleLoan(User $user, int|string|null $loanId): ?Loan
    {
        if (! $loanId) {
            return null;
        }

        return Loan::query()
            ->with(['client', 'branch'])
            ->tap(fn (Builder $query): Builder => app(LoanVisibilityService::class)->scope($query, $user))
            ->whereKey($loanId)
            ->firstOrFail();
    }

    private function conversationFor(User $sender, User $recipient, ?Loan $loan): ChatConversation
    {
        [$participantOneId, $participantTwoId] = collect([$sender->id, $recipient->id])->sort()->values()->all();

        $conversation = ChatConversation::query()
            ->where('participant_one_id', $participantOneId)
            ->where('participant_two_id', $participantTwoId)
            ->where('loan_id', $loan?->id)
            ->first();

        if ($conversation) {
            return $conversation;
        }

        return ChatConversation::create([
            'organization_id' => $loan?->organization_id ?? $sender->organization_id ?? $recipient->organization_id,
            'branch_id' => $loan?->branch_id ?? $sender->branch_id ?? $recipient->branch_id,
            'participant_one_id' => $participantOneId,
            'participant_two_id' => $participantTwoId,
            'initiated_by' => $sender->id,
            'loan_id' => $loan?->id,
            'last_message_at' => now(),
        ]);
    }

    private function createMessage(ChatConversation $conversation, User $sender, string $body): ChatMessage
    {
        $body = trim($body);

        if ($body === '') {
            throw ValidationException::withMessages(['body' => 'Message cannot be empty.']);
        }

        $message = $conversation->messages()->create([
            'sender_id' => $sender->id,
            'body' => $body,
        ]);
        $conversation->update(['last_message_at' => Carbon::now()]);

        return $message;
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
        ];
    }
}
