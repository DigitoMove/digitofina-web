<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\OpenApiDocumentationService;
use Illuminate\Http\JsonResponse;

class ApiDocumentationController extends Controller
{
    public function openapi(OpenApiDocumentationService $documentation): JsonResponse
    {
        return response()->json($documentation->document());
    }
}
