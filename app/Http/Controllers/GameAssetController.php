<?php

namespace App\Http\Controllers;

use App\Enums\ClientProtocol;
use App\Models\Game;
use App\Models\GameBundle;
use App\Models\GameSession;
use App\Models\GameTemplate;
use App\Models\Jackpot;
use App\Models\User;
use App\Services\Fx;
use App\Services\GamePlay\GameConfig;
use App\Services\GamePlay\NetEnt\NetEntCurrencyFile;
use App\Services\GamePlay\Protocol\GamePlatformLobby;
use App\Services\GamePlay\Wazdan\WazdanLiveClient;
use App\Services\GamePlay\Wazdan\WazdanMirror;
use App\Services\Legacy\LegacyGameReader;
use App\Services\SeamlessWallet\GameLaunch;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mime\MimeTypes;

/**
 * Serves the uploaded front-end bundle for a game and boots a play session.
 *
 *   GET /games/{code}?token=<launch token>   → verify, open a game session,
 *                                              serve the entry HTML with a
 *                                              <script> telling the frontend
 *                                              where to POST commands.
 *   GET /games/{code}/{path}                  → stream a bundle asset (static).
 */
class GameAssetController extends Controller
{
    public function __construct(private GameLaunch $launcher, private Fx $fx) {}

