@php
    $title = $game->title ?: $game->template->title;
    $poster = $game->template->posterUrl();
    $hue = hexdec(substr(md5($game->template->code), 0, 2)) / 255 * 360;
@endphp

<a href="{{ route('frontend.play', $game->template->code) }}" class="game-card group">
    @if($game->label)
        <span class="ribbon ribbon-{{ $game->label->value }}">{{ $game->label->value }}</span>
    @endif

    <div class="relative aspect-square w-full overflow-hidden">
        {{-- gradient + title fallback, always rendered; the poster (if any) sits on top --}}
        <div class="absolute inset-0 flex items-center justify-center p-3 text-center"
             style="background: linear-gradient(150deg, hsl({{ $hue }} 45% 22%), hsl({{ $hue + 40 }} 40% 10%));">
            <span class="font-display text-sm font-bold tracking-wide text-white/90">{{ $title }}</span>
        </div>
        @if($poster)
            <img src="{{ $poster }}" alt="{{ $title }}" loading="lazy" onerror="this.remove()"
                 class="relative h-full w-full object-cover transition duration-300 group-hover:scale-105">
        @endif

        <div class="absolute inset-0 flex items-center justify-center bg-ink-950/60 opacity-0 transition group-hover:opacity-100">
            <span class="btn-gold">Play</span>
        </div>
    </div>

    <div class="flex items-center justify-between gap-2 px-2.5 py-2">
        <span class="truncate text-xs font-medium text-zinc-200">{{ $title }}</span>
        @if($game->categories->isNotEmpty())
            <span class="shrink-0 text-[10px] uppercase tracking-wide text-zinc-500">{{ $game->categories->first()->title }}</span>
        @endif
    </div>
</a>
