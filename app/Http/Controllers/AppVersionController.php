<?php

namespace App\Http\Controllers;

use App\Http\Middleware\CheckAppVersion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AppVersionController extends Controller
{
    /**
     * التطبيق يناديه أول ما يفتح (قبل تسجيل الدخول): هل لازم تحديث إجباري، أو فيه تحديث اختياري.
     */
    public function check(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'platform' => ['required', 'in:android,ios'],
            'version' => ['required', 'string', 'max:20'],
        ]);

        $config = config("app_version.platforms.{$validated['platform']}");
        $latest = $config['latest'] ?? null;

        return response()->json([
            'platform' => $validated['platform'],
            'current' => $validated['version'],
            'minimum' => $config['minimum'] ?? null,
            'latest' => $latest,
            'update_required' => CheckAppVersion::updateRequired($validated['version'], $validated['platform']),
            'update_available' => $latest && version_compare($validated['version'], $latest, '<'),
            'store_url' => $config['store_url'] ?? null,
            'message' => config('app_version.message'),
        ]);
    }
}