    public function play(Request $request, string $code): Response
    {
        $template = GameTemplate::where('code', $code)->firstOr(fn () => abort(404));
        $bundle = $template->activeBundle;

        if (! $bundle) {
            // No uploaded files yet — fall back to the built-in demo shell so the
            // pipeline still works for engine=internal games.
            return $this->demoShell($request, $template);
        }

        ['user' => $user, 'game' => $game] = $this->resolveFromToken($request, $template);

        $session = GameSession::updateOrCreate(
            ['user_id' => $user->id, 'game_id' => $game->id],
            ['token' => bin2hex(random_bytes(20)), 'is_active' => true, 'last_seen_at' => now()],
        );

        // Novomatic / Greentube bundle — ships only its JS engine, no HTML. Build
        // the loader shell at request time (the bundle is never modified).
        if ($bundle->entry === LegacyGameReader::SLOT_EVENT_SHELL || $this->isEngineOnlyBundle($bundle)) {
            return $this->slotEventShell($request, $template, $game, $session, $bundle, $user);
        }

        // Playtech bundle — a compiled GWT "platform" chrome app that
        // boots a nested "bib" game app. The top-level page legacy served was
        // never a bundle file (it was a per-game Blade view); synthesise it.
        if ((new GameConfig($template, $game))->clientProtocol() === ClientProtocol::Playtech) {
            return $this->playtechShell($template, $game, $session, $user);
        }

        // Play'n GO — its launcher page was never part of the game build; synthesise it.
        if ((new GameConfig($template, $game))->clientProtocol() === ClientProtocol::PlayNGo) {
            return $this->playNGoShell($request, $template, $game, $session, $user);
        }

        $rel = $bundle->filePath($bundle->entry) ?? abort(500, 'Bundle entry file missing.');
        $html = $bundle->disk()->get($rel);

        // Legacy bundles nest the entry (amarent/index.html, gs2c/html5Game.html,
        // app/<slug>/index.html, …). Relative asset URLs inside it must resolve
        // against the entry's own directory, not the bundle root — and even a
        // root-level index.html needs this: the launch URL is `/games/{code}`
        // with no trailing slash, so relative paths would otherwise resolve one
        // level too high, against `/games/` instead of `/games/{code}/`
        // (verified against Pragmatic's `AncientEgyptPM`, whose relative
        // `desktop/bootstrap.js` etc. requests landed on `/games/desktop/…`
        // and 404'd). Bundles that ship their own <base> (EGT's
        // "/games/<Code>/html5/") are left alone — `code` is unchanged, so
        // those absolute paths still hit the asset route.
        //
        // The base carries the bundle version (`/games/<Code>~v2/…`, see
        // asset()) so a re-uploaded bundle never mixes with files the browser
        // cached from the previous one under the same name — e.g. an Amatic
        // config whose name stays `config_1373_<hash>.js` across re-imports.
        $entryDir = trim(str_replace('\\', '/', dirname((string) $bundle->entry)), '/.');
        if (! preg_match('/<base\s/i', $html)) {
            $base = rtrim(url("/games/{$code}~v{$bundle->version}/{$entryDir}"), '/').'/';
            $html = $this->injectHead($html, "<base href=\"{$base}\">");
        }

        $config = new GameConfig($template, $game);

        // NetEnt's HTML5 client reads its launch parameters off the page URL —
        // the ones NetEnt's launcher adds: which game, which servlet (ours, under
        // /netent/{session}/), which session, which language.
        if ($config->clientProtocol() === ClientProtocol::NetEnt) {
            $netent = (array) ($config->bonusConfig()['netent'] ?? []);
            $languages = (array) ($netent['languages'] ?? ['en']);
            $lang = substr((string) ($request->query('lang') ?: app()->getLocale()), 0, 2);
            $params = json_encode(http_build_query([
                'gameId' => (string) ($netent['game_id'] ?? ''),
                'server' => url("/netent/{$session->token}").'/',
                'sessId' => $session->token,
                'lang' => in_array($lang, $languages, true) ? $lang : 'en',
                'lobbyURL' => '',
                'operatorId' => 'default',
            ]));
            $inject = "<script>(function(){var s=location.search;history.replaceState(null,'',location.pathname+s+(s?'&':'?')+{$params});})();</script>";

            return response($this->injectHead($html, $inject.$this->jackpotTickerSnippet($game, $user)))
                ->header('Content-Type', 'text/html');
        }

        // IGT's page reads `currency`, `denom`, `gameStatic` (the game's folder)
        // and `server` (ours: /igt/{session}/rgs?) off its URL — split on `&`
        // and `=` without decoding, so they go in as they are.
        if ($config->clientProtocol() === ClientProtocol::Igt) {
            $folder = (string) ($config->bonusConfig()['igt']['folder'] ?? '');
            $iso = $user->wallet?->currency;
            $params = json_encode('currency='.($iso instanceof \BackedEnum ? $iso->value : (string) ($iso ?: 'EUR')).'&denom='.((float) ($config->bonusConfig()['igt']['denom'] ?? 0.01))
                .'&gameStatic='.url("/games/{$code}~v{$bundle->version}/{$folder}").'/'
                .'&server='.url("/igt/{$session->token}/rgs").'?');
            $inject = "<script>(function(){history.replaceState(null,'',location.pathname+'?'+{$params});})();</script>";

            return response($this->injectHead($html, $inject.$this->jackpotTickerSnippet($game, $user)))
                ->header('Content-Type', 'text/html');
        }

        // Booongo: our page (built by BooongoMirror) starts the game runner with
        // its server, token and language from window.BNG — the runner then
        // POSTs `{server}?gsc=<command>` to /booongo/{session}/engine.
        if ($config->clientProtocol() === ClientProtocol::Booongo) {
            $languages = (array) ($config->bonusConfig()['booongo']['languages'] ?? ['en']);
            $lang = strtolower(substr((string) ($request->query('lang') ?: app()->getLocale()), 0, 2));
            $bng = json_encode(['server' => url("/booongo/{$session->token}/engine"), 'token' => $session->token,
                'lang' => in_array($lang, $languages, true) ? $lang : 'en'], JSON_UNESCAPED_SLASHES);
            $inject = "<script>window.BNG={$bng};</script>";

            return response($this->injectHead($html, $inject.$this->jackpotTickerSnippet($game, $user)))
                ->header('Content-Type', 'text/html');
        }

        // Pragmatic Play: our page (the legacy client's gs2c/html5Game.html, see
        // PragmaticLegacyBundle) hands Html5GameManager the gameConfig in window.PRG —
        // the recorded one with our game service (/pragmatic/{session}/gameService:
        // the client derives saveSettings.do etc. from its folder), the game's
        // datapath in this bundle, the language and a blank (fun-style) currency
        // sign replaced by the wallet's.
        if ($config->clientProtocol() === ClientProtocol::PragmaticPlay) {
            $prg = (array) ($config->bonusConfig()['pragmatic'] ?? []);
            $languages = (array) ($prg['languages'] ?? ['en']);
            $lang = strtolower(substr((string) ($request->query('lang') ?: app()->getLocale()), 0, 2));
            $iso = $user->wallet?->currency;
            $iso = $iso instanceof \BackedEnum ? (string) $iso->value : (string) ($iso ?: 'EUR');
            $datapath = url("/games/{$code}~v{$bundle->version}/".($prg['datapath'] ?? $prg['symbol'])).'/';
            $gameConfig = array_merge((array) ($prg['config'] ?? []), [
                'datapath' => $datapath,
                'datapath_alternative' => $datapath,
                'gameService' => url("/pragmatic/{$session->token}/gameService"),
                'mgckey' => $session->token,
                'lang' => in_array($lang, $languages, true) ? $lang : 'en',
                'currency' => $iso, 'currencyOriginal' => $iso,
            ]);
            $inject = '<script>window.PRG='.json_encode(['config' => $gameConfig], JSON_UNESCAPED_SLASHES).';</script>';

            return response($this->injectHead($html, $inject.$this->jackpotTickerSnippet($game, $user)))
                ->header('Content-Type', 'text/html');
        }

        // Merkur (Edict): the launcher (iframe2.html, base /mk/commonStatic2/)
        // reads `game`, `lang`, `serverURL` and `session` off its URL — split on
        // `&` and `=` without decoding, so they go in as they are. serverURL is
        // ours: the launcher appends WebEngine.php's "path" as the query string.
        if ($config->clientProtocol() === ClientProtocol::Merkur) {
            $merkur = (array) ($config->bonusConfig()['merkur'] ?? []);
            $languages = (array) ($merkur['languages'] ?? ['en']);
            $lang = strtolower(substr((string) ($request->query('lang') ?: app()->getLocale()), 0, 2));
            $html = str_replace('href="/mk/commonStatic2/"', 'href="'.url("/games/{$code}~v{$bundle->version}/commonStatic2").'/"', $html);
            $params = json_encode('game='.($merkur['game_key'] ?? '').'&lang='.(in_array($lang, $languages, true) ? $lang : 'en')
                .'&serverURL='.url("/merkur/{$session->token}/engine").'?&session='.url("/merkur/{$session->token}/session"), JSON_UNESCAPED_SLASHES);
            $inject = "<script>(function(){history.replaceState(null,'',location.pathname+'?'+{$params});})();</script>";

            return response($this->injectHead($html, $inject.$this->jackpotTickerSnippet($game, $user)))
                ->header('Content-Type', 'text/html');
        }

        // Novoline (Greentube): our page (built by NovolineMirror) starts the client
        // with `hostname` = window.NOVO.host; the client opens
        // wss://<hostname>/mux?websocket=… — a WebSocket shim (for that host only)
        // POSTs each frame to /novoline/{session}/ws, in order, and hands back the
        // frames the reply lists, one message each.
        if ($config->clientProtocol() === ClientProtocol::Novoline) {
            $server = url("/novoline/{$session->token}/ws");
            $host = (string) preg_replace('#^https?://#', '', url("/novoline/{$session->token}"));
            $novo = json_encode(['host' => $host, 'room' => '1']);
            $serverJs = json_encode($server);
            $inject = "<script>window.NOVO={$novo};(function(){var S={$serverJs},R=window.WebSocket,key=window.NOVO.host;"
                .'function F(u){var t=this;t.url=u;t.readyState=0;t.protocol="";t.extensions="";t.bufferedAmount=0;t.binaryType="blob";t._h={};t._q=Promise.resolve();'
                .'setTimeout(function(){t.readyState=1;t._e("open",{type:"open",target:t});},0);}'
                .'F.CONNECTING=0;F.OPEN=1;F.CLOSING=2;F.CLOSED=3;'
                .'F.prototype._e=function(n,e){var f=this["on"+n];f&&f.call(this,e);(this._h[n]||[]).slice().forEach(function(g){g.call(this,e);},this);};'
                .'F.prototype.addEventListener=function(n,f){(this._h[n]=this._h[n]||[]).push(f);};'
                .'F.prototype.removeEventListener=function(n,f){this._h[n]=(this._h[n]||[]).filter(function(g){return g!==f;});};'
                .'F.prototype.send=function(d){var t=this;t._q=t._q.then(function(){if(t.readyState!==1)return;'
                .'return fetch(S,{method:"POST",body:String(d),headers:{"Content-Type":"text/plain;charset=UTF-8","X-Requested-With":"XMLHttpRequest"},credentials:"same-origin"})'
                .'.then(function(r){if(r.status===410){t.close();return[];}return r.ok?r.json():[];})'
                .'.then(function(a){(a||[]).forEach(function(m){if(t.readyState===1)t._e("message",{type:"message",data:m,target:t});});})'
                .'.catch(function(){});});};'
                .'F.prototype.close=function(){if(this.readyState===3)return;this.readyState=3;var t=this;setTimeout(function(){t._e("close",{type:"close",code:1000,reason:"",wasClean:true,target:t});},0);};'
                .'var W=function(u,p){return String(u).replace(/^wss?:\/\//i,"").toLowerCase().indexOf(key.toLowerCase())===0?new F(u):(p===undefined?new R(u):new R(u,p));};'   // the client writes WSS://
                .'W.CONNECTING=0;W.OPEN=1;W.CLOSING=2;W.CLOSED=3;W.prototype=R.prototype;window.WebSocket=W;})();</script>';

            return response($this->injectHead($html, $inject.$this->jackpotTickerSnippet($game, $user)))
                ->header('Content-Type', 'text/html');
        }

        // EGT: our page (built by EgtMirror) completes the client's connectionParams
        // from window.EGT — the client then opens
        // `<protocol>://<host>:<port>?sessionKey=…` with host = /egt/{session}/ws/
        // on our origin; a WebSocket shim (for that host only) POSTs each frame
        // to /egt/{session}/ws, in order, and hands back the frames the reply lists.
        if ($config->clientProtocol() === ClientProtocol::Egt) {
            $languages = (array) ($config->bonusConfig()['egt']['languages'] ?? ['en']);
            $lang = strtolower(substr((string) ($request->query('lang') ?: app()->getLocale()), 0, 2));
            $server = url("/egt/{$session->token}/ws");
            $host = (string) preg_replace('#^https?://#', '', $server).'/';
            $egt = json_encode([
                'host' => $host, 'port' => (string) (parse_url($server, PHP_URL_PORT) ?: (str_starts_with($server, 'https') ? 443 : 80)),
                'protocol' => str_starts_with($server, 'https') ? 'wss' : 'ws', 'sessionKey' => $session->token,
                'lang' => in_array($lang, $languages, true) ? $lang : 'en',
                'baseUrl' => rtrim((string) parse_url(url("/games/{$code}~v{$bundle->version}"), PHP_URL_PATH), '/'),
                'player' => 'player'.$user->id,
            ], JSON_UNESCAPED_SLASHES);
            $serverJs = json_encode($server, JSON_UNESCAPED_SLASHES);
            $inject = "<script>window.EGT={$egt};(function(){var S={$serverJs},R=window.WebSocket,key=window.EGT.host.replace(/\/+$/,'');"
                .'function F(u){var t=this;t.url=u;t.readyState=0;t.protocol="";t.extensions="";t.bufferedAmount=0;t.binaryType="blob";t._h={};t._q=Promise.resolve();'
                .'setTimeout(function(){t.readyState=1;t._e("open",{type:"open",target:t});},0);}'
                .'F.CONNECTING=0;F.OPEN=1;F.CLOSING=2;F.CLOSED=3;'
                .'F.prototype._e=function(n,e){var f=this["on"+n];f&&f.call(this,e);(this._h[n]||[]).slice().forEach(function(g){g.call(this,e);},this);};'
                .'F.prototype.addEventListener=function(n,f){(this._h[n]=this._h[n]||[]).push(f);};'
                .'F.prototype.removeEventListener=function(n,f){this._h[n]=(this._h[n]||[]).filter(function(g){return g!==f;});};'
                .'F.prototype.send=function(d){var t=this;t._q=t._q.then(function(){if(t.readyState!==1)return;'
                .'return fetch(S,{method:"POST",body:String(d),headers:{"Content-Type":"text/plain;charset=UTF-8","X-Requested-With":"XMLHttpRequest"},credentials:"same-origin"})'
                .'.then(function(r){if(r.status===410){t.close();return[];}return r.ok?r.json():[];})'
                .'.then(function(a){(a||[]).forEach(function(m){if(t.readyState===1)t._e("message",{type:"message",data:m,target:t});});})'
                .'.catch(function(){});});};'
                .'F.prototype.close=function(){if(this.readyState===3)return;this.readyState=3;var t=this;setTimeout(function(){t._e("close",{type:"close",code:1000,reason:"",wasClean:true,target:t});},0);};'
                .'var W=function(u,p){return String(u).replace(/^wss?:\/\//i,"").toLowerCase().indexOf(key.toLowerCase())===0?new F(u):(p===undefined?new R(u):new R(u,p));};'
                .'W.CONNECTING=0;W.OPEN=1;W.CLOSING=2;W.CLOSED=3;W.prototype=R.prototype;window.WebSocket=W;})();</script>';

            return response($this->injectHead($html, $inject.$this->jackpotTickerSnippet($game, $user)))
                ->header('Content-Type', 'text/html');
        }

        // Wazdan's launch page hands the GWT client its parameters as
        // `var SID2 = [char codes]` (`host=…;port=…;cmd=…;help_url=…;…`). Ours:
        // the captured ones with host = /wazdan/{session} on our origin, the
        // rules page from /wazdan/{session}/get.php. The client talks binary
        // WebSocket frames (the hex of its AES-encrypted JSON); a WebSocket shim
        // POSTs each to /wazdan/{session}, in order, and hands back the reply.
        if ($config->clientProtocol() === ClientProtocol::Wazdan) {
            $wz = (array) ($config->bonusConfig()['wazdan'] ?? []);
            $mobile = (bool) preg_match('/Mobi|Android|iPhone|iPad|iPod/i', (string) $request->userAgent());
            $server = url("/wazdan/{$session->token}");
            $p = WazdanLiveClient::params((string) ($wz['sid'] ?? ''));
            $p = array_merge($p, [
                'lang' => strtolower(substr((string) ($request->query('lang') ?: app()->getLocale()), 0, 2)) ?: 'en',
                'host' => (string) preg_replace('#^https?://#', '', $server).'/', 'port' => (string) (parse_url($server, PHP_URL_PORT) ?: (str_starts_with($server, 'https') ? 443 : 80)),
                'help_url' => "{$server}/get.php", 'diag_url' => url('/wz/common/api.json'),
                'platform' => $mobile ? 'mobile' : 'desktop', 'mode' => 'real',
            ]);
            $gparams = $p['gparams'] ?? null;
            unset($p['gparams']);
            $sid = implode('', array_map(fn ($k, $v) => "{$k}={$v};", array_keys($p), $p)).($gparams !== null ? "gparams={$gparams};" : '');
            $html = str_replace('var SID2 = [];', 'var SID2 = ['.implode(',', array_map('ord', str_split($sid))).'];', $html);
            $html = (string) preg_replace("#var isMobile = Boolean\('[^']*'\);#", "var isMobile = Boolean('".($mobile ? '1' : '')."');", $html);
            $html = (string) preg_replace("#var assetsBase = '([^']*)';#", "var assetsBase = '".url("/games/{$code}~v{$bundle->version}")."/$1';", $html);
            $serverJs = json_encode($server, JSON_UNESCAPED_SLASHES);
            // GWT runs its code in a hidden iframe (the module's own window):
            // the shim goes into every iframe the page adds, built from that
            // window's own Blob / ArrayBuffer so the client's type checks hold
            $inject = "<script>(function(){var S={$serverJs};"
                .'function I(w){if(!w||w.__wzShim)return;w.__wzShim=1;var R=w.WebSocket;'
                .'function F(u){var t=this;t.url=u;t.readyState=0;t.protocol="";t.extensions="";t.bufferedAmount=0;t.binaryType="blob";t._h={};t._q=Promise.resolve();'
                .'setTimeout(function(){t.readyState=1;t._e("open",{type:"open",target:t});},0);}'
                .'F.CONNECTING=0;F.OPEN=1;F.CLOSING=2;F.CLOSED=3;'
                .'F.prototype._e=function(n,e){var f=this["on"+n];f&&f.call(this,e);(this._h[n]||[]).slice().forEach(function(g){g.call(this,e);},this);};'
                .'F.prototype.addEventListener=function(n,f){(this._h[n]=this._h[n]||[]).push(f);};'
                .'F.prototype.removeEventListener=function(n,f){this._h[n]=(this._h[n]||[]).filter(function(g){return g!==f;});};'
                .'function txt(d){if(typeof d==="string")return Promise.resolve(d);if(d&&typeof d.text==="function"&&typeof d.size==="number")return d.text();'
                .'var u=d&&d.buffer?new Uint8Array(d.buffer,d.byteOffset,d.byteLength):new Uint8Array(d);var s="";for(var i=0;i<u.length;i++)s+=String.fromCharCode(u[i]);return Promise.resolve(s);}'
                .'F.prototype.send=function(d){var t=this;t._q=t._q.then(function(){if(t.readyState!==1)return;return txt(d).then(function(b){'
                .'return fetch(S,{method:"POST",body:b,headers:{"Content-Type":"text/plain;charset=UTF-8","X-Requested-With":"XMLHttpRequest"},credentials:"same-origin"});})'
                .'.then(function(r){return r.ok?r.text():"";})'
                .'.then(function(h){if(!h||t.readyState!==1)return;var m;if(t.binaryType==="arraybuffer"){var a=new w.Uint8Array(h.length);for(var i=0;i<h.length;i++)a[i]=h.charCodeAt(i);m=a.buffer;}else m=new w.Blob([h]);'
                .'t._e("message",{type:"message",data:m,target:t});})'
                .'.catch(function(){});});};'
                .'F.prototype.close=function(){if(this.readyState===3)return;this.readyState=3;var t=this;setTimeout(function(){t._e("close",{type:"close",code:1000,reason:"",wasClean:true,target:t});},0);};'
                .'var K=S.replace(/^https?:\/\//,"");var W=function(u,p){return String(u).replace(/^wss?:\/\//,"").indexOf(K)===0?new F(u):(p===undefined?new R(u):new R(u,p));};'
                .'W.CONNECTING=0;W.OPEN=1;W.CLOSING=2;W.CLOSED=3;W.prototype=R.prototype;w.WebSocket=W;}'
                .'I(window);new MutationObserver(function(ms){ms.forEach(function(m){m.addedNodes.forEach(function(n){'
                .'if(n.tagName==="IFRAME"){try{I(n.contentWindow);}catch(e){}}});});}).observe(document.documentElement,{childList:true,subtree:true});'
                .'})();</script>';

            return response($this->injectHead($html, $inject.$this->jackpotTickerSnippet($game, $user)))
                ->header('Content-Type', 'text/html');
        }

        // Apollo's launch page reads `socketURL` / `language` off its URL, and its
        // client talks over a WebSocket to that URL. We serve the actions over
        // HTTP instead: a WebSocket shim (for that one URL only) POSTs each frame
        // (`name=casino.spin\n<headers>\n\n<json>`) to /apollo/{session}/<action>
        // and hands the reply back as the frame Apollo's server would send.
        if ($config->clientProtocol() === ClientProtocol::Apollo) {
            $languages = array_map('strtolower', (array) ($config->bonusConfig()['apollo']['languages'] ?? ['en']));
            $lang = strtolower(substr((string) ($request->query('lang') ?: app()->getLocale()), 0, 2));
            $server = url("/apollo/{$session->token}");
            $params = json_encode(http_build_query([
                'mode' => 'real', 'language' => in_array($lang, $languages, true) ? $lang : 'en',
                'socketURL' => $server,
            ]));
            $serverJs = json_encode($server);
            $inject = "<script>(function(){var s=location.search;history.replaceState(null,'',location.pathname+s+(s?'&':'?')+{$params});"
                ."var S={$serverJs},R=window.WebSocket,key=S.replace(/^https?:\/\//,'');"
                .'function F(u){var t=this;t.url=u;t.readyState=0;t.protocol="";t.extensions="";t.bufferedAmount=0;'
                .'setTimeout(function(){t.readyState=1;t.onopen&&t.onopen({type:"open",target:t});},0);}'
                .'F.CONNECTING=0;F.OPEN=1;F.CLOSING=2;F.CLOSED=3;'
                .'F.prototype.send=function(d){var t=this,i=d.indexOf("\n\n"),h=i<0?d:d.substring(0,i),b=i<0?"{}":d.substring(i+2),n="",a="";'
                .'h.split("\n").forEach(function(l){var k=l.indexOf("=");if(k>0){var p=l.substring(0,k).toLowerCase();if(p==="name")n=l.substring(k+1);if(p==="authorization")a=l.substring(k+1);}});'
                .'if(!n)return;var x=new XMLHttpRequest();x.open("POST",S+"/"+n,true);x.setRequestHeader("Content-Type","application/json");'
                .'x.onreadystatechange=function(){if(x.readyState!==4||t.readyState!==1)return;'
                .'var st=x.status||503,body=x.responseText||"{\"category\":\"FATAL\",\"code\":\"SERVER_ERROR\",\"title\":\"SERVER_ERROR\",\"detailed\":\"\"}";'
                .'t.onmessage&&t.onmessage({data:"response="+n+"\nstatus="+st+"\nContent-Type=application/json;charset=utf-8\n"+(st===200?"Authorization="+(x.getResponseHeader("Authorization")||a||"sessionToken")+"\n":"")+"\n"+body,target:t});};'
                .'x.send(b);};'
                .'F.prototype.close=function(){if(this.readyState===3)return;this.readyState=3;var t=this;setTimeout(function(){t.onclose&&t.onclose({code:1000,reason:"",wasClean:true,target:t});},0);};'
                .'F.prototype.addEventListener=function(e,f){var o=this["on"+e];this["on"+e]=o?function(v){o(v);f(v);}:f;};'
                .'F.prototype.removeEventListener=function(){};'
                .'var W=function(u,p){return String(u).replace(/^wss?:\/\//,"").indexOf(key)===0?new F(u):(p===undefined?new R(u):new R(u,p));};'
                .'W.CONNECTING=0;W.OPEN=1;W.CLOSING=2;W.CLOSED=3;W.prototype=R.prototype;window.WebSocket=W;})();</script>';

            return response($this->injectHead($html, $inject.$this->jackpotTickerSnippet($game, $user)))
                ->header('Content-Type', 'text/html');
        }

        // WebSocket bundles (EGT GamePlatform, …) identify the player by a
        // `sessionId` in the page, not a POST endpoint. Force this launch's token
        // in (overwriting any stale value the tab's sessionStorage kept).
        if ($config->clientProtocol()->usesWebSocket()) {
            $token = json_encode($session->token);
            $inject = "<script>try{sessionStorage.setItem('sessionId',{$token});}catch(e){}</script>";

            // The newer Amatic client generation (gmsl/mpp bundles, e.g.
            // AdmiralNelsonNew) doesn't use `sessionId` at all — it reads its
            // own session token from `sessionValue2` (its internal name for
            // the `hash`/`user` URL params it otherwise expects) and sends it
            // positionally in its first `A/u25` frame, see SocketServer's raw
            // Amatic handling. Harmless no-op for the older amarent client,
            // which never reads this key.
            if ($config->clientProtocol() === ClientProtocol::Amatic) {
                $inject .= "<script>try{sessionStorage.setItem('sessionValue2',{$token});}catch(e){}</script>";

                // Page params Amatic's own launcher would have added (e.g.
                // `classic=true`, which selects the game variant matching the
                // legacy maths) — the client reads them off this page's URL.
                $params = array_filter((array) data_get($config->layout(), 'url_params', []), 'is_scalar');
                if ($params !== []) {
                    $query = json_encode(http_build_query($params));
                    $inject .= "<script>(function(){var s=location.search;history.replaceState(null,'',location.pathname+s+(s?'&':'?')+{$query});})();</script>";
                }
            }

            $inject .= $this->jackpotTickerSnippet($game, $user);
            $html = $this->injectHead($html, $inject);

            // The bundle hard-codes a stale `gameIdentificationNumber` (legacy
            // reused "546" across games). Rewrite it to the gin our login/lobby
            // will actually return for this game, so the client finds its entry
            // instead of dropping to the portal / "game unavailable".
            $gin = app(GamePlatformLobby::class)->gin($config);
            $html = preg_replace(
                '/(gameIdentificationNumber\s*[:=]\s*["\']?)\d+(["\']?)/',
                '${1}'.$gin.'${2}',
                $html,
                1,
            ) ?? $html;

            return response($html)->header('Content-Type', 'text/html');
        }

        // Real (modern "gs2c") Pragmatic Play bundles bake their command
        // endpoint and sibling URLs into the page as
        // `"https://'+location.hostname+'/games/<Code>/gs2c/..."` — built
        // correctly at runtime EXCEPT it drops the port (`location.hostname`,
        // not `location.host`), which breaks on this deploy's non-default
        // port. Swap in `location.origin` (scheme+host+port together) so it
        // resolves against wherever the page actually loaded from.
        if (in_array($config->clientProtocol(), [ClientProtocol::PragmaticTumble, ClientProtocol::PragmaticPayline], true)) {
            $html = str_replace("https://'+location.hostname+'", "'+location.origin+'", $html);

            // Same session-recovery problem as the classic Pragmatic bundles
            // (GameAssetController's other branch, below): the client doesn't
            // echo back any token we hand it, so GameServerController falls
            // back to recovering it from the Referer header — force the full
            // launch URL (session id included) to always be sent as referer.
            $extra = "sessionId={$session->token}";
            $currency = ($user->currency ?? $game->shop->currency)->value;
            $html = $this->injectHead($html, '<meta name="referrer" content="unsafe-url">'
                // The bundle reads `sessionStorage.getItem('Curr')` before its
                // own bootstrap script runs, baking the (unset) result
                // straight into every balance/bet display as the literal
                // string "null" (`"currency":"'+Curr+'"` in html5Game.html) —
                // seed it so the client has a real currency code to show.
                ."<script>try{sessionStorage.setItem('Curr','{$currency}');}catch(e){}</script>"
                ."<script>(function(){var s=location.search;if(!/[?&]sessionId=/i.test(s)){history.replaceState(null,'',location.pathname+s+(s?'&':'?')+'{$extra}');}})();</script>"
                .$this->jackpotTickerSnippet($game, $user));

            return response($html)->header('Content-Type', 'text/html');
        }

        // Real Pragmatic Play bundles hard-code their POST endpoint
        // (`"gameService":"https:///game/<Code>/server"` — note the broken,
        // host-less scheme, baked in as-is by the legacy scrape) with no
        // session identifier at all; the real client instead relied on the
        // browser's own authenticated cookie session. Point it at our actual
        // origin and append this launch's session token, matching every
        // other provider's `?sessionId=` convention.
        if ($config->clientProtocol() === ClientProtocol::Pragmatic) {
            $endpoint = url("/game/{$code}/server")."?sessionId={$session->token}";
            $html = preg_replace(
                '#https?:///game/'.preg_quote($code, '#').'/server#i',
                $endpoint,
                $html,
            ) ?? $html;

            // The bundle's own bootstrap reads `cur` off *this page's* URL
            // (`qstr.cur.toUpperCase()`) — our launch URL only ever carries
            // `?token=`. But the actual `doInit` POST was observed going out
            // as `…/server?sessionId=null` regardless — its request-builder
            // (inside the compiled bootstrap.js) never honours `gameService`'s
            // query string nor `qstr.sessionId`. Since we can't fix that
            // client-side, GameServerController instead recovers the real
            // token from this request's `Referer` header, which still points
            // at this page's URL — force the *full* URL (query string
            // included) to always be sent as referer for that to work.
            $currency = ($user->currency ?? $game->shop->currency)->value;
            $extra = "cur={$currency}&sessionId={$session->token}";
            $html = $this->injectHead($html, '<meta name="referrer" content="unsafe-url">'
                ."<script>(function(){var s=location.search;if(!/[?&]cur=/i.test(s)){history.replaceState(null,'',location.pathname+s+(s?'&':'?')+'{$extra}');}})();</script>"
                .$this->jackpotTickerSnippet($game, $user));

            return response($html)->header('Content-Type', 'text/html');
        }

        return response($this->injectBootstrap($html, $code, $session, $user, $game))
            ->header('Content-Type', 'text/html');
    }

