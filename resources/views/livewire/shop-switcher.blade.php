<div>
    @if ($shops->isNotEmpty())
        <x-filament::dropdown placement="bottom-start">
            <x-slot name="trigger">
                <button type="button" class="fi-topbar-item-btn">
                    <x-filament::icon
                        icon="heroicon-o-building-storefront"
                        class="fi-icon h-5 w-5"
                    />

                    <span class="fi-topbar-item-label">
                        {{ $currentShop?->name ?? 'All shops' }}
                    </span>

                    <x-filament::icon
                        icon="heroicon-m-chevron-down"
                        class="fi-icon h-4 w-4"
                    />
                </button>
            </x-slot>

            <x-filament::dropdown.list>
                <x-filament::dropdown.list.item
                    icon="heroicon-o-squares-2x2"
                    :color="$currentShop ? 'gray' : 'primary'"
                    wire:click="selectShop(null)"
                >
                    All shops
                </x-filament::dropdown.list.item>

                @foreach ($shops as $shop)
                    <x-filament::dropdown.list.item
                        icon="heroicon-o-building-storefront"
                        :color="$currentShop?->id === $shop->id ? 'primary' : 'gray'"
                        wire:click="selectShop({{ $shop->id }})"
                    >
                        {{ $shop->name }}
                    </x-filament::dropdown.list.item>
                @endforeach
            </x-filament::dropdown.list>
        </x-filament::dropdown>
    @endif
</div>
