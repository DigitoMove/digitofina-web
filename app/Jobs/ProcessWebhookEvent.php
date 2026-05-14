<?php

namespace App\Jobs;

use App\Models\WebhookEvent;
use App\Services\WebhookIngestionService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class ProcessWebhookEvent implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /**
     * Create a new job instance.
     */
    public function __construct(public WebhookEvent $webhookEvent) {}

    /**
     * Execute the job.
     */
    public function handle(WebhookIngestionService $webhooks): void
    {
        $webhooks->process($this->webhookEvent->fresh() ?? $this->webhookEvent);
    }

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [60, 300, 900];
    }

    public function failed(Throwable $exception): void
    {
        $event = $this->webhookEvent->fresh();

        if (! $event) {
            return;
        }

        $event->forceFill([
            'status' => 'failed',
            'error_message' => $exception->getMessage(),
            'next_retry_at' => now()->addMinutes(15),
        ])->save();
    }
}