    public function asset(string $code, string $path): Response
    {
        // `<Code>~v<n>` — the versioned base play() injects; the version only
        // busts browser caches, files always come from the active bundle.
        $code = (string) preg_replace('/~v\d+$/', '', $code);
        $template = GameTemplate::where('code', $code)->firstOr(fn () => abort(404));
        $bundle = $template->activeBundle ?? abort(404);

        $rel = $bundle->filePath($path);

        // EGT portal bundles ship only `library-v<n>.json`; the client asks for
        // `library-single-game-v<n>.json` once its game list has one entry. Alias
        // it at request time so nothing has to be written into the bundle.
        if (! $rel && preg_match('#(^|/)assets/library-single-game-(v\d+\.\w+)$#', $path, $m)) {
            $rel = $bundle->filePath(str_replace('library-single-game-', 'library-', $path))
                ?? $bundle->filePath('html5/assets/library-'.$m[2]);
        }

        // NetEnt clients fetch the player's currency format; a currency the
        // bundle lacks (NetEnt never had ALL) is made at request time
        if (! $rel && $template->client_protocol === ClientProtocol::NetEnt && ($file = NetEntCurrencyFile::for($path))) {
            return response($file['body'], 200, ['Content-Type' => $file['type'], 'Cache-Control' => 'public, max-age=3600']);
        }

        // Wazdan: what every Wazdan game shares (content-addressed `assets/…`,
        // `common/…`, the rules pages' `_common/…` and images) lives once in
        // the shared store, not in each bundle — see WazdanMirror
        if (! $rel && $template->client_protocol === ClientProtocol::Wazdan
            && ($file = WazdanMirror::shared($path)) !== null) {
            return response()->file($file, ['Content-Type' => $this->mimeType($path) ?? (mime_content_type($file) ?: 'application/octet-stream'),
                'Cache-Control' => 'public, max-age=86400']);
        }

        if (! $rel) {
            abort(404);
        }

        $disk = $bundle->disk();
        $content = $disk->get($rel);

        if (str_ends_with($rel, '.js')) {
            $content = $this->patchLegacyGmslQuirks($content, $bundle, dirname($rel));
        }

        // Merkur's launcher and help pages name the host's `/mk/commonStatic2/…`
        // (the gaming lib rewrites help images to it at run time)
        if ($template->client_protocol === ClientProtocol::Merkur && preg_match('/\.(html|css|js)$/i', $rel) && str_contains($content, '/mk/commonStatic2/')) {
            $content = str_replace('/mk/commonStatic2/', url("/games/{$template->code}~v{$bundle->version}/commonStatic2").'/', $content);
        }

        return response($content, 200, [
            'Content-Type' => $this->mimeType($rel) ?? ($disk->mimeType($rel) ?: 'application/octet-stream'),
            'Cache-Control' => 'public, max-age=3600',
        ]);
    }

