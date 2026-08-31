<?php

namespace App\Enums;

/** Legacy w_statistics.type (add / out). */
enum TxnDirection: string
{
    case Credit = 'credit';
    case Debit = 'debit';
}
