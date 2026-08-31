<?php

namespace App\Enums;

/**
 * What caused a balance movement (legacy w_statistics.system, extended with
 * bet/win which the legacy schema only recorded in w_stat_game).
 */
enum TxnSource: string
{
    case Bet = 'bet';
    case Win = 'win';
    case GameBank = 'game_bank';           // legacy 'bank'
    case Jackpot = 'jackpot';              // legacy 'jpg'
    case PlayerTransfer = 'player_transfer'; // legacy 'user'
    case ShopTransfer = 'shop_transfer';   // legacy 'shop'
    case Handpay = 'handpay';
    case Pincode = 'pincode';
    case Refund = 'refund';
    case HappyHour = 'happy_hour';         // legacy 'happyhour'
    case Invite = 'invite';
    case Progress = 'progress';
    case Tournament = 'tournament';
    case DailyEntry = 'daily_entry';
    case WelcomeBonus = 'welcome_bonus';
    case SmsBonus = 'sms_bonus';
    case WheelFortune = 'wheel_fortune';   // legacy 'wheelfortune'
    case Interkassa = 'interkassa';
    case Coinbase = 'coinbase';
    case BtcPayServer = 'btcpayserver';
}