    /**
     * Amatic's "gmsl/mpp" loaders (e.g. amarent) read two legacy
     * query-string parameters off the *page's* URL that our launch flow has
     * no reason to know or forward, and their absence silently breaks the
     * game rather than falling back to a sane default:
     *
     * - `config` — `admiralloader_*.js` builds its config script's src as
     *   `./src/config_"+getParam("config")+"_<hash>.js`. Empty param → 404
     *   (`config__<hash>.js`) → the global `Config` never gets defined →
     *   `ReferenceError: Config is not defined` and the game is dead before
     *   it starts. A bundle only ever ships the one config file it was
     *   scraped with, so hardcode that id instead of trusting the URL.
     * - `lang` — the main engine script (`admiral_*.js`) only restricts its
     *   preload manifest to `lang`'s comma list when that param is present;
     *   otherwise it preloads *every* locale the manifest declares (~28),
     *   nearly all of which 404 because a bundle only ever ships the
     *   handful of locales it was actually translated into — and the loader
     *   has no fallback, so it hangs on the loading spinner forever.
     *   Hardcode the comma list to whatever locale data files actually
     *   shipped in this bundle.
     *
     * (Verified against AdmiralNelsonNew: threw `Config is not defined`
     * without the first fix, then hung indefinitely on a 404'd `vi_1.json`
     * — a locale never bundled — without the second.)
     */
    private function patchLegacyGmslQuirks(string $js, GameBundle $bundle, string $dir): string
    {
        if (str_contains($js, 'getParam("config")')) {
            $configFile = collect($bundle->disk()->files($dir))
                // mpp: config_<id>_…; the older mphome clients: settings_<id>_…
                ->first(fn ($f) => preg_match('/(?:config|settings)_(\d+)_/', basename($f)));

            if ($configFile && preg_match('/(?:config|settings)_(\d+)_/', basename($configFile), $m)) {
                $js = str_replace('getParam("config")', '"'.$m[1].'"', $js);
            }
        }

        if (str_contains($js, 'a("lang")')) {
            $langs = collect($bundle->disk()->allFiles($bundle->path))
                ->map(fn ($f) => basename($f))
                ->filter(fn ($f) => preg_match('/^[a-z]{2}_\d+\.json$/i', $f))
                ->map(fn ($f) => strtolower(explode('_', $f)[0]))
                ->unique()
                // The client shows the list's first locale — alphabetical
                // order put Bulgarian first once a bundle shipped every locale.
                ->sortBy(fn ($l) => $l === 'en' ? '' : $l);

            if ($langs->isNotEmpty()) {
                $js = str_replace('a("lang")', '"'.$langs->implode(',').'"', $js);
            }
        }

        return $js;
    }

