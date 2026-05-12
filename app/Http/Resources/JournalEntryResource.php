<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class JournalEntryResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'journal_number' => $this->journal_number,
            'journal_date' => $this->journal_date?->toDateString(),
            'description' => $this->description,
            'status' => $this->status,
            'posted_at' => $this->posted_at?->toIso8601String(),
            'reference' => $this->reference,
            'amount' => (float) $this->amount,
            'lines' => $this->whenLoaded('lines', fn () => $this->lines->map(fn ($line) => [
                'id' => $line->id,
                'account' => [
                    'id' => $line->chartAccount?->id,
                    'code' => $line->chartAccount?->code,
                    'name' => $line->chartAccount?->name,
                ],
                'debit' => (float) $line->debit,
                'credit' => (float) $line->credit,
                'narration' => $line->narration,
            ])),
        ];
    }
}
