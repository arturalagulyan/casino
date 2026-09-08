/**
 * Player frontend — small vanilla helpers. No framework.
 *   - live balance chip (polls /api/me/balance)
 *   - fullscreen toggle on the play page
 *   - debounced lobby search
 */

function pollBalance() {
    const chips = document.querySelectorAll('[data-balance-poll]');
    if (chips.length === 0) return;

    const tick = async () => {
        try {
            const res = await fetch('/me/balance', { headers: { Accept: 'application/json' } });
            if (!res.ok) return;
            const { formatted } = await res.json();
            chips.forEach((el) => { el.textContent = formatted; });
        } catch {
            /* offline — keep the last value */
        }
    };

    tick();
    setInterval(tick, 10000);
    window.addEventListener('focus', tick);
}

function fullscreenToggle() {
    const btn = document.querySelector('[data-fullscreen]');
    const target = document.querySelector('[data-fullscreen-target]');
    if (!btn || !target) return;

    btn.addEventListener('click', () => {
        if (document.fullscreenElement) {
            document.exitFullscreen();
        } else {
            target.requestFullscreen?.();
        }
    });
}

function lobbySearch() {
    const input = document.querySelector('[data-lobby-search]');
    if (!input) return;

    let timer;
    input.addEventListener('input', () => {
        clearTimeout(timer);
        timer = setTimeout(() => input.form?.requestSubmit(), 450);
    });
}

document.addEventListener('DOMContentLoaded', () => {
    pollBalance();
    fullscreenToggle();
    lobbySearch();
});
