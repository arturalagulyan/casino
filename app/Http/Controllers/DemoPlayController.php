<?php

namespace App\Http\Controllers;

use App\Models\Game;
use App\Models\GameTemplate;
use App\Services\GamePlay\DemoLauncher;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Redirect;
use Symfony\Component\HttpFoundation\Response;

/**
 * "Play demo" from the admin panel: mint a demo launch token for the shop's
 * throwaway demo player and drop the staffer straight into the game.
 *
 *   GET /games/demo/{code}          → pick a shop if the template has more than one
 *   GET /games/demo/{code}?shop=ID  → launch that shop's copy
 */
class DemoPlayController extends Controller
{
    public function __construct(private DemoLauncher $demo) {}

    public function start(Request $request, string $code): Response
    {
        $user = $request->user();

        if (! $user) {
            return Redirect::guest(route('filament.admin.auth.login'));
        }

        abort_unless($user->hasPermission('games.manage'), 403);

        $template = GameTemplate::where('code', $code)->firstOr(fn () => abort(404));

        $games = Game::query()
            ->where('template_id', $template->id)
            ->visibleTo($user)
            ->with('shop')
            ->get()
            ->sortBy('shop.name')
            ->values();

        if ($shopId = $request->integer('shop')) {
            $games = $games->where('shop_id', $shopId)->values();
        }

        if ($games->isEmpty()) {
            abort(404, 'No shop carries this game yet.');
        }

        if ($games->count() > 1) {
            return response()->view('games.demo-picker', [
                'template' => $template,
                'games' => $games,
            ]);
        }

        return Redirect::to($this->demo->launchUrl($games->first()));
    }
}
