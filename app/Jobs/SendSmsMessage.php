<?php

namespace App\Jobs;

use App\Models\SmsLog;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

class SendSmsMessage implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 30;

    public function __construct(public SmsLog $smsLog) {}

    public function handle(): void
    {
        $smsLog = SmsLog::query()->with('provider')->findOrFail($this->smsLog->id);

        if (! in_array($smsLog->status, ['queued', 'failed'], true)) {
            return;
        }

        $smsLog->update(['status' => 'processing']);

        if (! $smsLog->provider) {
            $smsLog->update([
                'status' => 'failed',
                'metadata' => ['error' => 'No active SMS provider is configured.'],
            ]);

            return;
        }

        $metadata = $smsLog->metadata ?? [];
        $metadata['queued_provider'] = $smsLog->provider->code;
        $metadata['processed_by'] = static::class;

        $smsLog->update([
            'status' => 'sent',
            'sent_at' => now(),
            'metadata' => $metadata,
        ]);
    }

    public function uniqueId(): string
    {
        return (string) $this->smsLog->id;
    }

    public function backoff(): array
    {
        return [30, 120, 300];
    }

    public function failed(Throwable $exception): void
    {
        $smsLog = SmsLog::query()->find($this->smsLog->id);

        if ($smsLog) {
            $smsLog->update([
                'status' => 'failed',
                'metadata' => ['error' => $exception->getMessage()],
            ]);
        }

        Log::error('Queued SMS sending failed.', [
            'sms_log_id' => $this->smsLog->id,
            'error' => $exception->getMessage(),
        ]);
    }
}
