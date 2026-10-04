/*!
 * RoyalSpin Engine — the platform's own slot front-end.
 *
 * One runtime for every RoyalSpin game; each bundle ships this file plus its
 * own game.json (theme, symbol names, feature text), art (img/) and music
 * (snd/). Talks the platform's standard JSON protocol:
 *
 *   POST window.CasinoGame.endpoint  {session, command: init|bet|gamble, …}
 *
 * Two mechanics:
 *   lines   — classic reels + paylines (LineSlotServer): spin, staggered
 *             stops, line wins, scatter → free spins, red/black gamble.
 *   cascade — scatter-pays grid (CascadeSlotServer): drop-in, burst, tumble,
 *             multiplier bombs in free spins.
 */
(function () {
    'use strict';

    const W = 1280, H = 720;
    const CG = window.CasinoGame || {};
    const script = document.currentScript;
    const BUILD = (script && script.dataset.build && !script.dataset.build.includes('{')) ? script.dataset.build : String(Date.now());
    const v = (u) => u + (u.indexOf('?') >= 0 ? '&' : '?') + 'v=' + BUILD;

    // ------------------------------------------------------------------ utils

    const clamp = (x, a, b) => Math.max(a, Math.min(b, x));
    const lerp = (a, b, t) => a + (b - a) * t;
    const rand = (a, b) => a + Math.random() * (b - a);
    const pick = (arr) => arr[Math.floor(Math.random() * arr.length)];
    const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
    const Ease = {
        linear: (t) => t,
        outQuad: (t) => 1 - (1 - t) * (1 - t),
        inQuad: (t) => t * t,
        outCubic: (t) => 1 - Math.pow(1 - t, 3),
        inOutSine: (t) => -(Math.cos(Math.PI * t) - 1) / 2,
        outBack: (t) => { const s = 1.70158; return 1 + (s + 1) * Math.pow(t - 1, 3) + s * Math.pow(t - 1, 2); },
    };

    function el(tag, cls, html) {
        const e = document.createElement(tag);
        if (cls) e.className = cls;
        if (html != null) e.innerHTML = html;
        return e;
    }

    const ICON = {
        info: '<svg viewBox="0 0 24 24"><path d="M11 7h2v2h-2zm0 4h2v6h-2zm1-9a10 10 0 1 0 0 20 10 10 0 0 0 0-20zm0 18a8 8 0 1 1 0-16 8 8 0 0 1 0 16z"/></svg>',
        soundOn: '<svg viewBox="0 0 24 24"><path d="M3 9v6h4l5 5V4L7 9zm13.5 3A4.5 4.5 0 0 0 14 8v8a4.5 4.5 0 0 0 2.5-4zM14 3.2v2.1a7 7 0 0 1 0 13.4v2.1a9 9 0 0 0 0-17.6z"/></svg>',
        soundOff: '<svg viewBox="0 0 24 24"><path d="M16.5 12A4.5 4.5 0 0 0 14 8v2.2l2.4 2.4.1-.6zM19 12c0 .9-.2 1.8-.5 2.6l1.5 1.5A9 9 0 0 0 14 3.2v2.1A7 7 0 0 1 19 12zM4.3 3 3 4.3 7.7 9H3v6h4l5 5v-6.7l4.3 4.3c-.7.5-1.4.9-2.3 1.2v2.1a9 9 0 0 0 3.7-1.8l2 2 1.3-1.3-9-9zM12 4 9.9 6.1 12 8.2z"/></svg>',
        turbo: '<svg viewBox="0 0 24 24"><path d="M13 2 4 14h7l-1 8 9-12h-7z"/></svg>',
        spin: '<svg viewBox="0 0 24 24"><path d="M12 4V1L8 5l4 4V6a6 6 0 1 1-6 6H4a8 8 0 1 0 8-8z"/></svg>',
        stop: '<svg viewBox="0 0 24 24"><rect x="6" y="6" width="12" height="12" rx="2"/></svg>',
        close: '<svg viewBox="0 0 24 24"><path d="M19 6.4 17.6 5 12 10.6 6.4 5 5 6.4 10.6 12 5 17.6 6.4 19 12 13.4 17.6 19 19 17.6 13.4 12z"/></svg>',
        minus: '<svg viewBox="0 0 24 24"><path d="M5 11h14v2H5z"/></svg>',
        plus: '<svg viewBox="0 0 24 24"><path d="M11 5h2v6h6v2h-6v6h-2v-6H5v-2h6z"/></svg>',
    };

    const store = {
        get(k, d) { try { const x = localStorage.getItem('rs_' + k); return x === null ? d : JSON.parse(x); } catch (e) { return d; } },
        set(k, val) { try { localStorage.setItem('rs_' + k, JSON.stringify(val)); } catch (e) { /* private mode */ } },
    };

    // ------------------------------------------------------------------ tweens

    class Tweens {
        constructor() { this.list = []; }
        to(obj, props, dur, ease = Ease.outQuad, delay = 0) {
            return new Promise((resolve) => {
                const from = {};
                for (const k in props) from[k] = obj[k];
                this.list.push({ obj, props, from, dur: Math.max(1, dur), ease, t: -delay, resolve });
            });
        }
        update(dt) {
            for (let i = this.list.length - 1; i >= 0; i--) {
                const tw = this.list[i];
                tw.t += dt;
                if (tw.t < 0) continue;
                const u = clamp(tw.t / tw.dur, 0, 1), e = tw.ease(u);
                for (const k in tw.props) tw.obj[k] = lerp(tw.from[k], tw.props[k], e);
                if (u >= 1) { this.list.splice(i, 1); tw.resolve(); }
            }
        }
        killOf(obj) { this.list = this.list.filter((tw) => { if (tw.obj === obj) { tw.resolve(); return false; } return true; }); }
    }

    // ------------------------------------------------------------------ audio

    class Sound {
        constructor() {
            this.buffers = {};
            this.enabled = store.get('sound', true);
            this.music = null;
            this.musicName = null;
            try {
                const AC = window.AudioContext || window.webkitAudioContext;
                this.ctx = new AC();
                this.master = this.ctx.createGain();
                this.master.gain.value = this.enabled ? 1 : 0;
                this.master.connect(this.ctx.destination);
                this.sfx = this.ctx.createGain(); this.sfx.gain.value = 0.8; this.sfx.connect(this.master);
                this.mus = this.ctx.createGain(); this.mus.gain.value = 0.32; this.mus.connect(this.master);
            } catch (e) { this.ctx = null; }
        }
        async load(name, url) {
            if (!this.ctx) return;
            try {
                const res = await fetch(url);
                const data = await res.arrayBuffer();
                this.buffers[name] = await new Promise((ok, fail) => this.ctx.decodeAudioData(data, ok, fail));
            } catch (e) { /* a missing sound must never break the game */ }
        }
        unlock() { if (this.ctx && this.ctx.state === 'suspended') this.ctx.resume(); }
        play(name, { volume = 1, rate = 1, loop = false } = {}) {
            if (!this.ctx || !this.buffers[name]) return { stop() {} };
            const src = this.ctx.createBufferSource();
            src.buffer = this.buffers[name];
            src.playbackRate.value = rate;
            src.loop = loop;
            const g = this.ctx.createGain();
            g.gain.value = volume;
            src.connect(g); g.connect(this.sfx);
            src.start();
            return { stop: () => { try { g.gain.setTargetAtTime(0, this.ctx.currentTime, 0.05); src.stop(this.ctx.currentTime + 0.3); } catch (e) { /* already stopped */ } } };
        }
        playMusic(name) {
            if (!this.ctx || this.musicName === name) return;
            this.stopMusic();
            this.musicName = name;
            if (!this.buffers[name]) return;
            const src = this.ctx.createBufferSource();
            src.buffer = this.buffers[name];
            src.loop = true;
            const g = this.ctx.createGain();
            g.gain.setValueAtTime(0, this.ctx.currentTime);
            g.gain.linearRampToValueAtTime(1, this.ctx.currentTime + 1.2);
            src.connect(g); g.connect(this.mus);
            src.start();
            this.music = { src, g };
        }
        stopMusic() {
            if (!this.music) return;
            const { src, g } = this.music;
            try { g.gain.setTargetAtTime(0, this.ctx.currentTime, 0.25); src.stop(this.ctx.currentTime + 1.2); } catch (e) { /* noop */ }
            this.music = null; this.musicName = null;
        }
        setEnabled(on) {
            this.enabled = on; store.set('sound', on);
            if (this.master) this.master.gain.setTargetAtTime(on ? 1 : 0, this.ctx.currentTime, 0.05);
        }
    }

    // ------------------------------------------------------------------ api

    class Api {
        constructor(endpoint, session) { this.endpoint = endpoint; this.session = session; }
        async call(command, extra = {}) {
            let res;
            try {
                res = await fetch(this.endpoint, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
                    body: JSON.stringify(Object.assign({ session: this.session, command }, extra)),
                });
            } catch (e) {
                throw Object.assign(new Error('Connection lost. Please check your network.'), { network: true });
            }
            let data = {};
            try { data = await res.json(); } catch (e) { /* non-JSON */ }
            if (!res.ok || data.error) {
                const err = new Error(data.error || ('Server error (' + res.status + ')'));
                err.status = res.status; err.data = data;
                throw err;
            }
            return data;
        }
    }

    // ------------------------------------------------------------------ assets

    function loadImage(url) {
        return new Promise((ok, fail) => {
            const img = new Image();
            img.onload = () => ok(img);
            img.onerror = () => fail(new Error('Could not load ' + url));
            img.src = url;
        });
    }

    /** Pre-render an image into a canvas at device resolution (drawing SVG every frame is slow). */
    function raster(img, size, px) {
        const c = document.createElement('canvas');
        c.width = c.height = Math.max(1, Math.round(size * px));
        c.getContext('2d').drawImage(img, 0, 0, c.width, c.height);
        return c;
    }

    /** A vertically smeared copy for fast-moving reels. */
    function motionBlur(src) {
        const c = document.createElement('canvas');
        c.width = src.width; c.height = src.height;
        const x = c.getContext('2d');
        const step = src.height * 0.016;
        for (let i = -8; i <= 8; i++) {
            x.globalAlpha = 0.16 * (1 - Math.abs(i) / 10);
            x.drawImage(src, 0, i * step);
        }
        return c;
    }

    // ------------------------------------------------------------------ fx (particles)

    class Fx {
        constructor(canvas) { this.canvas = canvas; this.ctx = canvas.getContext('2d'); this.parts = []; this.fountain = 0; }
        coin(x, y, vx, vy) { this.parts.push({ kind: 'coin', x, y, vx, vy, r: rand(10, 18), spin: rand(0, 6), vs: rand(4, 9), life: 3.5, g: 900 }); }
        burst(x, y, color, n = 14) {
            for (let i = 0; i < n; i++) {
                const a = rand(0, Math.PI * 2), s = rand(120, 420);
                this.parts.push({ kind: 'dot', x, y, vx: Math.cos(a) * s, vy: Math.sin(a) * s - 100, r: rand(3, 7), color, life: rand(0.5, 0.9), max: 0.9, g: 600 });
            }
            for (let i = 0; i < 5; i++) this.parts.push({ kind: 'spark', x: x + rand(-30, 30), y: y + rand(-30, 30), r: rand(8, 16), life: rand(0.3, 0.6), max: 0.6, vx: 0, vy: 0, g: 0 });
        }
        update(dt) {
            const s = dt / 1000;
            if (this.fountain > 0) {
                this.fountain -= dt;
                for (let i = 0; i < 3; i++) this.coin(W / 2 + rand(-80, 80), H + 20, rand(-420, 420), rand(-1250, -850));
            }
            for (let i = this.parts.length - 1; i >= 0; i--) {
                const p = this.parts[i];
                p.life -= s; p.vy += p.g * s; p.x += p.vx * s; p.y += p.vy * s; if (p.spin !== undefined) p.spin += p.vs * s;
                if (p.life <= 0 || p.y > H + 80) this.parts.splice(i, 1);
            }
        }
        draw(px) {
            const c = this.ctx;
            c.setTransform(px, 0, 0, px, 0, 0);
            c.clearRect(0, 0, W, H);
            for (const p of this.parts) {
                if (p.kind === 'coin') {
                    const sx = Math.abs(Math.cos(p.spin));
                    c.save(); c.translate(p.x, p.y); c.scale(Math.max(0.12, sx), 1);
                    const g = c.createRadialGradient(-p.r * 0.3, -p.r * 0.3, 1, 0, 0, p.r);
                    g.addColorStop(0, '#fffbe0'); g.addColorStop(0.5, '#ffd84a'); g.addColorStop(1, '#a06a00');
                    c.fillStyle = g; c.beginPath(); c.arc(0, 0, p.r, 0, Math.PI * 2); c.fill();
                    c.strokeStyle = '#7a4a00'; c.lineWidth = 2; c.stroke();
                    c.fillStyle = '#c08a10'; c.font = 'bold ' + Math.round(p.r * 1.1) + 'px Georgia'; c.textAlign = 'center'; c.textBaseline = 'middle'; c.fillText('$', 0, 1);
                    c.restore();
                } else if (p.kind === 'dot') {
                    c.globalAlpha = clamp(p.life / p.max, 0, 1); c.fillStyle = p.color;
                    c.beginPath(); c.arc(p.x, p.y, p.r, 0, Math.PI * 2); c.fill(); c.globalAlpha = 1;
                } else {
                    const a = clamp(p.life / p.max, 0, 1), r = p.r * (1.4 - a * 0.4);
                    c.globalAlpha = a; c.fillStyle = '#fff';
                    c.beginPath(); c.moveTo(p.x, p.y - r); c.quadraticCurveTo(p.x, p.y, p.x + r, p.y); c.quadraticCurveTo(p.x, p.y, p.x, p.y + r);
                    c.quadraticCurveTo(p.x, p.y, p.x - r, p.y); c.quadraticCurveTo(p.x, p.y, p.x, p.y - r); c.fill(); c.globalAlpha = 1;
                }
            }
        }
    }

    // ------------------------------------------------------------------ shared drawing

    function roundRect(c, x, y, w, h, r) {
        c.beginPath();
        c.moveTo(x + r, y); c.arcTo(x + w, y, x + w, y + h, r); c.arcTo(x + w, y + h, x, y + h, r);
        c.arcTo(x, y + h, x, y, r); c.arcTo(x, y, x + w, y, r); c.closePath();
    }

    function drawFrame(c, area, theme, glow) {
        const pad = 14;
        const fx = area.x - pad, fy = area.y - pad, fw = area.w + pad * 2, fh = area.h + pad * 2;
        const fr = theme.frame || ['#fff2a8', '#d4a017', '#7a5200'];
        c.save();
        c.shadowColor = glow || '#000'; c.shadowBlur = glow ? 40 : 24;
        roundRect(c, fx, fy, fw, fh, 22);
        const g = c.createLinearGradient(0, fy, 0, fy + fh);
        g.addColorStop(0, fr[0]); g.addColorStop(0.5, fr[1]); g.addColorStop(1, fr[2]);
        c.fillStyle = g; c.fill();
        c.restore();
        roundRect(c, area.x - 4, area.y - 4, area.w + 8, area.h + 8, 12);
        c.fillStyle = '#000'; c.fill();
        const bg = theme.reelBg || ['#14141c', '#06060a'];
        const g2 = c.createLinearGradient(0, area.y, 0, area.y + area.h);
        g2.addColorStop(0, bg[0]); g2.addColorStop(1, bg[1]);
        roundRect(c, area.x, area.y, area.w, area.h, 8);
        c.fillStyle = g2; c.fill();
    }

    // ================================================================== LINE SCENE

    class LineScene {
        constructor(game) {
            this.g = game;
            const cfg = game.cfg;
            this.reels = cfg.reels; this.rows = cfg.rows;
            this.area = { x: 165, y: 120, w: 950, h: 450 };
            this.cw = this.area.w / this.reels; this.ch = this.area.h / this.rows;
            this.size = Math.min(this.cw, this.ch) * 0.94;
            this.pool = cfg.symbols.filter((s) => s !== cfg.scatter);
            this.cols = [];
            for (let r = 0; r < this.reels; r++) {
                const col = [];
                for (let i = 0; i < this.rows; i++) col.push(pick(this.pool));
                this.cols.push({ tape: [pick(this.pool)].concat(col, [pick(this.pool)]), pos: 1, speed: 0, state: 'idle', bounce: 0, glow: 0 });
            }
            this.show = null;      // { wins, cells:Set, index }
            this.t = 0;
        }

        cellCenter(reel, row) { return [this.area.x + (reel + 0.5) * this.cw, this.area.y + (row + 0.5) * this.ch]; }

        startSpin() {
            this.show = null;
            const turbo = this.g.turbo;
            this.cols.forEach((col, i) => {
                col.state = 'start'; col.anticipate = false;
                const kick = { p: col.pos };
                // tiny upward kick, then accelerate downward
                this.g.tw.to(kick, { p: col.pos + 0.18 }, turbo ? 40 : 90, Ease.outQuad, i * (turbo ? 15 : 45)).then(() => {
                    col.pos = kick.p; col.state = 'spin'; col.speed = turbo ? 34 : 24;
                });
                col._kick = kick;
            });
        }

        /** Land the reels on `board` ([reel][row]); resolves when every reel has stopped. */
        stopAt(board, opts = {}) {
            const turbo = this.g.turbo;
            const gap = opts.quick ? 0 : (turbo ? 70 : 170);
            const minSpin = opts.quick ? 0 : (turbo ? 260 : 650);
            const scatter = this.g.cfg.scatter;
            const need = this.g.cfg.has_free_spins ? 3 : 99;
            let seenScatters = 0;
            let delay = minSpin;
            const promises = this.cols.map((col, r) => {
                // anticipation: enough scatters already on earlier reels to tease the feature
                const tease = !opts.quick && !turbo && seenScatters >= need - 1 && r < this.reels && scatter !== null;
                if (tease) delay += 900;
                const at = delay;
                delay += gap;
                seenScatters += board[r].filter((s) => s === scatter).length;
                return new Promise((resolve) => {
                    col.pendingStop = { at, board: board[r], resolve, tease };
                });
            });
            this.stopClock = 0;
            return Promise.all(promises);
        }

        quickStop() {
            this.cols.forEach((col) => { if (col.pendingStop) col.pendingStop.at = Math.min(col.pendingStop.at, this.stopClock); if (col.state === 'stopping') col.quick = true; });
        }

        update(dt) {
            this.t += dt / 1000;
            if (this.stopClock !== undefined) this.stopClock += dt;
            for (let r = 0; r < this.reels; r++) {
                const col = this.cols[r];
                if (col.state === 'start') { col.pos = col._kick.p; continue; }
                if (col.state === 'spin') {
                    col.pos -= col.speed * dt / 1000;
                    while (col.pos < 2) { col.tape.unshift(pick(this.pool)); col.pos += 1; }
                    if (col.tape.length > 40) col.tape.length = 40;
                    col.glow = col.pendingStop && col.pendingStop.tease ? Math.min(1, col.glow + dt / 300) : Math.max(0, col.glow - dt / 300);
                    if (col.pendingStop && this.stopClock >= col.pendingStop.at) this.beginStop(col);
                } else if (col.state === 'stopping') {
                    const s = col.stop;
                    s.t += dt * (col.quick ? 3 : 1);
                    const u = clamp(s.t / s.dur, 0, 1);
                    col.pos = s.from - (s.from - 1) * (1 - (1 - u) * (1 - u));   // ease-out, starts at spin speed
                    if (u >= 1) {
                        col.state = 'bounce'; col.bounceT = 0; col.pos = 1;
                        this.g.sound.play('reel_stop', { volume: 0.7 });
                        if (col.stop.board.includes(this.g.cfg.scatter)) this.g.sound.play('scatter', { volume: 0.6 });
                    }
                } else if (col.state === 'bounce') {
                    col.bounceT += dt;
                    const u = clamp(col.bounceT / 170, 0, 1);
                    col.pos = 1 - 0.16 * Math.sin(Math.PI * u);
                    col.glow = Math.max(0, col.glow - dt / 200);
                    if (u >= 1) {
                        col.pos = 1; col.state = 'idle'; col.quick = false;
                        col.tape = [pick(this.pool)].concat(col.stop.board, [pick(this.pool)]);
                        const done = col.stop.resolve; col.stop = null; done();
                    }
                }
            }
        }

        beginStop(col) {
            const ps = col.pendingStop; col.pendingStop = null;
            // prepend [random, …final rows] above the current window; land on index 1
            const head = [pick(this.pool)].concat(ps.board);
            col.tape = head.concat(col.tape);
            col.pos += head.length;
            const dist = col.pos - 1;
            const speed = Math.max(8, col.speed);
            col.state = 'stopping';
            col.stop = { from: col.pos, t: 0, dur: Math.max(120, (2 * dist / speed) * 1000), board: ps.board, resolve: ps.resolve };
        }

        presentWins(wins, scatterCells) {
            const cells = new Set();
            wins.forEach((w) => {
                if (w.line >= 0) for (let i = 0; i < w.cells.length; i += 2) cells.add(w.cells[i] + ',' + w.cells[i + 1]);
            });
            (scatterCells || []).forEach(([r, row]) => cells.add(r + ',' + row));
            this.show = { wins, cells, index: -1, scatterCells: scatterCells || [] };
        }
        clearWins() { this.show = null; }

        draw(c) {
            const g = this.g, a = this.area;
            const glow = this.cols.some((col) => col.glow > 0.05) ? g.theme.accent : null;
            drawFrame(c, a, g.theme, glow);
            // reel separators
            c.strokeStyle = '#ffffff10'; c.lineWidth = 2;
            for (let r = 1; r < this.reels; r++) { c.beginPath(); c.moveTo(a.x + r * this.cw, a.y + 6); c.lineTo(a.x + r * this.cw, a.y + a.h - 6); c.stroke(); }

            c.save();
            roundRect(c, a.x, a.y, a.w, a.h, 8); c.clip();
            const show = this.show;
            const focus = show && show.index >= 0 ? show.wins[show.index] : null;
            const focusCells = new Set();
            if (focus) {
                if (focus.line >= 0) for (let i = 0; i < focus.cells.length; i += 2) focusCells.add(focus.cells[i] + ',' + focus.cells[i + 1]);
                else show.scatterCells.forEach(([r, row]) => focusCells.add(r + ',' + row));
            }
            for (let r = 0; r < this.reels; r++) {
                const col = this.cols[r];
                if (col.glow > 0) {
                    c.fillStyle = 'rgba(255,216,74,' + (0.12 * col.glow * (0.6 + 0.4 * Math.sin(this.t * 10))) + ')';
                    c.fillRect(a.x + r * this.cw, a.y, this.cw, a.h);
                }
                const fast = (col.state === 'spin' || col.state === 'stopping') && col.speed > 10;
                const top = Math.floor(col.pos) - 1;
                for (let idx = top; idx <= top + this.rows + 2; idx++) {
                    const sym = col.tape[idx];
                    if (sym === undefined) continue;
                    const row = idx - col.pos;
                    const cx = a.x + (r + 0.5) * this.cw, cy = a.y + (row + 0.5) * this.ch;
                    let scale = 1, alpha = 1;
                    if (show && col.state === 'idle') {
                        const key = r + ',' + Math.round(row);
                        const hot = focus ? focusCells.has(key) : show.cells.has(key);
                        if (hot) scale = 1 + 0.07 * Math.sin(this.t * 9);
                        else alpha = 0.45;
                    }
                    g.drawSymbol(c, sym, cx, cy, this.size * scale, alpha, fast && col.state === 'spin');
                }
            }
            c.restore();

            // win lines
            if (show && show.wins.length) {
                const list = focus ? [focus] : show.wins;
                for (const w of list) {
                    if (w.line < 0) continue;
                    const color = g.lineColor(w.line);
                    const path = g.cfg.paylines[w.line];
                    c.save();
                    c.lineJoin = 'round'; c.lineCap = 'round';
                    c.shadowColor = color; c.shadowBlur = 14;
                    c.strokeStyle = '#000a'; c.lineWidth = 10;
                    this.linePath(c, path); c.stroke();
                    c.strokeStyle = color; c.lineWidth = 5;
                    this.linePath(c, path); c.stroke();
                    c.restore();
                    if (focus) {
                        c.save(); c.strokeStyle = color; c.lineWidth = 4; c.shadowColor = color; c.shadowBlur = 12;
                        for (let i = 0; i < w.cells.length; i += 2) {
                            const x = a.x + w.cells[i] * this.cw, y = a.y + w.cells[i + 1] * this.ch;
                            roundRect(c, x + 5, y + 5, this.cw - 10, this.ch - 10, 12); c.stroke();
                        }
                        c.restore();
                    }
                }
                if (focus && focus.line < 0) {
                    c.save(); c.strokeStyle = g.theme.accent; c.lineWidth = 4; c.shadowColor = g.theme.accent; c.shadowBlur = 14;
                    show.scatterCells.forEach(([r, row]) => { roundRect(c, a.x + r * this.cw + 5, a.y + row * this.ch + 5, this.cw - 10, this.ch - 10, 12); c.stroke(); });
                    c.restore();
                }
                // line number badges
                if (focus && focus.line >= 0) {
                    const y = this.cellCenter(0, g.cfg.paylines[focus.line][0])[1];
                    g.badge(c, a.x - 30, y, String(focus.line + 1), g.lineColor(focus.line));
                }
            }
        }

        linePath(c, path) {
            c.beginPath();
            const [x0, y0] = this.cellCenter(0, path[0]);
            c.moveTo(this.area.x - 12, y0);
            c.lineTo(x0, y0);
            for (let r = 1; r < path.length; r++) { const [x, y] = this.cellCenter(r, path[r]); c.lineTo(x, y); }
            const yl = this.cellCenter(path.length - 1, path[path.length - 1])[1];
            c.lineTo(this.area.x + this.area.w + 12, yl);
        }
    }

    // ================================================================== CASCADE SCENE

    class CascadeScene {
        constructor(game) {
            this.g = game;
            const cfg = game.cfg;
            this.reels = cfg.reels; this.rows = cfg.rows;
            const cell = Math.min(Math.floor(860 / this.reels), Math.floor(450 / this.rows));
            this.cw = this.ch = cell;
            this.area = { x: Math.round((W - cell * this.reels) / 2), y: 122, w: cell * this.reels, h: cell * this.rows };
            this.size = cell * 0.9;
            this.pool = cfg.symbols.filter((s) => s !== cfg.scatter && s !== (cfg.cascade || {}).multiplier_symbol);
            this.grid = [];
            for (let r = 0; r < this.reels; r++) {
                this.grid.push([]);
                for (let row = 0; row < this.rows; row++) this.grid[r].push(this.cell(pick(this.pool)));
            }
            this.t = 0;
            this.labels = [];
        }

        cell(sym, bomb) { return { sym, oy: 0, scale: 1, alpha: 1, flash: 0, bomb: bomb || 0 }; }
        center(reel, row) { return [this.area.x + (reel + 0.5) * this.cw, this.area.y + (row + 0.5) * this.ch]; }

        bombAt(bombs, reel, row) {
            const b = (bombs || []).find((x) => x.reel === reel && x.row === row);
            return b ? b.value : 0;
        }

        /** Current symbols fall out of the bottom. */
        async dropOut() {
            const turbo = this.g.turbo, jobs = [];
            this.grid.forEach((col, r) => col.forEach((c, row) => {
                jobs.push(this.g.tw.to(c, { oy: this.area.h + this.ch * 2 }, turbo ? 180 : 320, Ease.inQuad, (turbo ? 15 : 40) * r + (this.rows - 1 - row) * 12));
            }));
            await Promise.all(jobs);
        }

        /** A fresh grid drops in from above, bottom rows first. */
        async dropIn(grid, bombs) {
            const turbo = this.g.turbo, jobs = [];
            this.grid = grid.map((col, r) => col.map((sym, row) => {
                const c = this.cell(sym, this.bombAt(bombs, r, row));
                c.oy = -(this.area.h + this.ch);
                return c;
            }));
            this.grid.forEach((col, r) => {
                col.forEach((c, row) => {
                    const delay = (turbo ? 25 : 70) * r + (this.rows - 1 - row) * (turbo ? 15 : 35);
                    jobs.push(this.g.tw.to(c, { oy: 0 }, turbo ? 220 : 380, Ease.outBack, delay).then(() => {
                        if (row === this.rows - 1) this.g.sound.play('drop', { volume: 0.5, rate: rand(0.9, 1.1) });
                        if (c.sym === this.g.cfg.scatter) this.g.sound.play('scatter', { volume: 0.5 });
                    }));
                });
            });
            await Promise.all(jobs);
        }

        /** Flash + burst the winning cells. */
        async explode(wins) {
            const cells = [];
            wins.forEach((w) => w.cells.forEach(([r, row]) => cells.push(this.grid[r][row])));
            cells.forEach((c) => { c.flash = 1; });
            // amount labels at each cluster's centroid
            wins.forEach((w) => {
                const xs = w.cells.map(([r, row]) => this.center(r, row));
                const cx = xs.reduce((s, p) => s + p[0], 0) / xs.length, cy = xs.reduce((s, p) => s + p[1], 0) / xs.length;
                this.labels.push({ x: cx, y: cy, text: this.g.money(w.amount), life: 1.4 });
            });
            await sleep(this.g.turbo ? 250 : 650);
            this.g.sound.play('pop', { volume: 0.8 });
            wins.forEach((w) => w.cells.forEach(([r, row]) => {
                const [x, y] = this.center(r, row);
                this.g.fx.burst(x, y, this.g.symbolColor(w.symbol), 10);
            }));
            await Promise.all(cells.map((c) => this.g.tw.to(c, { scale: 1.35, alpha: 0 }, 200, Ease.outQuad)));
            cells.forEach((c) => { c.dead = true; });
        }

        /** Survivors fall into the gaps, new symbols drop in to complete `next`. */
        async collapse(next, bombs) {
            const turbo = this.g.turbo, jobs = [];
            const newGrid = [];
            for (let r = 0; r < this.reels; r++) {
                const survivors = this.grid[r].map((c, row) => ({ c, row })).filter((x) => !x.c.dead);
                const missing = this.rows - survivors.length;
                const col = [];
                for (let row = 0; row < this.rows; row++) {
                    if (row < missing) {
                        const c = this.cell(next[r][row], this.bombAt(bombs, r, row));
                        c.oy = -(missing + 0.5) * this.ch - row * 6;
                        col.push(c);
                        jobs.push(this.g.tw.to(c, { oy: 0 }, turbo ? 200 : 360, Ease.outBack, (turbo ? 20 : 50) * r));
                    } else {
                        const s = survivors[row - missing];
                        const c = s.c;
                        c.sym = next[r][row];   // trust the server's board
                        const fall = (row - s.row) * this.ch;
                        c.oy -= fall;
                        if (fall !== 0) jobs.push(this.g.tw.to(c, { oy: 0 }, turbo ? 160 : 280, Ease.outBack, (turbo ? 10 : 30) * r));
                        const bv = this.bombAt(bombs, r, row); if (bv) c.bomb = bv;
                        col.push(c);
                    }
                }
                newGrid.push(col);
            }
            this.grid = newGrid;
            this.g.sound.play('drop', { volume: 0.6 });
            await Promise.all(jobs);
        }

        setGrid(grid, bombs) { this.grid = grid.map((col, r) => col.map((sym, row) => this.cell(sym, this.bombAt(bombs, r, row)))); }

        update(dt) {
            this.t += dt / 1000;
            for (let i = this.labels.length - 1; i >= 0; i--) { const l = this.labels[i]; l.life -= dt / 1000; l.y -= dt * 0.02; if (l.life <= 0) this.labels.splice(i, 1); }
            this.grid.forEach((col) => col.forEach((c) => { if (c.flash > 0 && !c.dead) c.flash = Math.max(0, c.flash - dt / 900); }));
        }

        draw(c) {
            const g = this.g, a = this.area;
            drawFrame(c, a, g.theme, null);
            c.save();
            roundRect(c, a.x, a.y, a.w, a.h, 8); c.clip();
            // checker tint
            for (let r = 0; r < this.reels; r++) for (let row = 0; row < this.rows; row++) {
                if ((r + row) % 2) { c.fillStyle = '#ffffff10'; c.fillRect(a.x + r * this.cw, a.y + row * this.ch, this.cw, this.ch); }
            }
            this.grid.forEach((col, r) => col.forEach((cell, row) => {
                if (cell.dead) return;
                const [x, y0] = this.center(r, row);
                const y = y0 + cell.oy;
                if (cell.flash > 0) {
                    c.save(); c.globalAlpha = cell.flash * (0.5 + 0.5 * Math.sin(this.t * 22));
                    c.fillStyle = g.theme.accent; c.shadowColor = '#fff'; c.shadowBlur = 20;
                    roundRect(c, x - this.cw / 2 + 4, y - this.ch / 2 + 4, this.cw - 8, this.ch - 8, 14); c.fill(); c.restore();
                }
                const pulse = cell.flash > 0 ? 1 + 0.08 * Math.sin(this.t * 14) : 1;
                g.drawSymbol(c, cell.sym, x, y, this.size * cell.scale * pulse, cell.alpha, false);
                if (cell.bomb && cell.alpha > 0.1) {
                    c.save();
                    c.font = '900 ' + Math.round(this.ch * 0.34) + 'px Georgia, serif';
                    c.textAlign = 'center'; c.textBaseline = 'middle';
                    c.lineWidth = 6; c.strokeStyle = '#3a0050'; c.strokeText('x' + cell.bomb, x, y + 4);
                    c.fillStyle = '#fff'; c.fillText('x' + cell.bomb, x, y + 4);
                    c.restore();
                }
            }));
            c.restore();
            for (const l of this.labels) {
                c.save(); c.globalAlpha = clamp(l.life, 0, 1);
                c.font = '900 34px "Segoe UI", sans-serif'; c.textAlign = 'center'; c.textBaseline = 'middle';
                c.lineWidth = 7; c.strokeStyle = '#000'; c.strokeText(l.text, l.x, l.y);
                c.fillStyle = '#fff'; c.fillText(l.text, l.x, l.y);
                c.restore();
            }
        }
    }

    // ================================================================== GAME

    class Game {
        constructor(root) {
            this.root = root;
            this.tw = new Tweens();
            this.sound = new Sound();
            this.api = new Api(CG.endpoint, CG.session);
            this.turbo = store.get('turbo', false);
            this.busy = false;
            this.auto = 0;
            this.fs = { active: false, left: 0, total: 0, won: 0, mult: 1 };
            this.lastWin = 0;
            this.sprites = {};
            this.images = {};
        }

        // ---------- boot

        async boot() {
            this.buildLoader();
            try {
                this.meta = await (await fetch(v('game.json'))).json();
            } catch (e) {
                return this.fatal('Game files are missing (game.json).');
            }
            this.theme = this.meta.theme || {};
            document.title = this.meta.title || 'RoyalSpin';
            this.root.style.setProperty('--accent', this.theme.accent || '#ffd84a');
            this.loaderLogo.src = v('img/logo.svg');

            if (!CG.endpoint || !CG.session) return this.fatal('This game must be launched from the casino.');

            let init;
            try {
                init = await this.api.call('init');
            } catch (e) {
                return this.fatal(e.status === 403 ? 'Your game session has expired. Please reopen the game.' : e.message);
            }
            this.init = init;
            this.cfg = init.config;
            this.currency = init.currency || CG.currency || 'EUR';
            this.denom = +init.denomination || 1;
            this.bets = (init.bet_options || [1]).map(Number);
            this.balance = +init.balance;
            this.mechanic = this.cfg.cascade ? 'cascade' : 'lines';
            this.lines = this.mechanic === 'cascade' ? this.cfg.cascade.lines : this.cfg.paylines.length;
            const savedBet = store.get('bet_' + init.game.code, null);
            this.betIndex = Math.max(0, this.bets.indexOf(savedBet));

            // assets
            const syms = this.cfg.symbols;
            const sounds = ['click', 'spin', 'reel_stop', 'scatter', 'win_small', 'win_medium', 'win_big', 'coins', 'freespins', 'card_flip', 'gamble_win', 'gamble_lose', 'pop', 'drop', 'bomb'];
            const tasks = [];
            syms.forEach((s) => tasks.push(loadImage(v('img/sym/' + s + '.svg')).then((img) => { this.images[s] = img; })));
            tasks.push(loadImage(v('img/bg.svg')).then((i) => { this.bgImg = i; }));
            tasks.push(loadImage(v('img/bg_free.svg')).then((i) => { this.bgFreeImg = i; }).catch(() => {}));
            sounds.forEach((n) => tasks.push(this.sound.load(n, v('snd/' + n + '.wav'))));
            tasks.push(this.sound.load('music', v(this.theme.music || 'snd/music.wav')));
            tasks.push(this.sound.load('music_free', v(this.theme.musicFree || 'snd/music_free.wav')));
            let done = 0;
            await Promise.all(tasks.map((p) => p.catch(() => {}).then(() => { done++; this.loaderBar.style.width = Math.round((done / tasks.length) * 100) + '%'; })));
            if (Object.keys(this.images).length < syms.length) return this.fatal('Could not load the game graphics.');

            this.buildStage();
            this.scene = this.mechanic === 'cascade' ? new CascadeScene(this) : new LineScene(this);
            this.resize();
            window.addEventListener('resize', () => this.resize());
            this.updateHud();
            this.loop();

            // tap to start (unlocks audio on mobile)
            this.loaderText.textContent = 'Tap to start';
            this.loaderText.style.color = '#fff';
            this.loaderEl.style.cursor = 'pointer';
            await new Promise((ok) => this.loaderEl.addEventListener('pointerdown', ok, { once: true }));
            this.sound.unlock();
            this.loaderEl.remove();
            this.sound.playMusic('music');

            // resume an unfinished free-spin round
            const st = init.state || {};
            if (+st.free_spins_left > 0) {
                const bl = +st.free_spins_betline;
                const idx = this.bets.indexOf(bl);
                if (idx >= 0) this.betIndex = idx;
                await this.enterFreeSpins(+st.free_spins_left, true);
            } else {
                this.message('Good luck!');
            }
        }

        fatal(msg) {
            if (this.loaderText) { this.loaderText.textContent = msg; this.loaderText.style.color = '#ff8a95'; }
            if (this.loaderBar) this.loaderBar.parentNode.style.display = 'none';
        }

        buildLoader() {
            this.loaderEl = el('div', 'rs-loader');
            const inner = el('div', 'inner');
            this.loaderLogo = el('img');
            this.loaderLogo.onerror = () => { this.loaderLogo.style.display = 'none'; };
            const bar = el('div', 'rs-bar'); this.loaderBar = el('i'); bar.appendChild(this.loaderBar);
            this.loaderText = el('small', null, 'Loading');
            inner.append(this.loaderLogo, bar, this.loaderText, el('div', 'rs-brand', 'ROYALSPIN'));
            this.loaderEl.appendChild(inner);
            this.root.appendChild(this.loaderEl);
        }

        buildStage() {
            const st = this.stage = el('div', 'rs-stage');
            const bg = el('img', 'rs-bg'); bg.src = this.bgImg.src; st.appendChild(bg);
            if (this.bgFreeImg) { const bf = el('img', 'rs-bg free'); bf.src = this.bgFreeImg.src; st.appendChild(bf); }
            this.canvas = el('canvas', 'rs-canvas'); st.appendChild(this.canvas);
            this.ctx = this.canvas.getContext('2d');
            const logo = el('img', 'rs-logo'); logo.src = v('img/logo.svg'); st.appendChild(logo);
            this.msgEl = el('div', 'rs-msg'); st.appendChild(this.msgEl);

            this.fsLeftEl = el('div', 'rs-fs left', '<label>FREE SPINS</label><b>0</b>');
            this.fsWinEl = el('div', 'rs-fs right', '<label>FEATURE WIN</label><b>0</b>');
            st.append(this.fsLeftEl, this.fsWinEl);

            // HUD
            const hud = el('div', 'rs-hud');
            const left = el('div', 'rs-group');
            this.btnInfo = this.iconBtn(ICON.info, () => this.openPaytable());
            this.btnSound = this.iconBtn(this.sound.enabled ? ICON.soundOn : ICON.soundOff, () => {
                this.sound.setEnabled(!this.sound.enabled);
                this.btnSound.innerHTML = this.sound.enabled ? ICON.soundOn : ICON.soundOff;
            });
            left.append(this.btnInfo, this.btnSound);

            const bal = el('div', 'rs-box', '<label>Balance</label><b>0</b>');
            this.balEl = bal.querySelector('b');

            const betWrap = el('div', 'rs-bet');
            this.btnBetDown = this.iconBtn(ICON.minus, () => this.changeBet(-1), 'sm');
            this.btnBetUp = this.iconBtn(ICON.plus, () => this.changeBet(1), 'sm');
            const betBox = el('div', 'rs-box', '<label>Total bet</label><b>0</b>');
            this.betEl = betBox.querySelector('b');
            betWrap.append(this.btnBetDown, betBox, this.btnBetUp);

            const win = el('div', 'rs-box win', '<label>Win</label><b>0</b>');
            this.winEl = win.querySelector('b');

            const right = el('div', 'rs-group');
            this.btnGamble = el('button', 'rs-pill gamble', 'GAMBLE');
            this.btnGamble.onclick = () => { this.sound.play('click'); this.openGamble(); };
            this.btnAuto = el('button', 'rs-pill auto', 'AUTO');
            this.btnAuto.onclick = () => { this.sound.play('click'); this.auto > 0 ? this.stopAuto() : this.openAuto(); };
            this.btnTurbo = this.iconBtn(ICON.turbo, () => {
                this.turbo = !this.turbo; store.set('turbo', this.turbo);
                this.btnTurbo.classList.toggle('on', this.turbo);
            });
            this.btnTurbo.classList.toggle('on', this.turbo);
            this.btnSpin = el('button', 'rs-spin', ICON.spin + '<span class="rs-count"></span>');
            this.btnSpin.onclick = () => { this.sound.unlock(); this.onSpinButton(); };
            right.append(this.btnGamble, this.btnAuto, this.btnTurbo, this.btnSpin);

            hud.append(left, bal, betWrap, win, el('div', 'rs-spacer'), right);
            st.appendChild(hud);

            this.fxCanvas = el('canvas', 'rs-fx'); st.appendChild(this.fxCanvas);
            this.fx = new Fx(this.fxCanvas);

            this.root.appendChild(st);

            document.addEventListener('keydown', (e) => {
                if (e.code === 'Space' && !e.repeat && !this.overlay) { e.preventDefault(); this.onSpinButton(); }
            });
        }

        iconBtn(svg, fn, extra) {
            const b = el('button', 'rs-ico' + (extra ? ' ' + extra : ''), svg);
            b.onclick = () => { this.sound.unlock(); this.sound.play('click', { volume: 0.6 }); fn(); };
            return b;
        }

        resize() {
            const s = Math.min(window.innerWidth / W, window.innerHeight / H);
            this.scale = s;
            // centred on the viewport, scaled about its own centre
            this.stage.style.transformOrigin = '50% 50%';
            this.stage.style.transform = 'translate(-50%, -50%) scale(' + s + ')';
            const px = this.px = s * (window.devicePixelRatio || 1);
            for (const cv of [this.canvas, this.fxCanvas]) { cv.width = Math.round(W * px); cv.height = Math.round(H * px); }
            // re-raster symbol sprites at the new resolution (with headroom for win pulses)
            const size = Math.ceil(this.scene.size * 1.12);
            this.sprites = {};
            for (const s2 in this.images) {
                const base = raster(this.images[s2], size, px);
                this.sprites[s2] = { base, blur: motionBlur(base) };
            }
        }

        loop() {
            let last = performance.now();
            const frame = (now) => {
                const dt = Math.min(64, now - last); last = now;
                this.tw.update(dt);
                this.scene.update(dt);
                this.fx.update(dt);
                const c = this.ctx;
                c.setTransform(this.px, 0, 0, this.px, 0, 0);
                c.clearRect(0, 0, W, H);
                this.scene.draw(c);
                this.fx.draw(this.px);
                requestAnimationFrame(frame);
            };
            requestAnimationFrame(frame);
        }

        // ---------- drawing helpers used by scenes

        drawSymbol(c, sym, cx, cy, size, alpha, blur) {
            const sp = this.sprites[sym];
            if (!sp) return;
            c.globalAlpha = alpha;
            c.drawImage(blur ? sp.blur : sp.base, cx - size / 2, cy - size / 2, size, size);
            c.globalAlpha = 1;
        }

        badge(c, x, y, text, color) {
            c.save(); c.fillStyle = color; c.shadowColor = color; c.shadowBlur = 10;
            c.beginPath(); c.arc(x, y, 17, 0, Math.PI * 2); c.fill();
            c.shadowBlur = 0; c.fillStyle = '#000'; c.font = 'bold 17px sans-serif'; c.textAlign = 'center'; c.textBaseline = 'middle'; c.fillText(text, x, y + 1);
            c.restore();
        }

        lineColor(i) { const l = this.theme.lineColors || ['#ffd84a']; return l[i % l.length]; }
        symbolColor(sym) { return ['#ffb300', '#29a3ff', '#38c43a', '#a64dff', '#ff8a1f', '#ff7ab8', '#ff5fa8', '#ff2a6d', '#ffd84a', '#ffffff'][sym % 10]; }

        // ---------- money / HUD

        money(n) {
            try { return new Intl.NumberFormat(undefined, { style: 'currency', currency: this.currency, minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(n); }
            catch (e) { return (+n).toFixed(2) + ' ' + this.currency; }
        }
        betValue() { return this.bets[this.betIndex]; }
        stake() { return Math.round(this.lines * this.betValue() * this.denom * 10000) / 10000; }
        updateHud() {
            this.balEl.textContent = this.money(this.balance);
            this.betEl.textContent = this.money(this.stake());
            const lock = this.busy || this.fs.active || this.auto > 0;
            this.btnBetDown.disabled = lock || this.betIndex === 0;
            this.btnBetUp.disabled = lock || this.betIndex === this.bets.length - 1;
            this.btnAuto.classList.toggle('on', this.auto > 0);
            this.btnAuto.textContent = this.auto > 0 ? (this.auto === Infinity ? 'STOP ∞' : 'STOP ' + this.auto) : 'AUTO';
            this.btnAuto.disabled = this.fs.active;
            this.btnInfo.disabled = this.busy;
            this.btnSpin.classList.toggle('spinning', this.busy && !this.fs.active);
            this.btnSpin.disabled = this.fs.active;
            this.fsLeftEl.querySelector('b').textContent = this.fs.left;
            this.fsWinEl.querySelector('b').textContent = this.money(this.fs.won);
        }
        setWin(n) { this.winEl.textContent = this.money(n); }
        message(text) { this.msgEl.textContent = text || ''; }
        changeBet(d) {
            this.betIndex = clamp(this.betIndex + d, 0, this.bets.length - 1);
            store.set('bet_' + this.init.game.code, this.betValue());
            this.updateHud();
        }

        async rollWin(from, to, ms) {
            const o = { v: from };
            const loop = ms > 900 ? this.sound.play('coins', { loop: true, volume: 0.5 }) : null;
            const tick = () => { this.setWin(o.v); };
            const t = this.tw.to(o, { v: to }, ms, Ease.outQuad);
            const iv = setInterval(tick, 40);
            await t; clearInterval(iv); tick();
            if (loop) loop.stop();
        }

        // ---------- spin flow

        onSpinButton() {
            if (this.overlay) return;
            if (this.busy) {
                if (this.scene.quickStop && this.spinning) this.scene.quickStop();
                if (this.presenting) this.skipPresent = true;
                return;
            }
            if (this.fs.active) return;
            this.closeGambleOffer();
            this.spin();
        }

        async spin() {
            if (this.busy) return;
            const free = this.fs.active;
            const stake = this.stake();
            if (!free && this.balance + 1e-9 < stake) {
                this.message('Insufficient balance');
                this.toast('Insufficient balance — lower your bet.', true);
                this.stopAuto();
                return;
            }
            this.busy = true; this.spinning = true;
            this.closeGambleOffer();
            if (!free) { this.balance -= stake; this.setWin(0); }
            this.updateHud();
            this.message(free ? 'Free spin ' + (this.fs.total - this.fs.left + 1) + ' of ' + this.fs.total : 'Good luck!');
            this.sound.play('spin', { volume: 0.6 });

            let res;
            const request = this.api.call('bet', { bet: this.betValue(), lines: this.lines });
            try {
                if (this.mechanic === 'lines') {
                    this.scene.startSpin();
                    res = await request;
                    await this.scene.stopAt(res.reels);
                } else {
                    const out = this.scene.dropOut();
                    res = await request;
                    await out;
                }
            } catch (e) {
                this.spinning = false;
                if (this.mechanic === 'lines' && this.scene.cols.some((c) => c.state !== 'idle')) {
                    await this.scene.stopAt(this.scene.cols.map((c) => c.tape.slice(1, 1 + this.scene.rows)), { quick: true });
                } else if (this.mechanic === 'cascade') {
                    await this.scene.dropIn(this.scene.grid.map((col) => col.map((c) => c.sym)), []);
                }
                if (!free) this.balance += stake;
                if (e.data && e.data.balance !== undefined) this.balance = +e.data.balance;
                this.busy = false; this.updateHud();
                this.stopAuto();
                if (e.status === 403) return this.showError('Your game session has expired. Please reopen the game.');
                this.message('');
                this.toast(e.message, true);
                return;
            }
            this.spinning = false;
            await this.present(res, free);
        }

        async present(res, free) {
            this.presenting = true; this.skipPresent = false;
            const win = +res.win || 0;
            const stake = this.stake();

            if (this.mechanic === 'cascade') {
                await this.presentCascade(res);
            } else if (win > 0 || (res.free_spins_awarded && !free)) {
                const sc = res.scatter_cells && this.cfg.scatter !== null ? (res.scatter_cells[this.cfg.scatter] || []) : [];
                const scatterHit = res.lines.some((l) => l.line < 0) || (res.free_spins_awarded && !free);
                this.scene.presentWins(res.lines, scatterHit ? sc : []);
            }

            if (win > 0) {
                const ratio = win / stake;
                const base = free ? this.fs.won : 0;
                this.sound.play(ratio >= 10 ? 'win_big' : ratio >= 3 ? 'win_medium' : 'win_small', { volume: 0.8 });
                if (ratio >= 10) await this.bigWin(win, ratio);
                else if (this.mechanic === 'cascade') this.setWin(base + win);   // already counted up tumble by tumble
                else await this.rollWin(base, base + win, this.turbo ? 300 : clamp(400 + ratio * 220, 500, 2200));
                if (free) this.setWin(this.fs.won + win);
                this.message(this.winMessage(res));
            } else if (!free) {
                this.message(this.auto > 0 ? 'Autoplay' : 'Place your bet');
            }

            this.balance = +res.balance;
            if (free) { this.fs.won += win; this.fs.left = +res.free_spins_left || 0; }
            this.lastWin = win;
            this.presenting = false;
            this.busy = false;
            this.updateHud();

            // cycle individual line wins (line games) while idle
            if (this.mechanic === 'lines' && res.lines && res.lines.length > 1) this.cycleLines();

            // feature transitions
            if (res.free_spins_awarded && !free) {
                await sleep(this.turbo ? 200 : 700);
                await this.enterFreeSpins(+res.free_spins_awarded, false);
                return;
            }
            if (free && res.free_spins_awarded) {
                this.fs.total += +res.free_spins_awarded;
                this.sound.play('freespins', { volume: 0.7 });
                this.toast('+' + res.free_spins_awarded + ' FREE SPINS');
            }
            if (free) {
                this.updateHud();
                if (this.fs.left > 0) { await sleep(this.turbo ? 350 : 900); this.spin(); }
                else await this.exitFreeSpins();
                return;
            }

            if (win > 0 && this.cfg.has_gamble && this.auto === 0) this.offerGamble();

            if (this.auto > 0) {
                if (this.auto !== Infinity) this.auto--;
                this.updateHud();
                if (this.auto > 0) { await sleep(this.turbo ? 250 : 650); if (this.auto > 0 && !this.busy && !this.overlay) this.spin(); }
            }
        }

        winMessage(res) {
            const ws = res.lines || [];
            if (this.mechanic === 'cascade') {
                let m = 'You won ' + this.money(res.win);
                if (res.multiplier > 0) m += ' (x' + res.multiplier + ' multiplier)';
                return m;
            }
            if (ws.length === 1) {
                const w = ws[0], name = (this.meta.symbols || {})[w.symbol] || 'Symbol';
                return w.line >= 0 ? 'Line ' + (w.line + 1) + ': ' + w.count + ' × ' + name + ' pays ' + this.money(w.amount) : w.count + ' × ' + name + ' pays ' + this.money(w.amount);
            }
            return ws.length + ' wins — ' + this.money(res.win) + (res.multiplier > 1 ? ' (x' + res.multiplier + ')' : '');
        }

        async cycleLines() {
            const show = this.scene.show;
            if (!show) return;
            const id = (this.cycleId = (this.cycleId || 0) + 1);
            await sleep(1400);
            let i = 0;
            while (this.scene.show === show && this.cycleId === id && !this.busy) {
                show.index = i % show.wins.length;
                const w = show.wins[show.index], name = (this.meta.symbols || {})[w.symbol] || 'Symbol';
                this.message(w.line >= 0 ? 'Line ' + (w.line + 1) + ': ' + w.count + ' × ' + name + ' — ' + this.money(w.amount) : w.count + ' × ' + name + ' — ' + this.money(w.amount));
                await sleep(1300);
                i++;
            }
        }

        async presentCascade(res) {
            const steps = res.cascade || [];
            if (!steps.length) return;
            await this.scene.dropIn(steps[0].grid, steps[0].bombs);
            let acc = this.fs.active ? this.fs.won : 0;
            for (let s = 0; s < steps.length; s++) {
                const step = steps[s];
                if (!step.wins.length) break;
                await this.scene.explode(step.wins);
                acc += step.win;
                this.setWin(acc);
                this.message('Tumble win ' + this.money(step.win));
                const next = steps[s + 1];
                if (next) await this.scene.collapse(next.grid, next.bombs);
            }
            // scatters
            if ((res.scatters || 0) >= (this.fs.active ? this.cfg.cascade.retrigger : this.cfg.cascade.trigger)) {
                this.sound.play('scatter', { volume: 0.9 });
                (res.scatter_cells || []).forEach(([r, row]) => { const c = this.scene.grid[r][row]; if (c) c.flash = 1.6; });
                await sleep(this.turbo ? 400 : 1200);
            }
            // bombs multiply the tumble win
            if (res.multiplier > 0) {
                this.sound.play('bomb', { volume: 0.9 });
                this.scene.grid.forEach((col, r) => col.forEach((c, row) => {
                    if (c.bomb) { c.flash = 1.4; const [x, y] = this.scene.center(r, row); this.fx.burst(x, y, '#ffffff', 16); }
                }));
                this.toast('x' + res.multiplier + ' MULTIPLIER');
                await sleep(this.turbo ? 500 : 1400);
            }
        }

        async bigWin(win, ratio) {
            const title = ratio >= 50 ? 'EPIC WIN' : ratio >= 25 ? 'MEGA WIN' : 'BIG WIN';
            const ov = el('div', 'rs-ov clear');
            const box = el('div', 'rs-bigwin', '<div class="title">' + title + '</div><div class="amount">' + this.money(0) + '</div>');
            ov.appendChild(box); this.stage.appendChild(ov);
            const amt = box.querySelector('.amount');
            this.fx.fountain = this.turbo ? 1500 : 3500;
            const o = { v: 0 };
            let skip = false;
            box.onclick = () => { skip = true; };
            const loop = this.sound.play('coins', { loop: true, volume: 0.5 });
            const dur = this.turbo ? 1500 : clamp(2500 + ratio * 40, 3000, 7000);
            this.tw.to(o, { v: win }, dur, Ease.outCubic);
            const base = this.fs.active ? this.fs.won : 0;
            while (o.v < win && !skip && !this.skipPresent) { amt.textContent = this.money(o.v); this.setWin(base + o.v); await sleep(40); }
            this.tw.killOf(o);
            loop.stop();
            amt.textContent = this.money(win); this.setWin(base + win);
            await sleep(skip || this.skipPresent ? 600 : 1600);
            this.fx.fountain = 0;
            ov.remove();
        }

        // ---------- free spins

        async enterFreeSpins(count, resumed) {
            this.stopAuto();
            this.sound.play('freespins', { volume: 0.8 });
            const mult = this.mechanic === 'lines' ? Math.max(1, +this.cfg.free_spins_multiplier || 1) : 1;
            const text = '<h2>' + (resumed ? 'Welcome back!' : 'FREE SPINS') + '</h2>' +
                '<p class="big">' + count + '</p><p>' + (resumed ? 'free spins left — let\'s finish your feature.' : 'free spins awarded!') + '</p>' +
                (mult > 1 ? '<p>All wins are multiplied by <b style="color:var(--accent)">x' + mult + '</b></p>' : '') +
                (this.mechanic === 'cascade' ? '<p>Rainbow bombs multiply your tumble wins!</p>' : '');
            await this.modal(text, 'START');
            this.fs = { active: true, left: count, total: count, won: 0, mult };
            this.stage.classList.add('in-free');
            this.sound.playMusic('music_free');
            this.scene.clearWins && this.scene.clearWins();
            this.setWin(0);
            this.updateHud();
            await sleep(300);
            this.spin();
        }

        async exitFreeSpins() {
            const won = this.fs.won;
            await sleep(600);
            this.sound.play(won > 0 ? 'win_big' : 'win_small', { volume: 0.7 });
            if (won > 0) this.fx.fountain = 1800;
            await this.modal('<h2>FEATURE COMPLETE</h2><p>You won</p><p class="big">' + this.money(won) + '</p><p>in ' + this.fs.total + ' free spins</p>', 'COLLECT');
            this.fx.fountain = 0;
            this.fs = { active: false, left: 0, total: 0, won: 0, mult: 1 };
            this.stage.classList.remove('in-free');
            this.sound.playMusic('music');
            this.setWin(won);
            this.lastWin = won;
            this.updateHud();
            this.message(won > 0 ? 'Feature win ' + this.money(won) : 'Place your bet');
        }

        // ---------- overlays

        modal(html, cta) {
            return new Promise((ok) => {
                const ov = el('div', 'rs-ov');
                const p = el('div', 'rs-panel', html);
                const b = el('button', 'rs-cta', cta);
                let auto;
                const close = () => { clearTimeout(auto); ov.remove(); this.overlay = null; ok(); };
                b.onclick = () => { this.sound.play('click'); close(); };
                p.appendChild(b); ov.appendChild(p); this.stage.appendChild(ov);
                this.overlay = ov;
                auto = setTimeout(close, 8000);   // never strand a free-spin round
            });
        }

        toast(text, err) {
            const t = el('div', 'rs-toast' + (err ? ' err' : ''), text);
            this.stage.appendChild(t);
            setTimeout(() => t.remove(), err ? 2600 : 1600);
        }

        showError(msg) {
            const ov = el('div', 'rs-ov');
            ov.appendChild(el('div', 'rs-panel', '<h2>Oops</h2><p>' + msg + '</p>'));
            this.stage.appendChild(ov);
            this.overlay = ov;
        }

        openAuto() {
            if (this.busy || this.fs.active) return;
            const ov = el('div', 'rs-ov');
            const p = el('div', 'rs-panel rs-auto', '<h2>AUTOPLAY</h2><p>Number of spins</p>');
            const opts = el('div', 'opts');
            [10, 25, 50, 100, 250, Infinity].forEach((n) => {
                const b = el('button', null, n === Infinity ? '∞' : String(n));
                b.onclick = () => { this.sound.play('click'); ov.remove(); this.overlay = null; this.auto = n; this.updateHud(); this.spin(); };
                opts.appendChild(b);
            });
            p.appendChild(opts);
            p.appendChild(el('p', null, '<small style="color:#999">Autoplay stops when a feature is triggered or your balance is too low.</small>'));
            const x = this.iconBtn(ICON.close, () => { ov.remove(); this.overlay = null; });
            x.classList.add('rs-close'); p.appendChild(x);
            ov.appendChild(p); this.stage.appendChild(ov); this.overlay = ov;
        }

        stopAuto() { this.auto = 0; if (this.btnAuto) this.updateHud(); }

        // ---------- gamble (red / black)

        offerGamble() { this.btnGamble.classList.add('show'); }
        closeGambleOffer() { this.btnGamble.classList.remove('show'); }

        openGamble() {
            if (this.busy || this.lastWin <= 0) return;
            this.closeGambleOffer();
            this.scene.clearWins && this.scene.clearWins();
            const maxSteps = 5;
            let amount = this.lastWin, steps = 0, locked = false;
            const ov = el('div', 'rs-ov');
            const p = el('div', 'rs-panel rs-gamble',
                '<h2>GAMBLE</h2>' +
                '<div class="vals"><div><label>GAMBLE AMOUNT</label><b data-a>0</b></div><div><label>GAMBLE TO WIN</label><b data-w>0</b></div></div>' +
                '<div class="cards"><div class="rs-card back">♛</div></div>' +
                '<p style="color:#aaa;margin:0 0 6px">Previous cards</p><div class="rs-hist"></div>' +
                '<div class="row"><button class="red">RED</button><button class="collect">COLLECT</button><button class="black">BLACK</button></div>');
            ov.appendChild(p); this.stage.appendChild(ov); this.overlay = ov;
            const aEl = p.querySelector('[data-a]'), wEl = p.querySelector('[data-w]');
            const card = p.querySelector('.rs-card'), hist = p.querySelector('.rs-hist');
            const btns = p.querySelectorAll('.row button');
            const refresh = () => { aEl.textContent = this.money(amount); wEl.textContent = this.money(amount * 2); };
            refresh();
            const finish = async (delay) => {
                btns.forEach((b) => { b.disabled = true; });
                await sleep(delay);
                ov.remove(); this.overlay = null;
                this.lastWin = amount;
                this.setWin(amount);
                this.updateHud();
                this.message(amount > 0 ? 'You collected ' + this.money(amount) : 'Better luck next time!');
            };
            const guess = async (color) => {
                if (locked) return;
                locked = true;
                btns.forEach((b) => { b.disabled = true; });
                this.sound.play('card_flip');
                card.classList.add('flip');
                let res;
                try {
                    res = await this.api.call('gamble', { guess: color === 'red' ? 0 : 1 });
                } catch (e) {
                    card.classList.remove('flip');
                    this.toast(e.message, true);
                    return finish(800);
                }
                await sleep(220);
                const shown = res.won ? color : (color === 'red' ? 'black' : 'red');
                const suit = shown === 'red' ? pick(['♥', '♦']) : pick(['♠', '♣']);
                card.className = 'rs-card ' + shown;
                card.textContent = suit;
                const h = el('span', shown === 'black' ? 'blk' : 'rd', suit);
                hist.prepend(h); while (hist.children.length > 6) hist.lastChild.remove();
                this.balance = +res.balance;
                this.updateHud();
                if (res.won) {
                    this.sound.play('gamble_win');
                    amount = +res.amount;
                    steps++;
                    refresh();
                    this.setWin(amount);
                    if (steps >= maxSteps) return finish(1200);
                    await sleep(700);
                    card.className = 'rs-card back'; card.textContent = '♛';
                    locked = false;
                    btns.forEach((b) => { b.disabled = false; });
                } else {
                    this.sound.play('gamble_lose');
                    amount = 0; refresh();
                    finish(1300);
                }
            };
            p.querySelector('.red').onclick = () => guess('red');
            p.querySelector('.black').onclick = () => guess('black');
            p.querySelector('.collect').onclick = () => { this.sound.play('click'); finish(0); };
        }

        // ---------- paytable / rules

        openPaytable() {
            if (this.busy) return;
            const ov = el('div', 'rs-ov');
            const p = el('div', 'rs-panel rs-pay');
            const tabs = el('div', 'rs-tabs');
            const body = el('div', 'body');
            const pages = { PAYTABLE: () => this.paytableHtml(), FEATURES: () => this.featuresHtml() };
            if (this.mechanic === 'lines') pages.LINES = () => this.linesHtml();
            pages.RULES = () => this.rulesHtml();
            Object.keys(pages).forEach((name, i) => {
                const b = el('button', i === 0 ? 'on' : '', name);
                b.onclick = () => { tabs.querySelectorAll('button').forEach((x) => x.classList.remove('on')); b.classList.add('on'); body.innerHTML = pages[name](); };
                tabs.appendChild(b);
            });
            body.innerHTML = pages.PAYTABLE();
            const x = this.iconBtn(ICON.close, () => { ov.remove(); this.overlay = null; });
            x.classList.add('rs-close');
            p.append(tabs, body, x);
            ov.appendChild(p); this.stage.appendChild(ov); this.overlay = ov;
        }

        paytableHtml() {
            const cfg = this.cfg, names = this.meta.symbols || {};
            const unit = this.betValue() * this.denom, stake = this.stake();
            const order = cfg.symbols.slice().sort((a, b) => this.topPay(b) - this.topPay(a));
            let html = '<div class="grid">';
            for (const s of order) {
                const row = (cfg.paytable[s] || []).map(Number);
                let rows = '';
                let note = '';
                if (this.mechanic === 'cascade') {
                    const tiers = cfg.cascade.tiers;
                    if (s === cfg.scatter) {
                        Object.keys(cfg.cascade.scatter_pays).sort((a, b) => b - a).forEach((n) => { rows += '<tr><td>' + n + (+n === 6 ? '+' : '') + '</td><td>' + this.money(cfg.cascade.scatter_pays[n] * unit) + '</td></tr>'; });
                        note = cfg.cascade.trigger + '+ award ' + cfg.cascade.free_spins + ' free spins';
                    } else if (s === cfg.cascade.multiplier_symbol) {
                        note = 'Free spins only. x2 – x100 multiplier.';
                    } else {
                        for (let i = tiers.length - 1; i >= 0; i--) {
                            const label = i === tiers.length - 1 ? tiers[i] + '+' : tiers[i] + '-' + (tiers[i + 1] - 1);
                            if (row[i] > 0) rows += '<tr><td>' + label + '</td><td>' + this.money(row[i] * unit) + '</td></tr>';
                        }
                    }
                } else {
                    for (let n = row.length - 1; n >= 1; n--) {
                        if (row[n] > 0) rows += '<tr><td>' + n + '×</td><td>' + this.money(row[n] * (s === cfg.scatter ? stake : unit)) + '</td></tr>';
                    }
                    if (s === cfg.wild) note = 'WILD — substitutes for all symbols except scatter' + (cfg.wild_multiplier > 1 ? ', x' + cfg.wild_multiplier + ' on wins' : '') + '.';
                    if (s === cfg.scatter) note = 'SCATTER — pays anywhere' + (cfg.has_free_spins ? ', 3+ trigger free spins' : '') + '.';
                }
                html += '<div class="rs-sym"><img src="' + v('img/sym/' + s + '.svg') + '" alt=""><div><div class="n">' + (names[s] || 'Symbol') + '</div>' +
                    (rows ? '<table>' + rows + '</table>' : '') + (note ? '<div class="note">' + note + '</div>' : '') + '</div></div>';
            }
            return html + '</div><p style="color:#999;font-size:13px;margin-top:14px">Values shown for the current bet of ' + this.money(stake) + '.</p>';
        }

        topPay(s) {
            if (s === this.cfg.wild) return 1e9;
            if (s === this.cfg.scatter) return 1e8;
            if (this.cfg.cascade && s === this.cfg.cascade.multiplier_symbol) return 1e7;
            return Math.max.apply(null, (this.cfg.paytable[s] || [0]).map(Number));
        }

        featuresHtml() { return '<ul>' + (this.meta.features || []).map((f) => '<li>' + f + '</li>').join('') + '</ul>'; }

        linesHtml() {
            const rows = this.cfg.rows, reels = this.cfg.reels;
            let html = '<div class="rs-lines">';
            this.cfg.paylines.forEach((line, i) => {
                let cells = '';
                for (let r = 0; r < rows; r++) for (let c = 0; c < reels; c++) cells += '<i class="' + (line[c] === r ? 'on' : '') + '"></i>';
                html += '<div class="rs-line">LINE ' + (i + 1) + '<div class="g" style="grid-template-columns:repeat(' + reels + ',1fr)">' + cells + '</div></div>';
            });
            return html + '</div>';
        }

        rulesHtml() {
            const vol = { low: 'Low', medium: 'Medium', high: 'High' }[this.cfg.volatility] || '';
            const rules = [
                'Bet range: ' + this.money(this.lines * this.bets[0] * this.denom) + ' – ' + this.money(this.lines * this.bets[this.bets.length - 1] * this.denom) + ' per spin.',
                this.mechanic === 'lines' ? 'All ' + this.lines + ' lines are always active. Only the highest win per line is paid; line wins are added together.' : 'Wins are paid for symbol counts anywhere on the grid; tumble wins are added together.',
                'Scatter wins are added to line wins.',
                'Volatility: ' + vol + '.',
                'Malfunction voids all pays and plays.',
                'Press SPACE to spin. Press again while spinning to stop the reels quickly.',
            ];
            return '<ul>' + rules.map((r) => '<li>' + r + '</li>').join('') + '</ul><p style="color:#777;margin-top:20px">RoyalSpin Engine · build ' + BUILD + '</p>';
        }
    }

    // ------------------------------------------------------------------ start

    function start() {
        const root = document.getElementById('rs-root') || document.body.appendChild(el('div', null));
        root.id = 'rs-root';
        const game = new Game(root);
        window.RoyalSpin = game;   // handy for debugging
        game.boot().catch((e) => { console.error(e); game.fatal('Something went wrong: ' + e.message); });
    }

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start);
    else start();
})();
