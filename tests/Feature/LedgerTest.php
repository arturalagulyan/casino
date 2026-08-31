<?php

namespace Tests\Feature;

use App\Enums\BankType;
use App\Enums\Currency;
use App\Enums\TxnDirection;
use App\Enums\TxnSource;
use App\Models\GameBank;
use App\Models\Jackpot;
use App\Models\Shop;
use App\Models\User;
use App\Services\Ledger;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LedgerTest extends TestCase
{
    use RefreshDatabase;

    private Ledger $ledger;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->ledger = app(Ledger::class);
    }

    private function shop(string $currency = 'EUR'): Shop
    {
        return Shop::create([
            'name' => 'S'.uniqid(), 'slug' => 's'.uniqid(), 'frontend' => 'default',
            'currency' => $currency, 'balance' => 10000,
        ]);
    }

    public function test_cashier_deposit_moves_player_balance_and_drains_shop_float(): void
    {
        $shop = $this->shop();
        $cashier = User::factory()->create(['shop_id' => $shop->id]);
        $cashier->assignRole('cashier');
        $player = User::factory()->create(['shop_id' => $shop->id, 'currency' => 'EUR']);
        $player->assignRole('user');

        $txn = $this->ledger->adjustPlayer($player, 250, TxnDirection::Credit, $cashier);

        $this->assertSame('250.0000', $player->wallet->fresh()->balance);
        $this->assertSame('9750.0000', $shop->fresh()->balance);
        $this->assertSame(TxnSource::Handpay, $txn->source);
        $this->assertSame(Currency::EUR, $txn->currency);
        $this->assertSame('0.0000', $txn->balance_before);
        // legacy w_statistics_add: cashier credit => credit_out + money_in
        $this->assertEquals(['credit_out' => 250.0, 'money_in' => 250.0], $txn->accounting);
    }

    public function test_player_withdrawal_refills_shop_and_checks_funds(): void
    {
        $shop = $this->shop();
        $cashier = User::factory()->create(['shop_id' => $shop->id]);
        $cashier->assignRole('cashier');
        $player = User::factory()->create(['shop_id' => $shop->id]);
        $player->assignRole('user');
        $player->wallet->update(['balance' => 100]);

        $this->ledger->adjustPlayer($player, 60, TxnDirection::Debit, $cashier);
        $this->assertSame('40.0000', $player->wallet->fresh()->balance);
        $this->assertSame('10060.0000', $shop->fresh()->balance);

        $this->expectExceptionMessage("Not enough money in {$player->username}'s balance");
        $this->ledger->adjustPlayer($player, 999, TxnDirection::Debit, $cashier);
    }

    public function test_staff_transfer_debits_the_actor(): void
    {
        $agent = User::factory()->create();
        $agent->assignRole('agent');
        $agent->wallet->update(['balance' => 5000]);
        $distributor = User::factory()->create(['parent_id' => $agent->id]);
        $distributor->assignRole('distributor');

        $this->ledger->adjustStaff($distributor, 1200, TxnDirection::Credit, $agent);

        $this->assertSame('1200.0000', $distributor->wallet->fresh()->balance);
        $this->assertSame('3800.0000', $agent->wallet->fresh()->balance);
    }

    public function test_admin_transfer_does_not_debit_admin(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $agent = User::factory()->create();
        $agent->assignRole('agent');

        $txn = $this->ledger->adjustStaff($agent, 9999, TxnDirection::Credit, $admin);

        $this->assertSame('9999.0000', $agent->wallet->fresh()->balance);
        $this->assertSame('0.0000', $admin->wallet->fresh()->balance);
        $this->assertEquals(['agent_in' => 9999.0], $txn->accounting);
    }

    public function test_bank_pool_adjust_and_underflow_guard(): void
    {
        $shop = $this->shop();
        $bank = GameBank::create(['shop_id' => $shop->id, 'currency' => 'EUR', 'slots' => 500]);
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $this->ledger->adjustBankPool($bank, BankType::Slots, 300, TxnDirection::Debit, $admin);
        $this->assertSame('200.0000', $bank->fresh()->slots);

        $this->expectExceptionMessage('Not enough in the slots pool');
        $this->ledger->adjustBankPool($bank, BankType::Slots, 9999, TxnDirection::Debit, $admin);
    }

    public function test_jackpot_payout_credits_winner_and_resets_pool(): void
    {
        $shop = $this->shop();
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $player = User::factory()->create(['shop_id' => $shop->id]);
        $player->assignRole('user');

        $jackpot = Jackpot::create([
            'shop_id' => $shop->id, 'name' => 'Mega', 'balance' => 1500,
            'contribution_percent' => 1, 'is_active' => true,
        ]);

        $txn = $this->ledger->payoutJackpot($jackpot, $player, $admin);

        $this->assertSame('1500.0000', $player->wallet->fresh()->balance);
        $this->assertSame('0.000000', $jackpot->fresh()->balance);
        $this->assertSame($player->id, $jackpot->fresh()->last_winner_id);
        $this->assertDatabaseHas('jackpot_wins', ['jackpot_id' => $jackpot->id, 'user_id' => $player->id]);
        $this->assertSame(TxnSource::Jackpot, $txn->source);
    }
}
