<?php

namespace App\Services\GamePlay\Protocol;

use App\Services\GamePlay\GameContext;
use App\Services\GamePlay\SpinResult;
use App\Services\Legacy\EgtGameParser;

/**
 * Builds the exact `3:::{…}` frames the legacy Pragmatic Play front-end (a
 * compiled GWT "platform" + "bib" pair) expects — ported byte-for-byte from
 * the legacy per-game `Server.php` `switch ($umid)` housekeeping block, which
 * is identical across every Pragmatic title (only balance/currency/bet values
 * are dynamic; verified by diffing GreatBluePT vs BuffaloBlitzPT). Each frame
 * is a fixed legacy RPC opcode (`ID`) the client's GWT deserializer expects
 * verbatim — this is not a designed API, it's a faithful transcript.
 *
 * A spin reply carries no win/line breakdown at all — only the post-spin
 * balance and the raw reel-strip stop position per reel (`results`). The
 * untouched compiled client re-derives the winning lines/animation itself
 * from those positions against its own baked-in paytable + reel strips,
 * which must stay byte-identical to ours (imported from the same
 * `reels.txt` / `SlotSettings.php` — see {@see EgtGameParser}).
 */
class PragmaticFormatter
{
    /** Whole-cents balance, legacy `sprintf('%01.2f', credits) * 100`. */
    public function balanceInCents(GameContext $ctx): int
    {
        $denom = $ctx->config()->denomination();
        $credits = $denom > 0 ? $ctx->balance() / $denom : $ctx->balance();

        return (int) round($credits * 100);
    }

    /**
     * The generic login/housekeeping handshake, keyed by the legacy `ID`
     * opcode. Returns `null` for an opcode this port doesn't recognise (the
     * caller just sends nothing back for it, same as legacy's empty
     * `switch` fallthrough).
     *
     * @return list<string>|null
     */
    public function housekeeping(GameContext $ctx, int $id): ?array
    {
        $cur = $ctx->currency->value;
        $bal = $this->balanceInCents($ctx);

        return match ($id) {
            31031 => [
                '3:::{"data":{"urlList":[{"urlType":"mobile_login","url":"","priority":1},{"urlType":"mobile_support","url":"","priority":1},{"urlType":"playerprofile","url":"","priority":1},{"urlType":"playerprofile","url":"","priority":10},{"urlType":"gambling_commission","url":"","priority":1},{"urlType":"cashier","url":"","priority":1},{"urlType":"cashier","url":"","priority":1}]},"ID":100}',
            ],
            10001 => [
                '3:::{"data":{"typeBalance":2,"balanceInCents":0},"ID":40083,"umid":3}',
                '3:::{"data":{"typeBalance":0,"currency":"'.$cur.'","balanceInCents":'.$bal.',"deltaBalanceInCents":0},"ID":40083,"umid":4}',
                '3:::{"data":{"commandId":13218,"params":["0","null"]},"ID":50001,"umid":5}',
                '3:::{"token":{"secretKey":"","currency":"'.$cur.'","balance":0,"loginTime":""},"ID":10002,"umid":7}',
            ],
            40294 => [
                '3:::{"nicknameInfo":{"nickname":""},"ID":10022,"umid":8}',
                '3:::{"data":{"commandId":10713,"params":["0","ba","bj","ct","gc","grel","hb","po","ro","sc","tr"]},"ID":50001,"umid":9}',
                '3:::{"data":{"commandId":11666,"params":["0","0","0"]},"ID":50001,"umid":11}',
                '3:::{"data":{"commandId":13981,"params":["0","1"]},"ID":50001,"umid":12}',
                '3:::{"data":{"commandId":14080,"params":["0","0"]},"ID":50001,"umid":14}',
                '3:::{"data":{"keyValueCount":5,"elementsPerKey":1,"params":["10","1","11","500","12","1","13","0","14","0"]},"ID":40716,"umid":15}',
                '3:::{"data":{"typeBalance":0,"currency":"'.$cur.'","balanceInCents":'.$bal.',"deltaBalanceInCents":0},"ID":40083,"umid":16}',
                '3:::{"balanceInfo":{"clientType":"casino","totalBalance":'.$bal.',"currency":"'.$cur.'","balanceChange":'.$bal.'},"ID":10006,"umid":17}',
                '3:::{"data":{},"ID":40292,"umid":18}',
            ],
            10010 => [
                '3:::{"data":{"urls":{"cashier":[{"url":"","priority":1},{"url":"","priority":1}],"gambling_commission":[{"url":"","priority":1},{"url":"","priority":1}],"playerprofile":[{"url":"","priority":1},{"url":"","priority":10}]}},"ID":10011,"umid":19}',
                '3:::{"data":{"brokenGames":[],"windowId":"SuJLru"},"ID":40037,"umid":20}',
            ],
            40024 => [
                '3:::{"data":{"funNoticeGames":0,"funNoticePayouts":0,"gameGroup":"pmn","minBet":0,"maxBet":0,"minPosBet":0,"maxPosBet":50000,"coinSizes":['
                    .implode(',', array_map(fn ($b) => (int) round($b * 100), $ctx->betOptions())).']},"ID":40025,"umid":21}',
            ],
            40036 => [
                '3:::{"data":{"brokenGames":[""],"windowId":"SuJLru"},"ID":40037,"umid":22}',
            ],
            40020, 40030 => [
                '3:::{"data":{"typeBalance":2,"balanceInCents":0},"ID":40085}',
                '3:::{"data":{"typeBalance":1,"balanceInCents":0},"ID":40085}',
                '3:::{"data":{"typeBalance":0,"currency":"'.$cur.'","balanceInCents":'.$bal.',"deltaBalanceInCents":0},"ID":40085}',
                '3:::{"data":{"credit":'.$bal.',"windowId":"SuJLru"},"ID":40026,"umid":28}',
            ],
            48300 => [
                '3:::{"balanceInfo":{"clientType":"casino","totalBalance":'.$bal.',"currency":"'.$cur.'","balanceChange":0},"ID":10006,"umid":30}',
                '3:::{"data":{"waitingLogins":[],"waitingAlerts":[],"waitingDialogs":[],"waitingDialogMessages":[],"waitingToasterMessages":[]},"ID":48301,"umid":31}',
            ],
            default => null,
        };
    }

