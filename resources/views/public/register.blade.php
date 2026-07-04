@extends('public.layout')

@section('title', __('public.auth.register_title'))
@section('robots', 'noindex,follow')

@section('content')
<section class="content-card">
    <h1>{{ __('public.auth.register_heading') }}</h1>
    <p>{{ __('public.auth.trial') }}</p>
    <form class="form-grid" method="post" action="{{ route('register.store') }}">
        @csrf
        <label class="@error('name') field-invalid @enderror">
            <span>{{ __('public.auth.name') }}</span>
            <input name="name" value="{{ old('name') }}" autocomplete="name" required @error('name') aria-invalid="true" @enderror autofocus>
            @error('name')<small class="field-error">{{ $message }}</small>@enderror
        </label>
        <label class="@error('email') field-invalid @enderror">
            <span>{{ __('public.common.email') }}</span>
            <input name="email" type="email" value="{{ old('email') }}" autocomplete="email" required @error('email') aria-invalid="true" @enderror>
            @error('email')
                <small class="field-error">{{ $message }}</small>
                <small><a href="{{ route('login', ['login' => old('email')]) }}">{{ __('public.auth.existing') }}</a></small>
            @enderror
        </label>
        <label class="@error('password') field-invalid @enderror">
            <span>{{ __('public.common.password') }}</span>
            <input name="password" type="password" autocomplete="new-password" required @error('password') aria-invalid="true" @enderror>
            <small>{{ __('public.auth.password_hint') }}</small>
            @error('password')<small class="field-error">{{ $message }}</small>@enderror
        </label>
        <label>
            <span>{{ __('public.common.password_confirmation') }}</span>
            <input name="password_confirmation" type="password" autocomplete="new-password" required>
        </label>
        <label class="@error('tree_name') field-invalid @enderror">
            <span>{{ __('public.auth.tree_name') }}</span>
            <input name="tree_name" value="{{ old('tree_name') }}" placeholder="{{ __('public.auth.tree_name_placeholder') }}" required @error('tree_name') aria-invalid="true" @enderror>
            @error('tree_name')<small class="field-error">{{ $message }}</small>@enderror
        </label>
        <label class="@error('tree_slug') field-invalid @enderror">
            <span>{{ __('public.auth.tree_slug') }}</span>
            <input name="tree_slug" value="{{ old('tree_slug') }}" placeholder="{{ __('public.auth.tree_slug_placeholder') }}" autocapitalize="none" required @error('tree_slug') aria-invalid="true" @enderror>
            @error('tree_slug')<small class="field-error">{{ $message }}</small>@enderror
            @if(session('slug_suggestions'))
                <small>{{ __('public.auth.slug_suggestions') }}
                    @foreach(session('slug_suggestions') as $suggestion)
                        <button class="slug-suggestion" type="button" data-slug="{{ $suggestion }}">{{ $suggestion }}</button>
                    @endforeach
                </small>
            @endif
        </label>
        <label class="wide check-field @error('privacy_consent') field-invalid @enderror">
            <input name="privacy_consent" type="checkbox" value="1" required @checked(old('privacy_consent')) @error('privacy_consent') aria-invalid="true" @enderror>
            <span>
                {!! __('public.auth.privacy_consent', [
                    'privacy' => '<a href="'.e(route('public.page', ['slug' => 'datenschutz', 'lang' => app()->getLocale()])).'" target="_blank" rel="noopener">'.e(__('public.auth.privacy_link')).'</a>',
                ]) !!}
            </span>
            @error('privacy_consent')<small class="field-error">{{ $message }}</small>@enderror
        </label>
        <div class="wide form-actions">
            <button class="button" type="submit">{{ __('public.auth.create') }}</button>
        </div>
    </form>
</section>
@endsection
