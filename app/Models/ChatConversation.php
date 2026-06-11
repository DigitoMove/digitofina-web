<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class ChatConversation extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'last_message_at' => 'datetime',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function participantOne(): BelongsTo
    {
        return $this->belongsTo(User::class, 'participant_one_id');
    }

    public function participantTwo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'participant_two_id');
    }

    public function initiatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'initiated_by');
    }

    public function loan(): BelongsTo
    {
        return $this->belongsTo(Loan::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(ChatMessage::class, 'conversation_id');
    }

    public function latestMessage(): HasOne
    {
        return $this->hasOne(ChatMessage::class, 'conversation_id')->latestOfMany();
    }

    public function hasParticipant(User $user): bool
    {
        return (int) $this->participant_one_id === (int) $user->id
            || (int) $this->participant_two_id === (int) $user->id;
    }

    public function otherParticipant(User $user): ?User
    {
        if ((int) $this->participant_one_id === (int) $user->id) {
            return $this->participantTwo;
        }

        if ((int) $this->participant_two_id === (int) $user->id) {
            return $this->participantOne;
        }

        return null;
    }
}
