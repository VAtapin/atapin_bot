@extends('public.layout')

@section('title', 'Доступ ожидает подтверждения — Я и дом мой')

@section('content')
<section class="content-card">
    <h1>Доступ ожидает подтверждения</h1>
    <p>
        @if($tree)
            Владелец дерева «{{ $tree->name }}» ещё не подтвердил вашу заявку.
        @else
            У вашей учётной записи пока нет подтверждённого доступа к семейному дереву.
        @endif
    </p>
    <a class="button secondary" href="{{ route('home') }}">На главную</a>
</section>
@endsection
