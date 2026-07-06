@extends('public.layout')

@section('title', __('public.trees.title'))
@section('robots', 'noindex,nofollow')

@section('content')
<section class="content-card">
    <h1>{{ __('public.trees.heading') }}</h1>
    <div class="tree-choice-list">
        @forelse($memberships as $membership)
            <a class="button secondary"
               href="{{ in_array($membership->role, ['owner', 'moderator'], true)
                    ? '/manage/'.$membership->tree->slug
                    : app(\App\Support\FamilyTreeUrl::class)->tree($membership->tree) }}">
                {{ $membership->tree->name }} — {{ \App\Models\TreeMembership::ROLES[$membership->role] ?? $membership->role }}
                @if($membership->tree->status === 'deleting')
                    · {{ __('public.trees.deleting', ['date' => $membership->tree->deletion_scheduled_at?->format('d.m.Y')]) }}
                @endif
            </a>
        @empty
            <p>{{ __('public.trees.empty') }}</p>
        @endforelse
    </div>
</section>
@endsection
