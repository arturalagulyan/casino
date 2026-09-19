<div>
    @if ($shops->isNotEmpty())
        {{--
            Hand-rolled dropdown instead of <x-filament::dropdown>: that component
            opens on `mousedown` but only registers its "click outside" listener
            (on `window`, for `click`) at that same moment — so on iOS Safari the
            synthetic `click` that follows the same tap is immediately caught by
            the listener it just registered, closing the panel it just opened.
            Alpine's own `@click.outside` doesn't have this race (it's bound once,
            at page load, not re-armed per open), which is why it doesn't exhibit
            the bug — this is the standard Alpine dropdown recipe.
        --}}
        <div
            x-data="{ open: false }"
            x-on:click.outside="open = false"
            x-on:keydown.escape.window="open = false"
            class="fi-dropdown relative"
        >
            <div class="fi-dropdown-trigger">
                <button
                    type="button"
                    class="fi-topbar-item-btn"
                    x-on:click="open = ! open"
                    x-bind:aria-expanded="open"
                    aria-haspopup="true"
                >
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
            </div>

            <div
                x-show="open"
                x-cloak
                x-transition
                class="fi-dropdown-panel top-full start-0 mt-2"
            >
                <x-filament::dropdown.list>
                    <x-filament::dropdown.list.item
                        icon="heroicon-o-squares-2x2"
                        :color="$currentShop ? 'gray' : 'primary'"
                        wire:click="selectShop(null)"
                        x-on:click="open = false"
                    >
                        All shops
                    </x-filament::dropdown.list.item>

                    @foreach ($shops as $shop)
                        <x-filament::dropdown.list.item
                            icon="heroicon-o-building-storefront"
                            :color="$currentShop?->id === $shop->id ? 'primary' : 'gray'"
                            wire:click="selectShop({{ $shop->id }})"
                            x-on:click="open = false"
                        >
                            {{ $shop->name }}
                        </x-filament::dropdown.list.item>
                    @endforeach
                </x-filament::dropdown.list>
            </div>
        </div>
    @endif
</div>