    // ---- helpers ----------------------------------------------------

    /**
     * Extension-based MIME lookup. Legacy loader scripts build up HTML
     * (`document.write('<script>…</script>')`) as string literals, which
     * makes content-sniffing (`Storage::mimeType()`, backed by finfo/libmagic)
     * misdetect plain `.js`/`.css` files as `text/html`. Combined with the
     * `X-Content-Type-Options: nosniff` header, browsers then silently refuse
     * to execute the script. Trust the extension for known text asset types.
     */
    private function mimeType(string $path): ?string
    {
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        return MimeTypes::getDefault()->getMimeTypes($extension)[0] ?? null;
    }

    private function isEngineOnlyBundle(GameBundle $bundle): bool
    {
        return $bundle->filePath('js/loader.js') !== null
            && $bundle->filePath('js/core.js') !== null
            && $bundle->filePath($bundle->entry) === null;
    }

    /**
     * Render the Novomatic/Greentube loader HTML (js/core.js POSTs `{slotEvent}`
     * to `/game/{code}/server`). Script order + canvas match the legacy
     * server-generated page; only class files that exist in the bundle are
     * included (the `*GT` variant drops a couple).
     */
    private function slotEventShell(Request $request, GameTemplate $template, Game $game, GameSession $session, GameBundle $bundle, User $user): Response
    {
        $config = new GameConfig($template, $game);

        $libs = collect($bundle->disk()->files($bundle->path.'/js/lib'))
            ->map(fn ($p) => 'js/lib/'.basename($p))
            ->reject(fn ($p) => str_ends_with($p, '.map'))
            ->sortBy(fn ($p) => match (true) {          // font loader → pixi core → pixi plugins → the rest
                str_contains($p, 'webfont') => 0,
                str_ends_with($p, 'pixi.min.js') => 1,
                str_contains($p, 'pixi') => 2,
                default => 3,
            })
            ->values();
        // The newer Greentube `*GT`/`*DX` bundles render with PIXI and append
        // their own <canvas>; the older Novomatic engine draws into #game.
        $pixi = $libs->contains(fn ($p) => str_ends_with($p, 'pixi.min.js'));
        if ($libs->isEmpty()) {
            $libs = collect(['js/lib/createjs.min.js']);
        }

        $classes = ['GameButton', 'GameBack', 'GameUI', 'GameView', 'GameReels', 'GameLines', 'GameCounters', 'GameRules'];
        if ($config->hasGamble()) {
            $classes[] = 'GameGamble';
        }
        if ($config->hasFreeSpins() || $config->hasBonus()) {
            $classes[] = 'GameBonus';
        }
        $classes[] = 'GameMessages';

        $scripts = $libs->all();
        foreach ($classes as $c) {
            if ($bundle->filePath("js/classes/{$c}.js")) {
                $scripts[] = "js/classes/{$c}.js";
            }
        }
        foreach (['js/utils.js', 'js/loader.js', 'js/core.js', 'js/classes/Sounds.js'] as $s) {
            if ($bundle->filePath($s)) {
                $scripts[] = $s;
            }
        }

        // The PIXI engine's loader gates every frame on a global `isFontLoaded`
        // that the legacy host page set via WebFont; synthesise it from the
        // bundle's own css/fonts.css @font-face names.
        $fontsCss = $bundle->filePath('css/fonts.css') ? 'css/fonts.css' : null;
        $fontFamilies = [];
        if ($pixi && $fontsCss && ($raw = $bundle->disk()->get($bundle->path.'/css/fonts.css'))) {
            preg_match_all('/font-family:\s*[\'"]([^\'"]+)[\'"]/i', $raw, $m);
            $fontFamilies = array_values(array_unique($m[1]));
        }

        return response()->view('games.slot-event-shell', [
            'title' => $game->title ?? $template->title,
            'base' => rtrim(url("/games/{$template->code}"), '/').'/',
            'token' => $session->token,
            'scripts' => $scripts,
            'fontsCss' => $fontsCss,
            'canvas' => ! $pixi,
            'fontFamilies' => $fontFamilies,
            'width' => 750,
            'height' => 630,
            'jackpotTicker' => $this->jackpotTickerSnippet($game, $user),
        ]);
    }

