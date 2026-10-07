// RoyalSpin — sound generator.
//
// Synthesises every sound effect (engine/snd/*.wav, shared by all games) and
// each game's music loops (games/<Code>/snd/music.wav + music_free.wav) from
// scratch — oscillators, envelopes, a little filtering and echo. No samples,
// no dependencies; deterministic output (seeded noise).
//
//   node resources/games/royalspin/tools/make-sounds.mjs

import { writeFileSync, mkdirSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = join(dirname(fileURLToPath(import.meta.url)), '..');
const SR = 22050;

// ---- primitives ------------------------------------------------------------

let seed = 12345;
const noise = () => { seed = (seed * 1103515245 + 12345) & 0x7fffffff; return seed / 0x3fffffff - 1; };
const midi = (n) => 440 * Math.pow(2, (n - 69) / 12);

const osc = {
    sine: (p) => Math.sin(2 * Math.PI * p),
    tri: (p) => 1 - 4 * Math.abs(Math.round(p - 0.25) - (p - 0.25)),
    saw: (p) => 2 * (p - Math.floor(p + 0.5)),
    square: (p) => (p % 1 < 0.5 ? 1 : -1),
    pulse: (p) => (p % 1 < 0.25 ? 1 : -1),
};

function buffer(seconds) { return new Float32Array(Math.ceil(seconds * SR)); }

/** ADSR-ish envelope value at time t for a note of length len. */
function env(t, len, a = 0.005, d = 0.08, s = 0.6, r = 0.08) {
    if (t < 0) return 0;
    if (t < a) return t / a;
    if (t < a + d) return 1 - (1 - s) * ((t - a) / d);
    if (t < len) return s;
    if (t < len + r) return s * (1 - (t - len) / r);
    return 0;
}

/**
 * Mix one tone into buf.
 * opts: wave, vol, a,d,s,r, slide (Hz/s pitch glide), vib (vibrato depth, semitones), detune (cents, adds 2nd voice)
 */
function tone(buf, start, len, freq, o = {}) {
    const { wave = 'sine', vol = 0.3, a = 0.005, d = 0.08, s = 0.6, r = 0.08, slide = 0, vib = 0, detune = 0, fm = 0, fmRatio = 2 } = o;
    const fn = osc[wave];
    const i0 = Math.floor(start * SR);
    const n = Math.ceil((len + r) * SR);
    let ph = 0, ph2 = 0, phm = 0;
    for (let i = 0; i < n && i0 + i < buf.length; i++) {
        const t = i / SR;
        let f = freq + slide * t;
        if (vib) f *= Math.pow(2, (vib * Math.sin(2 * Math.PI * 5.5 * t)) / 12);
        phm += (f * fmRatio) / SR;
        const fmv = fm ? fm * Math.sin(2 * Math.PI * phm) : 0;
        ph += (f * (1 + fmv)) / SR;
        let v = fn(ph % 1);
        if (detune) { ph2 += (f * Math.pow(2, detune / 1200)) / SR; v = 0.5 * (v + fn(ph2 % 1)); }
        if (i0 + i >= 0) buf[i0 + i] += v * vol * env(t, len, a, d, s, r);
    }
}

function noiseBurst(buf, start, len, o = {}) {
    const { vol = 0.3, a = 0.002, d = 0.05, s = 0.3, r = 0.05, lp = 0.5 } = o;
    const i0 = Math.floor(start * SR);
    const n = Math.ceil((len + r) * SR);
    let y = 0;
    for (let i = 0; i < n && i0 + i < buf.length; i++) {
        const t = i / SR;
        y += lp * (noise() - y);   // one-pole low-pass, lp in (0,1]
        buf[i0 + i] += y * vol * env(t, len, a, d, s, r);
    }
}

function lowpass(buf, k) { let y = 0; for (let i = 0; i < buf.length; i++) { y += k * (buf[i] - y); buf[i] = y; } return buf; }

function echo(buf, delay, fb, mix) {
    const dn = Math.floor(delay * SR);
    const out = Float32Array.from(buf);
    for (let i = dn; i < out.length; i++) out[i] += out[i - dn] * fb;
    for (let i = 0; i < buf.length; i++) buf[i] = buf[i] * (1 - mix) + out[i] * mix;
    return buf;
}

/** Wrap-around echo for seamless music loops (tail feeds back into the start). */
function loopEcho(buf, delay, fb, mix) {
    const dn = Math.floor(delay * SR), L = buf.length;
    const wet = new Float32Array(L);
    for (let pass = 0; pass < 2; pass++) {
        for (let i = 0; i < L; i++) wet[i] = buf[i] + wet[(i - dn + L) % L] * fb;
    }
    for (let i = 0; i < L; i++) buf[i] = buf[i] * (1 - mix) + wet[i] * mix;
    return buf;
}

function normalize(buf, peak = 0.89) {
    let m = 0;
    for (const v of buf) m = Math.max(m, Math.abs(v));
    if (m > 0) for (let i = 0; i < buf.length; i++) buf[i] = (buf[i] / m) * peak;
    return buf;
}

function fadeEdges(buf, ms = 4) {
    const n = Math.floor((ms / 1000) * SR);
    for (let i = 0; i < n && i < buf.length; i++) { buf[i] *= i / n; buf[buf.length - 1 - i] *= i / n; }
    return buf;
}

function wav(file, buf, peak = 0.89, fade = true) {
    normalize(buf, peak);
    if (fade) fadeEdges(buf);
    const data = Buffer.alloc(44 + buf.length * 2);
    data.write('RIFF', 0); data.writeUInt32LE(36 + buf.length * 2, 4); data.write('WAVE', 8);
    data.write('fmt ', 12); data.writeUInt32LE(16, 16); data.writeUInt16LE(1, 20); data.writeUInt16LE(1, 22);
    data.writeUInt32LE(SR, 24); data.writeUInt32LE(SR * 2, 28); data.writeUInt16LE(2, 32); data.writeUInt16LE(16, 34);
    data.write('data', 36); data.writeUInt32LE(buf.length * 2, 40);
    for (let i = 0; i < buf.length; i++) data.writeInt16LE(Math.max(-32767, Math.min(32767, Math.round(buf[i] * 32767))), 44 + i * 2);
    mkdirSync(dirname(file), { recursive: true });
    writeFileSync(file, data);
    console.log('wrote', file.replace(ROOT, ''), (data.length / 1024).toFixed(0) + 'KB');
}

// ---- sound effects (engine/snd) ---------------------------------------------

const SFX = join(ROOT, 'engine', 'snd');

{ // click — short UI tick
    const b = buffer(0.08);
    tone(b, 0, 0.02, 1800, { wave: 'sine', vol: 0.5, d: 0.02, s: 0, r: 0.03 });
    noiseBurst(b, 0, 0.01, { vol: 0.15, lp: 0.9, r: 0.01 });
    wav(join(SFX, 'click.wav'), b, 0.6);
}
{ // spin — rising whoosh + mechanical start
    const b = buffer(0.45);
    noiseBurst(b, 0, 0.3, { vol: 0.5, a: 0.05, d: 0.2, s: 0.4, r: 0.12, lp: 0.12 });
    tone(b, 0, 0.3, 180, { wave: 'saw', vol: 0.12, slide: 500, a: 0.03, r: 0.1 });
    lowpass(b, 0.35);
    wav(join(SFX, 'spin.wav'), b, 0.7);
}
{ // reel_stop — wooden thud
    const b = buffer(0.18);
    tone(b, 0, 0.03, 110, { wave: 'sine', vol: 0.8, slide: -600, d: 0.06, s: 0, r: 0.08 });
    noiseBurst(b, 0, 0.02, { vol: 0.35, lp: 0.3, d: 0.03, s: 0, r: 0.03 });
    tone(b, 0, 0.02, 900, { wave: 'tri', vol: 0.12, d: 0.03, s: 0, r: 0.03 });
    wav(join(SFX, 'reel_stop.wav'), b, 0.75);
}
{ // scatter — bright bell chime
    const b = buffer(1.2);
    [84, 88, 91, 96].forEach((n, i) => tone(b, i * 0.06, 0.05, midi(n), { wave: 'sine', vol: 0.35, fm: 0.8, fmRatio: 3.5, d: 0.3, s: 0.1, r: 0.6 }));
    echo(b, 0.11, 0.35, 0.4);
    wav(join(SFX, 'scatter.wav'), b);
}
{ // win_small — quick happy arpeggio
    const b = buffer(0.7);
    [72, 76, 79, 84].forEach((n, i) => tone(b, i * 0.07, 0.08, midi(n), { wave: 'square', vol: 0.18, d: 0.05, s: 0.4, r: 0.15 }));
    lowpass(b, 0.5);
    echo(b, 0.09, 0.3, 0.3);
    wav(join(SFX, 'win_small.wav'), b, 0.75);
}
{ // win_medium — arpeggio + sparkle
    const b = buffer(1.4);
    [67, 72, 76, 79, 84, 88].forEach((n, i) => tone(b, i * 0.08, 0.1, midi(n), { wave: 'square', vol: 0.16, d: 0.05, s: 0.5, r: 0.2 }));
    [96, 100, 103].forEach((n, i) => tone(b, 0.5 + i * 0.09, 0.05, midi(n), { wave: 'sine', vol: 0.18, fm: 1, fmRatio: 4, d: 0.2, s: 0, r: 0.3 }));
    tone(b, 0.48, 0.5, midi(48), { wave: 'tri', vol: 0.3, r: 0.3 });
    lowpass(b, 0.55);
    echo(b, 0.12, 0.3, 0.3);
    wav(join(SFX, 'win_medium.wav'), b, 0.8);
}
{ // win_big — brass fanfare
    const b = buffer(3.2);
    const brass = { wave: 'saw', vol: 0.14, a: 0.03, d: 0.1, s: 0.8, r: 0.25, detune: 8, vib: 0.08 };
    const seq = [[0, 0.15, [60, 64, 67]], [0.18, 0.15, [60, 64, 67]], [0.36, 0.15, [60, 64, 67]], [0.56, 0.5, [65, 69, 72]], [1.1, 0.25, [67, 71, 74]], [1.4, 1.2, [72, 76, 79, 84]]];
    for (const [t, l, notes] of seq) notes.forEach((n) => tone(b, t, l, midi(n), brass));
    [36, 36, 36, 41, 43, 48].forEach((n, i) => tone(b, seq[i][0], seq[i][1], midi(n), { wave: 'tri', vol: 0.4, r: 0.2 }));
    for (let i = 0; i < 6; i++) noiseBurst(b, seq[i][0], 0.03, { vol: 0.25, lp: 0.6, d: 0.08, s: 0, r: 0.08 });
    for (let i = 0; i < 12; i++) tone(b, 1.5 + i * 0.1, 0.04, midi(96 + ((i * 5) % 12)), { wave: 'sine', vol: 0.08, fm: 1, fmRatio: 4, d: 0.15, s: 0, r: 0.2 });
    lowpass(b, 0.45);
    echo(b, 0.15, 0.3, 0.3);
    wav(join(SFX, 'win_big.wav'), b, 0.85);
}
{ // coins — rollup jingle (loopable-ish)
    const b = buffer(1.0);
    for (let i = 0; i < 16; i++) {
        const t = i * 0.0625;
        tone(b, t, 0.02, midi(91 + ((i * 7) % 9)), { wave: 'sine', vol: 0.25, fm: 2.2, fmRatio: 3.7, d: 0.06, s: 0, r: 0.06 });
    }
    wav(join(SFX, 'coins.wav'), b, 0.55, false);
}
{ // freespins — feature fanfare with shimmer
    const b = buffer(2.6);
    [60, 64, 67, 72, 76, 79, 84].forEach((n, i) => tone(b, i * 0.09, 0.12, midi(n), { wave: 'saw', vol: 0.1, detune: 10, d: 0.05, s: 0.6, r: 0.2 }));
    [72, 76, 79, 84].forEach((n) => tone(b, 0.7, 1.2, midi(n), { wave: 'saw', vol: 0.09, a: 0.05, detune: 12, vib: 0.1, r: 0.5 }));
    tone(b, 0.7, 1.2, midi(36), { wave: 'tri', vol: 0.35, r: 0.5 });
    for (let i = 0; i < 20; i++) tone(b, 0.75 + i * 0.06, 0.03, midi(96 + ((i * 3) % 10)), { wave: 'sine', vol: 0.07, d: 0.1, s: 0, r: 0.1 });
    lowpass(b, 0.5);
    echo(b, 0.14, 0.35, 0.35);
    wav(join(SFX, 'freespins.wav'), b, 0.85);
}
{ // card_flip
    const b = buffer(0.15);
    noiseBurst(b, 0, 0.06, { vol: 0.5, lp: 0.7, a: 0.01, d: 0.05, s: 0.2, r: 0.04 });
    wav(join(SFX, 'card_flip.wav'), b, 0.6);
}
{ // gamble_win
    const b = buffer(0.6);
    [76, 83, 88].forEach((n, i) => tone(b, i * 0.08, 0.1, midi(n), { wave: 'square', vol: 0.18, d: 0.05, s: 0.5, r: 0.2 }));
    lowpass(b, 0.55);
    wav(join(SFX, 'gamble_win.wav'), b, 0.75);
}
{ // gamble_lose — descending buzz
    const b = buffer(0.7);
    [64, 60, 55].forEach((n, i) => tone(b, i * 0.14, 0.13, midi(n), { wave: 'saw', vol: 0.18, d: 0.05, s: 0.6, r: 0.15, slide: -20 }));
    lowpass(b, 0.3);
    wav(join(SFX, 'gamble_lose.wav'), b, 0.7);
}
{ // pop — cascade symbol burst
    const b = buffer(0.25);
    tone(b, 0, 0.03, 600, { wave: 'sine', vol: 0.6, slide: 2400, d: 0.05, s: 0, r: 0.06 });
    noiseBurst(b, 0, 0.04, { vol: 0.3, lp: 0.8, d: 0.06, s: 0, r: 0.08 });
    tone(b, 0.03, 0.05, 2200, { wave: 'sine', vol: 0.2, fm: 1.5, fmRatio: 2.7, d: 0.1, s: 0, r: 0.1 });
    wav(join(SFX, 'pop.wav'), b, 0.7);
}
{ // drop — soft landing thump
    const b = buffer(0.15);
    tone(b, 0, 0.03, 220, { wave: 'sine', vol: 0.7, slide: -900, d: 0.05, s: 0, r: 0.06 });
    noiseBurst(b, 0, 0.015, { vol: 0.15, lp: 0.4, r: 0.02 });
    wav(join(SFX, 'drop.wav'), b, 0.55);
}
{ // bomb — multiplier boom + sparkle
    const b = buffer(1.0);
    tone(b, 0, 0.1, 90, { wave: 'sine', vol: 0.8, slide: -200, d: 0.2, s: 0.2, r: 0.3 });
    noiseBurst(b, 0, 0.12, { vol: 0.5, lp: 0.25, d: 0.15, s: 0.2, r: 0.3 });
    [88, 92, 95, 100].forEach((n, i) => tone(b, 0.15 + i * 0.05, 0.04, midi(n), { wave: 'sine', vol: 0.2, fm: 1, fmRatio: 3, d: 0.15, s: 0, r: 0.2 }));
    wav(join(SFX, 'bomb.wav'), b, 0.85);
}

// ---- music loops (games/<Code>/snd) ------------------------------------------

/**
 * A simple loop sequencer: chords per bar, a bass line, an arpeggio and a
 * melody on top, plus kick/hat. Everything is generated from a scale + chord
 * list so each game gets its own mood.
 */
function music(file, o) {
    const { bpm, bars = 8, chords, scale, root, mel, arpWave = 'tri', padWave = 'saw', leadWave = 'square', swing = 0, drums = true, bright = 0.35 } = o;
    const beat = 60 / bpm;
    const barLen = beat * 4;
    const b = buffer(bars * barLen);

    for (let bar = 0; bar < bars; bar++) {
        const chord = chords[bar % chords.length];   // scale degrees
        const t0 = bar * barLen;
        const notes = chord.map((d) => root + scale[d % scale.length] + 12 * Math.floor(d / scale.length));
        // pad
        notes.forEach((n) => tone(b, t0, barLen * 0.95, midi(n), { wave: padWave, vol: 0.045, a: 0.2, d: 0.3, s: 0.8, r: 0.15, detune: 9 }));
        // bass: root on 1 and 3, fifth on the "and" of 4
        tone(b, t0, beat * 0.9, midi(notes[0] - 24), { wave: 'tri', vol: 0.32, d: 0.1, s: 0.7, r: 0.05 });
        tone(b, t0 + 2 * beat, beat * 0.9, midi(notes[0] - 24), { wave: 'tri', vol: 0.32, d: 0.1, s: 0.7, r: 0.05 });
        tone(b, t0 + 3.5 * beat, beat * 0.45, midi(notes[2 % notes.length] - 24), { wave: 'tri', vol: 0.25, r: 0.05 });
        // arpeggio 8ths
        for (let k = 0; k < 8; k++) {
            const tt = t0 + k * beat / 2 + (k % 2 ? swing * beat : 0);
            tone(b, tt, beat * 0.3, midi(notes[k % notes.length] + 12), { wave: arpWave, vol: 0.06, d: 0.08, s: 0.3, r: 0.08 });
        }
        // drums
        if (drums) {
            for (let k = 0; k < 4; k++) {
                tone(b, t0 + k * beat, 0.05, 120, { wave: 'sine', vol: k % 2 ? 0.12 : 0.3, slide: -700, d: 0.08, s: 0, r: 0.06 });
                noiseBurst(b, t0 + k * beat + beat / 2 + swing * beat, 0.01, { vol: 0.06, lp: 0.95, d: 0.03, s: 0, r: 0.02 });
            }
            noiseBurst(b, t0 + beat, 0.04, { vol: 0.08, lp: 0.6, d: 0.06, s: 0, r: 0.06 });
            noiseBurst(b, t0 + 3 * beat, 0.04, { vol: 0.08, lp: 0.6, d: 0.06, s: 0, r: 0.06 });
        }
    }
    // melody: [bar, beatOffset, lengthBeats, scaleDegree] (degree may be negative)
    for (const [bar, off, len, deg] of mel) {
        const n = root + 12 + scale[((deg % scale.length) + scale.length) % scale.length] + 12 * Math.floor(deg / scale.length);
        tone(b, bar * barLen + off * beat, len * beat * 0.9, midi(n), { wave: leadWave, vol: 0.075, a: 0.01, d: 0.1, s: 0.6, r: 0.1, vib: 0.12 });
    }
    lowpass(b, bright);
    loopEcho(b, beat * 0.75, 0.3, 0.25);
    wav(file, b, 0.7, false);
}

const MAJOR = [0, 2, 4, 5, 7, 9, 11];
const MINOR = [0, 2, 3, 5, 7, 8, 10];
const HIJAZ = [0, 1, 4, 5, 7, 8, 10];
const LYDIAN = [0, 2, 4, 6, 7, 9, 11];

const G = (code) => join(ROOT, 'games', code, 'snd');

// melody helpers
const phrase = (bars, pattern) => bars.flatMap((bar, i) => pattern[i % pattern.length].map(([o, l, d]) => [bar, o, l, d]));

music(join(G('RoyalSevensRS'), 'music.wav'), {
    bpm: 128, root: 60, scale: MAJOR, chords: [[0, 2, 4], [3, 5, 7], [4, 6, 8], [0, 2, 4], [5, 7, 9], [3, 5, 7], [4, 6, 8], [4, 6, 8]], swing: 0.08, leadWave: 'pulse', bright: 0.4,
    mel: phrase([0, 1, 2, 3, 4, 5, 6, 7], [
        [[0, 0.5, 4], [0.5, 0.5, 4], [1, 1, 5], [2, 1, 4], [3, 1, 2]], [[0, 1, 3], [1, 1, 5], [2, 2, 7]],
        [[0, 0.5, 6], [0.5, 0.5, 6], [1, 1, 5], [2, 1, 4], [3, 1, 3]], [[0, 2, 2], [2, 2, 0]],
    ]),
});
music(join(G('RoyalSevensRS'), 'music_free.wav'), {
    bpm: 140, root: 62, scale: MAJOR, chords: [[0, 2, 4], [4, 6, 8], [5, 7, 9], [3, 5, 7]], swing: 0.05, leadWave: 'pulse', bright: 0.45,
    mel: phrase([0, 1, 2, 3, 4, 5, 6, 7], [[[0, 0.5, 7], [0.5, 0.5, 9], [1, 0.5, 11], [1.5, 0.5, 9], [2, 2, 7]], [[0, 1, 8], [1, 1, 6], [2, 2, 4]]]),
});

music(join(G('CrownJewelsRS'), 'music.wav'), {
    bpm: 96, root: 62, scale: LYDIAN, chords: [[0, 2, 4, 6], [1, 3, 5], [0, 2, 4, 6], [5, 7, 9], [3, 5, 7], [1, 3, 5], [4, 6, 8], [4, 6, 8]], arpWave: 'sine', leadWave: 'sine', drums: true, bright: 0.3,
    mel: phrase([0, 1, 2, 3, 4, 5, 6, 7], [[[0, 1.5, 4], [1.5, 0.5, 3], [2, 2, 2]], [[0, 1, 1], [1, 1, 3], [2, 2, 5]], [[0, 1.5, 6], [1.5, 0.5, 5], [2, 2, 4]], [[0, 3, 2], [3, 1, 4]]]),
});
music(join(G('CrownJewelsRS'), 'music_free.wav'), {
    bpm: 120, root: 64, scale: LYDIAN, chords: [[0, 2, 4, 6], [5, 7, 9], [3, 5, 7], [4, 6, 8]], arpWave: 'sine', leadWave: 'tri', bright: 0.38,
    mel: phrase([0, 1, 2, 3, 4, 5, 6, 7], [[[0, 0.5, 7], [0.5, 0.5, 6], [1, 0.5, 4], [1.5, 0.5, 6], [2, 2, 7]], [[0, 1, 9], [1, 1, 8], [2, 2, 6]]]),
});

music(join(G('PharaohsRichesRS'), 'music.wav'), {
    bpm: 100, root: 57, scale: HIJAZ, chords: [[0, 2, 4], [0, 2, 4], [1, 3, 5], [0, 2, 4], [6, 8, 10], [5, 7, 9], [1, 3, 5], [0, 2, 4]], arpWave: 'tri', padWave: 'saw', leadWave: 'saw', bright: 0.28,
    mel: phrase([0, 1, 2, 3, 4, 5, 6, 7], [[[0, 0.5, 4], [0.5, 0.5, 5], [1, 0.5, 4], [1.5, 0.5, 2], [2, 2, 1]], [[0, 1, 0], [1, 1, 1], [2, 2, 2]], [[0, 0.5, 4], [0.5, 0.5, 5], [1, 1, 7], [2, 1, 5], [3, 1, 4]], [[0, 3, 1], [3, 1, 0]]]),
});
music(join(G('PharaohsRichesRS'), 'music_free.wav'), {
    bpm: 124, root: 57, scale: HIJAZ, chords: [[0, 2, 4], [1, 3, 5], [6, 8, 10], [0, 2, 4]], arpWave: 'tri', leadWave: 'saw', bright: 0.32,
    mel: phrase([0, 1, 2, 3, 4, 5, 6, 7], [[[0, 0.5, 7], [0.5, 0.5, 8], [1, 0.5, 7], [1.5, 0.5, 5], [2, 1, 4], [3, 1, 5]], [[0, 2, 1], [2, 2, 0]]]),
});

music(join(G('CandyRoyaleRS'), 'music.wav'), {
    bpm: 118, root: 65, scale: MAJOR, chords: [[0, 2, 4], [5, 7, 9], [3, 5, 7], [4, 6, 8]], arpWave: 'sine', padWave: 'tri', leadWave: 'tri', swing: 0.1, bright: 0.45,
    mel: phrase([0, 1, 2, 3, 4, 5, 6, 7], [[[0, 0.5, 4], [0.5, 0.5, 7], [1, 0.5, 9], [1.5, 0.5, 7], [2, 1, 4], [3, 1, 2]], [[0, 0.5, 5], [0.5, 0.5, 4], [1, 1, 2], [2, 2, 0]], [[0, 0.5, 9], [0.5, 0.5, 11], [1, 1, 9], [2, 1, 7], [3, 1, 5]], [[0, 1, 4], [1, 1, 2], [2, 2, 1]]]),
});
music(join(G('CandyRoyaleRS'), 'music_free.wav'), {
    bpm: 132, root: 67, scale: MAJOR, chords: [[0, 2, 4], [3, 5, 7], [4, 6, 8], [0, 2, 4]], arpWave: 'sine', padWave: 'tri', leadWave: 'sine', swing: 0.1, bright: 0.5,
    mel: phrase([0, 1, 2, 3, 4, 5, 6, 7], [[[0, 0.5, 7], [0.5, 0.5, 9], [1, 0.5, 11], [1.5, 0.5, 14], [2, 2, 11]], [[0, 1, 10], [1, 1, 8], [2, 2, 7]]]),
});

// ---- Olympus pack + its effects (appended last so the noise seed of every
// sound above stays put and their files are byte-identical) -----------------

{ // thunder — sharp crack, then a long rolling rumble (multiplier strikes)
    const b = buffer(2.2);
    noiseBurst(b, 0, 0.05, { vol: 0.9, lp: 0.9, d: 0.06, s: 0.1, r: 0.1 });
    noiseBurst(b, 0.04, 1.4, { vol: 0.7, a: 0.05, d: 0.5, s: 0.5, r: 0.6, lp: 0.05 });
    tone(b, 0, 1.2, 60, { wave: 'sine', vol: 0.5, slide: -25, d: 0.6, s: 0.4, r: 0.5 });
    echo(b, 0.23, 0.35, 0.35);
    wav(join(SFX, 'thunder.wav'), b, 0.85);
}

const P = (pack) => join(ROOT, 'packs', pack, 'snd');
const DORIAN = [0, 2, 3, 5, 7, 9, 10];

music(join(P('olympus'), 'music.wav'), {
    bpm: 92, root: 57, scale: DORIAN, chords: [[0, 2, 4], [6, 8, 10], [3, 5, 7], [4, 6, 8], [0, 2, 4], [5, 7, 9], [3, 5, 7], [4, 6, 8]], arpWave: 'tri', padWave: 'saw', leadWave: 'saw', bright: 0.3,
    mel: phrase([0, 1, 2, 3, 4, 5, 6, 7], [[[0, 1.5, 4], [1.5, 0.5, 5], [2, 2, 7]], [[0, 1, 6], [1, 1, 5], [2, 2, 4]], [[0, 1.5, 2], [1.5, 0.5, 3], [2, 2, 4]], [[0, 3, 1], [3, 1, 0]]]),
});
music(join(P('olympus'), 'music_free.wav'), {
    bpm: 116, root: 57, scale: DORIAN, chords: [[0, 2, 4], [3, 5, 7], [6, 8, 10], [4, 6, 8]], arpWave: 'tri', leadWave: 'saw', bright: 0.36,
    mel: phrase([0, 1, 2, 3, 4, 5, 6, 7], [[[0, 0.5, 7], [0.5, 0.5, 8], [1, 0.5, 9], [1.5, 0.5, 8], [2, 2, 7]], [[0, 1, 4], [1, 1, 6], [2, 2, 5]]]),
});
