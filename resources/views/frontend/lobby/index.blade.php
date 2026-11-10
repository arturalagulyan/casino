@extends('frontend.layout')

@section('title', config('frontend.brand').' · Games')

@section('content')
    <div class="mx-auto max-w-7xl px-4 py-6">

        {{-- mobile search --}}
        <form action="{{ route('frontend.lobby') }}" method="GET" class="mb-4 sm:hidden">
            <input type="search" name="q" value="{{ request('q') }}" placeholder="Search games…"
                   data-lobby-search autocomplete="off" class="field">
        </form>

        {{-- provider rail --}}
        <div class="mb-3 flex items-center gap-2 overflow-x-auto pb-2">
            <a href="{{ route('frontend.lobby', array_filter(['tag' => $activeTag, 'q' => $search])) }}"
               class="cat-chip" data-active="{{ $activeCategory ? 'false' : 'true' }}">All</a>
            @foreach($categories as $category)
                <a href="{{ route('frontend.lobby', array_filter(['category' => $category->slug, 'tag' => $activeTag, 'q' => $search])) }}"
                   class="cat-chip shrink-0" title="{{ $category->title }}"
                   data-active="{{ $activeCategory === $category->slug ? 'true' : 'false' }}">
                    @if($icon = $category->imageUrl())
                        <img src="{{ $icon }}" alt="{{ $category->title }}" class="h-4 w-auto max-w-[110px] object-contain">
                    @else
                        {{ $category->title }}
                    @endif
                    <span class="text-zinc-500">{{ $category->games_count }}</span>
                </a>
            @endforeach
        </div>

        {{-- tag rail --}}
        @if($tags->isNotEmpty())
            <div class="mb-4 flex items-center gap-2 overflow-x-auto pb-2">
                @foreach($tags as $tag)
                    <a href="{{ route('frontend.lobby', array_filter([
                            'category' => $activeCategory,
                            'q' => $search,
                            'tag' => $activeTag === $tag->slug ? null : $tag->slug,
                        ])) }}"
                       class="cat-chip shrink-0" data-active="{{ $activeTag === $tag->slug ? 'true' : 'false' }}">
                        @if($icon = $tag->imageUrl())
                            <img src="{{ $icon }}" alt="" class="h-4 w-4 object-contain">
                        @endif
                        {{ $tag->name }}
                        <span class="text-zinc-500">{{ $tag->games_count }}</span>
                    </a>
                @endforeach
            </div>
        @endif

        {{-- label filters --}}
        <div class="mb-6 flex gap-2">
            @foreach(\App\Enums\GameLabel::cases() as $label)
                <a href="{{ route('frontend.lobby', array_filter([
                        'category' => $activeCategory,
                        'tag' => $activeTag,
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
