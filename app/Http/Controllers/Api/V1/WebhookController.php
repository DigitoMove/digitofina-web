<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\WebhookEventResource;
use App\Jobs\ProcessWebhookEvent;
use App\Models\WebhookEvent;
use App\Services\WebhookIngestionService;
use App\Support\ApiResponse;
use App\Support\ApiSecurity;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WebhookController extends Controller
{
    public function store(Request $request, string $source, string $provider, WebhookIngestionService $webhooks): JsonResponse
    {
        $event = $webhooks->ingest($request, $source, $provider);

        if ($event->status === 'rejected') {
            return ApiResponse::error('Webhook signature verification failed.', [
                'signature' => ['The webhook signature is invalid.'],
            ], 401, [
                'webhook_event_id' => $event->id,
            ]);
        }

        if ($event->status !== 'duplicate') {
            ProcessWebhookEvent::dispatch($event);
        }

        return ApiResponse::resource(
            new WebhookEventResource($event->fresh()),
            $event->status === 'duplicate' ? 'Duplicate webhook received; original event will not be reprocessed.' : 'Webhook received successfully.',
            $event->status === 'duplicate' ? 200 : 202
        );
    }

    public function retry(Request $request, WebhookEvent $webhookEvent, WebhookIngestionService $webhooks): JsonResponse
    {
        abort_unless($request->user()->canPerform('manage-settings') || $request->user()->canPerform('manage-accounting'), 403);

        if ($webhookEvent->organization_id !== null) {
            ApiSecurity::assertTenantAccess($request->user(), $webhookEvent);
        }

        $event = $webhooks->retry($webhookEvent);
        ProcessWebhookEvent::dispatch($event);

        return ApiResponse::resource(new WebhookEventResource($event->fresh()), 'Webhook retry queued successfully.', 202);
    }
}
