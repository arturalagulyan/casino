{{-- Request-time loader for the legacy Novomatic / Greentube JS engine
     (js/loader.js + js/core.js). Port of jackpotmatic-master's
     resources/views/frontend/games/list/Asian.blade.php — the bundle itself is
     never modified. The newer `*GT`/`*DX` bundles render with PIXI and append
     their own <canvas> from core.js top-level, so the engine scripts must run
     after <body> exists — they load at end-of-body for every variant. --}}
<!DOCTYPE html>
<html lang="en">
<head>
    <title>{{ $title }}</title>
    <meta charset="utf-8">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, minimal-ui">
    <base href="{{ $base }}">
    @if ($fontsCss)
        <link href="{{ $fontsCss }}" rel="stylesheet" type="text/css">
    @endif
    <script>
        try { sessionStorage.setItem('sessionId', @json($token)); } catch (e) {}
        window.onerror = function () { return true; };
        {{-- the PIXI loader reads this global every frame; the legacy host page owned it --}}
        window.isFontLoaded = @json(empty($fontFamilies));
    </script>
    <style>
        html, body { position: fixed; width: 100%; height: 100%; margin: 0; background: #000; }
        #game { display: block; }
        canvas { display: block; }
    </style>
    {!! $jackpotTicker ?? '' !!}
</head>
<body onload="InitializeGame()">
    @if ($canvas ?? true)
        <canvas id="game" width="{{ $width }}" height="{{ $height }}"></canvas>
    @endif
    @foreach ($scripts as $src)
        <script src="{{ $src }}" type="text/javascript"></script>
    @endforeach
    @if (! empty($fontFamilies))
        <script>
            (function () {
                var done = function () { window.isFontLoaded = true; };
                try {
                    if (window.WebFont) {
                        WebFont.load({
                            custom: { families: @json($fontFamilies) },
                            active: done, inactive: done, timeout: 3000,
                        });
                    }
                } catch (e) {}
                if (document.fonts && document.fonts.ready) { document.fonts.ready.then(done); }
                setTimeout(done, 3000);
            })();
        </script>
    @endif
</body>
</html>
