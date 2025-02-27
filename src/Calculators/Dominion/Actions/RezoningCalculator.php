<?php

namespace OpenDominion\Calculators\Dominion\Actions;

use OpenDominion\Calculators\Dominion\LandCalculator;
use OpenDominion\Calculators\Dominion\SpellCalculator;
use OpenDominion\Models\Dominion;

class RezoningCalculator
{
    /** @var LandCalculator */
    protected $landCalculator;

    /** @var SpellCalculator */
    protected $spellCalculator;

    /**
     * RezoningCalculator constructor.
     *
     * @param LandCalculator $landCalculator
     */
    public function __construct(
        LandCalculator $landCalculator,
        SpellCalculator $spellCalculator
    )
    {
        $this->landCalculator = $landCalculator;
        $this->spellCalculator = $spellCalculator;
    }

    /**
     * Returns the Dominion's rezoning platinum cost (per acre of land).
     *
     * @param Dominion $dominion
     * @return int
     */
    public function getPlatinumCost(Dominion $dominion): int
    {
        $platinum = 0;

        $platinum += $this->landCalculator->getTotalLand($dominion);

        $platinum -= 250;
        $platinum *= 0.6;
        $platinum += 250;

        $platinum *= $this->getCostMultiplier($dominion);

        return round($platinum);
    }

    /**
     * Returns the maximum number of acres of land a Dominion can rezone.
     *
     * @param Dominion $dominion
     * @return int
     */
    public function getMaxAfford(Dominion $dominion): int
    {
        return min(
            floor($dominion->resource_platinum / $this->getPlatinumCost($dominion)),
            $this->landCalculator->getTotalBarrenLand($dominion)
        );
    }

    /**
     * Returns the Dominion's rezoning cost multiplier.
     *
     * @param Dominion $dominion
     * @return float
     */
    public function getCostMultiplier(Dominion $dominion): float
    {
        $multiplier = 1;

        // Values (percentages)
        $factoryReduction = 4;
        $factoryReductionMax = 60;

        // Factories
        $multiplier -= min(
            (($dominion->building_factory / $this->landCalculator->getTotalLand($dominion)) * $factoryReduction),
            ($factoryReductionMax / 100)
        );

        // Racial Bonus
        $multiplier += $dominion->race->getPerkMultiplier('rezone_cost');

        // Techs
        $multiplier += $dominion->getTechPerkMultiplier('rezone_cost');

        // Wonders
        $multiplier += $dominion->getWonderPerkMultiplier('rezone_cost');

        // Spells
        $multiplier += $dominion->getSpellPerkMultiplier('rezone_cost');

        return $multiplier;
    }
}
