<?php

namespace App\Enums;

/** How the server side of a game runs. */
enum GameEngine: string
{
    /** Runs on the platform's own engine — App\Services\GamePlay (fully DB-driven). */
    case Internal = 'internal';
    /** Merkur-style proxied game server. */
    case Merkur = 'merkur';
    /** Remote provider via seamless-wallet callbacks. */
    case Seamless = 'seamless';
}
