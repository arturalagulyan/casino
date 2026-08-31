<x-filament-panels::page>
    {{ $this->table }}

    @if (method_exists($this, 'totals') && ($totals = $this->totals()))
        <x-filament::section heading="Totals">
            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($totals as $currency => $t)
                    <div @class([
                        'rounded-xl p-4 ring-1',
                        'bg-success-50 ring-success-600/20 dark:bg-success-500/10' => $t['net'] >= 0,
                        'bg-danger-50 ring-danger-600/20 dark:bg-danger-500/10' => $t['net'] < 0,
                    ])>
                        <div class="text-sm font-medium text-gray-500 dark:text-gray-400">{{ $currency }}</div>
                        <div class="mt-1 text-xl font-bold">{{ $this->fmt($t['net'], $currency) }}</div>
                        <div class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                            in {{ $this->fmt($t['in'], $currency) }} · out {{ $this->fmt($t['out'], $currency) }}
                        </div>
                    </div>
                @endforeach
            </div>
        </x-filament::section>
    @endif
</x-filament-panels::page>
