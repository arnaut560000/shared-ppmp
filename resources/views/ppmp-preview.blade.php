<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $reference }} · PPMP preview</title>
    <style>
        {!! $styles !!}
        body { margin: 0; background: #eef3f8; color: #15283e; }
        nav { display: flex; align-items: center; flex-wrap: wrap; gap: 1rem; padding: 1rem; background: white; font: 1rem system-ui, sans-serif; }
        nav strong { margin-right: auto; }
        nav button, nav a { font: inherit; border-radius: .4rem; padding: .4rem .8rem; cursor: pointer; }
        nav button { color: white; background: #174a88; border: 1px solid #174a88; }
        nav a { color: #174a88; }
        main { padding: 1rem; overflow: auto; }
        table.sheet0 { background: white; margin: auto; border-collapse: collapse; }
        @page { size: A4 landscape; margin: 8mm; }
        @media print { nav { display: none; } }
    </style>
</head>
<body>
    <nav aria-label="PPMP print actions">
        <strong>{{ $reference }}</strong>
        <a href="{{ $download }}">Download Excel</a>
        <button type="button">Print PPMP</button>
    </nav>
    <main aria-label="Talavera procurement list">{!! $table !!}</main>
    <script nonce="{{ $nonce }}">document.querySelector("button").addEventListener("click", () => window.print());</script>
</body>
</html>