    /**
     * The legacy per-game Blade shell (`resources/views/frontend/games/list/<Code>.blade.php`
     * on the legacy server) — identical boilerplate across every Playtech
     * title bar `$game->name` / `$game->title`, so synthesised generically
     * instead of shipping ~60 near-duplicate Blade files. Base href points at
     * the bundle's `platform/` folder (the GWT "chrome" app, not the game
     * itself); it boots and internally loads the nested "bib" game app.
     */
    private function playtechShell(GameTemplate $template, Game $game, GameSession $session, User $user): Response
    {
        return response()->view('games.playtech-shell', [
            'title' => $game->title ?? $template->title,
            'base' => rtrim(url("/games/{$template->code}/platform"), '/').'/',
            'sessionsPlayerKey' => 'bs='.$template->code,
            'token' => $session->token,
            'jackpotTicker' => $this->jackpotTickerSnippet($game, $user),
        ]);
    }

    private function playNGoShell(Request $request, GameTemplate $template, Game $game, GameSession $session, User $user): Response
    {
        $png = (array) ((new GameConfig($template, $game))->bonusConfig()['playngo'] ?? []);
        $mobile = $request->query('channel') === 'mobile'
            || ($request->query('channel') === null && preg_match('/Mobi|Android|iPhone|iPad/i', (string) $request->userAgent()));

        return response()->view('games.playngo-shell', [
            'title' => $game->title ?? $template->title,
            'coreWeb' => url("/playngo/{$session->token}").'/',
            'gameId' => $png['game_id'] ?? 0,
            'gid' => (string) ($png['gid'] ?? ''),
            'channel' => $mobile ? 'mobile' : 'desktop',
            'token' => $session->token,
            'launcher' => url("/games/{$template->code}/cdn/".ltrim((string) ($png['launcher'] ?? ''), '/')),
            'jackpotTicker' => $this->jackpotTickerSnippet($game, $user),
        ]);
    }

