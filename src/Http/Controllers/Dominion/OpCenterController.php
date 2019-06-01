<?php

namespace OpenDominion\Http\Controllers\Dominion;

use OpenDominion\Calculators\Dominion\LandCalculator;
use OpenDominion\Calculators\Dominion\RangeCalculator;
use OpenDominion\Calculators\Dominion\SpellCalculator;
use OpenDominion\Helpers\BuildingHelper;
use OpenDominion\Helpers\ImprovementHelper;
use OpenDominion\Helpers\LandHelper;
use OpenDominion\Helpers\SpellHelper;
use OpenDominion\Helpers\UnitHelper;
use OpenDominion\Models\Dominion;
use OpenDominion\Models\Realm;
use OpenDominion\Services\Dominion\InfoOpService;
use OpenDominion\Services\GameEventService;

class OpCenterController extends AbstractDominionController
{
    public function getIndex()
    {
        $dominion = $this->getSelectedDominion();

        // todo: filter $targetDominions by $dominion range
        // todo: keep track of how many dominions are filtered due to range? to see if upper/lower end of realm is active much

        return view('pages.dominion.op-center.index', [
            'infoOpService' => app(InfoOpService::class),
            'rangeCalculator' => app(RangeCalculator::class),
            'spellHelper' => app(SpellHelper::class),
            'targetDominions' => $dominion->realm->infoOpTargetDominions
        ]);
    }

    public function getDominion(Dominion $dominion)
    {
        $infoOpService = app(InfoOpService::class);

        if (!$infoOpService->hasInfoOps($this->getSelectedDominion()->realm, $dominion)) {
            return redirect()->route('dominion.op-center');
        }

        return view('pages.dominion.op-center.show', [
            'buildingHelper' => app(BuildingHelper::class),
            'improvementHelper' => app(ImprovementHelper::class),
            'infoOpService' => app(InfoOpService::class),
            'landCalculator' => app(LandCalculator::class),
            'landHelper' => app(LandHelper::class),
            'rangeCalculator' => app(RangeCalculator::class),
            'spellCalculator' => app(SpellCalculator::class),
            'spellHelper' => app(SpellHelper::class),
            'unitHelper' => app(UnitHelper::class),
            'dominion' => $dominion,
        ]);
    }

    public function getClairvoyance(int $realmId)
    {
        $targetRealm = Realm::find($realmId);

        $infoOpService = app(InfoOpService::class);

        $clairvoyanceInfoOp = $infoOpService->getInfoOpForRealm($this->getSelectedDominion()->realm, $targetRealm, 'clairvoyance');

        if($clairvoyanceInfoOp == null)
        {
            // throw?
        }

        $gameEventService = app(GameEventService::class);

        $clairvoyanceUpdateAt = $clairvoyanceInfoOp->updated_at;
        $clairvoyanceData = $gameEventService->getClairvoyance($targetRealm, $clairvoyanceUpdateAt);

        $gameEvents = $clairvoyanceData['gameEvents'];
        $dominionIds = $clairvoyanceData['dominionIds'];

        return view('pages.dominion.town-crier', compact(
            'gameEvents',
            'targetRealm',
            'dominionIds'
        ));
    }
}
