<?php

namespace App\Enums;

/** How a shop's game grid is ordered by default (legacy w_shops.orderby). */
enum GameOrder: string
{
    case Alphabetical = 'az';
    case Random = 'random';
    case Rtp = 'rtp';
    case PlayCount = 'count';
    case ReleaseDate = 'date';
}