    /** @return array{user: User, game: Game} */
    private function resolveFromToken(Request $request, GameTemplate $template): array
    {
        $token = $request->query('token') ?: abort(403, 'Missing launch token.');

        try {
            $resolved = $this->launcher->verifyToken($token);
        } catch (\Throwable $e) {
            abort(403, $e->getMessage());
        }

        if ($resolved['game']->template_id !== $template->id) {
            abort(403, 'Token is for a different game.');
        }

        return $resolved;
    }

    private function injectBootstrap(string $html, string $code, GameSession $session, User $user, Game $game): string
    {
        $config = json_encode([
            'endpoint' => url("/api/game/{$code}/server"),
            'session' => $session->token,
            'currency' => ($user->currency ?? $game->shop->currency)->value,
            'balance' => (float) $user->wallet->balance,
        ], JSON_UNESCAPED_SLASHES);

        $head = "<script>window.CasinoGame={$config};</script>";
        $head .= $this->jackpotTickerSnippet($game, $user);

        // Relative asset paths in the bundle resolve against the asset route,
        // not the launch URL — unless the bundle ships its own <base> (or a
        // nested-entry <base> was already injected by play()).
        if (! preg_match('/<base\s/i', $html)) {
            $base = rtrim(url("/games/{$code}"), '/').'/';
            $head = "<base href=\"{$base}\">".$head;
        }

        return $this->injectHead($html, $head);
    }

