<?php

namespace App\Enums;

enum UserStatus: string
{
    case Active = 'active';
    case Unconfirmed = 'unconfirmed';
    case Banned = 'banned';
    case Inactive = 'inactive';
}
