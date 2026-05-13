<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\InAppNotificationResource;
use App\Http\Resources\SmsLogResource;
use App\Models\SmsLog;
use App\Services\SmsService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;

class NotificationController extends Controller
{
    public function sendSms(Request $request, SmsService $sms): JsonResponse
    {
        abort_unless($request->user()->canPerform('manage-settings') || $request->user()->canPerform('manage-clients'), 403);

        $data = $request->validate([
            'recipient' => ['required', 'string', 'max:40'],
            'message' => ['required', 'string', 'max:1000'],
            'type' => ['required', 'string', 'max:80'],
        ]);

        return ApiResponse::resource(new SmsLogResource($sms->queue($data['recipient'], $data['message'], $data['type'], $request->user())->load('provider')), 'SMS queued successfully.', 201);
    }

    public function smsLogs(Request $request): JsonResponse
    {
        abort_unless($request->user()->canPerform('view-reports') || $request->user()->canPerform('manage-settings'), 403);

        return ApiResponse::collection(SmsLogResource::collection(SmsLog::query()->with('provider')->latest()->paginate($request->integer('per_page', 30))), 'SMS logs retrieved.');
    }

    public function notifications(Request $request): JsonResponse
    {
        return ApiResponse::collection(InAppNotificationResource::collection($request->user()->notifications()->latest()->paginate($request->integer('per_page', 30))), 'Notifications retrieved.');
    }

    public function markRead(Request $request, string $notification): JsonResponse
    {
        $record = DatabaseNotification::query()
            ->where('notifiable_type', $request->user()->getMorphClass())
            ->where('notifiable_id', $request->user()->getKey())
            ->whereKey($notification)
            ->firstOrFail();

        $record->markAsRead();

        return ApiResponse::resource(new InAppNotificationResource($record), 'Notification marked as read.');
    }
}
