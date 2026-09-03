<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Public base URL
    |--------------------------------------------------------------------------
    |
    | Launch URLs handed to external operators must be absolute and reachable
    | from the player's browser — not necessarily the same host the app runs on.
    | Falls back to APP_URL.
    |
    */

    'public_url' => env('GAMES_PUBLIC_URL', env('APP_URL', 'http://localhost')),

    /*
    |--------------------------------------------------------------------------
    | Game socket
    |--------------------------------------------------------------------------
    |
    | Legacy EGT "GamePlatform" bundles talk to the server over a raw WebSocket
    | (`:::`-framed JSON) instead of HTTP. `php artisan game:socket` runs that
    | server — as the `gamesocket` compose service in every environment. The
    | `public_*` values are what the browser is told to connect to (served as
    | /socket_config.json).
    |
    */

    'socket' => [
        'host' => env('GAME_SOCKET_HOST', '0.0.0.0'),
        'port' => (int) env('GAME_SOCKET_PORT', 2087),
        'workers' => (int) env('GAME_SOCKET_WORKERS', 1),

        // Where the browser is told to open the game WebSocket. Defaults to the
        // APP_URL host (so a deploy behind http://<ip>:8080 just works) and only
        // needs setting when the socket is reachable at a different hostname.
        'public_host' => env('GAME_SOCKET_PUBLIC_HOST')
            ?: (parse_url((string) env('APP_URL', 'http://localhost'), PHP_URL_HOST) ?: 'localhost'),
        'public_port' => (int) env('GAME_SOCKET_PUBLIC_PORT', (int) env('GAME_SOCKET_PORT', 2087)),
        'scheme' => env('GAME_SOCKET_SCHEME', 'ws'),   // ws | wss
        'path' => env('GAME_SOCKET_PATH', '/slots'),
    ],

];
