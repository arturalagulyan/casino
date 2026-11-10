<?php

namespace App\Http\Controllers\Frontend;

use App\Enums\GameLabel;
use App\Services\Frontend\HouseCasino;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\View\View;

class LobbyController extends Controller
{
    public function __construct(private readonly HouseCasino $house) {}

    public function index(Request $request): View
    {
        $category = $request->string('category')->toString() ?: null;
        $tag = $request->string('tag')->toString() ?: null;
        $label = GameLabel::tryFrom($request->string('label')->toString());
        $search = $request->string('q')->trim()->toString() ?: null;

        $games = $this->house->games()
            ->when($category, fn (Builder $q) => $q->whereHas(
                'categories', fn (Builder $c) => $c->where('categories.slug', $category),
            ))
            ->when($tag, fn (Builder $q) => $q->whereHas(
                'template.tags', fn (Builder $t) => $t->where('tags.slug', $tag),
            ))
            ->when($label, fn (Builder $q) => $q->where('label', $label))
            ->when($search, fn (Builder $q) => $q->where(fn (Builder $w) => $w
                ->where('games.title', 'like', "%{$search}%")
                ->orWhereHas('template', fn (Builder $t) => $t
                    ->where('title', 'like', "%{$search}%")
                    ->orWhere('code', 'like', "%{$search}%")
                    ->orWhereHas('tags', fn (Builder $g) => $g->where('tags.name', 'like', "%{$search}%")))
                ->orWhereHas('categories', fn (Builder $c) => $c->where('categories.title', 'like', "%{$search}%"))))
            ->orderByRaw('label is null')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->paginate(48)
            ->withQueryString();

        return view('frontend.lobby.index', [
            'games' => $games,
            'categories' => $this->house->categories(),
            'tags' => $this->house->tags(),
            'activeCategory' => $category,
            'activeTag' => $tag,
            'activeLabel' => $label,
            'search' => $search,
        ]);
    }
}
