@extends('public.layout')

@section('title', 'Вопросы и ответы — Я и дом мой')
@section('description', 'Быстрый старт и ответы на вопросы о семейных деревьях, импорте, доступе и доменах.')

@section('content')
<div class="faq-page">
    <section class="faq-hero">
        <span class="faq-kicker">Справочный центр</span>
        <h1>Как вам помочь?</h1>
        <p>Найдите ответ или начните с раздела «Быстрый старт».</p>
        <label class="faq-search">
            <span aria-hidden="true">⌕</span>
            <input id="faq-search" type="search" placeholder="Например: импорт GEDCOM или приглашение" autocomplete="off">
        </label>
    </section>

    <nav class="faq-categories" aria-label="Разделы справки">
        @foreach($categories as $category)
            <a href="#{{ $category->slug }}">{{ $category->title }}</a>
        @endforeach
    </nav>

    <p id="faq-empty" class="faq-empty" hidden>По вашему запросу ничего не найдено. Попробуйте написать короче.</p>

    @foreach($categories as $category)
        <section class="faq-section" id="{{ $category->slug }}">
            <header>
                <h2>{{ $category->title }}</h2>
                @if($category->description)<p>{{ $category->description }}</p>@endif
            </header>
            <div class="faq-items">
                @foreach($category->items as $item)
                    <details class="faq-item" data-search="{{ mb_strtolower($item->question.' '.$item->keywords.' '.strip_tags($item->answer)) }}">
                        <summary>{{ $item->question }} <span aria-hidden="true">＋</span></summary>
                        <div class="faq-answer">{!! $item->answer !!}</div>
                    </details>
                @endforeach
            </div>
        </section>
    @endforeach
</div>

<style>
    .faq-page{width:min(980px,calc(100% - 32px));margin:28px auto 80px}
    .faq-hero{text-align:center;padding:48px 22px 34px;border:1px solid var(--line);border-radius:24px;background:var(--card)}
    .faq-kicker{color:var(--green);font-weight:750}.faq-hero h1{margin:8px 0;font:700 44px/1.1 Georgia,serif}.faq-hero p{color:var(--muted)}
    .faq-search{display:flex;align-items:center;gap:10px;max-width:650px;margin:24px auto 0;padding:0 14px;border:1px solid var(--line);border-radius:14px;background:#fff}
    .faq-search:focus-within{border-color:var(--green);box-shadow:0 0 0 4px rgb(104 115 75 / 12%)}.faq-search input{border:0;outline:0;padding:15px 0;font-size:16px}
    .faq-categories{display:flex;gap:8px;overflow:auto;margin:18px 0 36px;padding-bottom:4px}.faq-categories a{flex:0 0 auto;padding:9px 13px;border:1px solid var(--line);border-radius:999px;background:var(--card);text-decoration:none}
    .faq-section{scroll-margin-top:20px;margin:34px 0}.faq-section>header h2{margin-bottom:3px;font:700 28px Georgia,serif}.faq-section>header p{margin-top:0;color:var(--muted)}
    .faq-items{display:grid;gap:10px}.faq-item{border:1px solid var(--line);border-radius:14px;background:var(--card);overflow:hidden}.faq-item summary{display:flex;justify-content:space-between;gap:15px;padding:18px 20px;font-weight:750;cursor:pointer;list-style:none}.faq-item summary::-webkit-details-marker{display:none}.faq-item[open] summary span{transform:rotate(45deg)}.faq-item summary span{font-size:22px;color:var(--green);transition:.15s}.faq-answer{padding:0 20px 20px;color:#514d45}.faq-answer :first-child{margin-top:0}.faq-answer :last-child{margin-bottom:0}.faq-empty{padding:22px;border:1px dashed var(--line);border-radius:14px;text-align:center;color:var(--muted)}
    @media(max-width:700px){.faq-hero{padding:34px 18px}.faq-hero h1{font-size:34px}.faq-section>header h2{font-size:24px}}
</style>
<script>
    (() => {
        const input = document.getElementById('faq-search');
        const sections = [...document.querySelectorAll('.faq-section')];
        const empty = document.getElementById('faq-empty');
        const normalize = value => value.toLocaleLowerCase('ru').replaceAll('ё', 'е').trim();
        input.addEventListener('input', () => {
            const query = normalize(input.value);
            let total = 0;
            sections.forEach(section => {
                let visible = 0;
                section.querySelectorAll('.faq-item').forEach(item => {
                    const matches = !query || normalize(item.dataset.search).includes(query);
                    item.hidden = !matches;
                    if (matches) visible++;
                });
                section.hidden = visible === 0;
                total += visible;
            });
            empty.hidden = total !== 0;
        });
    })();
</script>
@endsection
