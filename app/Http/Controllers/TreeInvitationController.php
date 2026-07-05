<?php

namespace App\Http\Controllers;

use App\Models\TreeInvitation;
use App\Models\User;
use App\Services\AnalyticsService;
use App\Services\AuthRedirector;
use App\Services\TreeAccessService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

class TreeInvitationController extends Controller
{
    public function show(Request $request, string $token): View|RedirectResponse
    {
        $invitation = $this->invitation($token, $request->user());

        $request->session()->put('family_invitation_token', $token);
        $request->session()->put('family_tree_id', $invitation->tree_id);

        if ($request->user()) {
            $membership = app(TreeAccessService::class)->acceptInvitation($request->user(), $token);
            $request->session()->forget('family_invitation_token');

            return app(AuthRedirector::class)->redirect($request->user(), $membership->tree);
        }

        return view('public.invitation', [
            'invitation' => $invitation,
            'token' => $token,
            'telegramLoginUrl' => config('services.telegram.oidc_client_id')
                ? route('telegram.login', [
                    'tree' => $invitation->tree->slug,
                    'return' => route('tree.invitation', $token, false),
                ])
                : null,
        ]);
    }

    public function store(
        Request $request,
        string $token,
        AnalyticsService $analytics,
    ): RedirectResponse {
        $invitation = $this->invitation($token);
        $request->merge(['email' => mb_strtolower(trim((string) $request->input('email')))]);
        $validator = Validator::make($request->all(), [
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'confirmed', Password::min(10)->letters()],
            'privacy_consent' => ['accepted'],
        ], [
            'required' => __('public.messages.required'),
            'email.email' => __('public.messages.email'),
            'email.unique' => __('public.messages.email_unique'),
            'password.confirmed' => __('public.messages.password_confirmed'),
            'password.min' => __('public.messages.password_min'),
            'password.letters' => __('public.messages.password_letters'),
            'privacy_consent.accepted' => __('public.auth.privacy_required'),
        ]);

        if ($validator->fails()) {
            return back()->withErrors($validator)->withInput($request->except([
                'password',
                'password_confirmation',
            ]));
        }

        $data = $validator->validated();
        [$user, $membership] = DB::transaction(function () use ($data, $request, $analytics, $token): array {
            $user = User::query()->create([
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => $data['password'],
                'is_active' => true,
                'privacy_accepted_at' => now(),
                'privacy_policy_version' => (string) config('privacy.policy_version'),
                'privacy_ip_hash' => $analytics->hashIp($request->ip()),
            ]);

            return [$user, app(TreeAccessService::class)->acceptInvitation($user, $token)];
        });

        Auth::login($user);
        $request->session()->regenerate();
        $request->session()->forget('family_invitation_token');
        $request->session()->put('family_user_id', $user->id);
        $request->session()->put('family_tree_id', $membership->tree_id);
        $analytics->linkUser($request, $user);
        $analytics->record('sign_up', $request, $user, $membership->tree, [
            'method' => 'invitation',
            'tree_id' => $membership->tree_id,
        ], "sign_up:user:{$user->id}", true);

        return app(AuthRedirector::class)->redirect($user, $membership->tree);
    }

    private function invitation(string $token, ?User $user = null): TreeInvitation
    {
        abort_unless((bool) preg_match('/^[a-f0-9]{64}$/', $token), 404);

        $invitation = TreeInvitation::query()
            ->with(['tree', 'person'])
            ->where('token_hash', hash('sha256', $token))
            ->firstOrFail();
        $alreadyHasAccess = $user && $user->memberships()
            ->where('tree_id', $invitation->tree_id)
            ->where('status', 'approved')
            ->exists();
        abort_unless(
            $invitation->isUsable() || $alreadyHasAccess,
            410,
            __('public.invitation.expired'),
        );

        return $invitation;
    }
}
