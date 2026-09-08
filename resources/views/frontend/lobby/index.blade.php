@extends('frontend.layout')

@section('title', config('frontend.brand').' · Games')

@section('content')
    <div class="mx-auto max-w-7xl px-4 py-6">

        {{-- mobile search --}}
        <form action="{{ route('frontend.lobby') }}" method="GET" class="mb-4 sm:hidden">
            <input type="search" name="q" value="{{ request('q') }}" placeholder="Search games…"
                   data-lobby-search autocomplete="off" class="field">
        </form>

        {{-- category rail --}}
        <div class="mb-4 flex gap-2 overflow-x-auto pb-2">
            <a href="{{ route('frontend.lobby', array_filter(['q' => $search])) }}"
               class="cat-chip" data-active="{{ $activeCategory ? 'false' : 'true' }}">All</a>
            @foreach($categories as $category)
                <a href="{{ route('frontend.lobby', array_filter(['category' => $category->slug, 'q' => $search])) }}"
                   class="cat-chip" data-active="{{ $activeCategory === $category->slug ? 'true' : 'false' }}">
                    {{ $category->title }}
                    <span class="text-zinc-500">{{ $category->games_count }}</span>
                </a>
            @endforeach
        </div>

        {{-- label filters --}}
        <div class="mb-6 flex gap-2">
            @foreach(\App\Enums\GameLabel::cases() as $label)
                <a href="{{ route('frontend.lobby', array_filter([
                        'category' => $activeCategory,
                        'q' => $search,
                        'label' => $activeLabel === $label ? null : $label->value,
                    ])) }}"
                   class="cat-chip capitalize" data-active="{{ $activeLabel === $label ? 'true' : 'false' }}">
                    {{ $label->value }}
                </a>
            @endforeach
        </div>

        @if($games->total() === 0)
            <div class="rounded-xl border border-ink-700 bg-ink-850/60 px-6 py-16 text-center text-zinc-400">
                No games match your filters.
                <a href="{{ route('frontend.lobby') }}" class="ml-1 text-gold-400 hover:underline">Clear</a>
            </div>
        @else
            <p class="mb-3 text-xs text-zinc-500">{{ $games->total() }} games</p>
            <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5 xl:grid-cols-6">
                @foreach($games as $game)
                    @include('frontend.lobby.card', ['game' => $game])
                @endforeach
            </div>

            <div class="mt-8">
                {{ $games->links() }}
            </div>
        @endif
    </div>
@endsection
