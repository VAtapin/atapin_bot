@extends('public.layout')

@section('title', 'Создать семейное дерево — Я и дом мой')

@section('content')
<section class="content-card">
    <h1>Создать семейное дерево</h1>
    <p>30 дней для знакомства с семейным архивом.</p>
    @if($errors->any())
        <div class="error">{{ $errors->first() }}</div>
    @endif
    <form class="form-grid" method="post" action="{{ route('register.store') }}">
        @csrf
        <label><span>Ваше имя</span><input name="name" value="{{ old('name') }}" required></label>
        <label><span>Email</span><input name="email" type="email" value="{{ old('email') }}" required></label>
        <label><span>Пароль</span><input name="password" type="password" required></label>
        <label><span>Повторите пароль</span><input name="password_confirmation" type="password" required></label>
        <label><span>Название семьи</span><input name="tree_name" value="{{ old('tree_name') }}" placeholder="Например, семья Ивановых" required></label>
        <label><span>Адрес дерева</span><input name="tree_slug" value="{{ old('tree_slug') }}" placeholder="ivanovy" required></label>
        <div class="wide"><button class="button" type="submit">Создать дерево</button></div>
    </form>
</section>
@endsection
