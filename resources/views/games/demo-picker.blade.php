<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Play demo — {{ $template->title }}</title>
    <style>
        :root { color-scheme: dark; }
        * { box-sizing: border-box; }
        body { margin: 0; font: 15px/1.5 system-ui, sans-serif; background: #0b0f0d; color: #e8eae9;
               display: flex; min-height: 100vh; align-items: center; justify-content: center; }
        .cab { width: min(460px, 94vw); background: #121a16; border: 1px solid #223; border-radius: 20px;
               padding: 28px; box-shadow: 0 30px 80px -20px #000; }
        h1 { margin: 0 0 4px; font: 700 20px/1.2 "Cinzel", Georgia, serif; letter-spacing: .06em; color: #e7c968; }
        .sub { margin: 0 0 20px; color: #8a938e; font-size: 13px; }
        a.shop { display: flex; justify-content: space-between; align-items: center; gap: 12px;
                 text-decoration: none; color: #e8eae9; background: #0d1512; border: 1px solid #1e2b25;
                 border-radius: 12px; padding: 14px 16px; margin: 8px 0; transition: .15s; }
        a.shop:hover { border-color: #d4af37; background: #143024; }
        a.shop span { color: #8a938e; font-size: 12px; }
    </style>
</head>
<body>
    <div class="cab">
        <h1>{{ $template->title }}</h1>
        <p class="sub">Choose a shop to demo-play in. Fake credits — nothing touches the shop's books.</p>

        @foreach ($games as $game)
            <a class="shop" href="{{ route('games.demo', ['code' => $template->code, 'shop' => $game->shop_id]) }}">
                <strong>{{ $game->shop->name }}</strong>
                <span>{{ $game->shop->currency->value }}</span>
            </a>
        @endforeach
    </div>
</body>
</html>
