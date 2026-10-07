<?php

namespace App\Services\RoyalSpin;

/**
 * Generated art for Game Builder games that have no uploaded logo / poster:
 * a title logo in the skin's style and a 400x400 lobby poster composed from
 * the game's own background, its top symbols, the character and the logo.
 * Plain SVG; every embedded picture goes in as a data: URI so the poster is
 * one self-contained file (SVG shown via <img> can't load external files).
 */
class BuilderArt
{
    /** Title logo, 640x170. `plaque` = gold-rimmed purple banner with bolts (Olympus); `gold` = plain gold lettering. */
    public static function logo(string $title, string $style = 'gold', string $accent = '#ffd76a'): string
    {
        $text = htmlspecialchars(mb_strtoupper($title), ENT_XML1);
        $len = mb_strlen($title);
        $size = 64;
        $fit = $len * $size * 0.72 > 470 ? ' textLength="470" lengthAdjust="spacingAndGlyphs"' : '';
        $gold = '<linearGradient id="au" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#fffbe0"/><stop offset=".3" stop-color="#ffe27a"/><stop offset=".55" stop-color="#e0a82e"/><stop offset=".8" stop-color="#a8660a"/><stop offset="1" stop-color="#ffd76a"/></linearGradient>';
        $fill = '<linearGradient id="f" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#fffbe0"/><stop offset=".4" stop-color="'.$accent.'"/><stop offset=".6" stop-color="#e0a82e"/><stop offset="1" stop-color="#ffd76a"/></linearGradient>';
        $shadow = '<filter id="sh" x="-30%" y="-30%" width="160%" height="160%"><feDropShadow dx="0" dy="6" stdDeviation="4" flood-color="#000" flood-opacity=".7"/></filter>';
        $glow = '<filter id="lt" x="-50%" y="-50%" width="200%" height="200%"><feGaussianBlur stdDeviation="4" result="b"/><feFlood flood-color="#9ae8ff"/><feComposite in2="b" operator="in" result="g"/><feMerge><feMergeNode in="g"/><feMergeNode in="SourceGraphic"/></feMerge></filter>';

        $body = '';
        if ($style === 'plaque') {
            $body .= '<g filter="url(#sh)"><path d="M40 60L80 26H560L600 60L560 128H80Z" fill="url(#p)" stroke="url(#au)" stroke-width="10" stroke-linejoin="round"/>'
                .'<path d="M300 26l20-22 20 22z" fill="url(#au)" stroke="#6a3a00" stroke-width="2"/><path d="M300 128l20 22 20-22z" fill="url(#au)" stroke="#6a3a00" stroke-width="2"/></g>'
                .'<polygon points="'.self::bolt(28, 30, 0.75).'" fill="#e8fbff" filter="url(#lt)"/><polygon points="'.self::bolt(596, 30, 0.75).'" fill="#e8fbff" filter="url(#lt)"/>';
            $y = 99;
        } else {
            $y = 112;
        }
        $body .= '<text x="320" y="'.$y.'" font-family="Georgia,\'Times New Roman\',serif" font-weight="bold" font-size="'.$size.'" text-anchor="middle" fill="url(#f)" stroke="#3a1a00" stroke-width="7" paint-order="stroke" letter-spacing="3"'.$fit.' filter="url(#sh)">'.$text.'</text>';

        $defs = $gold.$fill.$shadow.$glow.'<linearGradient id="p" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#7a3ad0"/><stop offset="1" stop-color="#3a0a7a"/></linearGradient>';

        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 640 170" width="640" height="170"><defs>'.$defs.'</defs>'.$body.'</svg>'."\n";
    }

    /**
     * 400x400 lobby poster.
     *
     * @param  string  $background  absolute path of the background picture
     * @param  list<string>  $symbols  absolute paths of up to three featured symbols
     */
    public static function poster(string $background, array $symbols, string $logoSvg, ?string $character = null): string
    {
        $img = fn (string $path, float $x, float $y, float $w, float $h, string $extra = '') => '<image href="'.self::dataUri($path).'" x="'.$x.'" y="'.$y.'" width="'.$w.'" height="'.$h.'" preserveAspectRatio="xMidYMid meet"'.$extra.'/>';

        // the 16:9 background, cropped to a square around the board
        $body = '<image href="'.self::dataUri($background).'" x="-160" y="0" width="711" height="400" preserveAspectRatio="xMidYMid slice"/>';
        if ($character) {
            $body .= $img($character, 210, 20, 220, 360);
        }
        $places = $character
            ? [[10, 120, 130, -10], [110, 150, 130, 8], [50, 60, 150, 0]]
            : [[22, 120, 150, -10], [228, 120, 150, 10], [110, 70, 190, 0]];
        foreach (array_slice($symbols, 0, 3) as $i => $path) {
            [$x, $y, $s, $r] = $places[$i];
            $c = $s / 2;
            $body .= '<g transform="rotate('.$r.' '.($x + $c).' '.($y + $c).')">'.$img($path, $x, $y, $s, $s).'</g>';
        }
        $body .= '<rect y="300" width="400" height="100" fill="#000" opacity=".35"/>';
        $body .= '<image href="data:image/svg+xml;base64,'.base64_encode($logoSvg).'" x="0" y="290" width="400" height="106"/>';
        $body .= '<text x="388" y="22" font-family="Georgia,serif" font-size="14" font-weight="bold" text-anchor="end" fill="#ffd84a" opacity=".9">RoyalSpin</text>';

        return '<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" viewBox="0 0 400 400" width="400" height="400">'.$body.'</svg>'."\n";
    }

    public static function dataUri(string $path): string
    {
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $mime = match ($ext) {
            'svg' => 'image/svg+xml',
            'jpg', 'jpeg' => 'image/jpeg',
            'webp' => 'image/webp',
            'gif' => 'image/gif',
            default => 'image/png',
        };

        return 'data:'.$mime.';base64,'.base64_encode((string) file_get_contents($path));
    }

    private static function bolt(float $x, float $y, float $s): string
    {
        $p = [[0, 0], [26, 0], [12, 40], [32, 40], [-6, 110], [6, 56], [-12, 56]];

        return implode(' ', array_map(fn ($q) => round($x + $q[0] * $s, 1).','.round($y + $q[1] * $s, 1), $p));
    }
}
