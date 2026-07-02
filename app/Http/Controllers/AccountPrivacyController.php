<?php

namespace App\Http\Controllers;

use App\Models\FamilyTree;
use App\Models\TelegramUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AccountPrivacyController extends Controller
{
    public function export(Request $request): JsonResponse
    {
        $user = $request->attributes->get('familyUser');
        $person = $request->attributes->get('familyPerson')
            ?: $request->attributes->get('treeMembership')?->person;

        return response()->json([
            'exported_at' => now()->toIso8601String(),
            'account' => $user?->only(['id', 'name', 'email', 'created_at']),
            'external_identities' => $user?->externalIdentities()
                ->get(['provider', 'provider_user_id', 'username', 'profile', 'created_at']),
            'memberships' => $user?->memberships()
                ->with('tree:id,name,slug')
                ->get(['id', 'tree_id', 'person_id', 'role', 'status', 'created_at']),
            'person' => $person?->load(['parents', 'children', 'photos', 'albums'])->toArray(),
        ], headers: [
            'Content-Disposition' => 'attachment; filename="my-idommoy-data.json"',
        ]);
    }

    public function destroy(Request $request): JsonResponse
    {
        $data = $request->validate([
            'confirmation' => ['required', 'in:УДАЛИТЬ АККАУНТ'],
        ]);
        $user = $request->attributes->get('familyUser');
        abort_unless($user, 403);

        if (FamilyTree::query()->where('owner_user_id', $user->id)->exists()) {
            throw ValidationException::withMessages([
                'confirmation' => 'Сначала передайте владение семейным деревом другому пользователю.',
            ]);
        }

        DB::transaction(function () use ($user): void {
            TelegramUser::query()->where('user_id', $user->id)->update([
                'user_id' => null,
                'person_id' => null,
                'status' => 'blocked',
            ]);
            $user->externalIdentities()->delete();
            $user->memberships()->delete();
            $user->update([
                'name' => 'Удалённый пользователь',
                'email' => 'deleted_'.$user->id.'_'.now()->timestamp.'@idommoy.local',
                'password' => bin2hex(random_bytes(32)),
                'is_active' => false,
            ]);
        });

        $request->session()->invalidate();

        return response()->json(['message' => 'Аккаунт удалён.']);
    }
}
