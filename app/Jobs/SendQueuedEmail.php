<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

class SendQueuedEmail implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 60;

    public function __construct(
        public string $to,
        public string $subject,
        public string $body,
        public array $metadata = [],
    ) {}

    public function handle(): void
    {
        Mail::raw($this->body, function ($message): void {
            $message->to($this->to)->subject($this->subject);
        });
    }

    public function backoff(): array
    {
        return [60, 300, 900];
    }

    public function failed(Throwable $exception): void
    {
        Log::error('Queued email sending failed.', [
            'to' => $this->to,
            'subject' => $this->subject,
            'metadata' => $this->metadata,
            'error' => $exception->getMessage(),
        ]);
    }
}
