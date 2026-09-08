<!DOCTYPE html>
<html lang="en" class="antialiased">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', config('frontend.brand'))</title>
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=cinzel:700|inter:400,500,600,700" rel="stylesheet">
    @vite(['resources/css/frontend.css', 'resources/js/frontend.js'])
</head>
<body class="font-sans">
@php($player = auth()->user())

@unless(request()->routeIs('frontend.login'))
    <header class="sticky top-0 z-40 border-b border-ink-800 bg-ink-950/80 backdrop-blur">
        <div class="mx-auto flex max-w-7xl items-center gap-4 px-4 py-3">
            <a href="{{ route('frontend.lobby') }}" class="wordmark text-lg sm:text-xl">{{ config('frontend.brand') }}</a>

            <form action="{{ route('frontend.lobby') }}" method="GET" class="ml-auto hidden max-w-xs flex-1 sm:block">
                <input type="search" name="q" value="{{ request('q') }}" placeholder="Search games…"
                       data-lobby-search autocomplete="off" class="field py-2">
            </form>

            @if($player)
                <span class="balance-chip" data-balance-poll>
                    {{ \App\Support\Money::format($player->wallet?->balance ?? 0, $player->wallet?->currency) }}
                </span>
                <a href="{{ route('frontend.account') }}" class="btn-ghost hidden sm:inline-flex">{{ $player->username }}</a>
                <form action="{{ route('frontend.logout') }}" method="POST">
                    @csrf
                    <button type="submit" class="btn-ghost">Sign out</button>
                </form>
            @endif
        </div>
    </header>
@endunless

@if(session('status'))
    <div class="mx-auto mt-4 max-w-7xl px-4">
        <div class="rounded-lg border border-win/40 bg-win/10 px-4 py-3 text-sm text-win">{{ session('status') }}</div>
    </div>
@endif

<main>
    @yield('content')
</main>

<footer class="mx-auto max-w-7xl px-4 py-10 text-center text-xs text-zinc-600">
    {{ config('frontend.brand') }} · Play responsibly · 18+
</footer>
</body>
</html>
