<?php

namespace App\Http\Controllers;

use App\Models\FamilyTree;
use App\Services\AnalyticsService;
use App\Services\AuthRedirector;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class PrivacyConsentController extends Controller
{
    public function show(): View
    {
        return view('public.privacy-consent');
    }

    public function store(Request $request, AnalyticsService $analytics, AuthRedirector $redirector): RedirectResponse
    {
        $request->validate(
            ['privacy_consent' => ['accepted']],
            ['privacy_consent.accepted' => __('public.auth.privacy_required')],
        );
        $request->user()->update([
            'privacy_accepted_at' => now(),
            'privacy_policy_version' => (string) config('privacy.policy_version'),
            'privacy_ip_hash' => $analytics->hashIp($request->ip()),
        ]);
        $treeId = $request->session()->pull('privacy_return_tree_id');
        $tree = $treeId ? FamilyTree::query()->find($treeId) : null;

        return $redirector->redirect($request->user(), $tree);
    }
}
