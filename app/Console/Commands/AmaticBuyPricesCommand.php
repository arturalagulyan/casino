<?php

namespace App\Console\Commands;

use App\Models\GameBundle;
use App\Services\GamePlay\Amatic\AmaticClientMath;
use Illuminate\Console\Command;

/**
 * Fills in `bonus_config.client.buy` / `buy_cap` for Amatic slots imported
 * before `amatic:fetch` read them: the bonus-buy prices sit in the captured
 * init tail, and the game's engine script (in its active bundle) says where
 * ({@see AmaticClientMath::buyOptions()}). Nothing is fetched from Amatic.
 */
class AmaticBuyPricesCommand extends Command
{
    protected $signature = 'amatic:buy-prices
        {--only= : Comma list of template codes to limit to}
        {--dry-run : Report what would change, write nothing}';

    protected $description = 'Read the bonus-buy prices of imported Amatic slots from their bundles';

    public function handle(AmaticClientMath $math): int
    {
        $only = array_filter(array_map('trim', explode(',', (string) $this->option('only'))));
        $query = GameBundle::query()->where('is_active', true)->with('template')
            ->whereHas('template', fn ($q) => $q->whereNotNull('bonus_config->client->init_tail')
                ->when($only !== [], fn ($q) => $q->whereIn('code', $only)));

        $found = 0;
        foreach ($query->orderBy('id')->cursor() as $bundle) {
            $template = $bundle->template;
            $config = (array) $template->bonus_config;
            $buy = $math->bundleBuyOptions($bundle, (string) $config['client']['init_tail']);
            if ($buy === null) {
                continue;
            }
            $found++;
            $this->line(sprintf('  %s: buy %s× bet%s', $template->code, implode('/', $buy['prices']),
                $buy['cap'] > 0 ? sprintf(', cost cap %.2f', $buy['cap'] / 100) : ''));
            if (! $this->option('dry-run')) {
                $config['client']['buy'] = $buy['prices'];
                $config['client']['buy_cap'] = $buy['cap'];
                $template->forceFill(['bonus_config' => $config])->save();
            }
        }
        $this->info(($this->option('dry-run') ? 'Would update' : 'Updated')." {$found} template(s) with a bonus buy");

        return self::SUCCESS;
    }
}
