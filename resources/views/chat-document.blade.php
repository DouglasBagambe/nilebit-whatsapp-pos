<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $title }}</title>
    <style>
        @page { margin: 42px; }
        body { color: #17212e; font-family: 'DejaVu Sans', sans-serif; font-size: 10pt; }
        .brand { color: #127e9b; font-size: 22pt; font-weight: bold; margin-bottom: 8px; }
        h1 { font-size: 17pt; margin: 24px 0 10px; }
        .shop { font-size: 12pt; }
        .line { border-bottom: 1px solid #e7ecf0; padding: 9px 0; overflow-wrap: break-word; }
        .footer { margin-top: 24px; color: #536170; font-size: 8pt; }
    </style>
</head>
<body>
    <div class="brand">NileBit POS</div>
    <div class="shop">{{ $shop }}</div>
    <h1>{{ $title }}</h1>
    @forelse ($lines as $line)
        <div class="line">{{ $line }}</div>
    @empty
        <div class="line">No figures were returned for this selection.</div>
    @endforelse
    <div class="footer">Figures from your POS at the time of this request. Amounts use your POS currency.</div>
</body>
</html>
