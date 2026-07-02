<?php

namespace App\Http\Controllers;

use App\Models\FamilyTree;
use App\Models\TelegramUser;
use App\Services\AuthRedirector;
use App\Services\ExternalIdentityService;
use App\Services\TreeAccessService;
use App\Support\SafeReturnUrl;
use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Throwable;

class TelegramLoginController extends Controller
{
    public function redirect(Request $request): RedirectResponse
    {
        $this->ensureConfigured();

        $state = Str::random(64);
        $verifier = $this->base64Url(random_bytes(64));
        $nonce = Str::random(64);
        $redirectUri = $this->redirectUri();
        $tree = $request->filled('tree')
            ? FamilyTree::query()
                ->where('slug', $request->string('tree'))
                ->where('status', 'active')
                ->first()
            : null;

        $request->session()->put('telegram_oidc', [
            'state' => $state,
            'verifier' => $verifier,
            'nonce' => $nonce,
            'created_at' => time(),
            'tree_id' => $tree?->id,
            'return_to' => SafeReturnUrl::path($request->query('return')),
        ]);

        $query = http_build_query([
            'client_id' => config('services.telegram.oidc_client_id'),
            'redirect_uri' => $redirectUri,
            'response_type' => 'code',
            'scope' => 'openid profile',
            'state' => $state,
            'nonce' => $nonce,
            'code_challenge' => $this->base64Url(hash('sha256', $verifier, true)),
            'code_challenge_method' => 'S256',
        ], '', '&', PHP_QUERY_RFC3986);

        return redirect()->away(config('services.telegram.oidc_authorize_url').'?'.$query);
    }

    public function callback(Request $request): RedirectResponse
    {
        if ($request->filled('error')) {
            return redirect()->route('login')
                ->with('telegram_auth_error', 'Вход через Telegram отменён.');
        }

        $oidc = $request->session()->pull('telegram_oidc');

        if (
            ! is_array($oidc)
            || ! hash_equals((string) ($oidc['state'] ?? ''), (string) $request->query('state'))
            || time() - (int) ($oidc['created_at'] ?? 0) > 600
        ) {
            return redirect()->route('login')
                ->with('telegram_auth_error', 'Сессия входа устарела. Попробуйте ещё раз.');
        }

        try {
            $response = Http::asForm()
                ->acceptJson()
                ->withBasicAuth(
                    (string) config('services.telegram.oidc_client_id'),
                    (string) config('services.telegram.oidc_client_secret'),
                )
                ->post(config('services.telegram.oidc_token_url'), [
                    'grant_type' => 'authorization_code',
                    'code' => $request->string('code')->toString(),
                    'redirect_uri' => $this->redirectUri(),
                    'client_id' => config('services.telegram.oidc_client_id'),
                    'code_verifier' => $oidc['verifier'],
                ]);

            $response->throw();
            $claims = $this->validateIdToken(
                (string) $response->json('id_token'),
                (string) $oidc['nonce'],
            );
        } catch (Throwable $exception) {
            report($exception);

            return redirect()->route('login')
                ->with('telegram_auth_error', 'Telegram не подтвердил вход. Попробуйте ещё раз.');
        }

        $telegramId = (int) ($claims->id ?? $claims->sub ?? 0);

        if ($telegramId <= 0) {
            return redirect()->route('login')
                ->with('telegram_auth_error', 'Telegram не передал ID пользователя.');
        }

        $nameParts = preg_split('/\s+/u', trim((string) ($claims->name ?? '')), 2);
        $user = TelegramUser::query()->firstOrNew(['telegram_user_id' => $telegramId]);
        $user->fill([
            'username' => $claims->preferred_username ?? $user->username,
            'first_name' => $nameParts[0] ?: $user->first_name,
            'last_name' => $nameParts[1] ?? $user->last_name,
            'photo_url' => $claims->picture ?? $user->photo_url,
            'last_web_login_at' => now(),
            'last_seen_at' => now(),
        ]);

        if (! $user->exists) {
            $user->status = in_array(
                (string) $telegramId,
                config('services.telegram.admin_ids', []),
                true,
            ) ? 'approved' : 'pending';
        }

        $user->save();
        $tree = isset($oidc['tree_id'])
            ? FamilyTree::query()->whereKey($oidc['tree_id'])->where('status', 'active')->first()
            : null;
        $familyUser = app(ExternalIdentityService::class)->resolve('telegram', $telegramId, [
            'username' => $user->username,
            'first_name' => $user->first_name,
            'last_name' => $user->last_name,
            'photo_url' => $user->photo_url,
        ]);
        $user->updateQuietly(array_filter([
            'user_id' => $familyUser->id,
            'current_tree_id' => $tree?->id,
        ], fn ($value): bool => $value !== null));
        if ($request->session()->has('family_invitation_token')) {
            $membership = app(TreeAccessService::class)->acceptInvitation(
                $familyUser,
                (string) $request->session()->pull('family_invitation_token'),
            );
            $tree = $membership->tree;
        } elseif ($tree) {
            $membership = app(TreeAccessService::class)->membership($familyUser, $tree);
            if (
                (
                    in_array((string) $telegramId, config('services.telegram.admin_ids', []), true)
                    || $familyUser->is_super_admin
                )
                && $membership->status === 'pending'
            ) {
                $membership->update([
                    'status' => 'approved',
                    'role' => $user->is_bot_admin ? 'moderator' : 'guest',
                    'approved_at' => now(),
                ]);
            }
        } else {
            $membership = null;
        }
        $request->session()->regenerate();
        $request->session()->put('family_telegram_user_id', $user->id);
        $request->session()->put('family_user_id', $familyUser->id);
        $request->session()->put('family_tree_id', $tree?->id);
        Auth::login($familyUser);

        if (
            ($returnTo = SafeReturnUrl::path($oidc['return_to'] ?? null))
            && ($familyUser->is_super_admin || $membership?->status === 'approved')
        ) {
            return redirect()->to($returnTo);
        }

        return app(AuthRedirector::class)->redirect($familyUser, $tree);
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->forget([
            'family_telegram_user_id',
            'family_user_id',
            'family_tree_id',
        ]);
        $request->session()->regenerateToken();

        return redirect()->route('home');
    }

    private function validateIdToken(string $idToken, string $nonce): object
    {
        if ($idToken === '') {
            throw ValidationException::withMessages(['id_token' => 'ID token отсутствует.']);
        }

        $jwks = Cache::remember('telegram-oidc-jwks', now()->addHours(6), function (): array {
            return Http::acceptJson()
                ->timeout(10)
                ->get(config('services.telegram.oidc_jwks_url'))
                ->throw()
                ->json();
        });
        $claims = JWT::decode($idToken, JWK::parseKeySet($jwks));
        $audience = (array) ($claims->aud ?? []);

        if (
            ($claims->iss ?? null) !== 'https://oauth.telegram.org'
            || ! in_array((string) config('services.telegram.oidc_client_id'), array_map('strval', $audience), true)
            || ! isset($claims->nonce)
            || ! hash_equals($nonce, (string) $claims->nonce)
        ) {
            throw ValidationException::withMessages(['id_token' => 'Некорректные claims Telegram.']);
        }

        return $claims;
    }

    private function ensureConfigured(): void
    {
        abort_unless(
            config('services.telegram.oidc_client_id')
            && config('services.telegram.oidc_client_secret'),
            503,
            'Вход через Telegram ещё не настроен.',
        );
    }

    private function redirectUri(): string
    {
        return (string) (
            config('services.telegram.oidc_redirect_uri')
            ?: route('telegram.login.callback')
        );
    }

    private function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
