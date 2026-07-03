<!doctype html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Приглашение — {{ $invitation->tree->name }}</title>
    <style>
        body{margin:0;display:grid;min-height:100vh;place-items:center;background:#f6f2e9;color:#29251f;font:16px system-ui,sans-serif}
        main{width:min(440px,calc(100% - 32px));padding:30px;border:1px solid #ddd5c8;border-radius:20px;background:#fffdf8;text-align:center}
        h1{font-family:Georgia,serif}canvas{max-width:100%;height:auto}.url{overflow-wrap:anywhere;color:#68734b}.print{padding:10px 16px;border:0;border-radius:9px;background:#68734b;color:#fff;font-weight:700}
        @media print{.print{display:none}body{background:#fff}main{border:0}}
    </style>
</head>
<body>
<main data-invitation-url="{{ $url }}">
    <h1>{{ $invitation->tree->name }}</h1>
    <p>{{ $invitation->label ?: 'Приглашение в семейное дерево' }}</p>
    <canvas id="qr"></canvas>
    <p class="url">{{ $url }}</p>
    <button class="print" onclick="window.print()">Распечатать</button>
</main>
@vite('resources/js/invitation-qr.js')
</body>
</html>
