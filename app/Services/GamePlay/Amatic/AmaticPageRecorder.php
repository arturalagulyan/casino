<?php

namespace App\Services\GamePlay\Amatic;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Lists the files an Amatic game page really loads, by opening it in a
 * WebDriver browser (the Selenium container) and reading Chrome's network
 * log. Needed for the older "mphome" clients: they build their file list in
 * code (`"symb"+i+".png"`…) and ship no resource manifest to crawl.
 *
 * Desktop and mobile layouts are both recorded (mobile adds its own GUI
 * sheet and buttons); the page is clicked once so lazily loaded parts load.
 */
class AmaticPageRecorder
{
    public function __construct(private readonly string $webdriver = '') {}

    /**
     * @return list<string> absolute URLs that answered 200
     */
    public function record(string $pageUrl): array
    {
        $urls = [];
        foreach ([false, true] as $mobile) {
            $url = $mobile ? (string) preg_replace('/([?&]type=)desktop/', '$1mobile', $pageUrl) : $pageUrl;
            $urls = array_merge($urls, $this->session($url, $mobile));
        }

        return array_values(array_unique($urls));
    }

    /** @return list<string> */
    private function session(string $url, bool $mobile): array
    {
        $wd = rtrim($this->webdriver ?: (string) config('services.selenium.url', 'http://selenium:4444'), '/');
        $options = ['args' => ['--window-size=1280,800', '--autoplay-policy=no-user-gesture-required', '--use-gl=angle', '--use-angle=swiftshader', '--enable-unsafe-swiftshader', '--ignore-gpu-blocklist']];
        if ($mobile) {
            $options['mobileEmulation'] = ['deviceName' => 'iPhone 12 Pro'];
        }
        $session = Http::timeout(120)->post("{$wd}/session", ['capabilities' => ['alwaysMatch' => [
            'browserName' => 'chrome', 'goog:loggingPrefs' => ['performance' => 'ALL'], 'goog:chromeOptions' => $options,
        ]]])->json('value.sessionId');
        if (! $session) {
            throw new RuntimeException("No WebDriver session at {$wd} — the older Amatic clients are recorded in a browser (Selenium)");
        }
        $s = "{$wd}/session/{$session}";
        $found = [];
        try {
            Http::timeout(120)->post("{$s}/url", ['url' => $url]);
            for ($t = 0; $t < 12; $t++) {
                sleep(5);
                foreach ((array) Http::timeout(120)->post("{$s}/se/log", ['type' => 'performance'])->json('value') as $entry) {
                    $m = json_decode((string) ($entry['message'] ?? ''), true)['message'] ?? [];
                    if (($m['method'] ?? '') === 'Network.responseReceived' && (int) ($m['params']['response']['status'] ?? 0) === 200) {
                        $found[] = (string) $m['params']['response']['url'];
                    }
                }
                if ($t === 6) {
                    Http::timeout(60)->post("{$s}/actions", ['actions' => [['type' => 'pointer', 'id' => 'm', 'parameters' => ['pointerType' => 'mouse'], 'actions' => [
                        ['type' => 'pointerMove', 'duration' => 0, 'x' => 640, 'y' => 400, 'origin' => 'viewport'],
                        ['type' => 'pointerDown', 'button' => 0], ['type' => 'pointerUp', 'button' => 0],
                    ]]]]);
                }
            }
        } finally {
            Http::timeout(60)->delete($s);
        }

        return $found;
    }
}
