<?php

namespace OpenDominion\Http\Controllers\Dominion;

use DB;
use Illuminate\Http\Request;
use Illuminate\Pagination\Paginator;

class RankingsController extends AbstractDominionController
{
    public function getRankings(Request $request, string $type = null)
    {
        if (($type === null) || !in_array($type, ['largest-dominions', 'strongest-dominions'], true)) {
            return redirect()->route('dominion.rankings', ['argest-dominions']);
        }

        $resultsPerPage = 10;
        $selectedDominion = $this->getSelectedDominion();

        // If no page is set, then navigate to our dominion's page
        if (empty($request->query())) {
            $myRankings = DB::table('daily_rankings')
                ->where('dominion_id', $selectedDominion->id)
                ->where('key', $type)
                ->get();

            if (!$myRankings->isEmpty()) {
                $myRankings = $myRankings->first();

                $myPage = ceil($myRankings->rank / $resultsPerPage);

                Paginator::currentPageResolver(function () use ($myPage) {
                    return $myPage;
                });
            }
        }

        $rankings = DB::table('daily_rankings')
            ->where('round_id', $selectedDominion->round_id)
            ->where('key', $type)
            ->orderBy('rank')
            ->paginate($resultsPerPage);

        return view('pages.dominion.rankings', [
            'type' => $type,
            'rankings' => $rankings,
        ]);
    }
}
