<?php

namespace App\Services\GamePlay;

/** What {@see AmaticCdnFetcher} mirrored for one game. */
final readonly class AmaticCdnGame
{
    /**
     * @param  string  $key  CDN game key, e.g. `aztecsecret`
     * @param  string  $gameId  the engine's own name for it, e.g. `AztecSecret`
     * @param  string  $entry  bundle-relative page, e.g. `gmsl/mpp/amarent/aztecsecret.html`
     * @param  list<string>  $languages  locales the manifests declare
     * @param  array<string, string>  $urlParams  page URL params Amatic's launcher adds (e.g. classic=true)
     * @param  list<string>  $files  bundle-relative paths mirrored
     * @param  list<string>  $missing  declared resources the CDN didn't have
     * @param  list<string>  $warnings
     */
    public function __construct(
        public string $key,
        public string $gameId,
        public string $title,
        public string $entry,
        public ?string $configId,
        public ?string $currency,
        public array $languages,
        public array $urlParams,
        public array $files,
        public int $bytes,
        public array $missing,
        public array $warnings,
    ) {}
}
