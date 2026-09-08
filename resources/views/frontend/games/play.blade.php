<!DOCTYPE html>
<html lang="en" class="antialiased">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title }} · {{ config('frontend.brand') }}</title>
    @vite(['resources/css/frontend.css', 'resources/js/frontend.js'])
</head>
<body class="font-sans">
@php($player = auth()->user())

<div data-fullscreen-target class="flex h-screen flex-col bg-ink-950">
    <header class="flex items-center gap-3 border-b border-ink-800 bg-ink-950/90 px-3 py-2">
        <a href="{{ route('frontend.lobby') }}" class="btn-ghost">← Lobby</a>
        <span class="truncate font-display text-sm font-bold tracking-wide text-gold-300">{{ $title }}</span>
        <span class="balance-chip ml-auto" data-balance-poll>
            {{ \App\Support\Money::format($player->wallet?->balance ?? 0, $player->wallet?->currency) }}
        </span>
        <button type="button" data-fullscreen class="btn-ghost" title="Toggle fullscreen">⤢</button>
    </header>

    <iframe src="{{ $src }}" title="{{ $title }}"
            class="min-h-0 w-full flex-1 border-0 bg-black"
            allow="autoplay; fullscreen; clipboard-write"></iframe>
</div>
</body>
</html>
