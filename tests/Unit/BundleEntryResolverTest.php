<?php

namespace Tests\Unit;

use App\Services\GamePlay\BundleEntryResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class BundleEntryResolverTest extends TestCase
{
    private BundleEntryResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resolver = new BundleEntryResolver;
    }

    /**
     * @param  list<string>  $files
     */
    #[DataProvider('familyCases')]
    public function test_picks_the_real_entry_per_provider_family(string $code, array $files, ?string $expected): void
    {
        $this->assertSame($expected, $this->resolver->resolve($files, $code));
    }

    /** @return iterable<string, array{0:string,1:list<string>,2:?string}> */
    public static function familyCases(): iterable
    {
        yield 'EGT root index' => ['ActionMoneyEGT', ['index.html', 'html5/game.js'], 'index.html'];

        yield 'NetGame skips browserChecker stub' => ['AfricanKingNG', [
            'tpl/browserChecker/browserCheckerIOS.html',
            'tpl/startScreen/startScreen.html',
            'app/africanKing.1/index.html',
        ], 'app/africanKing.1/index.html'];

        yield 'Wazdan skips help.html' => ['HighwayToHellWD', [
            'help.html',
            'wazdan40-176-3/index.html',
        ], 'wazdan40-176-3/index.html'];

        yield 'Playtech skips the GWT platform loader' => ['ThaiParadisePT', [
            'platform/index.html',
            'platform/platform/ABC123.cache.html',
            'tpd2/index.html',
        ], 'tpd2/index.html'];

        yield 'Amatic picks amarent/index over a sub-game' => ['JacksOrBetterAM', [
            'amarent/jacksorbetter.html',
            'amarent/index.html',
        ], 'amarent/index.html'];

        yield 'gs2c nested entry' => ['BigBassBonanza', [
            'gs2c/html5Game.html',
            'gs2c/v3/gameService.html',
            'gs2c/announcements/unread/index.html',
        ], 'gs2c/html5Game.html'];

        yield 'NetEnt xhtml entry, ignores rules template' => ['StarBurstNET', [
            'games/starburst_mobile_html/gamerules/templates/starburst_not_mobile.en.html',
            'games/starburst_mobile_html/game/starburst_mobile_html.xhtml',
        ], 'games/starburst_mobile_html/game/starburst_mobile_html.xhtml'];

        yield 'CT picks the folder matching the game name' => ['CombatRomanceCT', [
            'latest-stable/40MegaSlot/app.html',
            'latest-stable/CombatRomance/app.html',
        ], 'latest-stable/CombatRomance/app.html'];

        yield 'all-decoy bundle → no entry' => ['BurningHot6Reels40EGT', [
            'html5/BaseSlotEngine/games/VWJSlot/paytable/paytable_en.html',
            'html5/device.min.js',
        ], null];

        yield 'no html at all → no entry' => ['AfricaGT', ['js/loader.js', 'config/engine.json'], null];
    }

    public function test_explicit_entry_wins_when_present(): void
    {
        $this->assertSame(
            'a/b/thing.html',
            $this->resolver->resolve(['a/b/thing.html', 'index.html'], 'Whatever', 'a/b/thing.html'),
        );
    }

    public function test_explicit_entry_falls_back_when_missing(): void
    {
        $this->assertSame('index.html', $this->resolver->resolve(['index.html'], 'X', 'missing/file.html'));
    }

    #[DataProvider('prettyCases')]
    public function test_pretty_name(string $code, string $expected): void
    {
        $this->assertSame($expected, $this->resolver->prettyName($code));
    }

    /** @return iterable<int, array{0:string,1:string}> */
    public static function prettyCases(): iterable
    {
        yield ['ActionMoneyEGT', 'Action Money'];
        yield ['AgeOfEgyptPT', 'Age Of Egypt'];
        yield ['AgeOfEgyptPTM', 'Age Of Egypt'];
        yield ['Royal20FruitsNG', 'Royal 20 Fruits'];
        yield ['20SuperHotEGT', '20 Super Hot'];
        yield ['AncientEgyptClassic', 'Ancient Egypt Classic'];
        yield ['PandasFortune2M', 'Pandas Fortune 2'];
    }

    #[DataProvider('suffixCases')]
    public function test_suffix_of(string $code, ?string $expected): void
    {
        $this->assertSame($expected, $this->resolver->suffixOf($code));
    }

    /** @return iterable<int, array{0:string,1:?string}> */
    public static function suffixCases(): iterable
    {
        yield ['ActionMoneyEGT', 'EGT'];
        yield ['AgeOfPrivateersGTM', 'GTM'];
        yield ['AdmiralNelsonAM', 'AM'];
        yield ['AncientEgypt', null];
        yield ['BookOfRa', null];
    }
}
