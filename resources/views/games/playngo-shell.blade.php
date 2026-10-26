<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no" />
    <title>{{ $title }}</title>
    {{-- the launcher asks for some things by relative URL (games/<id>) — resolve those against our CoreWeb base --}}
    <base href="{{ $coreWeb }}">
    <style>
        html, body { overflow: hidden; -webkit-overflow-scrolling: touch; margin: 0; background: #000; }
        #root { display: block; }
    </style>
</head>
<body>
    <script>
        // Play'n GO's ContainerLauncher, pointed at us: its "CoreWeb" base (game +
        // configuration lookups) and its game server both live under /playngo/{session}/.
        window.serverUrl = @json($coreWeb);
        window.pngCoreWebUrl = @json($coreWeb);
        var model = {
            gameId: @json((string) $gameId),
            gid: @json($gid),
            coreWebUrl: @json($coreWeb),
            lang: "en_GB",
            productGroup: 2,
            brand: "",
            practice: 0,
            channel: @json($channel),
            resourceRoot: "",
            ticket: @json($token),
            embedMode: "iframe",
            externalGameUrl: "",
            isExternalGame: false
        };
    </script>
    <div id="root"></div>
    <script src="{{ $launcher }}"></script>
    {!! $jackpotTicker !!}
</body>
</html>
