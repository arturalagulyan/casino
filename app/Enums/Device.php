<?php

namespace App\Enums;

/** Legacy w_games.device: 1 = desktop, 2 = mobile. */
enum Device: string
{
    case Desktop = 'desktop';
    case Mobile = 'mobile';
    case Both = 'both';
}
