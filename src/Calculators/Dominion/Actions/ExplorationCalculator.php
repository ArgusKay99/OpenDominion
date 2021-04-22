<?php

namespace OpenDominion\Calculators\Dominion\Actions;

use OpenDominion\Calculators\Dominion\LandCalculator;
use OpenDominion\Models\Dominion;
use OpenDominion\Services\Dominion\GuardMembershipService;

class ExplorationCalculator
{
    /** @var LandCalculator */
    protected $landCalculator;

    /** @var GuardMembershipService */
    protected $guardMembershipService;

    /**
     * ExplorationCalculator constructor.
     *
     * @param LandCalculator $landCalculator
     * @param GuardMembershipService $guardMembershipService
     */
    public function __construct(
        LandCalculator $landCalculator,
        GuardMembershipService $guardMembershipService
    )
    {
        $this->landCalculator = $landCalculator;
        $this->guardMembershipService = $guardMembershipService;
    }

    /**
     * Returns the Dominion's exploration platinum cost (per acre of land).
     *
     * @param Dominion $dominion
     * @return int
     */
    public function getPlatinumCost(Dominion $dominion): int
    {
        $platinum = 0;
        $totalLand = $this->landCalculator->getTotalLand($dominion);

        if ($totalLand < 300) {
            $platinum += -(3 * (300 - $totalLand));
        } else {
            $exponent = ($totalLand ** 0.0185) / 1.05;
            $exponent = clamp($exponent, 1.09, 1.121);
            $platinum += (3 * (($totalLand - 300) ** $exponent));
        }

        $platinum += 1000;

        $multiplier = 1;

        // Techs
        $multiplier += $dominion->getTechPerkMultiplier('explore_platinum_cost');

        // Wonders
        $multiplier += $dominion->getWonderPerkMultiplier('explore_platinum_cost');

        // Elite Guard Tax
        if ($this->guardMembershipService->isEliteGuardMember($dominion)) {
            $multiplier += 0.25;
        }

        return round($platinum * $multiplier);
    }

    /**
     * Returns the Dominion's exploration draftee cost (per acre of land).
     *
     * @param Dominion $dominion
     * @return int
     */
    public function getDrafteeCost(Dominion $dominion): int
    {
        $draftees = 0;
        $totalLand = $this->landCalculator->getTotalLand($dominion);

        if ($totalLand < 300) {
            $draftees = -(300 / $totalLand);
        } else {
            $draftees += (0.003 * (($totalLand - 300) ** 1.07));
        }

        $draftees += 5;

        if($totalLand >= 4000) {
            $draftees *= 1.25;
        }

        $drafteeCost = round($draftees);

        if ($drafteeCost < 7) {
            // Minimum draftee cost is 4
            return max(4, $drafteeCost);
        } else {
            // Techs - Cannot reduce draftee cost below 6
            $drafteeCost += $dominion->getTechPerkValue('explore_draftee_cost');
            return max(6, $drafteeCost);
        }
    }

    /**
     * Returns the maximum number of acres of land a Dominion can afford to
     * explore.
     *
     * @param Dominion $dominion
     * @return int
     */
    public function getMaxAfford(Dominion $dominion): int
    {
        return min(
            floor($dominion->resource_platinum / $this->getPlatinumCost($dominion)),
            floor($dominion->military_draftees / $this->getDrafteeCost($dominion))
        );
    }

    /**
     * Returns the morale drop after exploring for $amount of acres of land.
     *
     * @param Dominion $dominion
     * @param int $amount
     * @return int
     */
    public function getMoraleDrop(Dominion $dominion, $amount): int
    {
        $multiplier = (1 + $dominion->getTechPerkMultiplier('explore_morale_cost'));

        return max(1, floor(($amount + 2) / 3 * $multiplier));
    }
}
