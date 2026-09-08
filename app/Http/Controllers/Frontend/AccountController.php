<?php

namespace App\Http\Controllers\Frontend;

use App\Enums\Currency;
use App\Models\GameRound;
use App\Support\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\View\View;

class AccountController extends Controller
{
    public function index(Request $request): View
    {
        $user = $request->user();
        $user->loadMissing('wallet');

        $rounds = GameRound::query()
            ->where('user_id', $user->id)
            ->latest('played_at')
            ->limit(25)
            ->get(['game_code', 'currency', 'bet', 'win', 'balance_after', 'played_at']);

        return view('frontend.account.index', [
            'user' => $user,
            'rounds' => $rounds,
        ]);
    }

    /** Live balance for the top-bar chip (polled by resources/js/frontend.js). */
    public function balance(Request $request): JsonResponse
    {
        $wallet = $request->user()->wallet;
        $currency = $wallet->currency ?? Currency::default();
        $balance = (float) ($wallet->balance ?? 0);

        return response()->json([
            'balance' => $balance,
            'currency' => $currency->value,
            'formatted' => Money::format($balance, $currency),
        ]);
    }
}
