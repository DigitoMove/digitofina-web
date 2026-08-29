<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\NylonPayClient;
use App\Services\QuoteBillingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

class NylonPayWebhookController extends Controller
{
    /**
     * Handle the incoming request.
     */
    public function __invoke(Request $request, NylonPayClient $nylonPay, QuoteBillingService $billing): JsonResponse
    {
        $rawPayload = $request->getContent();
        $signature = (string) $request->header('X-Nylon-Signature');

        if (! $nylonPay->verifyWebhook($rawPayload, $signature)) {
            return response()->json(['received' => false, 'message' => 'Invalid Nylon Pay signature.'], 401);
        }

        $payload = json_decode($rawPayload, true);

        if (! is_array($payload)) {
            return response()->json(['received' => false, 'message' => 'Invalid webhook payload.'], 422);
        }

        try {
            $quote = $billing->processWebhook($payload);
        } catch (InvalidArgumentException $exception) {
            return response()->json(['received' => false, 'message' => $exception->getMessage()], 422);
        }

        return response()->json([
            'received' => true,
            'matched' => $quote !== null,
            'quote' => $quote?->quote_number,
        ]);
    }
}
