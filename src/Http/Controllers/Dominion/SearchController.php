<?php

namespace OpenDominion\Http\Controllers\Dominion;

use OpenDominion\Calculators\Dominion\LandCalculator;
use OpenDominion\Calculators\Dominion\RangeCalculator;
use OpenDominion\Calculators\NetworthCalculator;
use OpenDominion\Models\Dominion;
use OpenDominion\Services\Dominion\GuardMembershipService;
use OpenDominion\Services\Dominion\ProtectionService;

class SearchController extends AbstractDominionController
{
    public function getSearch()
    {
        $guardMembershipService = app(GuardMembershipService::class);
        $landCalculator = app(LandCalculator::class);
        $networthCalculator = app(NetworthCalculator::class);
        $protectionService = app(ProtectionService::class);
        $rangeCalculator = app(RangeCalculator::class);

        $dominion = $this->getSelectedDominion();
        $dominions = Dominion::query()
            ->with([
                'round',
                'realm',
                'race',
            ])
            ->where('round_id', $dominion->round_id)
            ->where('locked_at', null)
            ->where(function ($query) {
                $query->where('abandoned_at', null)->orWhere('abandoned_at', '>', now());
            })
            ->get();

        return view('pages.dominion.search', compact(
            'guardMembershipService',
            'landCalculator',
            'networthCalculator',
            'protectionService',
            'rangeCalculator',
            'dominions'
        ));
    }
}
