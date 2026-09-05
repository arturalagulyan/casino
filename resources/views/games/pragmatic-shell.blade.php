{{-- Request-time loader for the legacy Pragmatic Play "platform" chrome GWT
     app (which boots the nested "bib" game GWT app). Port of the legacy
     per-game resources/views/frontend/games/list/<Code>.blade.php — that
     Blade file was ~100% identical across every Pragmatic title (only
     $game->name / $game->title varied), so this is one shared shell instead
     of ~60 near-duplicate views. The bundle itself is never modified. --}}
<!DOCTYPE html>
<html>
<head>
    <base href="{{ $base }}">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta http-equiv="Content-Type" content="text/html; charset=UTF-8">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, minimum-scale=1, target-densitydpi=device-dpi, user-scalable=no, viewport-fit=cover">
    <title>{{ $title }}</title>
    {!! $jackpotTicker ?? '' !!}
    <link type="text/css" rel="stylesheet" href="css/normalize.css">
    <link type="text/css" rel="stylesheet" href="css/style.css">
    <script src="js/cookie.js"></script>
    <script src="js/gls_config.js"></script>
    <script src="js/gls.js"></script>
    <script src="js/script.js"></script>
    <script src="js/viewportJs.js"></script>
    <script src="js/viewportCss.js"></script>
    <script src="js/lib/modernizr-animations.min.js"></script>
    <script src="js/bootstrap.js"></script>
    <script src="js/chat-wrapper.js"></script>
</head>
<body style="background-color:#000;overflow:hidden;" class="noBranding">
    <div id="size-handler"></div>
    <div id="app" style="background-color:#000;overflow:hidden;">
        <div class="scalable" id="viewport">
            <div id="size-reader"></div>
            <div id="wrapper" style="background-color:#000;overflow:hidden;"></div>
            <div id="system-place" style="display:none;"></div>
            <div id="modals"></div>
            <div id="tooltips" class="tooltipsWrapper"></div>
            <div id="overlays"></div>
            <div id="rotate"></div>
            <div id="split"></div>
            <div id="devTools"></div>
        </div>
    </div>
    <div id="hidden-content" class="hidden-content"></div>
    <noscript>
        <div class="noscript">Your web browser must have JavaScript enabled in order for this application to display correctly.</div>
    </noscript>
    <script>
        bootPlatform();
        try { sessionStorage.setItem('sessionId', @json($token)); } catch (e) {}
        try { localStorage.setItem('SESSIONS_PLAYER', @json($sessionsPlayerKey)); } catch (e) {}
    </script>
    <script src="platform/platform.nocache.js"></script>
</body>
</html>
