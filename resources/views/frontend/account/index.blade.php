@extends('frontend.layout')

@section('title', 'Account · '.config('frontend.brand'))

@section('content')
    <div class="mx-auto max-w-3xl px-4 py-8">
        <h1 class="font-display text-2xl font-bold tracking-wide text-gold-300">{{ $user->username }}</h1>
        <p class="mt-1 text-sm text-zinc-500">{{ $user->email }}</p>

        <div class="mt-6 grid gap-4 sm:grid-cols-2">
            <div class="rounded-xl border border-gold-500/30 bg-ink-850/70 p-5">
                <div class="text-xs uppercase tracking-wide text-zinc-400">Balance</div>
                <div class="mt-1 text-2xl font-bold tabular-nums text-gold-300" data-balance-poll>
                    {{ \App\Support\Money::format($user->wallet?->balance ?? 0, $user->wallet?->currency) }}
                </div>
            </div>
            <div class="rounded-xl border border-ink-700 bg-ink-850/70 p-5">
                <div class="text-xs uppercase tracking-wide text-zinc-400">Currency</div>
                <div class="mt-1 text-2xl font-bold text-zinc-200">{{ $user->wallet?->currency?->value ?? '—' }}</div>
            </div>
        </div>

        <h2 class="mt-10 mb-3 text-sm font-semibold uppercase tracking-wide text-zinc-400">Recent rounds</h2>
        <div class="overflow-hidden rounded-xl border border-ink-700">
            <table class="w-full text-sm">
                <thead class="bg-ink-850 text-xs uppercase tracking-wide text-zinc-500">
                    <tr>
                        <th class="px-4 py-2.5 text-left font-medium">Game</th>
                        <th class="px-4 py-2.5 text-right font-medium">Bet</th>
                        <th class="px-4 py-2.5 text-right font-medium">Win</th>
                        <th class="px-4 py-2.5 text-right font-medium">Balance</th>
                        <th class="px-4 py-2.5 text-right font-medium">When</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-ink-800">
                    @forelse($rounds as $r)
                        <tr class="bg-ink-900/40">
                            <td class="px-4 py-2.5 text-zinc-300">{{ $r->game_code }}</td>
                            <td class="px-4 py-2.5 text-right tabular-nums text-zinc-400">{{ \App\Support\Money::format($r->bet, $r->currency) }}</td>
                            <td class="px-4 py-2.5 text-right tabular-nums {{ (float) $r->win > 0 ? 'text-win' : 'text-zinc-500' }}">{{ \App\Support\Money::format($r->win, $r->currency) }}</td>
                            <td class="px-4 py-2.5 text-right tabular-nums text-zinc-300">{{ \App\Support\Money::format($r->balance_after, $r->currency) }}</td>
                            <td class="px-4 py-2.5 text-right text-zinc-500">{{ $r->played_at?->diffForHumans() }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="px-4 py-10 text-center text-zinc-500">No rounds played yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
@endsection
