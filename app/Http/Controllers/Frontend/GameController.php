<?php

namespace App\Http\Controllers\Frontend;

use App\Services\Frontend\HouseCasino;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\View\View;

class GameController extends Controller
{
    public function __construct(private readonly HouseCasino $house) {}

    /** The play page — our chrome (back / balance / fullscreen) around the game iframe. */
    public function show(string $code): View
    {
        $game = $this->house->game($code) ?? abort(404);

        return view('frontend.games.play', [
            'code' => $code,
            'title' => $game->title ?: $game->template->title,
            'src' => route('frontend.launch', $code),
        ]);
    }

    /** Mint a fresh launch token and bounce to the shared game-serving route. */
    public function launch(Request $request, string $code): RedirectResponse
    {
        return redirect()->away($this->house->launchUrl($request->user(), $code));
    }
}
