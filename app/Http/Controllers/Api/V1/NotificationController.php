<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\SmsLogResource;
use App\Models\SmsLog;
use App\Services\SmsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Notifications\DatabaseNotification;

class NotificationController extends Controller
{
    public function sendSms(Request $request, SmsService $sms): SmsLogResource
    {
        abort_unless($request->user()->canPerform('manage-settings') || $request->user()->canPerform('manage-clients'), 403);

        $data = $request->validate([
            'recipient' => ['required', 'string', 'max:40'],
            'message' => ['required', 'string', 'max:1000'],
            'type' => ['required', 'string', 'max:80'],
        ]);

        return new SmsLogResource($sms->queue($data['recipient'], $data['message'], $data['type'], $request->user())->load('provider'));
    }

    public function smsLogs(Request $request): AnonymousResourceCollection
    {
        abort_unless($request->user()->canPerform('view-reports') || $request->user()->canPerform('manage-settings'), 403);

        return SmsLogResource::collection(SmsLog::query()->with('provider')->latest()->paginate($request->integer('per_page', 30)));
    }

    public function notifications(Request $request): JsonResponse
    {
        return response()->json([
            'data' => $request->user()->notifications()->latest()->paginate($request->integer('per_page', 30)),
        ]);
    }

    public function markRead(Request $request, string $notification): JsonResponse
    {
        $record = DatabaseNotification::query()
            ->where('notifiable_type', $request->user()->getMorphClass())
            ->where('notifiable_id', $request->user()->getKey())
            ->whereKey($notification)
            ->firstOrFail();

        $record->markAsRead();

        return response()->json(['message' => 'Notification marked as read.']);
    }
}
