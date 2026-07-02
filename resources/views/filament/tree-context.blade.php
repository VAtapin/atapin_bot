@php
    $tree = filament()->getTenant();
    $role = $tree && auth()->user()
        ? auth()->user()->roleInTree($tree)
        : null;
    $label = match ($role) {
        'super_admin' => 'суперадминистратор',
        'owner' => 'владелец',
        'moderator' => 'модератор',
        default => $role,
    };
@endphp

@if($tree)
    <div style="display:flex;align-items:center;gap:.45rem;font-size:.82rem;white-space:nowrap">
        <span style="opacity:.65">Дерево:</span>
        <strong>{{ $tree->name }}</strong>
        @if($label)
            <span style="opacity:.65">· {{ $label }}</span>
        @endif
    </div>
@endif
