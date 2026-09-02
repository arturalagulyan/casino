<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title }}</title>
    <style>
        :root { color-scheme: dark; }
        * { box-sizing: border-box; }
        body { margin: 0; font: 15px/1.5 system-ui, sans-serif; background: #0b0f0d; color: #e8eae9;
               display: flex; min-height: 100vh; align-items: center; justify-content: center; }
        .cab { width: min(560px, 94vw); background: #121a16; border: 1px solid #223; border-radius: 20px;
               padding: 28px; box-shadow: 0 30px 80px -20px #000; }
        h1 { margin: 0 0 4px; font: 700 20px/1.2 "Cinzel", Georgia, serif; letter-spacing: .06em; color: #e7c968; }
        .sub { margin: 0 0 20px; color: #8a938e; font-size: 13px; }
        .grid { display: grid; grid-template-columns: repeat(5, 1fr); gap: 8px; margin: 8px 0 18px; }
        .cell { aspect-ratio: 1; display: grid; place-items: center; font-size: 30px; font-weight: 700;
                background: #0d1512; border: 1px solid #1e2b25; border-radius: 12px; transition: .15s; }
        .cell.hit { background: #143024; border-color: #2ecc71; color: #7bed9f; }
        .row { display: flex; justify-content: space-between; align-items: center; gap: 12px; margin: 10px 0; }
        .stat b { display: block; font-size: 20px; }
        .stat span { color: #8a938e; font-size: 12px; text-transform: uppercase; letter-spacing: .08em; }
        .win { color: #7bed9f; }
        button { font: 600 15px system-ui; padding: 12px 26px; border: 0; border-radius: 999px; cursor: pointer;
                 background: #d4af37; color: #1a1305; }
        button:disabled { opacity: .5; cursor: default; }
        select { background: #0d1512; color: #e8eae9; border: 1px solid #1e2b25; border-radius: 8px; padding: 8px; }
        .msg { min-height: 20px; font-size: 13px; color: #d88; }
    </style>
</head>
<body>
    <div class="cab">
        <h1>{{ $title }}</h1>
        <p class="sub">Reference demo shell — the universal ClassicSlot engine. Upload a real front-end bundle to replace this.</p>

        <div class="grid" id="grid"></div>

        <div class="row">
            <div class="stat"><b id="balance">—</b><span id="ccy">balance</span></div>
            <div class="stat" style="text-align:right"><b class="win" id="win">0</b><span>last win</span></div>
        </div>

        <div class="row">
            <label>Bet
                <select id="bet"></select>
            </label>
            <button id="spin" disabled>Spin</button>
        </div>
        <div class="msg" id="msg"></div>
    </div>

    <script>window.CasinoGame = { endpoint: @json($endpoint, JSON_UNESCAPED_SLASHES), session: @json($session) };</script>
    <script>
        const CFG = window.CasinoGame;
        const $ = (id) => document.getElementById(id);
        let lines = [], paylines = [], denom = 1;

        async function call(command, extra = {}) {
            const res = await fetch(CFG.endpoint, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
                body: JSON.stringify({ session: CFG.session, command, ...extra }),
            });
            const data = await res.json();
            if (!res.ok) throw new Error(data.error || 'error');
            return data;
        }

        function drawGrid(reels, hits) {
            const g = $('grid');
            g.innerHTML = '';
            const hitCells = new Set();
            (hits || []).forEach(h => {
                for (let r = 0; r < h.count; r++) hitCells.add(r + ',' + paylines[h.line][r]);
            });
            for (let row = 0; row < 3; row++) {
                for (let reel = 0; reel < 5; reel++) {
                    const cell = document.createElement('div');
                    cell.className = 'cell' + (hitCells.has(reel + ',' + row) ? ' hit' : '');
                    cell.textContent = reels?.[reel]?.[row] ?? '·';
                    g.appendChild(cell);
                }
            }
        }

        (async () => {
            try {
                const info = await call('init');
                paylines = info.config.paylines;
                denom = info.denomination;
                $('ccy').textContent = info.currency + ' balance';
                $('balance').textContent = (+info.balance).toFixed(2);
                $('bet').innerHTML = info.bet_options.map(b => `<option value="${b}">${b}</option>`).join('');
                drawGrid(null, null);
                $('spin').disabled = false;
            } catch (e) { $('msg').textContent = e.message; }
        })();

        $('spin').addEventListener('click', async () => {
            $('spin').disabled = true; $('msg').textContent = '';
            try {
                const r = await call('bet', { bet: +$('bet').value, lines: paylines.length });
                drawGrid(r.reels, r.lines);
                $('balance').textContent = (+r.balance).toFixed(2);
                $('win').textContent = (+r.win).toFixed(2);
            } catch (e) { $('msg').textContent = e.message; }
            $('spin').disabled = false;
        });
    </script>
</body>
</html>
