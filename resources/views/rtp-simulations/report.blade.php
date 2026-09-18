<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Test RTP — {{ $result['game_name'] ?? 'Report' }}</title>
    <style>
        :root { color-scheme: dark; }
        * { box-sizing: border-box; }
        body { margin: 0; font: 14px/1.5 system-ui, sans-serif; background: #0b0f0d; color: #e8eae9; padding: 28px 20px 60px; }
        .wrap { max-width: 1100px; margin: 0 auto; }
        h1 { margin: 0 0 2px; font: 700 20px/1.2 "Cinzel", Georgia, serif; letter-spacing: .04em; color: #e7c968; }
        .sub { margin: 0 0 22px; color: #8a938e; font-size: 13px; }
        .cards { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 10px; margin-bottom: 24px; }
        .card { background: #121a16; border: 1px solid #223; border-radius: 12px; padding: 12px 14px; }
        .card span { display: block; color: #8a938e; font-size: 11px; text-transform: uppercase; letter-spacing: .05em; margin-bottom: 4px; }
        .card strong { font-size: 16px; color: #e8eae9; }
        .card.rtp strong { color: #e7c968; }
        .actions { display: flex; gap: 10px; margin-bottom: 20px; }
        a.btn { text-decoration: none; color: #0b0f0d; background: #e7c968; border-radius: 10px; padding: 10px 18px;
                font-weight: 600; font-size: 13px; display: inline-block; }
        a.btn:hover { background: #f0d878; }
        .note { color: #8a938e; font-size: 12px; margin-bottom: 12px; }
        .table-wrap { border: 1px solid #223; border-radius: 12px; overflow: hidden; }
        table { width: 100%; border-collapse: collapse; font-size: 12.5px; }
        thead th { position: sticky; top: 0; background: #121a16; color: #8a938e; text-align: right;
                   padding: 8px 10px; font-weight: 600; border-bottom: 1px solid #223; }
        thead th:first-child, thead th:nth-child(2), thead th:nth-child(3) { text-align: left; }
        tbody td { padding: 6px 10px; text-align: right; border-bottom: 1px solid #161f1a; white-space: nowrap; }
        tbody td:first-child, tbody td:nth-child(2), tbody td:nth-child(3) { text-align: left; }
        tbody tr:hover { background: #0f1613; }
        .win { color: #7bd88f; }
        .scroll { max-height: 70vh; overflow: auto; }
    </style>
</head>
<body>
    <div class="wrap">
        <h1>Test RTP — {{ $result['game_name'] ?? '—' }}</h1>
        <p class="sub">{{ $result['shop_name'] ?? '' }} · {{ number_format($result['spins'] ?? 0) }} spins · isolated test run, no real balances/bank/jackpots touched.</p>

        <div class="cards">
            <div class="card rtp"><span>RTP</span><strong>{{ $result['rtp'] ?? 0 }}%</strong></div>
            <div class="card"><span>Target RTP</span><strong>{{ $result['target_rtp'] ?? 0 }}%</strong></div>
            <div class="card"><span>Total in</span><strong>{{ number_format($result['total_in'] ?? 0, 2) }}</strong></div>
            <div class="card"><span>Total out</span><strong>{{ number_format($result['total_out'] ?? 0, 2) }}</strong></div>
            <div class="card"><span>Net</span><strong>{{ number_format($result['net'] ?? 0, 2) }}</strong></div>
            <div class="card"><span>Game in</span><strong>{{ number_format($result['total_game_in'] ?? 0, 2) }}</strong></div>
            <div class="card"><span>Jackpot in</span><strong>{{ number_format($result['total_jackpot_in'] ?? 0, 2) }}</strong></div>
            <div class="card"><span>Profit</span><strong>{{ number_format($result['total_profit'] ?? 0, 2) }}</strong></div>
            <div class="card"><span>Hit rate</span><strong>{{ $result['hit_rate'] ?? 0 }}%</strong></div>
            <div class="card"><span>Biggest win</span><strong>{{ number_format($result['biggest_win'] ?? 0, 2) }}</strong></div>
        </div>

        <div class="actions">
            <a class="btn" href="{{ route('admin.rtp-simulations.download', $token) }}">Download full CSV</a>
        </div>

        @if ($truncated)
            <p class="note">Showing the first {{ number_format(count($rows)) }} of {{ number_format(count($result['rows'] ?? [])) }} spins — download the CSV for the full run.</p>
        @endif

        <div class="table-wrap">
            <div class="scroll">
                <table>
                    <thead>
                        <tr>
                            <th>Spin</th>
                            <th>Game</th>
                            <th>Shop</th>
                            <th>Bet</th>
                            <th>Win</th>
                            <th>P/L</th>
                            <th>Balance</th>
                            <th>Game in</th>
                            <th>Jackpot in</th>
                            <th>Profit</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($rows as $row)
                            <tr>
                                <td>{{ $row['spin'] }}</td>
                                <td>{{ $result['game_name'] ?? '' }}</td>
                                <td>{{ $result['shop_name'] ?? '' }}</td>
                                <td>{{ number_format($row['bet'], 4) }}</td>
                                <td class="{{ $row['win'] > 0 ? 'win' : '' }}">{{ number_format($row['win'], 4) }}</td>
                                <td>{{ number_format($row['net'], 4) }}</td>
                                <td>{{ number_format($row['balance_after'], 4) }}</td>
                                <td>{{ number_format($row['game_in'], 4) }}</td>
                                <td>{{ number_format($row['jackpot_in'], 4) }}</td>
                                <td>{{ number_format($row['profit'], 4) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</body>
</html>
