<?php

namespace App\Console\Commands;

use App\Enums\Currency;
use App\Enums\ShopStatus;
use App\Models\ApiKey;
use App\Models\Game;
use App\Models\GameBank;
use App\Models\Shop;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Provisions the first-party player frontend (see config/frontend.php):
 *
 *   1. the "house" shop
 *   2. a game bank to fund wins
 *   3. an API key (the frontend is just another API client)
 *   4. a clone of a live shop's visible game catalogue, categories included
 *
 * Idempotent — safe to re-run after importing more games.
 */
class FrontendSetupCommand extends Command
{
    protected $signature = 'frontend:setup
        {--from= : Source shop (slug or id) to clone games from; defaults to the shop with the most visible games}
        {--currency=EUR : House shop base currency}
        {--bank=0 : Starting slots-pool balance for the house game bank}
        {--fresh : Delete existing house games before cloning}';

    protected $description = 'Create the player-frontend house shop, API key, game bank and game catalogue';

    /** Game columns worth copying from the source shop's per-game tuning. */
    private const COPY = [
        'bank_type', 'label', 'rtp_percent', 'max_win_multiplier', 'wild_multiplier',
        'free_spins_count', 'free_spins_table', 'win_distribution', 'reserve_percent', 'cask',
        'lines_config_spin', 'lines_config_spin_bonus', 'lines_config_bonus', 'lines_config_bonus_bonus',
        'win_chances', 'jackpot_chances', 'advanced', 'bet_options', 'denomination',
        'scale_mode', 'view_state', 'is_visible', 'sort_order',
    ];

    public function handle(): int
    {
        $currency = Currency::tryFrom((string) $this->option('currency')) ?? Currency::EUR;

        $source = $this->sourceShop();
        if (! $source) {
            $this->error('No source shop with games found — import a catalogue first, or pass --from.');

            return self::FAILURE;
        }

        $house = Shop::updateOrCreate(
            ['slug' => config('frontend.shop_slug')],
            [
                'name' => config('frontend.shop_name'),
                'frontend' => 'default',
                'currency' => $currency,
                'status' => ShopStatus::Active,
                'rtp_percent' => $source->rtp_percent ?: 90,
                'max_win_multiplier' => $source->max_win_multiplier ?: 1000,
            ],
        );
        $this->info("House shop: {$house->name} (#{$house->id}, {$currency->value})");

        $bank = GameBank::firstOrCreate(['shop_id' => $house->id, 'currency' => $currency->value]);
        if (($seed = (float) $this->option('bank')) > 0) {
            $bank->increment('slots', $seed);
            $this->info("Funded slots pool +{$seed} (now {$bank->fresh()->slots}).");
        }

        $key = ApiKey::firstOrCreate(
            ['shop_id' => $house->id, 'name' => 'House Frontend'],
            ['key' => Str::random(48), 'is_active' => true],
        );
        $this->info("API key: {$key->key}");

        if ($this->option('fresh')) {
            $deleted = Game::where('shop_id', $house->id)->delete();
            $this->warn("Deleted {$deleted} existing house games.");
        }

        $this->cloneGames($source, $house, $currency);

        $this->newLine();
        $this->info(sprintf(
            'Done. %d games, %d visible, in %s.',
            Game::where('shop_id', $house->id)->count(),
            Game::where('shop_id', $house->id)->where('is_visible', true)->count(),
            $house->name,
        ));

        return self::SUCCESS;
    }

    private function sourceShop(): ?Shop
    {
        $from = $this->option('from');

        if ($from) {
            return Shop::where('slug', $from)->orWhere('id', $from)->first();
        }

        return Shop::query()
            ->where('slug', '!=', config('frontend.shop_slug'))
            ->withCount('games')
            ->orderByDesc('games_count')
            ->first();
    }

    private function cloneGames(Shop $source, Shop $house, Currency $currency): void
    {
        $total = Game::where('shop_id', $source->id)->count();
        $this->info("Cloning {$total} games from {$source->name}…");
        $bar = $this->output->createProgressBar($total);

        Game::where('shop_id', $source->id)
            ->with('categories:id')
            ->chunkById(200, function ($games) use ($house, $currency, $bar): void {
                foreach ($games as $src) {
                    $data = $src->only(self::COPY);
                    $data['pricing_currency'] = $currency;
                    $data['jackpot_id'] = null;

                    $game = Game::updateOrCreate(
                        ['shop_id' => $house->id, 'template_id' => $src->template_id],
                        $data,
                    );
                    $game->categories()->sync($src->categories->pluck('id'));
                    $bar->advance();
                }
            });

        $bar->finish();
        $this->newLine();
    }
}
