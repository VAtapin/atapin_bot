<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Validation\Rule;

class AnalyticsConsentController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'consent' => ['required', Rule::in(['granted', 'essential'])],
        ]);

        Cookie::queue(cookie(
            name: 'analytics_consent',
            value: $validated['consent'],
            minutes: 60 * 24 * 365,
            path: '/',
            secure: $request->isSecure(),
            httpOnly: true,
            raw: false,
            sameSite: 'lax',
        ));

        return response()->json(['consent' => $validated['consent']]);
    }
}
