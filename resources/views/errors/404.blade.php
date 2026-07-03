<!doctype html>
<html lang="ru"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Страница не найдена — Я и дом мой</title>
<style>body{margin:0;display:grid;min-height:100vh;place-items:center;background:#f6f2e9;color:#29251f;font:16px system-ui}.card{width:min(500px,calc(100% - 32px));padding:32px;border:1px solid #ddd5c8;border-radius:20px;background:#fffdf8;text-align:center}h1{font-family:Georgia,serif}a{display:inline-block;padding:10px 16px;border-radius:10px;background:#68734b;color:#fff;text-decoration:none;font-weight:700}</style>
</head><body><main class="card"><div style="font-size:42px">🌿</div><h1>Страница не найдена</h1>
<p>{{ $exception->getMessage() && $exception->getMessage() !== 'Not Found' ? $exception->getMessage() : 'Возможно, ссылка устарела или семейное дерево было удалено.' }}</p>
<a href="{{ route('home') }}">На главную</a></main></body></html>
