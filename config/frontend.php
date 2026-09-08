<?php

return [

    /*
    |--------------------------------------------------------------------------
    | House shop
    |--------------------------------------------------------------------------
    |
    | The built-in player frontend behaves like any other API client: it has
    | its own shop and its own API key. Every player who signs in on the
    | frontend belongs to this shop, and games are launched through the same
    | `App\Services\SeamlessWallet\GameLaunch` pipeline external operators use.
    |
    | Run `php artisan frontend:setup` to create the shop, its API key, a game
    | bank and to clone the game catalogue into it.
    |
    */

    'shop_slug' => env('FRONTEND_SHOP_SLUG', 'web-casino'),

    'shop_name' => env('FRONTEND_SHOP_NAME', 'Web Casino'),

    /*
    | Public brand shown in the player UI.
    */
    'brand' => env('FRONTEND_BRAND', 'Royal Spin'),

];
