// Load a Microgaming game page (a Betboy DEMO launch) in headless Chromium and
// record what it loads: every URL, and the bodies of what the session host
// answers (its config, API replies). Used by MicrogamingMirror.
//
//   node capture.cjs <launch url> <out.json> [wait ms]
//
// Playwright comes from MICROGAMING_PLAYWRIGHT (its node_modules folder).
const { chromium } = require((process.env.MICROGAMING_PLAYWRIGHT || '/opt/pw/node_modules') + '/playwright');
const fs = require('fs');

(async () => {
  const [url, out] = [process.argv[2], process.argv[3]];
  const wait = +(process.argv[4] || 35000);
  const session = new URL(url).host;
  const b = await chromium.launch({ args: ['--use-gl=swiftshader', '--enable-unsafe-swiftshader', '--autoplay-policy=no-user-gesture-required'] });
  const p = await b.newPage({ viewport: { width: 1280, height: 720 } });
  const requests = [];
  const errors = [];
  p.on('pageerror', e => errors.push(String(e).slice(0, 300)));
  p.on('response', async r => {
    const u = r.url();
    const item = { url: u, method: r.request().method(), status: r.status(), type: r.headers()['content-type'] || '', location: r.headers()['location'] || '' };
    if (new URL(u).host === session) {
      item.request = r.request().postData() || '';
      try { item.body = await r.text(); } catch (e) { item.body = ''; }
    }
    requests.push(item);
  });
  await p.goto(url, { waitUntil: 'domcontentloaded' }).catch(e => errors.push('goto: ' + e.message));
  await p.waitForTimeout(wait);
  // past the intro / splash (a centre tap; Gods of Giza's "next" arrow, lower right), then a spin or
  // two (Space; the classic shell's spin button, right edge): what loads on demand
  // (708, 480): its "Turn sounds on?" NO
  for (const [x, y] of [[640, 400], [1060, 572], [708, 480], [640, 680], [640, 400]]) { await p.mouse.click(x, y).catch(() => {}); await p.waitForTimeout(2500); }
  for (const key of ['Space', 'Space']) { await p.keyboard.press(key).catch(() => {}); await p.waitForTimeout(6000); }
  await p.mouse.click(1210, 360).catch(() => {}); await p.waitForTimeout(6000);
  await p.screenshot({ path: out.replace(/\.json$/, '.png') }).catch(() => {});
  fs.writeFileSync(out, JSON.stringify({ url, requests, errors }));
  await b.close();
})();
