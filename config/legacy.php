<?php

return [
    /*
     | Local mirror of the legacy server's game files, used by
     | `php artisan import:legacy` and its --bundles / --posters steps.
     |
     | The mirror lives on the host at LEGACY_MIRROR_PATH (default
     | /var/www/casino-legacy) and compose.yaml bind-mounts it to
     | /var/www/legacy:ro inside the container. Populate it once:
     |   rsync -a root@LEGACY:/var/www/game-api-server/app/Games/                   $LEGACY_MIRROR_PATH/app-games/
     |   rsync -a root@LEGACY:/var/www/game-api-server/public/games/                $LEGACY_MIRROR_PATH/gamess/    # ~59 GB front-ends
     |   rsync -a root@LEGACY:/var/www/game-api-server/public/frontend/Default/ico/ $LEGACY_MIRROR_PATH/frontend/Default/ico/
     */
    'app_games_path' => env('LEGACY_APP_GAMES_PATH', '/var/www/legacy/app-games'),
    'gamess_path' => env('LEGACY_GAMES_PATH', '/var/www/legacy/gamess'),

    // Theme whose /frontend/<theme>/ico/<code>.jpg posters to prefer.
    'poster_theme' => env('LEGACY_POSTER_THEME', 'Default'),
    'frontend_path' => env('LEGACY_FRONTEND_PATH', '/var/www/legacy/frontend'),
];
