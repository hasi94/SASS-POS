<?php

namespace App\Http\Controllers;

use App\Support\ModuleHost\ModuleRegistry;
use Illuminate\Http\JsonResponse;

class HealthController extends Controller
{
    /**
     * Liveness: the host process is up and able to answer.
     */
    public function live(): JsonResponse
    {
        return response()->json(['status' => 'ok']);
    }

    /**
     * Readiness: which modules this host process actually booted.
     *
     * Deeper checks (database, event bus, consumers) need infrastructure that
     * does not exist yet and are deliberately not reported here.
     */
    public function ready(ModuleRegistry $registry): JsonResponse
    {
        return response()->json([
            'status' => 'ok',
            'modules' => $registry->booted(),
        ]);
    }
}