    /**
     * Jackpots this game should show a live ticker for, plus the data the
     * frontend ticker (public/js/jackpot-ticker.js) needs to render + subscribe.
     *
     * Balances are converted from each pool's own currency into the player's
     * wallet currency for display only — the pool itself, its contributions,
     * and its payouts stay exactly as computed elsewhere (see [[GameContext]]/
     * Banker); this is purely a presentation-layer FX conversion.
     *
     * @return array{userId: int, currency: string, jackpots: list<array{id: int, name: string, balance: float, currency: string}>}
     */
    private function jackpotBootstrap(Game $game, User $user): array
    {
        $game->loadMissing('shop');
        $user->loadMissing('wallet');

        $currency = $user->wallet->currency;

        $jackpots = Jackpot::query()
            ->where('is_active', true)
            ->applicableTo($game->shop, $game->jackpot_id)
            ->get(['id', 'name', 'balance', 'currency', 'shop_id']);

        return [
            'userId' => $user->id,
            'currency' => $currency->value,
            'jackpots' => $jackpots->map(fn (Jackpot $j) => [
                'id' => $j->id,
                'name' => $j->name,
                'balance' => $this->fx->convert((float) $j->balance, $j->poolCurrency(), $currency),
                'currency' => $currency->value,
            ])->values()->all(),
        ];
    }

    /** HTML to inject in <head> for the live jackpot ticker; '' if nothing applies. */
    private function jackpotTickerSnippet(Game $game, User $user): string
    {
        $bootstrap = $this->jackpotBootstrap($game, $user);

        if (empty($bootstrap['jackpots'])) {
            return '';
        }

        $json = json_encode($bootstrap, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $src = e(asset('js/jackpot-ticker.js'));

        return "<script>window.CasinoJackpots={$json};</script><script src=\"{$src}\" defer></script>";
    }

    /** Insert markup right after <head> (or prepend it if the doc has no head). */
    private function injectHead(string $html, string $markup): string
    {
        return preg_match('/<head[^>]*>/i', $html)
            ? (string) preg_replace('/(<head[^>]*>)/i', '$1'.addcslashes($markup, '\\$'), $html, 1)
            : $markup.$html;
    }

    private function demoShell(Request $request, GameTemplate $template): Response
    {
        $resolved = $this->resolveFromToken($request, $template);

        $session = GameSession::updateOrCreate(
            ['user_id' => $resolved['user']->id, 'game_id' => $resolved['game']->id],
            ['token' => bin2hex(random_bytes(20)), 'is_active' => true, 'last_seen_at' => now()],
        );

        return response()->view('games.demo-shell', [
            'code' => $template->code,
            'title' => $template->title,
            'session' => $session->token,
            'endpoint' => url("/api/game/{$template->code}/server"),
        ]);
    }
}
