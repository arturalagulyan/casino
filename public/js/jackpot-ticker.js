/**
 * Live jackpot ticker + win splash, injected into every game engine's <head>
 * by GameAssetController (see window.CasinoJackpots for the bootstrap shape).
 * Talks to the same WebSocket server as EGT/Amatic gameplay (php artisan
 * game:socket) over a separate, protocol-agnostic "jackpots" channel — see
 * App\Services\GamePlay\JackpotChannel. Self-contained: no dependency on any
 * game engine's own JS globals.
 */
(function () {
    var boot = window.CasinoJackpots;
    if (!boot || !boot.jackpots || !boot.jackpots.length) {
        return;
    }

    var RECONNECT_DELAY_MS = 3000;
    var TWEEN_MS = 600;

    var amountEls = {};
    var currentValues = {};
    var currencies = {};

    function formatMoney(amount, currency) {
        try {
            return new Intl.NumberFormat(undefined, {
                style: 'currency',
                currency: currency,
                minimumFractionDigits: 2,
                maximumFractionDigits: 2,
            }).format(amount);
        } catch (e) {
            return amount.toFixed(2) + ' ' + currency;
        }
    }

    function injectStyles() {
        var style = document.createElement('style');
        style.textContent = [
            '#cj-ticker{position:fixed;top:0;left:0;right:0;z-index:2147483000;',
            'display:flex;flex-wrap:wrap;justify-content:center;gap:1.5em;',
            'padding:.4em 1em;background:linear-gradient(180deg,rgba(10,8,2,.92),rgba(10,8,2,.75));',
            'font:600 14px/1.4 system-ui,-apple-system,Segoe UI,Roboto,sans-serif;',
            'color:#f3d98b;text-shadow:0 1px 2px rgba(0,0,0,.6);pointer-events:none;}',
            '#cj-ticker .cj-item{display:flex;align-items:baseline;gap:.4em;white-space:nowrap;}',
            '#cj-ticker .cj-name{color:#e7c968;opacity:.85;font-weight:500;letter-spacing:.02em;}',
            '#cj-ticker .cj-amount{color:#ffd75e;font-weight:800;font-variant-numeric:tabular-nums;',
            'transition:transform .15s ease;}',
            '#cj-ticker .cj-amount.cj-bump{transform:scale(1.08);}',
            '#cj-splash{position:fixed;inset:0;z-index:2147483001;display:none;',
            'align-items:center;justify-content:center;background:rgba(4,3,1,.82);',
            'font:600 16px/1.5 system-ui,-apple-system,Segoe UI,Roboto,sans-serif;}',
            '#cj-splash.cj-open{display:flex;}',
            '#cj-splash .cj-card{background:radial-gradient(120% 140% at 50% -10%,#3a2a06,#0c0902 70%);',
            'border:1px solid rgba(255,215,94,.5);border-radius:16px;padding:2.2em 2.6em;',
            'text-align:center;color:#fff2cf;box-shadow:0 20px 60px rgba(0,0,0,.6),0 0 0 1px rgba(255,215,94,.08) inset;',
            'max-width:90vw;}',
            '#cj-splash .cj-title{font-size:1.6em;font-weight:800;color:#ffd75e;margin:0 0 .2em;',
            'text-shadow:0 2px 8px rgba(255,180,0,.35);}',
            '#cj-splash .cj-jname{opacity:.8;margin:0 0 .6em;}',
            '#cj-splash .cj-total{font-size:2.4em;font-weight:800;color:#fff;margin:0 0 1.1em;',
            'font-variant-numeric:tabular-nums;}',
            '#cj-splash button{appearance:none;border:none;border-radius:999px;',
            'padding:.75em 2.2em;font:inherit;font-weight:700;cursor:pointer;',
            'background:linear-gradient(180deg,#ffe08a,#d4af37);color:#231a02;}',
            '#cj-splash button:active{transform:translateY(1px);}',
        ].join('');
        document.head.appendChild(style);
    }

    function buildTicker() {
        var bar = document.createElement('div');
        bar.id = 'cj-ticker';

        boot.jackpots.forEach(function (j) {
            currentValues[j.id] = j.balance;
            currencies[j.id] = j.currency;

            var item = document.createElement('div');
            item.className = 'cj-item';

            var name = document.createElement('span');
            name.className = 'cj-name';
            name.textContent = j.name;

            var amount = document.createElement('span');
            amount.className = 'cj-amount';
            amount.textContent = formatMoney(j.balance, j.currency);

            item.appendChild(name);
            item.appendChild(amount);
            bar.appendChild(item);
            amountEls[j.id] = amount;
        });

        document.body ? document.body.appendChild(bar) : document.addEventListener('DOMContentLoaded', function () {
            document.body.appendChild(bar);
        });
    }

    function buildSplash() {
        var overlay = document.createElement('div');
        overlay.id = 'cj-splash';
        overlay.innerHTML =
            '<div class="cj-card">' +
            '<p class="cj-title">🎉 Jackpot won! 🎉</p>' +
            '<p class="cj-jname" id="cj-splash-name"></p>' +
            '<p class="cj-total" id="cj-splash-amount"></p>' +
            '<button type="button" id="cj-splash-collect">Collect</button>' +
            '</div>';

        var attach = function () {
            document.body.appendChild(overlay);
            document.getElementById('cj-splash-collect').addEventListener('click', function () {
                overlay.classList.remove('cj-open');
            });
        };
        document.body ? attach() : document.addEventListener('DOMContentLoaded', attach);

        return overlay;
    }

    function tweenTo(id, target) {
        var el = amountEls[id];
        if (!el) {
            return;
        }

        var start = currentValues[id] || 0;
        var currency = currencies[id];
        var startTime = null;

        el.classList.add('cj-bump');
        setTimeout(function () { el.classList.remove('cj-bump'); }, 200);

        function step(ts) {
            if (startTime === null) {
                startTime = ts;
            }
            var progress = Math.min(1, (ts - startTime) / TWEEN_MS);
            var value = start + (target - start) * progress;
            el.textContent = formatMoney(value, currency);

            if (progress < 1) {
                requestAnimationFrame(step);
            } else {
                currentValues[id] = target;
            }
        }

        requestAnimationFrame(step);
    }

    function showWin(jackpotName, amount) {
        var overlay = document.getElementById('cj-splash') || buildSplash();
        var nameEl = document.getElementById('cj-splash-name');
        var amountEl = document.getElementById('cj-splash-amount');

        if (nameEl) {
            nameEl.textContent = jackpotName;
        }
        if (amountEl) {
            // We don't know the winner's wallet currency here (it's whatever
            // their account uses, resolved server-side) — show the raw figure.
            amountEl.textContent = Number(amount).toLocaleString(undefined, {
                minimumFractionDigits: 2,
                maximumFractionDigits: 2,
            });
        }

        overlay.classList.add('cj-open');
    }

    function connect() {
        fetch('/socket_config.json')
            .then(function (r) { return r.json(); })
            .then(function (cfg) {
                var url = cfg.prefix_ws + cfg.host_ws + ':' + cfg.port;
                var ws = new WebSocket(url);

                ws.onopen = function () {
                    ws.send(JSON.stringify({
                        channel: 'jackpots',
                        subscribe: boot.jackpots.map(function (j) { return j.id; }),
                        userId: boot.userId,
                    }));
                };

                ws.onmessage = function (evt) {
                    var msg;
                    try {
                        msg = JSON.parse(evt.data);
                    } catch (e) {
                        return;
                    }

                    if (msg.type === 'snapshot' || msg.type === 'update') {
                        Object.keys(msg.balances || {}).forEach(function (id) {
                            tweenTo(Number(id), Number(msg.balances[id]));
                        });
                    } else if (msg.type === 'won') {
                        showWin(msg.jackpotName, msg.amount);
                    }
                };

                ws.onclose = function () { setTimeout(connect, RECONNECT_DELAY_MS); };
                ws.onerror = function () { ws.close(); };
            })
            .catch(function () { setTimeout(connect, RECONNECT_DELAY_MS); });
    }

    injectStyles();
    buildTicker();
    connect();
})();
