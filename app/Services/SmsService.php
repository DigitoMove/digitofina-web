<?php

namespace App\Services;

use App\Models\SmsLog;
use App\Models\SmsProvider;
use App\Models\User;

class SmsService
{
    public function queue(string $recipient, string $message, string $type, ?User $sentBy = null, ?SmsProvider $provider = null): SmsLog
    {
        $provider ??= SmsProvider::query()->where('is_default', true)->where('status', 'active')->first();

        return SmsLog::create([
            'organization_id' => $provider?->organization_id,
            'sms_provider_id' => $provider?->id,
            'sent_by' => $sentBy?->id,
            'recipient' => $recipient,
            'type' => $type,
            'message' => $message,
            'status' => 'queued',
        ]);
    }
}