    /** The generic ack legacy sends when the client posts `{"ID":n}` alone (no `umid`). */
    public function genericAck(GameContext $ctx): array
    {
        return [
            '3:::{"ID":18}',
            '3:::{"data":{"typeBalance":0,"currency":"'.$ctx->currency->value.'","balanceInCents":'.$this->balanceInCents($ctx).',"deltaBalanceInCents":1},"ID":40085}',
        ];
    }

    /**
     * The spin reply: final post-bet-and-win balance + one raw strip position
     * per reel. `results[i]` must equal legacy's `$value` — the MIDDLE row of
     * the 3-row window the client reconstructs as `[strip[value-1],
     * strip[value], strip[value+1]]` — whereas our engine's offset `at` is the
     * TOP row (`strip[at], strip[at+1], strip[at+2]`). Row 1 matches when
     * `value = at + 1`.
     *
     * @param  array<int,int>  $offsets  reel => SlotEngine's chosen top-row strip index
     */
    public function spin(GameContext $ctx, SpinResult $result, array $offsets): array
    {
        $cfg = $ctx->config();
        $positions = [];
        foreach ($cfg->reelStrips(false) as $reel => $strip) {
            $n = max(1, count($strip));
            $at = $offsets[$reel] ?? 0;
            $positions[] = ($at + 1) % $n;
        }

        $bal = $this->balanceInCents($ctx);

        return [
            '3:::{"data":{"credit":'.$bal.',"results":['.implode(',', $positions).'],"windowId":"Adbmao"},"ID":40022,"umid":35}',
            '3:::{"data":{"typeBalance":0,"currency":"'.$ctx->currency->value.'","balanceInCents":'.$bal.',"deltaBalanceInCents":1},"ID":40085}',
        ];
    }
}
