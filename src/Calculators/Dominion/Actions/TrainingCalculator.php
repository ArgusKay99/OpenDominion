<?php

namespace OpenDominion\Calculators\Dominion\Actions;

use OpenDominion\Calculators\Dominion\HeroCalculator;
use OpenDominion\Calculators\Dominion\LandCalculator;
use OpenDominion\Helpers\UnitHelper;
use OpenDominion\Models\Dominion;

class TrainingCalculator
{
    /** @var HeroCalculator */
    protected $heroCalculator;

    /** @var LandCalculator */
    protected $landCalculator;

    /** @var UnitHelper */
    protected $unitHelper;

    /**
     * TrainingCalculator constructor.
     */
    public function __construct()
    {
        $this->heroCalculator = app(HeroCalculator::class);
        $this->landCalculator = app(LandCalculator::class);
        $this->unitHelper = app(UnitHelper::class);
    }

    /**
     * Returns the Dominion's training costs per unit.
     *
     * @param Dominion $dominion
     * @return array
     */
    public function getTrainingCostsPerUnit(Dominion $dominion): array
    {
        $costsPerUnit = [];
        $spyBaseCost = 500;
        $assassinBaseCost = 1000;
        $assassinBaseCost += $dominion->race->getPerkValue('assassin_cost');
        $wizardBaseCost = 500;
        $archmageBaseCost = 1000;
        $archmageBaseCost += $dominion->race->getPerkValue('archmage_cost');

        $spyCostMultiplier = $this->getSpyCostMultiplier($dominion);
        $wizardCostMultiplier = $this->getWizardCostMultiplier($dominion);

        // Values
        $spyPlatinumCost = (int)ceil($spyBaseCost * $spyCostMultiplier);
        $assassinPlatinumCost = (int)ceil($assassinBaseCost * $spyCostMultiplier);
        $wizardPlatinumCost = (int)ceil($wizardBaseCost * $wizardCostMultiplier);
        $archmagePlatinumCost = (int)ceil($archmageBaseCost * $wizardCostMultiplier);

        $units = $dominion->race->units;

        foreach ($this->unitHelper->getUnitTypes() as $unitType) {
            $cost = [];

            switch ($unitType) {
                case 'spies':
                    $cost['draftees'] = 1;
                    $cost['platinum'] = $spyPlatinumCost;
                    break;

                case 'assassins':
                    $cost['platinum'] = $assassinPlatinumCost;
                    $cost['spies'] = 1;
                    break;

                case 'wizards':
                    $cost['draftees'] = 1;
                    $cost['platinum'] = $wizardPlatinumCost;
                    break;

                case 'archmages':
                    $cost['platinum'] = $archmagePlatinumCost;
                    $cost['wizards'] = 1;
                    break;

                default:
                    $unitSlot = (((int)str_replace('unit', '', $unitType)) - 1);

                    $platinum = $units[$unitSlot]->cost_platinum;
                    $ore = $units[$unitSlot]->cost_ore;
                    $mana = $units[$unitSlot]->cost_mana;
                    $lumber = $units[$unitSlot]->cost_lumber;
                    $gems = $units[$unitSlot]->cost_gems;

                    if ($platinum > 0) {
                        $cost['platinum'] = (int)ceil($platinum * $this->getSpecialistEliteCostMultiplier($dominion));
                    }

                    if ($ore > 0) {
                        $cost['ore'] = $ore;

                        if ($dominion->race->name !== 'Gnome') {
                            $cost['ore'] = (int)ceil($ore * $this->getSpecialistEliteCostMultiplier($dominion));
                        }
                    }

                    if ($mana > 0) {
                        $cost['mana'] = (int)ceil($mana * $this->getSpecialistEliteCostMultiplier($dominion));
                    }

                    if ($lumber > 0) {
                        $cost['lumber'] = (int)ceil($lumber * $this->getSpecialistEliteCostMultiplier($dominion));
                    }

                    if ($gems > 0) {
                        $cost['gems'] = (int)ceil($gems * $this->getSpecialistEliteCostMultiplier($dominion));
                    }

                    $cost['draftees'] = 1;

                    break;
            }

            $costsPerUnit[$unitType] = $cost;
        }

        return $costsPerUnit;
    }

    /**
     * Returns the Dominion's max military trainable population.
     *
     * @param Dominion $dominion
     * @return array
     */
    public function getMaxTrainable(Dominion $dominion): array
    {
        $trainable = [];

        $fieldMapping = [
            'platinum' => 'resource_platinum',
            'ore' => 'resource_ore',
            'mana' => 'resource_mana',
            'lumber' => 'resource_lumber',
            'gems' => 'resource_gems',
            'draftees' => 'military_draftees',
            'spies' => 'military_spies',
            'wizards' => 'military_wizards',
        ];

        $costsPerUnit = $this->getTrainingCostsPerUnit($dominion);

        foreach ($costsPerUnit as $unitType => $costs) {
            $trainableByCost = [];

            foreach ($costs as $type => $value) {
                $trainableByCost[$type] = (int)floor($dominion->{$fieldMapping[$type]} / $value);
            }

            $trainable[$unitType] = min($trainableByCost);
        }

        return $trainable;
    }

    /**
     * Returns the Dominion's training cost multiplier.
     *
     * @param Dominion $dominion
     * @return float
     */
    public function getSpecialistEliteCostMultiplier(Dominion $dominion): float
    {
        $multiplier = 1;

        // Values (percentages)
        $smithiesReduction = 2;
        $smithiesReductionMax = 36;

        // Smithies
        $multiplier -= min(
            (($dominion->building_smithy / $this->landCalculator->getTotalLand($dominion)) * $smithiesReduction),
            ($smithiesReductionMax / 100)
        );

        // Techs
        $multiplier += $dominion->getTechPerkMultiplier('military_cost');

        // Heroes
        $multiplier += $this->heroCalculator->getHeroPerkMultiplier($dominion, 'military_cost');

        return $multiplier;
    }

    /**
     * Returns the Dominion's training platinum cost multiplier for spies and assassins.
     *
     * @param Dominion $dominion
     * @return float
     */
    public function getSpyCostMultiplier(Dominion $dominion): float
    {
        $multiplier = 1;

        // Values (percentages)
        $guildReduction = 3.5;
        $guildReductionMax = 35;

        // Guilds
        $multiplier -= min(
            (($dominion->building_wizard_guild / $this->landCalculator->getTotalLand($dominion)) * $guildReduction),
            ($guildReductionMax / 100)
        );

        // Techs
        $multiplier += $dominion->getTechPerkMultiplier('spy_cost');

        return $multiplier;
    }

    /**
     * Returns the Dominion's training platinum cost multiplier for wizards and archmages.
     *
     * @param Dominion $dominion
     * @return float
     */
    public function getWizardCostMultiplier(Dominion $dominion): float
    {
        $multiplier = 1;

        // Values (percentages)
        $guildReduction = 3.5;
        $guildReductionMax = 35;

        // Guilds
        $multiplier -= min(
            (($dominion->building_wizard_guild / $this->landCalculator->getTotalLand($dominion)) * $guildReduction),
            ($guildReductionMax / 100)
        );

        // Techs
        $multiplier += $dominion->getTechPerkMultiplier('wizard_cost');

        return $multiplier;
    }
}
