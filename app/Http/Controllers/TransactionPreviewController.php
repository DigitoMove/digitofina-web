<?php

namespace App\Http\Controllers;

use App\Http\Requests\TransactionPreviewRequest;
use App\Services\TransactionPreviewService;
use Illuminate\Http\JsonResponse;
use InvalidArgumentException;

class TransactionPreviewController extends Controller
{
    /**
     * Handle the incoming request.
     */
    public function __invoke(TransactionPreviewRequest $request, TransactionPreviewService $previews): JsonResponse
    {
        try {
            return response()->json($previews->preview($request->validated(), $request->user()))->header('Cache-Control', 'no-store, private');
        } catch (InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }
    }
}
