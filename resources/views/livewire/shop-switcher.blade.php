<div>
    @if ($shops->isNotEmpty())
        {{--
            Hand-rolled dropdown instead of <x-filament::dropdown>, for issues
            found testing this on an actual iPhone:

            1. Filament's dropdown opens on `mousedown` but only registers its
               "click outside" listener (on `window`, for `click`) at that same
               moment — so on iOS Safari the synthetic `click` that follows the
               same tap is immediately caught by the listener it just
               registered, closing the panel it just opened. The manual
               outside-check below doesn't have this race (it's bound once, at
               page load, not re-armed per open).

            2. The topbar (`.fi-topbar-ctn`) is `position: sticky` with its own
               `z-index: 30` — which makes it a stacking context of its own.
               A dropdown panel left nested inside it can only ever out-stack
               *siblings within the topbar*; against the page's main content
               (outside that stacking context entirely) it's the *topbar's*
               z-index that counts, not the panel's, so on some layouts the
               panel paints under the page rather than over it. Filament's own
               topbar dropdowns dodge this with a `teleport` option — but that
               turns out to only switch their positioning engine to `fixed`
               coordinates, it doesn't actually relocate the DOM node (verified
               by inspecting Filament's own user-menu dropdown here: it's still
               nested exactly where it's declared). So instead this genuinely
               moves the panel to be a direct child of <body> on init — the
               only way to actually leave the topbar's stacking context — and
               positions it with plain fixed coordinates read off the trigger's
               on-screen position each time it opens. The move itself uses
               `x-init="…($el)"` on the panel directly rather than on the
               wrapper referencing `$refs.panel` — the wrapper's own `x-init`
               runs before Alpine has walked far enough to register a not-yet-
               visited child's `x-ref`, so `$refs.panel` needed a `$nextTick`
               there, and that extra tick was enough to make the very first
               click after page load a no-op (this element hadn't relocated
               yet when that click's handlers were wired up).

            3. `wire:click` on an item stopped reaching the component once its
               panel moved outside the component's own root div — Livewire
               resolves which component a `wire:click` targets by walking up
               the DOM from the clicked element to the nearest `wire:id`
               ancestor, and that ancestor no longer exists once the panel is
               a sibling of <body> rather than a descendant of this
               component's root. `$wire.selectShop(...)` (Alpine's bridge to
               this component instance, bound once via JS closure at init —
               not re-resolved from the clicked element's current DOM
               position) calls the same method without that dependency.
        --}}
        <div
            x-data="{
                open: false,
                position() {
                    let rect = this.$refs.trigger.getBoundingClientRect();
                    this.$refs.panel.style.top = Math.round(rect.bottom + 8) + 'px';
                    this.$refs.panel.style.left = Math.round(rect.left) + 'px';
                },
            }"
            x-on:click.window="if (open && ! $refs.trigger.contains($event.target) && ! $refs.panel.contains($event.target)) open = false"
            x-on:keydown.escape.window="open = false"
            x-on:resize.window.debounce="open && position()"
            class="fi-dropdown"
        >
            <div class="fi-dropdown-trigger">
                <button
                    x-ref="trigger"
                    type="button"
                    class="fi-topbar-item-btn"
                    x-on:click="open = ! open; if (open) $nextTick(() => position())"
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
                x-ref="panel"
                x-init="document.body.appendChild($el)"
                x-show="open"
                x-cloak
                x-transition
                class="fi-dropdown-panel"
                style="position: fixed; z-index: 40;"
            >
                <x-filament::dropdown.list>
                    <x-filament::dropdown.list.item
                        icon="heroicon-o-squares-2x2"
                        :color="$currentShop ? 'gray' : 'primary'"
                        x-on:click="open = false; $wire.selectShop(null)"
                    >
                        All shops
                    </x-filament::dropdown.list.item>

                    @foreach ($shops as $shop)
                        <x-filament::dropdown.list.item
                            icon="heroicon-o-building-storefront"
                            :color="$currentShop?->id === $shop->id ? 'primary' : 'gray'"
                            x-on:click="open = false; $wire.selectShop({{ $shop->id }})"
                        >
                            {{ $shop->name }}
                        </x-filament::dropdown.list.item>
                    @endforeach
                </x-filament::dropdown.list>
            </div>
        </div>
    @endif
</div>
