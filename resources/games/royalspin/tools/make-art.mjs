// RoyalSpin — art generator.
//
// Draws every game's artwork as SVG: symbols (img/sym/<id>.svg, 200x200),
// backgrounds (img/bg.svg + img/bg_free.svg, 1280x720), the logo
// (img/logo.svg) and the lobby poster (poster.svg, 400x400). All vector, no
// external assets; deterministic output.
//
//   node resources/games/royalspin/tools/make-art.mjs

import { writeFileSync, mkdirSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = join(dirname(fileURLToPath(import.meta.url)), '..', 'games');

let seed = 777;
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
const star = (cx, cy, r1, r2, n = 5, rot = -90) => Array.from({ length: n * 2 }, (_, i) => {
    const a = ((rot + (180 / n) * i) * Math.PI) / 180, r = i % 2 ? r2 : r1;
    return [cx + r * Math.cos(a), cy + r * Math.sin(a)];
});
const sparkle = (x, y, s, color = '#fff', op = 0.95) =>
    `<path d="M${x} ${y - s}Q${x + s * 0.15} ${y - s * 0.15} ${x + s} ${y}Q${x + s * 0.15} ${y + s * 0.15} ${x} ${y + s}Q${x - s * 0.15} ${y + s * 0.15} ${x - s} ${y}Q${x - s * 0.15} ${y - s * 0.15} ${x} ${y - s}Z" fill="${color}" opacity="${op}"/>`;

/** Ribbon label across the bottom of a symbol (WILD / SCATTER / FREE). */
function ribbon(p, text, c1, c2, fg = '#fff', y = 150, size = 30) {
    const w = Math.max(110, text.length * size * 0.72 + 30);
    const x = 100 - w / 2;
    return {
        defs: lin(p + 'rb', [c1, c2]),
        body: `<g filter="url(#${p}sh)"><path d="M${x - 12} ${y + 4}L${x} ${y - 18}L${x + w} ${y - 18}L${x + w + 12} ${y + 4}L${x + w} ${y + 26}L${x} ${y + 26}Z" fill="url(#${p}rb)" stroke="#fff3" stroke-width="2"/>` +
            `<text x="100" y="${y + 15}" font-family="Georgia,'Times New Roman',serif" font-weight="bold" font-size="${size}" text-anchor="middle" fill="${fg}" stroke="#0007" stroke-width="1.5" paint-order="stroke" letter-spacing="2">${text}</text></g>`,
    };
}

function sym(p, body, defs = '') { return svg(200, 200, body, shadow(p + 'sh') + defs); }

// ---- gems -------------------------------------------------------------------

/** Faceted gem: polygon outline, inner table, alternating facets, highlights. */
function gem(p, { n = 8, rx = 78, ry = 78, rot = -90, light, mid, dark, table = 0.5 }) {
    const outer = poly(n, 100, 98, rx, ry, rot);
    const inner = poly(n, 100, 98, rx * table, ry * table, rot);
    let facets = '';
    for (let i = 0; i < n; i++) {
        const j = (i + 1) % n;
        const shade = [`url(#${p}l)`, `url(#${p}d)`][i % 2];
        facets += `<polygon points="${pts([outer[i], outer[j], inner[j], inner[i]])}" fill="${shade}" opacity="${i % 2 ? 0.9 : 0.75}"/>`;
        facets += `<line x1="${outer[i][0]}" y1="${outer[i][1]}" x2="${inner[i][0]}" y2="${inner[i][1]}" stroke="#fff" stroke-opacity=".35" stroke-width="1.2"/>`;
    }
    const defs = lin(p + 'l', [light, mid], 0, 0, 1, 1) + lin(p + 'd', [mid, dark], 0, 0, 1, 1) + rad(p + 't', [['#fff', 0], [light, 0.35], [mid, 1]], 0.35, 0.3, 0.8);
    const body = `<g filter="url(#${p}sh)"><polygon points="${pts(outer)}" fill="${dark}" stroke="${dark}" stroke-width="5" stroke-linejoin="round"/>${facets}` +
        `<polygon points="${pts(inner)}" fill="url(#${p}t)" stroke="#fff" stroke-opacity=".5" stroke-width="1.5"/>` +
        `<polygon points="${pts(outer)}" fill="none" stroke="#fff" stroke-opacity=".45" stroke-width="2" stroke-linejoin="round"/></g>` +
        sparkle(100 - rx * 0.35, 98 - ry * 0.38, 13) + sparkle(100 + rx * 0.45, 98 + ry * 0.3, 7, '#fff', 0.7);
    return { defs, body };
}

function diamond(p) {
    const defs = lin(p + 'a', ['#ffffff', '#cfefff', '#7fc8f8'], 0, 0, 1, 1) + lin(p + 'b', ['#e9f8ff', '#5aaee8'], 0, 0, 1, 1) + lin(p + 'c', ['#a8dcff', '#2a7fc4'], 1, 0, 0, 1);
    const top = [[40, 75], [65, 42], [135, 42], [160, 75]];
    const tip = [100, 170];
    let body = `<g filter="url(#${p}sh)"><polygon points="${pts([...top, tip])}" fill="url(#${p}c)" stroke="#1d5f99" stroke-width="4" stroke-linejoin="round"/>`;
    const crown = [[40, 75], [65, 42], [82, 75], [100, 42], [118, 75], [135, 42], [160, 75]];
    for (let i = 0; i < crown.length - 1; i++) body += `<polygon points="${pts([crown[i], crown[i + 1], [crown[i][0] + (crown[i + 1][0] - crown[i][0]) / 2, 75]])}" fill="url(#${p}${i % 2 ? 'a' : 'b'})" opacity=".9"/>`;
    body += `<polygon points="${pts([[65, 42], [135, 42], [118, 75], [82, 75]])}" fill="url(#${p}a)"/>`;
    for (const x of [40, 82, 118, 160]) body += `<line x1="${x}" y1="75" x2="100" y2="170" stroke="#fff" stroke-opacity=".5" stroke-width="1.5"/>`;
    body += `<polygon points="${pts([[82, 75], [118, 75], tip])}" fill="#fff" opacity=".25"/><line x1="40" y1="75" x2="160" y2="75" stroke="#fff" stroke-opacity=".7" stroke-width="2"/></g>`;
    body += sparkle(72, 55, 16) + sparkle(140, 110, 8, '#fff', 0.8);
    return { defs, body };
}

function crown(p, { label = 'WILD' } = {}) {
    const defs = lin(p + 'g', ['#fff6c2', '#f5c842', '#b8860b', '#7a5200'], 0, 0, 0, 1) + rad(p + 'r', ['#ff8a8a', '#d10f2f', '#5c0010'], 0.35, 0.35, 0.7) + rad(p + 'e', ['#9dffcf', '#0fa060', '#00401f'], 0.35, 0.35, 0.7) + rad(p + 's', ['#a6d4ff', '#1d6ee0', '#06275c'], 0.35, 0.35, 0.7);
    const outline = [[30, 130], [24, 55], [62, 92], [100, 36], [138, 92], [176, 55], [170, 130]];
    let body = `<g filter="url(#${p}sh)"><polygon points="${pts(outline)}" fill="url(#${p}g)" stroke="#5a3a00" stroke-width="4" stroke-linejoin="round"/>` +
        `<rect x="28" y="122" width="144" height="26" rx="6" fill="url(#${p}g)" stroke="#5a3a00" stroke-width="4"/>` +
        `<circle cx="24" cy="52" r="9" fill="url(#${p}g)" stroke="#5a3a00" stroke-width="3"/><circle cx="100" cy="32" r="10" fill="url(#${p}g)" stroke="#5a3a00" stroke-width="3"/><circle cx="176" cy="52" r="9" fill="url(#${p}g)" stroke="#5a3a00" stroke-width="3"/>` +
        `<circle cx="100" cy="98" r="14" fill="url(#${p}r)" stroke="#5a3a00" stroke-width="2"/><circle cx="62" cy="108" r="9" fill="url(#${p}e)" stroke="#5a3a00" stroke-width="2"/><circle cx="138" cy="108" r="9" fill="url(#${p}s)" stroke="#5a3a00" stroke-width="2"/>` +
        [55, 80, 100, 120, 145].map((x, i) => `<circle cx="${x}" cy="135" r="5" fill="${['#d10f2f', '#0fa060', '#fff', '#1d6ee0', '#d10f2f'][i]}" stroke="#5a3a00" stroke-width="1.5"/>`).join('') +
        `<path d="M40 70L56 92" stroke="#fff" stroke-opacity=".6" stroke-width="4" stroke-linecap="round"/></g>` + sparkle(96, 90, 7);
    if (label) { const r = ribbon(p, label, '#c0142c', '#6a0010'); return { defs: defs + r.defs, body: body + r.body }; }
    return { defs, body };
}

function chest(p) {
    const defs = lin(p + 'w', ['#a0612a', '#6b3a12', '#3e1f06'], 0, 0, 0, 1) + lin(p + 'g', ['#fff2a8', '#f2c230', '#9c6b00'], 0, 0, 0, 1) + rad(p + 'l', ['#fffbe0', '#ffd84a', '#ff9d0000'], 0.5, 0.6, 0.6);
    const body = `<ellipse cx="100" cy="75" rx="80" ry="50" fill="url(#${p}l)"/>` +
        `<g filter="url(#${p}sh)"><path d="M30 92h140v58a10 10 0 0 1-10 10H40a10 10 0 0 1-10-10z" fill="url(#${p}w)" stroke="#2a1404" stroke-width="4"/>` +
        `<path d="M30 92c0-30 20-48 70-48s70 18 70 48z" fill="url(#${p}w)" stroke="#2a1404" stroke-width="4"/>` +
        `<path d="M42 70c10-8 30-14 58-14s48 6 58 14" stroke="#fff" stroke-opacity=".25" stroke-width="4" fill="none"/>` +
        `<rect x="26" y="88" width="148" height="12" rx="3" fill="url(#${p}g)" stroke="#5a3a00" stroke-width="2.5"/>` +
        `<rect x="44" y="46" width="12" height="114" fill="url(#${p}g)" stroke="#5a3a00" stroke-width="2"/><rect x="144" y="46" width="12" height="114" fill="url(#${p}g)" stroke="#5a3a00" stroke-width="2"/>` +
        `<rect x="88" y="94" width="24" height="30" rx="4" fill="url(#${p}g)" stroke="#5a3a00" stroke-width="2.5"/><circle cx="100" cy="106" r="4" fill="#3e1f06"/><rect x="98" y="108" width="4" height="9" fill="#3e1f06"/>` +
        `${[[70, 52], [100, 44], [128, 52], [86, 40], [114, 42]].map(([x, y], i) => `<circle cx="${x}" cy="${y}" r="${7 - (i % 2) * 2}" fill="url(#${p}g)" stroke="#9c6b00" stroke-width="1.5"/>`).join('')}</g>` +
        sparkle(70, 36, 10) + sparkle(134, 40, 8);
    const r = ribbon(p, 'SCATTER', '#1d6ee0', '#0a2a66', '#fff', 162, 22);
    return { defs: defs + r.defs, body: body + r.body };
}

function ring(p) {
    const defs = lin(p + 'g', ['#fff6c2', '#f5c842', '#9c6b00', '#f5c842'], 0, 0, 1, 1);
    const g = gem(p + 'x', { n: 8, rx: 34, ry: 30, light: '#ffd0f0', mid: '#e0218a', dark: '#5c0035', table: 0.45 });
    const body = `<g filter="url(#${p}sh)"><ellipse cx="100" cy="122" rx="60" ry="52" fill="none" stroke="url(#${p}g)" stroke-width="16"/>` +
        `<ellipse cx="100" cy="122" rx="60" ry="52" fill="none" stroke="#5a3a00" stroke-width="2" stroke-opacity=".6"/><ellipse cx="100" cy="122" rx="50" ry="42" fill="none" stroke="#5a3a00" stroke-width="1.5" stroke-opacity=".5"/>` +
        `<path d="M70 72l30-10 30 10-8 14H78z" fill="url(#${p}g)" stroke="#5a3a00" stroke-width="2.5"/></g>` +
        `<g transform="translate(0 -38)">${g.body}</g>`;
    return { defs: defs + g.defs + shadow(p + 'xsh'), body };
}

// ---- fruits / classic -------------------------------------------------------

function cherry(p) {
    const defs = rad(p + 'c', ['#ff9a9a', '#e0102c', '#6a0010'], 0.35, 0.3, 0.7) + lin(p + 'l', ['#7be36b', '#1f8a2b'], 0, 0, 1, 1);
    const body = `<g filter="url(#${p}sh)"><path d="M70 120C80 80 100 50 132 30M135 125C130 90 128 60 132 30" stroke="#5a3a12" stroke-width="7" fill="none" stroke-linecap="round"/>` +
        `<path d="M132 30C150 20 175 28 182 48C160 52 140 46 132 30z" fill="url(#${p}l)" stroke="#145a1c" stroke-width="2.5"/>` +
        `<circle cx="68" cy="138" r="38" fill="url(#${p}c)" stroke="#4a000c" stroke-width="3"/><circle cx="138" cy="140" r="36" fill="url(#${p}c)" stroke="#4a000c" stroke-width="3"/>` +
        `<ellipse cx="55" cy="122" rx="11" ry="7" fill="#fff" opacity=".7" transform="rotate(-30 55 122)"/><ellipse cx="125" cy="124" rx="10" ry="6" fill="#fff" opacity=".7" transform="rotate(-30 125 124)"/></g>`;
    return { defs, body };
}
function citrus(p, c1, c2, c3, shape) {
    const defs = rad(p + 'f', [c1, c2, c3], 0.38, 0.32, 0.75) + lin(p + 'l', ['#7be36b', '#1f8a2b'], 0, 0, 1, 1);
    const fruit = shape === 'lemon'
        ? `<path d="M22 104C30 62 70 40 104 42s72 22 76 60c-6 40-40 62-78 62S28 144 22 104z" fill="url(#${p}f)" stroke="#7a5a00" stroke-width="3"/><path d="M14 104l12-6v14zM186 100l-12-6v14z" fill="${c2}" stroke="#7a5a00" stroke-width="2"/>`
        : `<circle cx="100" cy="108" r="74" fill="url(#${p}f)" stroke="#7a3a00" stroke-width="3"/>`;
    let dots = '';
    for (let i = 0; i < 40; i++) dots += `<circle cx="${40 + rnd() * 120}" cy="${55 + rnd() * 100}" r="1.4" fill="#000" opacity=".08"/>`;
    const body = `<g filter="url(#${p}sh)">${fruit}${dots}<ellipse cx="72" cy="78" rx="22" ry="12" fill="#fff" opacity=".45" transform="rotate(-25 72 78)"/>` +
        (shape === 'orange' ? `<path d="M100 36c10-14 30-20 48-12-10 14-30 18-48 12z" fill="url(#${p}l)" stroke="#145a1c" stroke-width="2"/><circle cx="100" cy="36" r="4" fill="#4a3000"/>` : '') + '</g>';
    return { defs, body };
}
function plum(p) {
    const defs = rad(p + 'f', ['#d9a3ff', '#7b1fa2', '#2a0040'], 0.35, 0.3, 0.75) + lin(p + 'l', ['#7be36b', '#1f8a2b'], 0, 0, 1, 1);
    const body = `<g filter="url(#${p}sh)"><path d="M100 40c50 0 74 34 70 74s-36 64-70 64-74-24-70-64 20-74 70-74z" fill="url(#${p}f)" stroke="#2a0040" stroke-width="3"/>` +
        `<path d="M104 46c-6 40-4 90 4 128" stroke="#2a0040" stroke-opacity=".35" stroke-width="4" fill="none"/><ellipse cx="72" cy="82" rx="16" ry="24" fill="#fff" opacity=".35" transform="rotate(20 72 82)"/>` +
        `<path d="M100 42c4-14 0-22-6-28" stroke="#5a3a12" stroke-width="6" stroke-linecap="round" fill="none"/><path d="M100 30c16-14 38-12 48 0-18 10-36 10-48 0z" fill="url(#${p}l)" stroke="#145a1c" stroke-width="2"/></g>`;
    return { defs, body };
}
function grapes(p) {
    const defs = rad(p + 'g', ['#c8ff9a', '#4caf28', '#14500a'], 0.35, 0.3, 0.7) + lin(p + 'l', ['#7be36b', '#1f8a2b'], 0, 0, 1, 1);
    const pos = [[70, 70], [100, 66], [130, 70], [56, 98], [86, 96], [116, 96], [146, 98], [72, 124], [102, 124], [132, 124], [86, 150], [116, 150], [101, 174]];
    const body = `<g filter="url(#${p}sh)"><path d="M100 60c0-20 6-34 18-44" stroke="#5a3a12" stroke-width="6" stroke-linecap="round" fill="none"/>` +
        `<path d="M104 34c-26-22-60-14-70 4 24 10 50 10 70-4z" fill="url(#${p}l)" stroke="#145a1c" stroke-width="2"/>` +
        pos.map(([x, y]) => `<circle cx="${x}" cy="${y}" r="17" fill="url(#${p}g)" stroke="#0e3a06" stroke-width="2"/><circle cx="${x - 6}" cy="${y - 6}" r="4" fill="#fff" opacity=".6"/>`).join('') + '</g>';
    return { defs, body };
}
function melon(p) {
    const defs = lin(p + 'r', ['#ff6f7d', '#e0102c'], 0, 0, 0, 1) + lin(p + 'g', ['#4caf28', '#14500a'], 0, 0, 0, 1);
    let seeds = '';
    for (const [x, y] of [[60, 104], [84, 118], [100, 100], [116, 118], [140, 104], [76, 90], [124, 90], [100, 130]]) seeds += `<ellipse cx="${x}" cy="${y}" rx="4" ry="7" fill="#1a0a00" transform="rotate(${(x - 100) / 3} ${x} ${y})"/>`;
    const body = `<g filter="url(#${p}sh)"><path d="M14 70h172a86 86 0 0 1-172 0z" fill="url(#${p}g)" stroke="#0e3a06" stroke-width="3"/>` +
        `<path d="M26 70h148a74 74 0 0 1-148 0z" fill="#f2ffe0"/><path d="M34 70h132a66 66 0 0 1-132 0z" fill="url(#${p}r)"/>${seeds}` +
        `<path d="M40 76h50" stroke="#fff" stroke-opacity=".5" stroke-width="5" stroke-linecap="round"/></g>`;
    return { defs, body };
}
function seven(p) {
    const defs = lin(p + 's', ['#ff9a9a', '#ff2a3c', '#a0001a'], 0, 0, 0, 1) + lin(p + 'o', ['#fff6c2', '#f5c842', '#9c6b00'], 0, 0, 0, 1);
    const d = 'M40 36h124v26L104 176H66l58-110H40z';
    const body = `<g filter="url(#${p}sh)"><path d="${d}" fill="url(#${p}o)" stroke="#5a3a00" stroke-width="14" stroke-linejoin="round"/>` +
        `<path d="${d}" fill="url(#${p}s)" stroke="#fff" stroke-opacity=".35" stroke-width="2" stroke-linejoin="round"/><path d="M48 44h106" stroke="#fff" stroke-opacity=".55" stroke-width="5" stroke-linecap="round"/></g>` +
        sparkle(150, 40, 14) + sparkle(62, 160, 8, '#fff', 0.7);
    return { defs, body };
}
function starSym(p, label = 'SCATTER') {
    const defs = rad(p + 's', ['#fffbe0', '#ffd84a', '#e08a00', '#8a4a00'], 0.4, 0.35, 0.7) + rad(p + 'h', ['#fff8', '#fff0'], 0.5, 0.5, 0.5);
    const body = `<circle cx="100" cy="92" r="88" fill="url(#${p}h)"/><g filter="url(#${p}sh)"><polygon points="${pts(star(100, 90, 82, 36))}" fill="url(#${p}s)" stroke="#7a4000" stroke-width="5" stroke-linejoin="round"/>` +
        `<polygon points="${pts(star(100, 90, 60, 26))}" fill="none" stroke="#fff" stroke-opacity=".6" stroke-width="2.5" stroke-linejoin="round"/></g>` + sparkle(72, 62, 12);
    const r = ribbon(p, label, '#1d6ee0', '#0a2a66', '#fff', 162, 22);
    return { defs: defs + r.defs, body: body + r.body };
}

// ---- Egypt -----------------------------------------------------------------

function cardLetter(p, letter, c1, c2) {
    const defs = lin(p + 'f', [c1, c2], 0, 0, 0, 1) + lin(p + 'o', ['#fff6c2', '#e0a82e', '#7a5200'], 0, 0, 0, 1);
    const size = letter.length > 1 ? 104 : 132;
    const body = `<g filter="url(#${p}sh)"><text x="100" y="${letter.length > 1 ? 136 : 146}" font-family="Georgia,'Times New Roman',serif" font-weight="bold" font-size="${size}" text-anchor="middle" fill="url(#${p}f)" stroke="url(#${p}o)" stroke-width="7" paint-order="stroke" letter-spacing="-4">${letter}</text>` +
        `<path d="M44 168h112" stroke="url(#${p}o)" stroke-width="5" stroke-linecap="round"/><circle cx="100" cy="168" r="6" fill="url(#${p}o)"/></g>`;
    return { defs, body };
}
const GOLD = (p) => lin(p + 'g', ['#fff6c2', '#f5c842', '#b8860b', '#6a4400'], 0, 0, 0, 1);
function ankh(p) {
    const defs = GOLD(p) + rad(p + 'b', ['#7fe0ff', '#0a74c4', '#032a55'], 0.35, 0.35, 0.7);
    const body = `<g filter="url(#${p}sh)"><path d="M100 18c-26 0-42 20-42 42 0 18 10 30 22 40H40v26h46v58h28v-58h46v-26h-40c12-10 22-22 22-40 0-22-16-42-42-42zm0 26c10 0 16 8 16 18 0 12-8 22-16 30-8-8-16-18-16-30 0-10 6-18 16-18z" fill="url(#${p}g)" stroke="#4a2e00" stroke-width="4" stroke-linejoin="round"/>` +
        `<circle cx="100" cy="113" r="9" fill="url(#${p}b)" stroke="#4a2e00" stroke-width="2"/><path d="M48 106h34" stroke="#fff" stroke-opacity=".5" stroke-width="4" stroke-linecap="round"/></g>`;
    return { defs, body };
}
function scarab(p) {
    const defs = GOLD(p) + rad(p + 'b', ['#9dffe0', '#0aa078', '#023d2c'], 0.4, 0.3, 0.8) + lin(p + 'w', ['#7fd4ff', '#1d6ee0'], 0, 0, 1, 0);
    const body = `<g filter="url(#${p}sh)"><path d="M88 96C50 70 14 74 8 96c26 4 50 14 74 30zM112 96c38-26 74-22 80 0-26 4-50 14-74 30z" fill="url(#${p}w)" stroke="#06275c" stroke-width="3"/>` +
        `<g stroke="#4a2e00" stroke-width="5" stroke-linecap="round"><path d="M76 120l-26 22M76 140l-22 30M124 120l26 22M124 140l22 30M84 70l-14-20M116 70l14-20"/></g>` +
        `<ellipse cx="100" cy="122" rx="34" ry="46" fill="url(#${p}b)" stroke="#4a2e00" stroke-width="4"/><path d="M100 80v86" stroke="#4a2e00" stroke-width="3"/>` +
        `<path d="M74 72a26 20 0 0 1 52 0z" fill="url(#${p}g)" stroke="#4a2e00" stroke-width="3"/><circle cx="100" cy="46" r="22" fill="url(#${p}g)" stroke="#4a2e00" stroke-width="3"/>` +
        `<ellipse cx="86" cy="108" rx="7" ry="14" fill="#fff" opacity=".4"/></g>`;
    return { defs, body };
}
function eye(p) {
    const defs = GOLD(p) + rad(p + 'i', ['#7fe0ff', '#0a74c4', '#021a3a'], 0.4, 0.4, 0.6) + lin(p + 'bg', ['#1d6ee0', '#0a2a66'], 0, 0, 0, 1);
    const body = `<g filter="url(#${p}sh)"><circle cx="100" cy="100" r="86" fill="url(#${p}bg)" stroke="url(#${p}g)" stroke-width="8"/>` +
        `<path d="M24 92C60 52 140 52 176 92 140 128 60 128 24 92z" fill="#fff8e6" stroke="#1a0d00" stroke-width="7" stroke-linejoin="round"/>` +
        `<circle cx="100" cy="92" r="24" fill="url(#${p}i)" stroke="#1a0d00" stroke-width="4"/><circle cx="100" cy="92" r="9" fill="#000"/><circle cx="92" cy="84" r="5" fill="#fff"/>` +
        `<path d="M28 66C64 40 136 40 172 66" stroke="#1a0d00" stroke-width="8" fill="none" stroke-linecap="round"/>` +
        `<path d="M84 118l-8 44c-2 10 10 16 18 8" stroke="#1a0d00" stroke-width="8" fill="none" stroke-linecap="round"/><path d="M120 118c10 20 30 30 44 22" stroke="#1a0d00" stroke-width="7" fill="none" stroke-linecap="round"/></g>`;
    return { defs, body };
}
function pharaoh(p) {
    const defs = GOLD(p) + lin(p + 'b', ['#3f8cff', '#0a2a66'], 0, 0, 0, 1) + lin(p + 'sk', ['#e8b070', '#a8682a'], 0, 0, 0, 1);
    let stripes = '';
    for (let i = 0; i < 7; i++) {
        const x = 34 + i * 19;
        stripes += `<path d="M${x} ${70 + Math.abs(3 - i) * 4}L${x - 4 + (i - 3) * 3} 186" stroke="url(#${p}b)" stroke-width="9"/>`;
    }
    const body = `<g filter="url(#${p}sh)"><path d="M100 14C58 14 36 40 34 72L22 186h156L166 72C164 40 142 14 100 14z" fill="url(#${p}g)" stroke="#4a2e00" stroke-width="4"/>${stripes}` +
        `<path d="M66 64h68v52c0 26-14 44-34 44s-34-18-34-44z" fill="url(#${p}sk)" stroke="#4a2e00" stroke-width="3"/>` +
        `<path d="M66 64c4-12 18-20 34-20s30 8 34 20" fill="url(#${p}b)" stroke="#4a2e00" stroke-width="3"/>` +
        `<path d="M74 92q10-6 20 0M106 92q10-6 20 0" stroke="#1a0d00" stroke-width="4" fill="none" stroke-linecap="round"/><path d="M76 100h16M108 100h16" stroke="#1a0d00" stroke-width="5" stroke-linecap="round"/>` +
        `<path d="M100 104v18M92 136q8 4 16 0" stroke="#7a4a1a" stroke-width="3" fill="none" stroke-linecap="round"/><path d="M92 158h16l-3 26h-10z" fill="url(#${p}b)" stroke="#4a2e00" stroke-width="2"/>` +
        `<path d="M100 30c6 0 10 6 10 12s-4 10-10 12c-6-2-10-6-10-12s4-12 10-12z" fill="url(#${p}b)" stroke="#4a2e00" stroke-width="2"/></g>` + sparkle(140, 34, 9);
    return { defs, body };
}
function sunDisc(p) {
    const defs = rad(p + 's', ['#fffbe0', '#ffd84a', '#f08000', '#a03a00'], 0.4, 0.35, 0.7) + GOLD(p) + lin(p + 'w', ['#7fd4ff', '#1d6ee0', '#0a2a66'], 0, 0, 0, 1);
    let rays = '';
    for (let i = 0; i < 16; i++) {
        const a = (i / 16) * Math.PI * 2;
        rays += `<polygon points="${pts([[100 + Math.cos(a - 0.09) * 50, 82 + Math.sin(a - 0.09) * 50], [100 + Math.cos(a) * 90, 82 + Math.sin(a) * 90], [100 + Math.cos(a + 0.09) * 50, 82 + Math.sin(a + 0.09) * 50]])}" fill="url(#${p}g)" opacity=".85"/>`;
    }
    const body = `<g filter="url(#${p}sh)">${rays}<path d="M20 82c20-10 40-14 56-8M180 82c-20-10-40-14-56-8" stroke="url(#${p}w)" stroke-width="14" fill="none" stroke-linecap="round"/>` +
        `<circle cx="100" cy="82" r="50" fill="url(#${p}s)" stroke="#7a3000" stroke-width="4"/><circle cx="100" cy="82" r="36" fill="none" stroke="#fff" stroke-opacity=".5" stroke-width="2"/></g>` + sparkle(84, 64, 12);
    const r = ribbon(p, 'WILD', '#1d6ee0', '#0a2a66', '#ffe9a0', 158, 30);
    return { defs: defs + r.defs, body: body + r.body };
}
function pyramid(p) {
    const defs = lin(p + 'l', ['#ffe9a0', '#e0a82e'], 0, 0, 1, 1) + lin(p + 'r', ['#c0802a', '#6a4400'], 0, 0, 1, 1) + rad(p + 'sun', ['#fff', '#ffd84a', '#ff900000'], 0.5, 0.5, 0.5);
    const body = `<circle cx="100" cy="40" r="40" fill="url(#${p}sun)"/><g filter="url(#${p}sh)"><polygon points="100,26 18,150 100,150" fill="url(#${p}l)" stroke="#4a2e00" stroke-width="4" stroke-linejoin="round"/><polygon points="100,26 182,150 100,150" fill="url(#${p}r)" stroke="#4a2e00" stroke-width="4" stroke-linejoin="round"/>` +
        [60, 86, 112].map((y) => `<path d="M${100 - (y - 26) * 0.66} ${y}H${100 + (y - 26) * 0.66}" stroke="#4a2e00" stroke-opacity=".35" stroke-width="2"/>`).join('') +
        `<polygon points="100,26 88,44 112,44" fill="#fff6c2"/></g>`;
    const r = ribbon(p, 'SCATTER', '#c0142c', '#5c0010', '#fff', 166, 22);
    return { defs: defs + r.defs, body: body + r.body };
}

// ---- candy -----------------------------------------------------------------

function glossy(p, c1, c2, c3, shapeD, extra = '') {
    const defs = rad(p + 'c', [c1, c2, c3], 0.35, 0.3, 0.8);
    const body = `<g filter="url(#${p}sh)"><path d="${shapeD}" fill="url(#${p}c)" stroke="${c3}" stroke-width="4" stroke-linejoin="round"/>${extra}</g>`;
    return { defs, body };
}
const bean = (p) => glossy(p, '#fff0b3', '#ffb300', '#a35a00', 'M44 76c20-34 84-48 112-18s-2 82-50 92-82-40-62-74z', '<ellipse cx="80" cy="76" rx="26" ry="10" fill="#fff" opacity=".6" transform="rotate(-20 80 76)"/>');
const drop = (p) => glossy(p, '#d6f3ff', '#29a3ff', '#063a7a', 'M100 14c30 44 64 76 64 112a64 64 0 0 1-128 0c0-36 34-68 64-112z', '<ellipse cx="78" cy="118" rx="12" ry="24" fill="#fff" opacity=".55" transform="rotate(15 78 118)"/><circle cx="72" cy="150" r="5" fill="#fff" opacity=".5"/>');
const cube = (p) => glossy(p, '#d8ffc2', '#38c43a', '#0d5a12', 'M44 30h112a14 14 0 0 1 14 14v112a14 14 0 0 1-14 14H44a14 14 0 0 1-14-14V44a14 14 0 0 1 14-14z', '<path d="M46 44h90" stroke="#fff" stroke-opacity=".7" stroke-width="10" stroke-linecap="round"/><path d="M30 100h140M100 30v140" stroke="#0d5a12" stroke-opacity=".25" stroke-width="3"/>');
function swirl(p) {
    const defs = rad(p + 'c', ['#f3d6ff', '#a64dff', '#3d0a7a'], 0.35, 0.3, 0.8);
    let s = 'M100 100';
    for (let a = 0; a < Math.PI * 6; a += 0.2) { const r = 6 + a * 3.6; s += `L${(100 + Math.cos(a) * r).toFixed(1)} ${(100 + Math.sin(a) * r).toFixed(1)}`; }
    const body = `<g filter="url(#${p}sh)"><circle cx="100" cy="100" r="80" fill="url(#${p}c)" stroke="#3d0a7a" stroke-width="4"/><path d="${s}" stroke="#fff" stroke-opacity=".85" stroke-width="9" fill="none" stroke-linecap="round"/><ellipse cx="70" cy="58" rx="22" ry="10" fill="#fff" opacity=".5" transform="rotate(-30 70 58)"/></g>`;
    return { defs, body };
}
const candyStar = (p) => glossy(p, '#ffe2c2', '#ff8a1f', '#8a3a00', 'M' + star(100, 104, 88, 42).map(([x, y]) => `${x.toFixed(1)} ${y.toFixed(1)}`).join('L') + 'Z', '<ellipse cx="82" cy="72" rx="16" ry="8" fill="#fff" opacity=".6" transform="rotate(-30 82 72)"/>');
function lollipop(p) {
    const defs = rad(p + 'c', ['#ffffff', '#ff7ab8', '#a0005a'], 0.35, 0.3, 0.8);
    let rings = '';
    for (let i = 0; i < 6; i++) rings += `<path d="M100 82m-${10 + i * 9} 0a${10 + i * 9} ${10 + i * 9} 0 0 1 ${2 * (10 + i * 9)} 0" stroke="${i % 2 ? '#fff' : '#ffe14d'}" stroke-width="6" fill="none" transform="rotate(${i * 50} 100 82)"/>`;
    const body = `<g filter="url(#${p}sh)"><rect x="94" y="120" width="12" height="72" rx="6" fill="#fff" stroke="#c9c9c9" stroke-width="2"/><circle cx="100" cy="82" r="66" fill="url(#${p}c)" stroke="#a0005a" stroke-width="4"/>${rings}<ellipse cx="74" cy="46" rx="18" ry="9" fill="#fff" opacity=".6" transform="rotate(-30 74 46)"/></g>`;
    return { defs, body };
}
function cupcake(p) {
    const defs = lin(p + 'w', ['#7ad7ff', '#1d6ee0'], 0, 0, 1, 0) + rad(p + 'f', ['#fff', '#ffc4e1', '#ff5fa8'], 0.4, 0.3, 0.8) + rad(p + 'ch', ['#ff9a9a', '#e0102c', '#6a0010'], 0.35, 0.3, 0.7);
    let sprinkles = '';
    for (let i = 0; i < 14; i++) sprinkles += `<rect x="${52 + rnd() * 96}" y="${62 + rnd() * 50}" width="10" height="3.5" rx="1.75" fill="${['#ffe14d', '#29a3ff', '#38c43a', '#fff', '#a64dff'][i % 5]}" transform="rotate(${rnd() * 180} ${60 + i * 6} ${80 + (i % 4) * 8})"/>`;
    const body = `<g filter="url(#${p}sh)"><path d="M44 110h112l-14 70H58z" fill="url(#${p}w)" stroke="#06275c" stroke-width="3.5"/>` +
        [64, 82, 100, 118, 136].map((x) => `<path d="M${x} 112l${(x - 100) * 0.12} 66" stroke="#fff" stroke-opacity=".5" stroke-width="3"/>`).join('') +
        `<path d="M36 112c-10-26 14-40 26-36-4-26 26-40 40-26 14-14 44 0 40 26 12-4 36 10 26 36z" fill="url(#${p}f)" stroke="#c2185b" stroke-width="3.5"/>${sprinkles}` +
        `<circle cx="104" cy="40" r="15" fill="url(#${p}ch)" stroke="#4a000c" stroke-width="2.5"/><path d="M106 26q6-14 18-16" stroke="#3a5a12" stroke-width="3" fill="none"/></g>`;
    return { defs, body };
}
function heart(p) {
    const c = crown(p + 'k', { label: '' });
    const defs = rad(p + 'h', ['#ffd6e6', '#ff2a6d', '#7a0030'], 0.35, 0.3, 0.8) + c.defs + shadow(p + 'ksh');
    const body = `<g filter="url(#${p}sh)"><path d="M100 184C40 140 14 108 14 74c0-26 20-44 44-44 18 0 32 10 42 24 10-14 24-24 42-24 24 0 44 18 44 44 0 34-26 66-86 110z" fill="url(#${p}h)" stroke="#7a0030" stroke-width="4"/>` +
        `<ellipse cx="54" cy="66" rx="20" ry="11" fill="#fff" opacity=".6" transform="rotate(-35 54 66)"/></g>` +
        `<g transform="translate(58 -6) scale(.42)">${c.body}</g>` + sparkle(150, 120, 10);
    return { defs, body };
}
function candyScatter(p) {
    const defs = rad(p + 'c', ['#fffbe0', '#ffd84a', '#d08000'], 0.35, 0.3, 0.8) + lin(p + 'w', ['#ff7ab8', '#a64dff'], 0, 0, 1, 1);
    const body = `<g filter="url(#${p}sh)"><path d="M48 92L10 58l6 70zM152 92l38-34-6 70z" fill="url(#${p}w)" stroke="#5a0a6a" stroke-width="3" stroke-linejoin="round"/>` +
        `<ellipse cx="100" cy="92" rx="58" ry="50" fill="url(#${p}c)" stroke="#8a4a00" stroke-width="4"/>` +
        [0, 1, 2, 3].map((i) => `<path d="M${62 + i * 24} 50q-12 42 0 84" stroke="#fff" stroke-opacity=".7" stroke-width="7" fill="none"/>`).join('') +
        `<ellipse cx="80" cy="66" rx="18" ry="8" fill="#fff" opacity=".7" transform="rotate(-25 80 66)"/></g>` + sparkle(150, 40, 14) + sparkle(42, 150, 9);
    const r = ribbon(p, 'SCATTER', '#ff2a6d', '#7a0030', '#fff', 162, 22);
    return { defs: defs + r.defs, body: body + r.body };
}
function bomb(p) {
    const defs = rad(p + 'b', ['#ffffff', '#ff7ab8', '#a64dff', '#29a3ff', '#1a0040'], 0.35, 0.3, 0.8) + lin(p + 'f', ['#ffe14d', '#ff5a00'], 0, 0, 0, 1);
    const body = `<g filter="url(#${p}sh)"><polygon points="${pts(star(100, 108, 84, 64, 12, 0))}" fill="url(#${p}f)" opacity=".85"/><circle cx="100" cy="108" r="62" fill="url(#${p}b)" stroke="#1a0040" stroke-width="4"/>` +
        `<path d="M100 46c0-16 10-26 24-28" stroke="#5a3a12" stroke-width="7" fill="none" stroke-linecap="round"/><ellipse cx="76" cy="80" rx="20" ry="11" fill="#fff" opacity=".65" transform="rotate(-30 76 80)"/></g>` +
        sparkle(126, 18, 13, '#ffe14d', 1);
    return { defs, body };
}

// ---- backgrounds / logos / posters ----------------------------------------

function bg(theme, free = false) {
    const W = 1280, H = 720;
    const t = theme;
    let defs = rad('v', [['#0000', 0.55], ['#000c', 1]], 0.5, 0.5, 0.75);
    let body = '';
    if (t === 'classic') {
        defs += rad('a', free ? ['#2a5cff', '#0d1a5c', '#030617'] : ['#c4122c', '#5c0012', '#1a0006'], 0.5, 0.4, 0.8) + lin('g', ['#ffd84a00', '#ffd84a33', '#ffd84a00'], 0, 0, 1, 0);
        body += `<rect width="${W}" height="${H}" fill="url(#a)"/>`;
        for (let i = -12; i < 24; i++) body += `<path d="M${i * 80} 0L${i * 80 + 720} ${H}" stroke="#ffd84a" stroke-opacity=".06" stroke-width="2"/><path d="M${i * 80 + 720} 0L${i * 80} ${H}" stroke="#ffd84a" stroke-opacity=".06" stroke-width="2"/>`;
        for (let i = 0; i < 24; i++) body += `<circle cx="${(i * 53.3) % W}" cy="${i % 2 ? 14 : H - 14}" r="6" fill="#ffd84a" opacity="${0.4 + (i % 3) * 0.2}"/>`;
        body += `<ellipse cx="${W / 2}" cy="-40" rx="520" ry="220" fill="url(#g)" opacity=".7"/>`;
    } else if (t === 'jewels') {
        defs += rad('a', free ? ['#0f7a5c', '#06302a', '#010a08'] : ['#4a1f8a', '#1a0a40', '#05020f'], 0.5, 0.45, 0.85) + lin('c', free ? ['#0a5c40', '#021a12'] : ['#7a0a2a', '#2a0010'], 0, 0, 1, 0);
        body += `<rect width="${W}" height="${H}" fill="url(#a)"/>`;
        for (const side of [0, 1]) {
            let d = side ? `M${W} 0` : 'M0 0';
            for (let i = 0; i <= 6; i++) { const y = i * 120, x = (side ? W - 150 : 150) + (i % 2 ? 1 : -1) * 18 * (side ? -1 : 1); d += `L${x} ${y}`; }
            d += side ? `L${W} ${H}Z` : `L0 ${H}Z`;
            body += `<path d="${d}" fill="url(#c)" opacity=".9"/>`;
            for (let i = 0; i < 6; i++) body += `<path d="M${side ? W - 30 - i * 20 : 30 + i * 20} 0V${H}" stroke="#000" stroke-opacity=".18" stroke-width="6"/>`;
        }
        for (let i = 0; i < 70; i++) body += sparkle(rnd() * W, rnd() * H, 2 + rnd() * 6, i % 3 ? '#fff' : '#ffd84a', 0.2 + rnd() * 0.6);
    } else if (t === 'egypt') {
        defs += lin('sky', free ? ['#0a0a3a', '#3a1a6a', '#c0508a'] : ['#2a0f4a', '#c0502a', '#ffb050'], 0, 0, 0, 1) + rad('sun', ['#fff8d0', '#ffd84a', '#ff900000'], 0.5, 0.5, 0.5) + lin('dune', ['#d89040', '#7a4410'], 0, 0, 0, 1) + lin('pyr', ['#a0602a', '#3a1a06'], 0, 0, 1, 0);
        body += `<rect width="${W}" height="${H}" fill="url(#sky)"/><circle cx="${W / 2}" cy="${H * 0.52}" r="260" fill="url(#sun)" opacity="${free ? 0.5 : 0.9}"/>`;
        if (free) for (let i = 0; i < 90; i++) body += `<circle cx="${rnd() * W}" cy="${rnd() * H * 0.5}" r="${0.6 + rnd() * 1.6}" fill="#fff" opacity="${0.3 + rnd() * 0.7}"/>`;
        body += `<polygon points="120,560 300,250 480,560" fill="url(#pyr)"/><polygon points="820,560 1020,210 1220,560" fill="url(#pyr)"/><polygon points="980,560 1100,360 1220,560" fill="#2a1004" opacity=".6"/>`;
        body += `<path d="M0 560Q320 500 640 560T1280 540V720H0Z" fill="url(#dune)"/><path d="M0 620Q400 580 760 630T1280 610V720H0Z" fill="#6a3a0a" opacity=".7"/>`;
        for (const x of [0, W - 70]) {
            body += `<rect x="${x}" y="0" width="70" height="${H}" fill="#3a2006" opacity=".85"/><rect x="${x + 6}" y="0" width="58" height="${H}" fill="none" stroke="#e0a82e" stroke-width="3" opacity=".7"/>`;
            for (let i = 0; i < 10; i++) {
                const y = 30 + i * 70, cx = x + 35;
                body += [`<circle cx="${cx}" cy="${y + 10}" r="10" fill="none" stroke="#e0a82e" stroke-width="3" opacity=".6"/>`, `<path d="M${cx - 12} ${y + 20}l12-22 12 22z" fill="none" stroke="#e0a82e" stroke-width="3" opacity=".6"/>`, `<path d="M${cx} ${y}v24M${cx - 10} ${y + 8}h20" stroke="#e0a82e" stroke-width="3" opacity=".6"/>`][i % 3];
            }
        }
    } else if (t === 'candy') {
        defs += lin('sky', free ? ['#3a1a7a', '#a64dff', '#ff9ad0'] : ['#7fd4ff', '#ffc4e1', '#fff0f6'], 0, 0, 0, 1);
        body += `<rect width="${W}" height="${H}" fill="url(#sky)"/>`;
        for (let i = 0; i < 6; i++) { const x = 80 + i * 230 + rnd() * 40, y = 60 + rnd() * 120; body += `<g opacity=".85" fill="#fff"><ellipse cx="${x}" cy="${y}" rx="70" ry="26"/><circle cx="${x - 26}" cy="${y - 12}" r="26"/><circle cx="${x + 20}" cy="${y - 20}" r="32"/></g>`; }
        const hill = (cx, r, c) => `<circle cx="${cx}" cy="${H + r * 0.45}" r="${r}" fill="${c}"/>`;
        body += hill(160, 300, '#ff7ab8') + hill(640, 360, '#a64dff') + hill(1120, 300, '#29a3ff') + hill(400, 220, '#ffb300') + hill(900, 240, '#38c43a');
        for (const [x, h, c] of [[60, 260, '#ff2a6d'], [1220, 300, '#ffb300'], [260, 200, '#29a3ff'], [1040, 220, '#ff7ab8']]) {
            body += `<rect x="${x - 6}" y="${H - h}" width="12" height="${h}" fill="#fff" stroke="#ddd"/><circle cx="${x}" cy="${H - h}" r="46" fill="${c}" stroke="#fff" stroke-width="6"/><path d="M${x - 30} ${H - h}a30 30 0 0 1 60 0a20 20 0 0 1-40 0a10 10 0 0 1 20 0" stroke="#fff" stroke-width="5" fill="none"/>`;
        }
        for (let i = 0; i < 40; i++) body += sparkle(rnd() * W, rnd() * H * 0.6, 3 + rnd() * 6, '#fff', 0.5 + rnd() * 0.5);
    }
    body += `<rect width="${W}" height="${H}" fill="url(#v)"/>`;
    return svg(W, H, body, defs);
}

function logo(title, style) {
    const W = 640, H = 170;
    const font = style === 'candy' ? "'Trebuchet MS','Arial Rounded MT Bold',Verdana,sans-serif" : "Georgia,'Times New Roman',serif";
    const fill = style === 'candy' ? lin('f', ['#ffffff', '#ffd6e6', '#ff2a6d'], 0, 0, 0, 1) : lin('f', ['#fffbe0', '#ffd84a', '#c08000', '#ffd84a'], 0, 0, 0, 1);
    const stroke = style === 'candy' ? '#7a0030' : '#3a1a00';
    const size = Math.min(84, 1080 / Math.max(8, title.length));
    const k = crown('lg', { label: '' });
    const defs = fill + shadow('lgsh', 6, 4, 0.7) + k.defs;
    const body = `<g transform="translate(${W / 2 - 34} -4) scale(.34)">${k.body}</g>` +
        `<text x="${W / 2}" y="${H - 34}" font-family="${font}" font-weight="bold" font-size="${size}" text-anchor="middle" fill="url(#f)" stroke="${stroke}" stroke-width="8" paint-order="stroke" filter="url(#lgsh)" letter-spacing="2">${title}</text>`;
    return svg(W, H, body, defs);
}

function poster(code, title, theme, symbols, style) {
    const inner = (s) => s.replace(/^<svg[^>]*>/, '').replace(/<\/svg>\s*$/, '');
    // namespace each embedded svg's ids so they don't collide
    const ns = (s, k) => inner(s).replace(/id="([^"]+)"/g, `id="${k}$1"`).replace(/url\(#([^)]+)\)/g, `url(#${k}$1)`);
    const bgSvg = ns(bg(theme), 'bg');
    const font = style === 'candy' ? "'Trebuchet MS',Verdana,sans-serif" : "Georgia,'Times New Roman',serif";
    const place = [[22, 120, 150, -10], [228, 120, 150, 10], [110, 70, 190, 0]];
    let body = `<g transform="scale(${400 / 720}) translate(-280 0)">${bgSvg}</g>`;
    symbols.forEach((s, i) => { const [x, y, sz, r] = place[i]; body += `<g transform="translate(${x} ${y}) rotate(${r} ${sz / 2} ${sz / 2}) scale(${sz / 200})">${ns(s, 'p' + i)}</g>`; });
    const lg = ns(logo(title, style), 'lg');
    body += `<rect y="300" width="400" height="100" fill="#000" opacity=".35"/><g transform="translate(0 290) scale(${400 / 640})">${lg}</g>`;
    body += `<text x="388" y="22" font-family="${font}" font-size="14" font-weight="bold" text-anchor="end" fill="#ffd84a" opacity=".9">RoyalSpin</text>`;
    return svg(400, 400, body);
}

