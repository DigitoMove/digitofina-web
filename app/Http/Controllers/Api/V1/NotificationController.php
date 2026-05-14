<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\InAppNotificationResource;
use App\Http\Resources\SmsLogResource;
use App\Models\SmsLog;
use App\Services\SmsService;
use App\Support\ApiQuery;
use App\Support\ApiResponse;
use App\Support\ApiSecurity;
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

        $logs = ApiQuery::apply(
            ApiSecurity::scopeTenant(SmsLog::query(), $request->user())->with('provider'),
            $request,
            ['recipient', 'message', 'type'],
            ['type', 'status'],
            ['id', 'recipient', 'type', 'status', 'sent_at', 'created_at', 'updated_at'],
            'created_at',
        )->paginate(ApiQuery::perPage($request, 30));

        return ApiResponse::collection(SmsLogResource::collection($logs), 'SMS logs retrieved.', meta: ApiQuery::meta($request));
    }

    public function notifications(Request $request): JsonResponse
    {
        $notifications = ApiQuery::apply(
            $request->user()->notifications()->getQuery(),
            $request,
            ['type'],
            ['type'],
            ['id', 'type', 'read_at', 'created_at', 'updated_at'],
            'created_at',
        )->paginate(ApiQuery::perPage($request, 30));

        return ApiResponse::collection(InAppNotificationResource::collection($notifications), 'Notifications retrieved.', meta: ApiQuery::meta($request));
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
