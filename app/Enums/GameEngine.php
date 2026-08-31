<?php

namespace App\Enums;

/** How the server side of a game runs. */
enum GameEngine: string
{
    /** Server code shipped with the platform: \App\Games\<Code>\Server */
    case Internal = 'internal';
    /** Merkur-style proxied game server. */
    case Merkur = 'merkur';
    /** Remote provider via seamless-wallet callbacks. */
    case Seamless = 'seamless';
}
