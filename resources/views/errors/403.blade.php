<!doctype html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Недостаточно прав — Я и дом мой</title>
    <style>body{margin:0;display:grid;min-height:100vh;place-items:center;background:#f6f2e9;color:#29251f;font:16px system-ui}.card{width:min(480px,calc(100% - 32px));padding:32px;border:1px solid #ddd5c8;border-radius:20px;background:#fffdf8;text-align:center}h1{font-family:Georgia,serif}.actions{display:flex;justify-content:center;gap:10px;flex-wrap:wrap}a{padding:10px 16px;border-radius:10px;background:#68734b;color:#fff;text-decoration:none;font-weight:700}</style>
</head>
<body><main class="card">
    <div style="font-size:42px">🔒</div>
    <h1>Недостаточно прав</h1>
    <p>{{ $exception->getMessage() && $exception->getMessage() !== 'This action is unauthorized.' ? $exception->getMessage() : 'Эта функция недоступна для вашей роли.' }}</p>
    <div class="actions"><a href="javascript:history.back()">Вернуться</a><a href="{{ route('trees.choose') }}">Мои деревья</a></div>
</main></body>
</html>
