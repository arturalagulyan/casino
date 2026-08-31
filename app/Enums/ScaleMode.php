<?php

namespace App\Enums;

/** Legacy w_games.scaleMode. */
enum ScaleMode: string
{
    case Default = '';
    case ShowAll = 'showAll';
    case ExactFit = 'exactFit';
}
