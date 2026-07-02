@extends('public.layout')

@section('title', $page->meta_title ?: $page->title.' — Я и дом мой')
@section('description', $page->meta_description ?: '')

@section('content')
<article class="content-card">
    <h1>{{ $page->title }}</h1>
    <div>{!! nl2br(e($page->content)) !!}</div>
</article>
@endsection
