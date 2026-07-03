@extends('public.layout')

@section('title', 'Создать семейное дерево — Я и дом мой')

@section('content')
<section class="content-card">
    <h1>Создать семейное дерево</h1>
    <p>30 дней для знакомства с семейным архивом.</p>
    <form class="form-grid" method="post" action="{{ route('register.store') }}">
        @csrf
        <label class="@error('name') field-invalid @enderror">
            <span>Ваше имя</span>
            <input name="name" value="{{ old('name') }}" required @error('name') aria-invalid="true" autofocus @enderror>
            @error('name')<small class="field-error">{{ $message }}</small>@enderror
        </label>
        <label class="@error('email') field-invalid @enderror">
            <span>Email</span>
            <input name="email" type="email" value="{{ old('email') }}" required @error('email') aria-invalid="true" autofocus @enderror>
            @error('email')
                <small class="field-error">{{ $message }}</small>
                <small><a href="{{ route('login', ['login' => old('email')]) }}">Войти в существующий аккаунт</a></small>
            @enderror
        </label>
        <label class="@error('password') field-invalid @enderror">
            <span>Пароль</span>
            <input name="password" type="password" required @error('password') aria-invalid="true" autofocus @enderror>
            <small>Не менее 10 символов.</small>
            @error('password')<small class="field-error">{{ $message }}</small>@enderror
        </label>
        <label>
            <span>Повторите пароль</span>
            <input name="password_confirmation" type="password" required>
        </label>
        <label class="@error('tree_name') field-invalid @enderror">
            <span>Название семьи</span>
            <input name="tree_name" value="{{ old('tree_name') }}" placeholder="Например, семья Ивановых" required @error('tree_name') aria-invalid="true" autofocus @enderror>
            @error('tree_name')<small class="field-error">{{ $message }}</small>@enderror
        </label>
        <label class="@error('tree_slug') field-invalid @enderror">
            <span>Адрес дерева</span>
            <input name="tree_slug" value="{{ old('tree_slug') }}" placeholder="ivanovy" required @error('tree_slug') aria-invalid="true" autofocus @enderror>
            @error('tree_slug')<small class="field-error">{{ $message }}</small>@enderror
            @if(session('slug_suggestions'))
                <small>Свободные варианты:
                    @foreach(session('slug_suggestions') as $suggestion)
                        <button class="slug-suggestion" type="button" data-slug="{{ $suggestion }}">{{ $suggestion }}</button>
                    @endforeach
                </small>
            @endif
        </label>
        <div class="wide"><button class="button" type="submit">Создать дерево</button></div>
    </form>
</section>
<script>
document.querySelectorAll('[data-slug]').forEach((button) => button.addEventListener('click', () => {
    document.querySelector('[name="tree_slug"]').value = button.dataset.slug;
}));
</script>
@endsection
