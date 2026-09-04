<?php

namespace App\Livewire;

use App\Support\CurrentShop;
use Livewire\Component;

/**
 * Topbar control letting an admin pick "the shop they're working in" —
 * see App\Support\CurrentShop and App\Filament\Concerns\ScopesToViewer.
 */
class ShopSwitcher extends Component
{
    public ?int $shopId = null;

    public function mount(): void
    {
        $this->shopId = CurrentShop::id();
    }

    public function selectShop(?int $shopId): void
    {
        $this->shopId = $shopId;
        CurrentShop::set($shopId);

        $this->redirect(url()->previous(), navigate: false);
    }

    public function render()
    {
        $shops = CurrentShop::options();

        return view('livewire.shop-switcher', [
            'shops' => $shops,
            'currentShop' => $this->shopId ? $shops->firstWhere('id', $this->shopId) : null,
        ]);
    }
}
