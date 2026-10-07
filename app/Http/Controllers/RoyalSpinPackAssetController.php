<?php

namespace App\Http\Controllers;

use App\Services\RoyalSpin\ArtPacks;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Symfony\Component\HttpFoundation\Response;

/**
 * Serves stock art-pack files (symbol previews, backgrounds, music) to the
 * admin Game Builder form. Staff only; paths can't leave the pack directory.
 *
 *   GET /admin/royalspin-packs/{pack}/{path}
 */
class RoyalSpinPackAssetController extends Controller
{
    public function __invoke(Request $request, ArtPacks $packs, string $pack, string $path): Response
    {
        abort_unless($request->user()?->hasPermission('games.manage'), 403);

        $file = $packs->path($pack, $path) ?? abort(404);
        $mime = match (strtolower(pathinfo($file, PATHINFO_EXTENSION))) {
            'svg' => 'image/svg+xml',
            'png' => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            'webp' => 'image/webp',
            'wav' => 'audio/wav',
            'mp3' => 'audio/mpeg',
            'ogg' => 'audio/ogg',
            default => abort(404),
        };

        return response()->file($file, ['Content-Type' => $mime, 'Cache-Control' => 'private, max-age=3600']);
    }
}
