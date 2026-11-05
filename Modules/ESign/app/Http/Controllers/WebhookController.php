<?php

namespace Modules\ESign\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\ESign\Services\WebhookService;

final class WebhookController extends Controller
{
    public function __invoke(Request $request, WebhookService $service): JsonResponse
    {
        $event = $service->receive($request);

        return response()->json([
            'accepted' => true,
            'duplicate' => ! $event->wasRecentlyCreated,
        ], 202);
    }
}
