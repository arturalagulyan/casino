<?php

namespace App\Http\Controllers;

use App\Services\GamePlay\RtpSimulationReport;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Redirect;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * View/download a {@see \App\Services\GamePlay\RtpSimulator} run — the
 * "Test RTP" admin action stores its full spin-by-spin result under a token
 * (see {@see RtpSimulationReport}) and links here instead of trying to cram
 * thousands of rows into a notification.
 */
class RtpSimulationReportController extends Controller
{
    /** Rows rendered inline; the CSV download always carries the full run. */
    private const int PREVIEW_ROWS = 2000;

    public function __construct(private RtpSimulationReport $reports) {}

    public function show(Request $request, string $token): Response
    {
        $this->authorizeStaff($request);

        $result = $this->reports->find($token) ?? abort(404, 'This report has expired or does not exist.');

        return response()->view('rtp-simulations.report', [
            'token' => $token,
            'result' => $result,
            'rows' => array_slice($result['rows'] ?? [], 0, self::PREVIEW_ROWS),
            'truncated' => count($result['rows'] ?? []) > self::PREVIEW_ROWS,
        ]);
    }

    public function download(Request $request, string $token): StreamedResponse
    {
        $this->authorizeStaff($request);

        $result = $this->reports->find($token) ?? abort(404, 'This report has expired or does not exist.');

        $filename = 'rtp-simulation-'.str(($result['game_name'] ?? 'game').'-'.$token)->slug().'.csv';

        return response()->streamDownload(
            fn () => print($this->reports->toCsv($result)),
            $filename,
            ['Content-Type' => 'text/csv'],
        );
    }

    private function authorizeStaff(Request $request): void
    {
        $user = $request->user();

        if (! $user) {
            abort(Redirect::guest(route('filament.admin.auth.login')));
        }

        abort_unless($user->hasPermission('games.manage'), 403);
    }
}