// ---- games -----------------------------------------------------------------

const games = {
    RoyalSevensRS: {
        title: 'Royal Sevens', theme: 'classic', style: 'classic', poster: [0, 6, 7],
        symbols: [cherry, (p) => citrus(p, '#fffbe0', '#ffe14d', '#b89000', 'lemon'), (p) => citrus(p, '#ffe2c2', '#ff8a1f', '#a04000', 'orange'), plum, grapes, melon, seven, (p) => starSym(p)],
    },
    CrownJewelsRS: {
        title: 'Crown Jewels', theme: 'jewels', style: 'royal', poster: [4, 5, 7],
        symbols: [
            (p) => gem(p, { n: 4, rx: 74, ry: 74, rot: -90, light: '#bfe3ff', mid: '#1d6ee0', dark: '#06275c', table: 0.48 }),
            (p) => gem(p, { n: 8, rx: 64, ry: 82, rot: -67.5, light: '#c2ffd8', mid: '#0fa060', dark: '#00401f', table: 0.55 }),
            (p) => gem(p, { n: 3, rx: 86, ry: 86, rot: -90, light: '#f3d6ff', mid: '#9c27d0', dark: '#3d0a5a', table: 0.45 }),
            (p) => gem(p, { n: 6, rx: 80, ry: 74, rot: 0, light: '#fff0c2', mid: '#ff9d1f', dark: '#7a3a00', table: 0.5 }),
            (p) => gem(p, { n: 10, rx: 80, ry: 80, rot: -90, light: '#ffc2c8', mid: '#d10f2f', dark: '#5c0010', table: 0.52 }),
            diamond, ring, (p) => crown(p), chest,
        ],
    },
    PharaohsRichesRS: {
        title: "Pharaoh's Riches", theme: 'egypt', style: 'royal', poster: [7, 8, 10],
        symbols: [
            (p) => cardLetter(p, '10', '#9dd4ff', '#1d5fc4'), (p) => cardLetter(p, 'J', '#b8ffb0', '#1f8a2b'), (p) => cardLetter(p, 'Q', '#efc2ff', '#7a1fa2'),
            (p) => cardLetter(p, 'K', '#ffb8b8', '#c0142c'), (p) => cardLetter(p, 'A', '#fff2a8', '#d08000'),
            ankh, scarab, eye, pharaoh, sunDisc, pyramid,
        ],
    },
    CandyRoyaleRS: {
        title: 'Candy Royale', theme: 'candy', style: 'candy', poster: [5, 7, 6],
        symbols: [bean, drop, cube, swirl, candyStar, lollipop, cupcake, heart, candyScatter, bomb],
    },
};

const write = (file, content) => { mkdirSync(dirname(file), { recursive: true }); writeFileSync(file, content); };

for (const [code, g] of Object.entries(games)) {
    const dir = join(ROOT, code);
    const rendered = g.symbols.map((fn, i) => { const { defs, body } = fn('s' + i + '_'); return sym('s' + i + '_', body, defs); });
    rendered.forEach((s, i) => write(join(dir, 'img', 'sym', `${i}.svg`), s));
    write(join(dir, 'img', 'bg.svg'), bg(g.theme));
    write(join(dir, 'img', 'bg_free.svg'), bg(g.theme, true));
    write(join(dir, 'img', 'logo.svg'), logo(g.title, g.style));
    write(join(dir, 'poster.svg'), poster(code, g.title, g.theme, g.poster.map((i) => rendered[i]), g.style));
    console.log('art', code, rendered.length, 'symbols');
}
