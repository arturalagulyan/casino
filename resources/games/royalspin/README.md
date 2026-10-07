# RoyalSpin — first-party games

Our own games with our own front-end engine and server math. No legacy provider
behind them: they speak the platform's standard JSON protocol
(`POST /api/game/{code}/server`) and run on the shared engines.

| Code | Title | Mechanic | Server |
|---|---|---|---|
| `RoyalSevensRS` | Royal Sevens | 5x3, 5 lines, fruit classic, star scatter, gamble | `LineSlotServer` |
| `CrownJewelsRS` | Crown Jewels | 5x3, 10 lines, wild x2, 10/15/20 free spins x2, gamble | `LineSlotServer` |
| `PharaohsRichesRS` | Pharaoh's Riches | 5x3, 20 lines, wild, 12 free spins x3, gamble | `LineSlotServer` |
| `CandyRoyaleRS` | Candy Royale | 6x5 scatter-pays cascade, multiplier bombs in free spins | `CascadeSlotServer` |

## Layout

```
engine/                  shared front-end (copied into every bundle)
  index.html             entry; {{BUILD}} is replaced per build (cache-bust)
  js/rs-engine.js        runtime: reels + cascade renderers, HUD, free spins,
                         gamble, autoplay, paytable, sound, big-win fx
  css/rs-engine.css
  snd/*.wav              sound effects
games/<Code>/
  math.json              server math -> game_templates row (NOT shipped)
  game.json              client theme, symbol names, feature text
  img/sym/<id>.svg       symbols; img/bg.svg, bg_free.svg, logo.svg
  snd/music.wav, music_free.wav
  poster.svg             lobby poster (-> public disk, NOT shipped)
tools/                   generators (node, no dependencies)
  make-math.mjs          -> games/*/math.json (paytables, strips, lines)
  make-art.mjs           -> all SVG art + posters
  make-sounds.mjs        -> all WAV sound effects + music loops
```

## Workflow

```bash
# after editing a generator, regenerate its output
node resources/games/royalspin/tools/make-math.mjs
node resources/games/royalspin/tools/make-art.mjs
node resources/games/royalspin/tools/make-sounds.mjs

# build bundles + (re)register category, templates and per-shop games
php artisan royalspin:install                    # every shop; unchanged bundles are skipped
php artisan royalspin:install --only=CandyRoyaleRS --fresh-bundles
php artisan royalspin:install --shop=4
```

Math changes in `math.json` are applied to the template on every install.
Per-shop tuning (RTP, bets, win chances, max win) lives on the `games` row
and is edited in the admin panel as for any other game.

## Adding a game

1. Add an entry to `tools/make-math.mjs` (line game: paylines + strips;
   cascade game: add `tumble_config`) and to `tools/make-art.mjs`
   (symbols, theme, poster); add music to `tools/make-sounds.mjs`.
2. Write `games/<Code>/game.json` (`mechanic`, theme colours, symbol names, feature text).
3. Run the generators, then `php artisan royalspin:install --only=<Code>`.

Codes end in `RS` (a known provider suffix, so titles derive cleanly).

## Art packs & skins

```
packs/<key>/pack.json    stock art for the Game Builder: symbols (key, name,
                         kind low|high|wild|scatter|multiplier), backgrounds,
                         character, multiplier orbs, logo, music, default theme
games/<Code>/            every shipped game's art doubles as a pack too
tools/make-olympus.mjs   -> packs/olympus (gems, chalice, ring, hourglass, crown,
                         thunder-god scatter, orbs, temple + storm backgrounds,
                         the god character, logo); music comes from make-sounds
```

The engine has two **skins** (`game.json` → `skin`):

- `classic` — centred board in a rounded gold frame (the original look).
- `olympus` — Gates-of-Olympus-style: board left of centre in an ornate gold
  frame, the character standing to its right (lightning strikes onto landing
  multiplier orbs), a left panel with BUY FREE SPINS / ante bet / TOTAL
  MULTIPLIER, Pragmatic-style bottom bar.

`game.json` can also point at any picture instead of the stock file names:
`symbolImages` (`{"3": "img/sym/3.png"}`), `assets` (`background`,
`background_free`, `logo`, `character`, `music`, `music_free`) and `orbs`
(`[{"upTo": 5, "file": "img/orb/green.svg"}, …, {"upTo": null, …}]`).

## Cascade extras (tumble_config)

| key | meaning |
|---|---|
| `multiplier_in_base` | multiplier orbs land (and multiply) in the base game too |
| `multiplier_accumulate` | free spins keep a running TOTAL multiplier (session `free_spins_multiplier`) that multiplies every later winning round |
| `buy_feature` | price of buying the free spins, × stake (`bet` request with `buy: true`) |
| `ante_bet` / `ante_bonus_factor` | stake factor of the ante bet (`ante: true`) / how much likelier the feature is |

## Game Builder (admin → Games → Game Builder)

Admins design games without code: start **from a preset** (Olympus 6x5 cascade,
Olympus 5x3 lines, classic 3x3, jewels 5x3), **re-design a shipped game** (same
code = re-skin the live game) or start blank. Tabs: board + mechanic + art pack
+ skin, symbols (art or upload, role, pays, weight), paylines (generated for
any board, or custom), features, look & feel (colours, font, logo style,
uploads), bets & odds. **Publish** builds the math (`GameBuilder::math`),
the bundle (engine + art + generated `game.json`/logo/poster) and adds the game
to the chosen shops' RoyalSpin category. `royalspin:install` skips games a
design owns (`layout.builder_design`) unless `--take-back`.
