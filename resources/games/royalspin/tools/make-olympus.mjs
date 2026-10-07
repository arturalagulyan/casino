// RoyalSpin — "Olympus" art pack generator.
//
// Original vector art for the Greek-myth scatter-pays look (gems, chalice,
// ring, hourglass, crown, the thunder-god scatter, multiplier orbs, a sunset
// temple + a storm background, the god character standing beside the grid,
// and the logo). Writes resources/games/royalspin/packs/olympus/ — an art
// pack the admin Game Builder (and any game) can use. Deterministic output.
//
//   node resources/games/royalspin/tools/make-olympus.mjs

import { writeFileSync, mkdirSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const OUT = join(dirname(fileURLToPath(import.meta.url)), '..', 'packs', 'olympus');

let seed = 4242;
const rnd = () => { seed = (seed * 16807) % 2147483647; return (seed - 1) / 2147483646; };

// ---- svg helpers ------------------------------------------------------------

const svg = (w, h, body, defs = '') =>
    `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ${w} ${h}" width="${w}" height="${h}">` +
    (defs ? `<defs>${defs}</defs>` : '') + body + '</svg>\n';
const stops = (list) => list.map((c, i) => {
    const [color, off] = Array.isArray(c) ? c : [c, i / Math.max(1, list.length - 1)];
    return `<stop offset="${off}" stop-color="${color}"/>`;
}).join('');
const lin = (id, list, x1 = 0, y1 = 0, x2 = 0, y2 = 1) => `<linearGradient id="${id}" x1="${x1}" y1="${y1}" x2="${x2}" y2="${y2}">${stops(list)}</linearGradient>`;
const rad = (id, list, cx = 0.5, cy = 0.5, r = 0.5, fx = cx, fy = cy) => `<radialGradient id="${id}" cx="${cx}" cy="${cy}" r="${r}" fx="${fx}" fy="${fy}">${stops(list)}</radialGradient>`;
const shadow = (id, dy = 5, blur = 4, op = 0.55) => `<filter id="${id}" x="-30%" y="-30%" width="160%" height="160%"><feDropShadow dx="0" dy="${dy}" stdDeviation="${blur}" flood-color="#000" flood-opacity="${op}"/></filter>`;
const glow = (id, color, blur = 6) => `<filter id="${id}" x="-50%" y="-50%" width="200%" height="200%"><feGaussianBlur stdDeviation="${blur}" result="b"/><feFlood flood-color="${color}"/><feComposite in2="b" operator="in" result="g"/><feMerge><feMergeNode in="g"/><feMergeNode in="SourceGraphic"/></feMerge></filter>`;
const pts = (arr) => arr.map(([x, y]) => `${x.toFixed(1)},${y.toFixed(1)}`).join(' ');
const poly = (n, cx, cy, rx, ry, rot = -90) => Array.from({ length: n }, (_, i) => {
    const a = ((rot + (360 / n) * i) * Math.PI) / 180;
    return [cx + rx * Math.cos(a), cy + ry * Math.sin(a)];
});
const sparkle = (x, y, s, color = '#fff', op = 0.95) =>
    `<path d="M${x} ${y - s}Q${x + s * 0.15} ${y - s * 0.15} ${x + s} ${y}Q${x + s * 0.15} ${y + s * 0.15} ${x} ${y + s}Q${x - s * 0.15} ${y + s * 0.15} ${x - s} ${y}Q${x - s * 0.15} ${y - s * 0.15} ${x} ${y - s}Z" fill="${color}" opacity="${op}"/>`;
const GOLD = ['#fffbe0', '#ffe27a', '#e0a82e', '#a8660a', '#ffd76a'];
const gold = (id, dir = [0, 0, 0, 1]) => lin(id, GOLD, ...dir);
const sym = (p, body, defs = '') => svg(200, 200, body, shadow(p + 'sh', 6, 4, 0.6) + gold(p + 'au') + defs);

// ---- gems: faceted stone inside a gold bezel ---------------------------------

/** Shrink polygon `outer` toward (cx, cy) by factor k. */
const shrink = (outer, cx, cy, k) => outer.map(([x, y]) => [cx + (x - cx) * k, cy + (y - cy) * k]);

function gem(p, outer, cx, cy, light, mid, dark) {
    const stone = shrink(outer, cx, cy, 0.84);
    const table = shrink(outer, cx, cy, 0.42);
    let facets = '';
    for (let i = 0; i < stone.length; i++) {
        const j = (i + 1) % stone.length;
        facets += `<polygon points="${pts([stone[i], stone[j], table[j], table[i]])}" fill="url(#${p}${i % 2 ? 'd' : 'l'})"/>`;
        facets += `<line x1="${stone[i][0].toFixed(1)}" y1="${stone[i][1].toFixed(1)}" x2="${table[i][0].toFixed(1)}" y2="${table[i][1].toFixed(1)}" stroke="#fff" stroke-opacity=".4" stroke-width="1.4"/>`;
    }
    const defs = lin(p + 'l', [light, mid], 0, 0, 1, 1) + lin(p + 'd', [mid, dark], 0, 0, 1, 1) +
        rad(p + 't', [['#fff', 0], [light, 0.4], [mid, 1]], 0.35, 0.3, 0.85);
    const body = `<g filter="url(#${p}sh)">` +
        `<polygon points="${pts(outer)}" fill="url(#${p}au)" stroke="#6a3a00" stroke-width="3" stroke-linejoin="round"/>` +
        `<polygon points="${pts(shrink(outer, cx, cy, 0.9))}" fill="#5a2a00" opacity=".55"/>` +
        `<polygon points="${pts(stone)}" fill="${dark}"/>${facets}` +
        `<polygon points="${pts(table)}" fill="url(#${p}t)" stroke="#fff" stroke-opacity=".55" stroke-width="1.5"/>` +
        `</g>` + sparkle(cx - 22, cy - 26, 14) + sparkle(cx + 30, cy + 18, 7, '#fff', 0.7);
    return { defs, body };
}

const gemGreen = (p) => gem(p, [[100, 18], [182, 168], [18, 168]], 100, 118, '#d6ffb8', '#2ccf3a', '#055a12');
const gemBlue = (p) => gem(p, [[100, 12], [186, 100], [100, 188], [14, 100]], 100, 100, '#d2f4ff', '#18a8f0', '#063c78');
const gemPurple = (p) => gem(p, [[18, 34], [182, 34], [100, 184]], 100, 84, '#f6d2ff', '#b030e0', '#40065a');
const gemYellow = (p) => gem(p, poly(6, 100, 100, 88, 84, 0), 100, 100, '#fffbd0', '#ffc81a', '#8a5000');
const gemRed = (p) => gem(p, poly(8, 100, 100, 84, 84, -67.5), 100, 100, '#ffd2d6', '#e8183a', '#5c0010');

// ---- high symbols --------------------------------------------------------------

function chalice(p) {
    const defs = lin(p + 'cup', ['#fff6c8', '#f5c842', '#b07810', '#6a3a00'], 0, 0, 1, 0) + rad(p + 'wine', ['#ff4a6a', '#a0001a', '#40000a'], 0.5, 0.3, 0.7);
    const body = `<g filter="url(#${p}sh)">` +
        `<path d="M40 30h120c0 52-22 84-60 88-38-4-60-36-60-88z" fill="url(#${p}cup)" stroke="#6a3a00" stroke-width="3"/>` +
        `<ellipse cx="100" cy="32" rx="60" ry="12" fill="url(#${p}wine)" stroke="#6a3a00" stroke-width="3"/>` +
        `<path d="M92 116h16l4 34h-24z" fill="url(#${p}au)" stroke="#6a3a00" stroke-width="3"/>` +
        `<ellipse cx="100" cy="124" rx="16" ry="7" fill="url(#${p}au)" stroke="#6a3a00" stroke-width="2.5"/>` +
        `<path d="M54 186c4-22 24-36 46-36s42 14 46 36z" fill="url(#${p}cup)" stroke="#6a3a00" stroke-width="3"/>` +
        `<path d="M50 54c14 6 86 6 100 0" stroke="#6a3a00" stroke-width="3" fill="none"/>` +
        `<circle cx="100" cy="76" r="13" fill="#e8183a" stroke="#ffe27a" stroke-width="4"/><circle cx="96" cy="72" r="4" fill="#fff" opacity=".8"/>` +
        `<circle cx="66" cy="70" r="7" fill="#18a8f0" stroke="#ffe27a" stroke-width="3"/><circle cx="134" cy="70" r="7" fill="#18a8f0" stroke="#ffe27a" stroke-width="3"/>` +
        `<path d="M54 40c6 30 18 50 34 62" stroke="#fff" stroke-opacity=".55" stroke-width="7" stroke-linecap="round" fill="none"/>` +
        `</g>` + sparkle(150, 40, 12);
    return { defs, body };
}

function ring(p) {
    const defs = rad(p + 'band', ['#fff6c8', '#f5c842', '#a8660a'], 0.35, 0.3, 0.8) + rad(p + 'pink', ['#fff', '#ff7ac8', '#c0107a', '#5a0030'], 0.38, 0.32, 0.75);
    const body = `<g filter="url(#${p}sh)">` +
        `<ellipse cx="100" cy="118" rx="74" ry="64" fill="none" stroke="#6a3a00" stroke-width="30"/>` +
        `<ellipse cx="100" cy="118" rx="74" ry="64" fill="none" stroke="url(#${p}band)" stroke-width="24"/>` +
        `<ellipse cx="100" cy="118" rx="58" ry="49" fill="#c0107a" opacity=".55"/>` +
        `<ellipse cx="100" cy="118" rx="58" ry="49" fill="none" stroke="#6a3a00" stroke-width="2"/>` +
        `<path d="M62 64l16-26h44l16 26-38 26z" fill="url(#${p}au)" stroke="#6a3a00" stroke-width="3" stroke-linejoin="round"/>` +
        `<polygon points="${pts(poly(8, 100, 50, 34, 28, -67.5))}" fill="url(#${p}pink)" stroke="#6a3a00" stroke-width="3"/>` +
        `<polygon points="${pts(poly(8, 100, 50, 16, 12, -67.5))}" fill="#fff" opacity=".35"/>` +
        `<path d="M40 118a60 60 0 0 1 22-44" stroke="#fff" stroke-opacity=".7" stroke-width="6" stroke-linecap="round" fill="none"/>` +
        `</g>` + sparkle(84, 40, 12) + sparkle(160, 150, 7, '#fff', 0.6);
    return { defs, body };
}

function hourglass(p) {
    const defs = lin(p + 'glass', ['#e8fff2cc', '#7affc0aa', '#0a8a5aaa'], 0, 0, 1, 1) + lin(p + 'sand', ['#fff2b0', '#e0a82e'], 0, 0, 0, 1);
    const body = `<g filter="url(#${p}sh)">` +
        `<path d="M58 34h84c0 40-30 52-34 66 4 14 34 26 34 66H58c0-40 30-52 34-66-4-14-34-26-34-66z" fill="url(#${p}glass)" stroke="#e8fff2" stroke-width="2"/>` +
        `<path d="M74 54h52c-6 18-22 26-26 34-4-8-20-16-26-34z" fill="url(#${p}sand)"/>` +
        `<path d="M98 100h4v52h-4z" fill="#ffe27a"/><path d="M66 166c6-16 24-22 34-22s28 6 34 22z" fill="url(#${p}sand)"/>` +
        `<rect x="40" y="16" width="120" height="20" rx="6" fill="url(#${p}au)" stroke="#6a3a00" stroke-width="3"/>` +
        `<rect x="40" y="164" width="120" height="20" rx="6" fill="url(#${p}au)" stroke="#6a3a00" stroke-width="3"/>` +
        `<rect x="44" y="34" width="10" height="132" rx="4" fill="url(#${p}au)" stroke="#6a3a00" stroke-width="2.5"/>` +
        `<rect x="146" y="34" width="10" height="132" rx="4" fill="url(#${p}au)" stroke="#6a3a00" stroke-width="2.5"/>` +
        `<circle cx="100" cy="26" r="6" fill="#18a8f0" stroke="#6a3a00" stroke-width="2"/><circle cx="100" cy="174" r="6" fill="#18a8f0" stroke="#6a3a00" stroke-width="2"/>` +
        `<path d="M70 44c4 16 14 26 22 34" stroke="#fff" stroke-opacity=".7" stroke-width="5" stroke-linecap="round" fill="none"/>` +
        `</g>` + sparkle(150, 60, 10);
    return { defs, body };
}

function crown(p) {
    const defs = lin(p + 'vel', ['#ff4a5a', '#b0001c', '#5a000a'], 0, 0, 0, 1);
    const body = `<g filter="url(#${p}sh)">` +
        `<path d="M42 110c0-50 26-80 58-80s58 30 58 80z" fill="url(#${p}vel)" stroke="#5a000a" stroke-width="3"/>` +
        `<path d="M30 164L22 72l40 34 38-62 38 62 40-34-8 92z" fill="url(#${p}au)" stroke="#6a3a00" stroke-width="3.5" stroke-linejoin="round"/>` +
        `<rect x="26" y="150" width="148" height="30" rx="8" fill="url(#${p}au)" stroke="#6a3a00" stroke-width="3.5"/>` +
        `<circle cx="22" cy="70" r="9" fill="url(#${p}au)" stroke="#6a3a00" stroke-width="3"/><circle cx="178" cy="70" r="9" fill="url(#${p}au)" stroke="#6a3a00" stroke-width="3"/><circle cx="100" cy="40" r="10" fill="url(#${p}au)" stroke="#6a3a00" stroke-width="3"/>` +
        `<polygon points="${pts(poly(8, 100, 120, 20, 22, -67.5))}" fill="#2ccf3a" stroke="#6a3a00" stroke-width="3"/><polygon points="${pts(poly(8, 100, 120, 9, 10, -67.5))}" fill="#fff" opacity=".4"/>` +
        `<circle cx="56" cy="165" r="8" fill="#18a8f0" stroke="#6a3a00" stroke-width="2.5"/><circle cx="100" cy="165" r="8" fill="#e8183a" stroke="#6a3a00" stroke-width="2.5"/><circle cx="144" cy="165" r="8" fill="#18a8f0" stroke="#6a3a00" stroke-width="2.5"/>` +
        `<circle cx="62" cy="128" r="7" fill="#e8183a" stroke="#6a3a00" stroke-width="2.5"/><circle cx="138" cy="128" r="7" fill="#e8183a" stroke="#6a3a00" stroke-width="2.5"/>` +
        `<path d="M36 156h128" stroke="#fff" stroke-opacity=".6" stroke-width="3"/>` +
        `</g>` + sparkle(56, 92, 12) + sparkle(150, 100, 8, '#fff', 0.7);
    return { defs, body };
}

// ---- the thunder god (scatter tile + standing character) ---------------------

const SKIN = ['#ffe0c2', '#f0b088', '#b8724a'];

/** God's head: white hair + beard, laurel crown, glowing eyes. Centred on (cx, cy), unit scale ~ 1 = 100px face. */
function godHead(p, cx, cy, s = 1) {
    const T = (x, y) => `${(cx + x * s).toFixed(1)} ${(cy + y * s).toFixed(1)}`;
    const puff = (x, y, r, fill) => `<circle cx="${(cx + x * s).toFixed(1)}" cy="${(cy + y * s).toFixed(1)}" r="${(r * s).toFixed(1)}" fill="${fill}"/>`;
    let hair = '';
    for (const [x, y, r] of [[-46, -30, 22], [-50, -4, 20], [-46, 22, 18], [46, -30, 22], [50, -4, 20], [46, 22, 18], [-30, -50, 22], [0, -58, 24], [30, -50, 22]]) hair += puff(x, y, r, `url(#${p}hair)`);
    let beard = '';
    for (const [x, y, r] of [[-34, 30, 18], [34, 30, 18], [-26, 54, 18], [26, 54, 18], [-12, 72, 17], [12, 72, 17], [0, 88, 15], [-38, 8, 14], [38, 8, 14]]) beard += puff(x, y, r, `url(#${p}hair)`);
    let laurel = '';
    for (let i = 0; i < 7; i++) {
        const a = (-160 + i * 23) * Math.PI / 180, x = Math.cos(a) * 48, y = -22 + Math.sin(a) * 36;
        laurel += `<ellipse cx="${(cx + x * s).toFixed(1)}" cy="${(cy + y * s).toFixed(1)}" rx="${(11 * s).toFixed(1)}" ry="${(5 * s).toFixed(1)}" fill="url(#${p}au)" stroke="#6a3a00" stroke-width="${(1.5 * s).toFixed(1)}" transform="rotate(${(-160 + i * 23 + 90).toFixed(0)} ${(cx + x * s).toFixed(1)} ${(cy + y * s).toFixed(1)})"/>`;
    }
    const defs = rad(p + 'hair', ['#ffffff', '#e4ecf4', '#a8b4c4'], 0.4, 0.35, 0.7) + lin(p + 'skin', SKIN, 0, 0, 0, 1) + glow(p + 'eye', '#5ad8ff', 3);
    const body = hair +
        `<path d="M${T(-34, -26)}Q${T(-36, 28)} ${T(0, 40)}Q${T(36, 28)} ${T(34, -26)}Q${T(0, -46)} ${T(-34, -26)}Z" fill="url(#${p}skin)" stroke="#8a4a2a" stroke-width="${2 * s}"/>` +
        beard +
        `<path d="M${T(-22, 24)}Q${T(0, 16)} ${T(22, 24)}" stroke="#a8b4c4" stroke-width="${5 * s}" fill="none" stroke-linecap="round"/>` +
        `<path d="M${T(-26, -10)}L${T(-8, -6)}M${T(26, -10)}L${T(8, -6)}" stroke="#e4ecf4" stroke-width="${6 * s}" stroke-linecap="round"/>` +
        `<g filter="url(#${p}eye)"><ellipse cx="${cx - 15 * s}" cy="${cy + 2 * s}" rx="${6 * s}" ry="${3.5 * s}" fill="#bff4ff"/><ellipse cx="${cx + 15 * s}" cy="${cy + 2 * s}" rx="${6 * s}" ry="${3.5 * s}" fill="#bff4ff"/></g>` +
        `<path d="M${T(0, -2)}L${T(-5, 14)}L${T(4, 15)}" stroke="#8a4a2a" stroke-width="${2.5 * s}" fill="none" stroke-linejoin="round"/>` +
        laurel;
    return { defs, body };
}

function bolt(x, y, s = 1) {
    const P = [[0, 0], [26, 0], [12, 40], [32, 40], [-6, 110], [6, 56], [-12, 56]];
    return P.map(([a, b]) => `${(x + a * s).toFixed(1)},${(y + b * s).toFixed(1)}`).join(' ');
}

function scatter(p) {
    const h = godHead(p + 'h', 100, 86, 0.95);
    const defs = h.defs + lin(p + 'tile', ['#5ab4ff', '#2a3aa8', '#1a0a5a'], 0, 0, 0, 1) + lin(p + 'rb', ['#fff2a8', '#f5c842', '#b07810']) + glow(p + 'bolt', '#7ae8ff', 5);
    const body = `<g filter="url(#${p}sh)"><rect x="10" y="10" width="180" height="180" rx="16" fill="url(#${p}au)" stroke="#6a3a00" stroke-width="3"/>` +
        `<rect x="20" y="20" width="160" height="160" rx="10" fill="url(#${p}tile)"/>` +
        `<polygon points="${bolt(30, 26, 0.7)}" fill="#e8fbff" filter="url(#${p}bolt)" opacity=".85"/><polygon points="${bolt(150, 30, 0.6)}" fill="#e8fbff" filter="url(#${p}bolt)" opacity=".75"/>` +
        `${h.body}</g>` +
        `<g filter="url(#${p}sh)"><path d="M14 152L26 132H174L186 152L174 172H26Z" fill="url(#${p}rb)" stroke="#6a3a00" stroke-width="3"/>` +
        `<text x="100" y="162" font-family="Georgia,'Times New Roman',serif" font-weight="bold" font-size="28" text-anchor="middle" fill="#fff" stroke="#6a1a00" stroke-width="4" paint-order="stroke" letter-spacing="2">SCATTER</text></g>`;
    return { defs, body };
}

// ---- multiplier orbs ------------------------------------------------------------

const ORBS = {
    green: ['#eaffd0', '#5ae03a', '#0a6a1a', '#033a0a'],
    blue: ['#e0f6ff', '#3ab4ff', '#0a3a9a', '#03104a'],
    purple: ['#f8e0ff', '#c050ff', '#5a0a9a', '#22034a'],
    red: ['#fff0d0', '#ff5a2a', '#9a0a1a', '#4a0306'],
};

function orb(p, colors) {
    const [hi, mid, dark, deep] = colors;
    const defs = rad(p + 'o', [['#ffffff', 0], [hi, 0.12], [mid, 0.45], [dark, 0.85], [deep, 1]], 0.4, 0.35, 0.65) + glow(p + 'g', mid, 8) + rad(p + 'halo', [[mid + 'aa', 0.55], [mid + '00', 1]]);
    let rays = '';
    for (let i = 0; i < 12; i++) {
        const a = i * 30 * Math.PI / 180;
        rays += `<path d="M${100 + Math.cos(a) * 60} ${100 + Math.sin(a) * 60}L${100 + Math.cos(a + 0.12) * 96} ${100 + Math.sin(a + 0.12) * 96}L${100 + Math.cos(a - 0.12) * 96} ${100 + Math.sin(a - 0.12) * 96}Z" fill="${hi}" opacity=".35"/>`;
    }
    const body = `<circle cx="100" cy="100" r="98" fill="url(#${p}halo)"/>${rays}` +
        `<g filter="url(#${p}g)"><circle cx="100" cy="100" r="66" fill="url(#${p}o)" stroke="url(#${p}au)" stroke-width="6"/></g>` +
        `<ellipse cx="80" cy="70" rx="26" ry="14" fill="#fff" opacity=".55" transform="rotate(-30 80 70)"/>` +
        `<path d="M60 134q40 24 80 0" stroke="#fff" stroke-opacity=".25" stroke-width="5" fill="none" stroke-linecap="round"/>` +
        sparkle(146, 52, 12) + sparkle(44, 140, 7, '#fff', 0.7);
    return { defs, body };
}

// ---- backgrounds -------------------------------------------------------------------

function column(x, top, bottom, w, free) {
    const shaft = free ? 'url(#colN)' : 'url(#col)';
    let flutes = '';
    for (let i = 1; i < 6; i++) flutes += `<path d="M${x + (w * i) / 6} ${top + 34}V${bottom - 26}" stroke="#000" stroke-opacity=".12" stroke-width="3"/>`;
    return `<rect x="${x - 12}" y="${bottom - 26}" width="${w + 24}" height="26" fill="${shaft}"/><rect x="${x - 20}" y="${bottom - 8}" width="${w + 40}" height="16" fill="${shaft}"/>` +
        `<rect x="${x}" y="${top + 34}" width="${w}" height="${bottom - top - 60}" fill="${shaft}"/>${flutes}` +
        `<path d="M${x - 22} ${top + 34}h${w + 44}l-8-18h${-(w + 28)}z" fill="${shaft}"/>` +
        `<circle cx="${x - 16}" cy="${top + 22}" r="12" fill="none" stroke="${free ? '#8a96c8' : '#e8d8c0'}" stroke-width="6"/><circle cx="${x + w + 16}" cy="${top + 22}" r="12" fill="none" stroke="${free ? '#8a96c8' : '#e8d8c0'}" stroke-width="6"/>` +
        `<rect x="${x - 24}" y="${top}" width="${w + 48}" height="12" fill="${shaft}"/>`;
}

function brazier(x, y, free) {
    const flame = free ? ['#e0f6ff', '#5ab4ff', '#2a3aa8'] : ['#fff8c0', '#ffb020', '#e03a0a'];
    return `<path d="M${x - 34} ${y}h68l-12 26h-44z" fill="url(#au)" stroke="#6a3a00" stroke-width="3"/>` +
        `<path d="M${x - 30} ${y}c-6-40 18-52 14-90 22 24 8 46 26 58 4-22 18-30 18-48 18 30 14 56 2 80z" fill="${flame[2]}" opacity=".9"/>` +
        `<path d="M${x - 18} ${y}c-2-26 12-34 10-56 14 18 6 30 16 38 4-14 10-18 10-30 10 20 6 36-2 48z" fill="${flame[1]}"/>` +
        `<path d="M${x - 8} ${y}c0-14 6-18 6-30 8 10 4 18 10 22 2-8 6-10 6-16 4 12 2 20-4 24z" fill="${flame[0]}"/>`;
}

function background(free) {
    const W = 1280, H = 720;
    let defs = gold('au') +
        lin('sky', free ? ['#0a0626', '#1a1a5a', '#3a2a8a', '#6a3aa0'] : ['#5a2a8a', '#a04a9a', '#ff8a6a', '#ffd08a'], 0, 0, 0, 1) +
        lin('col', free ? ['#c8d0f0', '#8a96c8', '#5a6aa0'] : ['#fffaf0', '#f0e2cc', '#c8b08a'], 0, 0, 1, 0) +
        lin('colN', ['#c8d0f0', '#8a96c8', '#5a6aa0'], 0, 0, 1, 0) +
        lin('floor', free ? ['#3a3a7a', '#141436'] : ['#ffcf7a', '#c07a2a', '#5a2a0a'], 0, 0, 0, 1) +
        rad('sun', free ? ['#c0e8ff', '#5a8aff55', '#0000'] : ['#fffbe0', '#ffd08a99', '#ff8a6a00'], 0.5, 0.5, 0.5) +
        rad('v', [['#0000', 0.6], ['#000a', 1]], 0.5, 0.5, 0.75) +
        glow('lt', '#9ae8ff', 6);
    let body = `<rect width="${W}" height="${H}" fill="url(#sky)"/><circle cx="${W * 0.46}" cy="${H * 0.5}" r="380" fill="url(#sun)"/>`;
    if (free) for (let i = 0; i < 110; i++) body += `<circle cx="${(rnd() * W).toFixed(0)}" cy="${(rnd() * H * 0.55).toFixed(0)}" r="${(0.6 + rnd() * 1.6).toFixed(1)}" fill="#fff" opacity="${(0.3 + rnd() * 0.7).toFixed(2)}"/>`;
    // clouds
    for (let i = 0; i < 9; i++) {
        const x = rnd() * W, y = 330 + rnd() * 160, s = 0.7 + rnd() * 0.9;
        const c = free ? '#5a5aa0' : (i % 2 ? '#ffe2c8' : '#ffc8b0');
        body += `<g opacity="${free ? 0.55 : 0.8}" fill="${c}"><ellipse cx="${x}" cy="${y}" rx="${130 * s}" ry="${34 * s}"/><circle cx="${x - 50 * s}" cy="${y - 16 * s}" r="${36 * s}"/><circle cx="${x + 30 * s}" cy="${y - 28 * s}" r="${46 * s}"/></g>`;
    }
    // distant temple on the clouds
    const tx = 880, ty = 300;
    const temple = free ? '#7a82c0' : '#fff0dc';
    body += `<g opacity="${free ? 0.6 : 0.75}"><path d="M${tx - 140} ${ty}L${tx} ${ty - 60}L${tx + 140} ${ty}Z" fill="${temple}"/><rect x="${tx - 140}" y="${ty}" width="280" height="12" fill="${temple}"/>`;
    for (let i = 0; i < 8; i++) body += `<rect x="${tx - 128 + i * 34}" y="${ty + 12}" width="14" height="110" fill="${temple}"/>`;
    body += `<rect x="${tx - 150}" y="${ty + 122}" width="300" height="16" fill="${temple}"/></g>`;
    if (free) {
        for (const [x, y, s] of [[300, 40, 1.4], [1000, 20, 1.1], [620, 60, 0.9]]) body += `<polygon points="${bolt(x, y, s)}" fill="#e8fbff" filter="url(#lt)" opacity=".8"/>`;
    }
    // golden floor with perspective lines
    body += `<path d="M0 560H${W}V${H}H0Z" fill="url(#floor)"/>`;
    for (let i = -10; i <= 10; i++) body += `<path d="M${W / 2 + i * 40} 560L${W / 2 + i * 190} ${H}" stroke="${free ? '#7a82c0' : '#fff2c0'}" stroke-opacity=".25" stroke-width="2"/>`;
    for (const y of [585, 620, 670]) body += `<path d="M0 ${y}H${W}" stroke="${free ? '#7a82c0' : '#fff2c0'}" stroke-opacity=".2" stroke-width="2"/>`;
    // columns + fire on both sides
    body += column(40, 110, 600, 90, free) + column(W - 130, 110, 600, 90, free);
    body += brazier(85, 98, free) + brazier(W - 85, 98, free);
    body += `<rect width="${W}" height="${H}" fill="url(#v)"/>`;
    return svg(W, H, body, defs);
}

// ---- the god character (beside the grid) ----------------------------------------------

function character() {
    const W = 420, H = 680;
    const p = 'z';
    const h = godHead(p + 'h', 210, 120, 1.25);
    const defs = h.defs + gold(p + 'au') + lin(p + 'skin', SKIN, 0, 0, 1, 1) + lin(p + 'toga', ['#ffffff', '#e8eaf4', '#a8acc8'], 0, 0, 1, 1) +
        lin(p + 'cape', ['#8a3ad0', '#4a0a8a', '#22034a'], 0, 0, 1, 1) + rad(p + 'aura', [['#fff8d0aa', 0], ['#ffd08a44', 0.5], ['#ffd08a00', 1]]) +
        glow(p + 'bolt', '#7ae8ff', 10) + shadow(p + 'sh', 8, 6, 0.5);
    const body = `<ellipse cx="210" cy="330" rx="210" ry="330" fill="url(#${p}aura)"/>` +
        `<g filter="url(#${p}sh)">` +
        // cape behind
        `<path d="M110 190C60 320 70 520 40 660H380C350 520 360 320 310 190Z" fill="url(#${p}cape)"/>` +
        // legs
        `<path d="M160 470l-14 180h44l14-170zM250 470l18 180h44l-20-180z" fill="url(#${p}skin)" stroke="#8a4a2a" stroke-width="2"/>` +
        `<path d="M140 620h56v40h-60zM264 620h56l4 40h-60z" fill="url(#${p}au)" stroke="#6a3a00" stroke-width="2.5"/>` +
        // torso (bare chest) + toga over the left shoulder
        `<path d="M128 200C120 280 132 380 150 480H272C292 380 302 280 292 200C260 186 160 186 128 200Z" fill="url(#${p}skin)" stroke="#8a4a2a" stroke-width="2"/>` +
        `<path d="M176 250q34 18 70 0M170 300q40 10 80 0M186 350q24 8 48 0" stroke="#b8724a" stroke-width="3" fill="none" opacity=".6"/>` +
        `<path d="M128 200C170 260 250 300 292 300C300 380 290 440 300 560C240 590 170 590 116 560C130 470 120 300 128 200Z" fill="url(#${p}toga)" stroke="#8a8eb0" stroke-width="2"/>` +
        `<path d="M150 300q60 40 130 30M140 380q70 40 150 30M136 460q70 40 160 26" stroke="#a8acc8" stroke-width="4" fill="none"/>` +
        `<rect x="132" y="430" width="164" height="26" rx="8" fill="url(#${p}au)" stroke="#6a3a00" stroke-width="3"/><circle cx="214" cy="443" r="11" fill="#18a8f0" stroke="#6a3a00" stroke-width="2.5"/>` +
        // left arm (down, fist)
        `<path d="M130 206C96 230 88 320 96 400L128 404C128 330 138 270 152 236Z" fill="url(#${p}skin)" stroke="#8a4a2a" stroke-width="2"/>` +
        `<rect x="90" y="330" width="44" height="40" rx="8" fill="url(#${p}au)" stroke="#6a3a00" stroke-width="2.5"/><circle cx="112" cy="414" r="22" fill="url(#${p}skin)" stroke="#8a4a2a" stroke-width="2"/>` +
        // right arm raised, holding the bolt
        `<path d="M290 206C330 196 360 150 366 96L338 88C330 130 312 160 278 172Z" fill="url(#${p}skin)" stroke="#8a4a2a" stroke-width="2"/>` +
        `<rect x="328" y="104" width="40" height="36" rx="8" fill="url(#${p}au)" stroke="#6a3a00" stroke-width="2.5" transform="rotate(18 348 122)"/>` +
        `<circle cx="354" cy="80" r="20" fill="url(#${p}skin)" stroke="#8a4a2a" stroke-width="2"/>` +
        // shoulder plate
        `<path d="M258 182c30-10 52 0 58 24-24 4-44 0-58-24z" fill="url(#${p}au)" stroke="#6a3a00" stroke-width="3"/><circle cx="288" cy="196" r="8" fill="#18a8f0" stroke="#6a3a00" stroke-width="2"/>` +
        `</g>` +
        `<polygon points="${bolt(340, -10, 1.2)}" fill="#e8fbff" filter="url(#${p}bolt)"/>` +
        h.body;
    return svg(W, H, body, defs);
}

// ---- logo ----------------------------------------------------------------------------

function logo(title) {
    const W = 640, H = 170;
    const size = 64;
    const fit = title.length * size * 0.72 > 470 ? ' textLength="470" lengthAdjust="spacingAndGlyphs"' : '';
    const defs = gold('lgau') + lin('lgf', ['#fffbe0', '#ffe27a', '#e0a82e', '#ffd76a'], 0, 0, 0, 1) + lin('lgp', ['#7a3ad0', '#3a0a7a'], 0, 0, 0, 1) + shadow('lgsh', 6, 4, 0.7) + glow('lglt', '#9ae8ff', 4);
    const plaque = 'M40 60L80 26H560L600 60L560 128H80Z';
    const body = `<g filter="url(#lgsh)"><path d="${plaque}" fill="url(#lgp)" stroke="url(#lgau)" stroke-width="10" stroke-linejoin="round"/>` +
        `<path d="M300 26l20-22 20 22z" fill="url(#lgau)" stroke="#6a3a00" stroke-width="2"/><path d="M300 128l20 22 20-22z" fill="url(#lgau)" stroke="#6a3a00" stroke-width="2"/></g>` +
        `<polygon points="${bolt(28, 30, 0.75)}" fill="#e8fbff" filter="url(#lglt)"/><polygon points="${bolt(596, 30, 0.75)}" fill="#e8fbff" filter="url(#lglt)"/>` +
        `<text x="${W / 2}" y="${H / 2 + size * 0.32}" font-family="Georgia,'Times New Roman',serif" font-weight="bold" font-size="${size}" text-anchor="middle" fill="url(#lgf)" stroke="#3a1a00" stroke-width="7" paint-order="stroke" letter-spacing="3"${fit}>${title.toUpperCase()}</text>`;
    return svg(W, H, body, defs);
}

// ---- write the pack -------------------------------------------------------------------

const write = (file, content) => { mkdirSync(dirname(file), { recursive: true }); writeFileSync(file, content); };

const SYMBOLS = [
    ['gem_blue', 'Blue Gem', 'low', gemBlue],
    ['gem_green', 'Green Gem', 'low', gemGreen],
    ['gem_purple', 'Purple Gem', 'low', gemPurple],
    ['gem_red', 'Red Gem', 'low', gemRed],
    ['gem_yellow', 'Yellow Gem', 'low', gemYellow],
    ['chalice', 'Chalice', 'high', chalice],
    ['ring', 'Ring', 'high', ring],
    ['hourglass', 'Hourglass', 'high', hourglass],
    ['crown', 'Crown', 'high', crown],
    ['scatter', 'Thunder God', 'scatter', scatter],
    ['orb', 'Multiplier Orb', 'multiplier', (p) => orb(p, ORBS.green)],
];

for (const [key, , , fn] of SYMBOLS) {
    const p = key.replace(/[^a-z]/g, '') + '_';
    const { defs, body } = fn(p);
    write(join(OUT, 'img', 'sym', key + '.svg'), sym(p, body, defs));
}
for (const [name, colors] of Object.entries(ORBS)) {
    const { defs, body } = orb('o' + name + '_', colors);
    write(join(OUT, 'img', 'orb', name + '.svg'), sym('o' + name + '_', body, defs));
}
write(join(OUT, 'img', 'bg.svg'), background(false));
write(join(OUT, 'img', 'bg_free.svg'), background(true));
write(join(OUT, 'img', 'character.svg'), character());
write(join(OUT, 'img', 'logo.svg'), logo('Olympus Thunder'));

const pack = {
    key: 'olympus',
    title: 'Olympus — gods, gems & thunder',
    skin: 'olympus',
    symbols: SYMBOLS.map(([key, name, kind]) => ({ key, name, kind, file: `img/sym/${key}.svg` })),
    orbs: Object.keys(ORBS).map((k) => `img/orb/${k}.svg`),
    background: 'img/bg.svg',
    background_free: 'img/bg_free.svg',
    character: 'img/character.svg',
    logo: 'img/logo.svg',
    music: 'snd/music.wav',
    music_free: 'snd/music_free.wav',
    theme: {
        accent: '#ffd76a',
        frame: ['#fffbe0', '#e0a82e', '#7a4a00'],
        reelBg: ['#3a0a4acc', '#1a0428e6'],
        font: "Georgia,'Times New Roman',serif",
    },
};
write(join(OUT, 'pack.json'), JSON.stringify(pack, null, 2) + '\n');
console.log('olympus pack written to', OUT);
