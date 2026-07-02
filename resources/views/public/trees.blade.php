@extends('public.layout')

@section('title', 'Выбор дерева — Я и дом мой')

@section('content')
<section class="content-card">
    <h1>Выберите семейное дерево</h1>
    <div style="display:grid;gap:12px">
        @forelse($memberships as $membership)
            <a class="button secondary"
               href="{{ in_array($membership->role, ['owner', 'moderator'], true)
                    ? '/manage/'.$membership->tree->slug
                    : route('family.tree', $membership->tree) }}">
                {{ $membership->tree->name }} — {{ \App\Models\TreeMembership::ROLES[$membership->role] ?? $membership->role }}
            </a>
        @empty
            <p>У вас пока нет подтверждённого доступа к семейным деревьям.</p>
        @endforelse
    </div>
</section>
@endsection
