<?php

namespace App\Enums;

enum ShopStatus: string
{
    case Active = 'active';
    case Blocked = 'blocked';
    case Pending = 'pending';
}
