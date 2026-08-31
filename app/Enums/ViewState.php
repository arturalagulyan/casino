<?php

namespace App\Enums;

/** Legacy w_games.slotViewState. */
enum ViewState: string
{
    case Default = '';
    case Normal = 'Normal';
    case HideUi = 'HideUI';
}
