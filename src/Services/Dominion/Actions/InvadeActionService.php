<?php

namespace OpenDominion\Services\Dominion\Actions;

use DB;
use OpenDominion\Calculators\Dominion\BuildingCalculator;
use OpenDominion\Calculators\Dominion\CasualtiesCalculator;
use OpenDominion\Calculators\Dominion\LandCalculator;
use OpenDominion\Calculators\Dominion\MilitaryCalculator;
use OpenDominion\Calculators\Dominion\RangeCalculator;
use OpenDominion\Calculators\Dominion\SpellCalculator;
use OpenDominion\Exceptions\GameException;
use OpenDominion\Models\Dominion;
use OpenDominion\Models\GameEvent;
use OpenDominion\Models\Unit;
use OpenDominion\Services\Dominion\GovernmentService;
use OpenDominion\Services\Dominion\HistoryService;
use OpenDominion\Services\Dominion\InvasionService;
use OpenDominion\Services\Dominion\ProtectionService;
use OpenDominion\Services\Dominion\QueueService;
use OpenDominion\Services\NotificationService;
use OpenDominion\Traits\DominionGuardsTrait;

class InvadeActionService
{
    use DominionGuardsTrait;

    /**
     * @var float Base percentage of boats sunk
     */
    protected const BOATS_SUNK_BASE_PERCENTAGE = 5;

    /**
     * @var float Base percentage of defensive casualties
     */
    protected const CASUALTIES_DEFENSIVE_BASE_PERCENTAGE = 4.05;

    /**
     * @var float Max percentage of defensive casualties
     */
    protected const CASUALTIES_DEFENSIVE_MAX_PERCENTAGE = 6.0;

    /**
     * @var float Base percentage of offensive casualties
     */
    protected const CASUALTIES_OFFENSIVE_BASE_PERCENTAGE = 8.5;

    /**
     * @var float Failing an invasion by this percentage (or more) results in 'being overwhelmed'
     */
    protected const OVERWHELMED_PERCENTAGE = 15.0;

    /**
     * @var float Used to cap prestige gain formula
     */
    protected const PRESTIGE_CAP = 130;

    /**
     * @var int Bonus prestige when invading successfully
     */
    protected const PRESTIGE_CHANGE_ADD = 20;

    /**
     * @var float Base prestige % change for both parties when invading
     */
    protected const PRESTIGE_CHANGE_PERCENTAGE = 5.0;

    /**
     * @var float Additional prestige % change for defender from recent invasions
     */
    protected const PRESTIGE_LOSS_PERCENTAGE_PER_INVASION = 1.0;

    /**
     * @var float Maximum prestige % change for defender
     */
    protected const PRESTIGE_LOSS_PERCENTAGE_CAP = 15.0;

    /** @var BuildingCalculator */
    protected $buildingCalculator;

    /** @var CasualtiesCalculator */
    protected $casualtiesCalculator;

    /** @var GovernmentService */
    protected $governmentService;

    /** @var InvasionService */
    protected $invasionService;

    /** @var LandCalculator */
    protected $landCalculator;

    /** @var MilitaryCalculator */
    protected $militaryCalculator;

    /** @var NotificationService */
    protected $notificationService;

    /** @var ProtectionService */
    protected $protectionService;

    /** @var QueueService */
    protected $queueService;

    /** @var RangeCalculator */
    protected $rangeCalculator;

    /** @var SpellCalculator */
    protected $spellCalculator;

    // todo: use InvasionRequest class with op, dp, mods etc etc. Since now it's
    // a bit hacky with getting new data between $dominion/$target->save()s

    /** @var array Invasion result array. todo: Should probably be refactored later to its own class */
    protected $invasionResult = [
        'result' => [],
        'attacker' => [
            'unitsLost' => [],
            'unitsSent' => [],
        ],
        'defender' => [
            'unitsLost' => [],
        ],
    ];

    // todo: refactor
    /** @var GameEvent */
    protected $invasionEvent;

    // todo: refactor to use $invasionResult instead
    /** @var int The amount of land lost during the invasion */
    protected $landLost = 0;

    /** @var int The amount of units lost during the invasion */
    protected $unitsLost = 0;

    /**
     * InvadeActionService constructor.
     *
     * @param BuildingCalculator $buildingCalculator
     * @param CasualtiesCalculator $casualtiesCalculator
     * @param GovernmentService $governmentService
     * @param InvasionService $invasionService
     * @param LandCalculator $landCalculator
     * @param MilitaryCalculator $militaryCalculator
     * @param NotificationService $notificationService
     * @param ProtectionService $protectionService
     * @param QueueService $queueService
     * @param RangeCalculator $rangeCalculator
     * @param SpellCalculator $spellCalculator
     */
    public function __construct(
        BuildingCalculator $buildingCalculator,
        CasualtiesCalculator $casualtiesCalculator,
        GovernmentService $governmentService,
        InvasionService $invasionService,
        LandCalculator $landCalculator,
        MilitaryCalculator $militaryCalculator,
        NotificationService $notificationService,
        ProtectionService $protectionService,
        QueueService $queueService,
        RangeCalculator $rangeCalculator,
        SpellCalculator $spellCalculator
    )
    {
        $this->buildingCalculator = $buildingCalculator;
        $this->casualtiesCalculator = $casualtiesCalculator;
        $this->governmentService = $governmentService;
        $this->invasionService = $invasionService;
        $this->landCalculator = $landCalculator;
        $this->militaryCalculator = $militaryCalculator;
        $this->notificationService = $notificationService;
        $this->protectionService = $protectionService;
        $this->queueService = $queueService;
        $this->rangeCalculator = $rangeCalculator;
        $this->spellCalculator = $spellCalculator;
    }

    /**
     * Invades dominion $target from $dominion.
     *
     * @param Dominion $dominion
     * @param Dominion $target
     * @param array $units
     * @return array
     * @throws GameException
     */
    public function invade(Dominion $dominion, Dominion $target, array $units, ?bool $cancel_leave_range): array
    {
        $this->guardLockedDominion($dominion);
        $this->guardLockedDominion($target);
        $this->guardActionsDuringTick($dominion, 5);

        DB::transaction(function () use ($dominion, $target, $units, $cancel_leave_range) {
            if ($dominion->round->hasOffensiveActionsDisabled()) {
                throw new GameException('Invasions have been disabled for the remainder of the round');
            }

            if ($this->protectionService->isUnderProtection($dominion)) {
                throw new GameException('You cannot invade while under protection');
            }

            if ($this->protectionService->isUnderProtection($target)) {
                throw new GameException('You cannot invade dominions which are under protection');
            }

            if (!$this->rangeCalculator->isInRange($dominion, $target)) {
                throw new GameException('You cannot invade dominions outside of your range');
            }

            if ($dominion->round->id !== $target->round->id) {
                throw new GameException('Nice try, but you cannot invade cross-round');
            }

            if ($dominion->realm->id === $target->realm->id) {
                throw new GameException('Nice try, but you cannot invade your realmies');
            }

            if ($cancel_leave_range === true) {
                $range = $this->rangeCalculator->getDominionRange($dominion, $target);
                if ($range < 75) {
                    throw new GameException('Your attack was canceled because the target is no longer in your 75% range');
                }
            }

            // Sanitize input
            $units = array_map('intval', array_filter($units));
            $landRatio = $this->rangeCalculator->getDominionRange($dominion, $target) / 100;

            if (!$this->invasionService->hasAnyOP($dominion, $units)) {
                throw new GameException('You need to send at least some units');
            }

            if (!$this->invasionService->allUnitsHaveOP($dominion, $units)) {
                throw new GameException('You cannot send units that have no OP');
            }

            if (!$this->invasionService->hasEnoughUnitsAtHome($dominion, $units)) {
                throw new GameException('You don\'t have enough units at home to send this many units');
            }

            if (!$this->invasionService->hasEnoughBoats($dominion, $units)) {
                throw new GameException('You do not have enough boats to send this many units');
            }

            if (!$this->invasionService->hasEnoughMorale($dominion)) {
                throw new GameException('You do not have enough morale to invade others');
            }

            if (!$this->invasionService->passes33PercentRule($dominion, $target, $units)) {
                throw new GameException('You need to leave more DP units at home (33% rule)');
            }

            if (!$this->invasionService->passes54RatioRule($dominion, $target, $landRatio, $units)) {
                throw new GameException('You are sending out too much OP, based on your new home DP (5:4 rule)');
            }

            foreach($units as $amount) {
                if ($amount < 0) {
                    throw new GameException('Invasion was canceled due to bad input');
                }
            }

            // Handle invasion results
            $this->checkInvasionSuccess($dominion, $target, $units);
            $this->checkOverwhelmed();

            $this->rangeCalculator->checkGuardApplications($dominion, $target);

            $this->invasionResult['attacker']['repeatInvasion'] = $this->militaryCalculator->getRecentlyInvadedCount($target, 8, true, $dominion) > 1;
            $this->invasionResult['defender']['recentlyInvadedCount'] = $this->militaryCalculator->getRecentlyInvadedCount($target);
            $this->handleBoats($dominion, $target, $units);
            $this->handlePrestigeChanges($dominion, $target, $units);

            $survivingUnits = $this->handleOffensiveCasualties($dominion, $target, $units);
            $totalDefensiveCasualties = $this->handleDefensiveCasualties($dominion, $target, $units);
            $convertedUnits = $this->handleConversions($dominion, $landRatio, $units, $totalDefensiveCasualties);

            $this->handleReturningUnits($dominion, $survivingUnits, $convertedUnits);
            $this->handleAfterInvasionUnitPerks($dominion, $target, $survivingUnits);
            $this->handleResearchPoints($dominion, $target, $survivingUnits);

            $this->handleMoraleChanges($dominion, $target);
            $this->handleLandGrabs($dominion, $target);

            $this->invasionResult['attacker']['unitsSent'] = $units;

            // Stat changes
            // todo: move to own method
            if ($this->invasionResult['result']['success']) {
                $dominion->stat_total_land_conquered += (int)array_sum($this->invasionResult['attacker']['landConquered']);
                $dominion->stat_total_land_conquered += (int)array_sum($this->invasionResult['attacker']['landGenerated']);
                $dominion->stat_attacking_success += 1;
                $target->stat_total_land_lost += (int)array_sum($this->invasionResult['attacker']['landConquered']);
                $target->stat_defending_failure += 1;
            } else {
                $target->stat_defending_success += 1;
                $dominion->stat_attacking_failure += 1;
            }

            // todo: move to GameEventService
            $this->invasionEvent = GameEvent::create([
                'round_id' => $dominion->round_id,
                'source_type' => Dominion::class,
                'source_id' => $dominion->id,
                'target_type' => Dominion::class,
                'target_id' => $target->id,
                'type' => 'invasion',
                'data' => $this->invasionResult,
            ]);

            // todo: move to its own method
            // Notification
            if ($this->invasionResult['result']['success']) {
                $this->notificationService->queueNotification('received_invasion', [
                    '_routeParams' => [(string)$this->invasionEvent->id],
                    'attackerDominionId' => $dominion->id,
                    'landLost' => $this->landLost,
                    'unitsLost' => $this->unitsLost,
                ]);
            } else {
                $this->notificationService->queueNotification('repelled_invasion', [
                    '_routeParams' => [(string)$this->invasionEvent->id],
                    'attackerDominionId' => $dominion->id,
                    'attackerWasOverwhelmed' => $this->invasionResult['result']['overwhelmed'],
                    'unitsLost' => $this->unitsLost,
                ]);
            }

            $dominion->resetAbandonment();
            $dominion->save(['event' => HistoryService::EVENT_ACTION_INVADE]);
            $target->resetAbandonment();
            $target->save(['event' => HistoryService::EVENT_ACTION_INVADED]);
        });

        $this->notificationService->sendNotifications($target, 'irregular_dominion');

        if ($this->invasionResult['result']['success']) {
            $message = sprintf(
                'Your army fights valiantly, and defeats the forces of %s (#%s), conquering %s new acres of land! During the invasion, your troops also discovered %s acres of land.',
                $target->name,
                $target->realm->number,
                number_format(array_sum($this->invasionResult['attacker']['landConquered'])),
                number_format(array_sum($this->invasionResult['attacker']['landGenerated']))
            );
            $alertType = 'success';
        } else {
            $message = sprintf(
                'Your army fails to defeat the forces of %s (#%s).',
                $target->name,
                $target->realm->number
            );
            $alertType = 'danger';
        }

        return [
            'message' => $message,
            'alert-type' => $alertType,
            'redirect' => route('dominion.event', [$this->invasionEvent->id])
        ];
    }

    /**
     * Handles prestige changes for both dominions.
     *
     * Prestige gains and losses are based on several factors. The most
     * important one is the range (aka relative land size percentage) of the
     * target compared to the attacker.
     *
     * -   X -  65 equals a very weak target, and the attacker is penalized with a prestige loss, no matter the outcome
     * -  66 -  74 equals a weak target, and incurs no prestige changes for either side, no matter the outcome
     * -  75 - 119 equals an equal target, and gives full prestige changes, depending on if the invasion is successful
     * - 120 - X   equals a strong target, and incurs no prestige changes for either side, no matter the outcome
     *
     * Due to the above, people are encouraged to hit targets in 75-119 range,
     * and are discouraged to hit anything below 66.
     *
     * Failing an attack above 66% range only results in a prestige loss if the
     * attacker is overwhelmed by the target defenses.
     *
     * @param Dominion $dominion
     * @param Dominion $target
     * @param array $units
     */
    protected function handlePrestigeChanges(Dominion $dominion, Dominion $target, array $units): void
    {
        $isInvasionSuccessful = $this->invasionResult['result']['success'];
        $isOverwhelmed = $this->invasionResult['result']['overwhelmed'];
        $range = $this->rangeCalculator->getDominionRange($dominion, $target);

        $attackerPrestigeChange = 0;
        $targetPrestigeChange = 0;
        $multiplier = 1;

        if ($isOverwhelmed || ($range < 60)) {
            $attackerPrestigeChange = ($dominion->prestige * -(static::PRESTIGE_CHANGE_PERCENTAGE / 100));
        } elseif ($isInvasionSuccessful && ($range >= 75)) {
            $attackerPrestigeChange = min(
                $target->prestige * (($range / 100) / 10), // Gained through invading
                static::PRESTIGE_CAP // But capped at 130
            ) + static::PRESTIGE_CHANGE_ADD;

            $weeklyInvadedCount = $this->militaryCalculator->getRecentlyInvadedCount($dominion, 24 * 7, true);
            $prestigeLossPercentage = min(
                (static::PRESTIGE_CHANGE_PERCENTAGE / 100) + (static::PRESTIGE_LOSS_PERCENTAGE_PER_INVASION / 100 * $weeklyInvadedCount),
                (static::PRESTIGE_LOSS_PERCENTAGE_CAP / 100)
            );
            $targetPrestigeChange = (int)round($target->prestige * -($prestigeLossPercentage));

            // Racial Bonus
            $multiplier += $dominion->race->getPerkMultiplier('prestige_gains');

            // Techs
            $multiplier += $dominion->getTechPerkMultiplier('prestige_gains');

            // Wonders
            $multiplier += $dominion->getWonderPerkMultiplier('prestige_gains');

            // War Bonus
            if ($this->governmentService->isMutualWarEscalated($dominion->realm, $target->realm)) {
                $multiplier += 0.2;
            } elseif ($this->governmentService->isWarEscalated($dominion->realm, $target->realm) || $this->governmentService->isWarEscalated($target->realm, $dominion->realm)) {
                $multiplier += 0.1;
            }

            $attackerPrestigeChange *= $multiplier;
        }

        // Repeat Invasions award no prestige
        if ($this->invasionResult['attacker']['repeatInvasion']) {
            $attackerPrestigeChange = 0;
        }

        // Reduce attacker prestige gain if the target was hit recently
        if ($attackerPrestigeChange > 0) {
            $recentlyInvadedCount = $this->invasionResult['defender']['recentlyInvadedCount'];

            if ($recentlyInvadedCount > 0) {
                $attackerPrestigeChange *= (1 - (0.1 * $recentlyInvadedCount));
            }

            if ($attackerPrestigeChange < 20) {
                $attackerPrestigeChange = 20;
            }
        }

        $attackerPrestigeChange = (int)round($attackerPrestigeChange);
        if ($attackerPrestigeChange !== 0) {
            if (!$isInvasionSuccessful) {
                // Unsuccessful invasions (bounces) give negative prestige immediately
                $dominion->prestige += $attackerPrestigeChange;

            } else {
                // todo: possible bug if all 12hr units die (somehow) and only 9hr units survive, prestige gets returned after 12 hrs, since $units is input, not surviving units. fix?
                $slowestTroopsReturnHours = $this->invasionService->getSlowestUnitReturnHours($dominion, $units);

                $this->queueService->queueResources(
                    'invasion',
                    $dominion,
                    ['prestige' => $attackerPrestigeChange],
                    $slowestTroopsReturnHours
                );
            }

            $this->invasionResult['attacker']['prestigeChange'] = $attackerPrestigeChange;
        }

        if ($targetPrestigeChange !== 0) {
            $target->prestige += $targetPrestigeChange;

            $this->invasionResult['defender']['prestigeChange'] = $targetPrestigeChange;
        }
    }

    /**
     * Handles offensive casualties for the attacking dominion.
     *
     * Offensive casualties are 8.5% of the units needed to break the target,
     * regardless of how many you send.
     *
     * On unsuccessful invasions, offensive casualties are 8.5% of all units
     * you send, doubled if you are overwhelmed.
     *
     * @param Dominion $dominion
     * @param Dominion $target
     * @param array $units
     * @return array All the units that survived and will return home
     */
    protected function handleOffensiveCasualties(Dominion $dominion, Dominion $target, array $units): array
    {
        $isInvasionSuccessful = $this->invasionResult['result']['success'];
        $isOverwhelmed = $this->invasionResult['result']['overwhelmed'];
        $landRatio = $this->rangeCalculator->getDominionRange($dominion, $target) / 100;
        $attackingForceOP = $this->invasionResult['attacker']['op'];
        $targetDP = $this->invasionResult['defender']['dp'];
        $offensiveCasualtiesPercentage = (static::CASUALTIES_OFFENSIVE_BASE_PERCENTAGE / 100);

        $offensiveUnitsLost = [];

        if ($isInvasionSuccessful) {
            $totalUnitsSent = array_sum($units);

            $averageOPPerUnitSent = ($attackingForceOP / $totalUnitsSent);
            $OPNeededToBreakTarget = ($targetDP + 1);
            $unitsNeededToBreakTarget = round($OPNeededToBreakTarget / $averageOPPerUnitSent);

            $totalUnitsLeftToKill = (int)ceil($unitsNeededToBreakTarget * $offensiveCasualtiesPercentage);

            foreach ($units as $slot => $amount) {
                $slotTotalAmountPercentage = ($amount / $totalUnitsSent);

                if ($slotTotalAmountPercentage === 0) {
                    continue;
                }

                $unitsToKill = ceil($unitsNeededToBreakTarget * $offensiveCasualtiesPercentage * $slotTotalAmountPercentage);
                $offensiveUnitsLost[$slot] = $unitsToKill;

                if ($totalUnitsLeftToKill < $unitsToKill) {
                    $unitsToKill = $totalUnitsLeftToKill;
                }

                $totalUnitsLeftToKill -= $unitsToKill;

                $fixedCasualtiesPerk = $dominion->race->getUnitPerkValueForUnitSlot($slot, 'fixed_casualties');
                if ($fixedCasualtiesPerk) {
                    $fixedCasualtiesRatio = $fixedCasualtiesPerk / 100;
                    $unitsActuallyKilled = (int)ceil($amount * $fixedCasualtiesRatio);
                    $offensiveUnitsLost[$slot] = $unitsActuallyKilled;
                }
            }
        } else {
            foreach ($units as $slot => $amount) {
                $fixedCasualtiesPerk = $dominion->race->getUnitPerkValueForUnitSlot($slot, 'fixed_casualties');
                if ($fixedCasualtiesPerk) {
                    $fixedCasualtiesRatio = $fixedCasualtiesPerk / 100;
                    $unitsToKill = (int)ceil($amount * $fixedCasualtiesRatio);
                    $offensiveUnitsLost[$slot] = $unitsToKill;
                    continue;
                }

                $unitsToKill = (int)ceil($amount * $offensiveCasualtiesPercentage);
                $offensiveUnitsLost[$slot] = $unitsToKill;
            }
        }

        foreach ($offensiveUnitsLost as $slot => &$amount) {
            // Reduce amount of units to kill by further multipliers
            $unitsToKillMultiplier = $this->casualtiesCalculator->getOffensiveCasualtiesMultiplierForUnitSlot($dominion, $target, $slot, $units, $landRatio, $isOverwhelmed);

            if ($unitsToKillMultiplier !== 1) {
                $amount = (int)ceil($amount * $unitsToKillMultiplier);
            }

            if ($amount > 0) {
                // Actually kill the units. RIP in peace, glorious warriors ;_;7
                $dominion->{"military_unit{$slot}"} -= $amount;

                $this->invasionResult['attacker']['unitsLost'][$slot] = $amount;
            }
        }
        unset($amount); // Unset var by reference from foreach loop above to prevent unintended side-effects

        $survivingUnits = $units;

        foreach ($units as $slot => $amount) {
            if (isset($offensiveUnitsLost[$slot])) {
                $survivingUnits[$slot] -= $offensiveUnitsLost[$slot];
            }
        }

        return $survivingUnits;
    }

    /**
     * Handles defensive casualties for the defending dominion.
     *
     * Defensive casualties are base 4.5% across all units that help defending.
     *
     * This scales with relative land size, and invading OP compared to
     * defending OP, up to max 6%.
     *
     * Unsuccessful invasions results in reduced defensive casualties, based on
     * the invading force's OP.
     *
     * Defensive casualties are spread out in ratio between all units that help
     * defend, including draftees. Being recently invaded reduces defensive
     * casualties: 100%, 80%, 60%, 55%, 45%, 35%.
     *
     * @param Dominion $dominion
     * @param Dominion $target
     * @return int
     */
    protected function handleDefensiveCasualties(Dominion $dominion, Dominion $target, array $units): int
    {
        if ($this->invasionResult['result']['overwhelmed'])
        {
            return 0;
        }

        $attackingForceOP = $this->invasionResult['attacker']['op'];
        $targetDP = $this->invasionResult['defender']['dp'];
        $defensiveCasualtiesPercentage = (static::CASUALTIES_DEFENSIVE_BASE_PERCENTAGE / 100);

        // Modify casualties percentage based on relative land size
        $landRatio = $this->rangeCalculator->getDominionRange($dominion, $target) / 100;
        $defensiveCasualtiesPercentage *= clamp($landRatio, 0.4, 1);

        // Scale casualties further with invading OP vs target DP
        $defensiveCasualtiesPercentage *= ($attackingForceOP / $targetDP);

        // Reduce casualties if target has been hit recently
        $recentlyInvadedCount = $this->invasionResult['defender']['recentlyInvadedCount'];

        if ($recentlyInvadedCount === 1) {
            $defensiveCasualtiesPercentage *= 0.8;
        } elseif ($recentlyInvadedCount === 2) {
            $defensiveCasualtiesPercentage *= 0.6;
        } elseif ($recentlyInvadedCount === 3) {
            $defensiveCasualtiesPercentage *= 0.55;
        } elseif ($recentlyInvadedCount === 4) {
            $defensiveCasualtiesPercentage *= 0.45;
        } elseif ($recentlyInvadedCount >= 5) {
            $defensiveCasualtiesPercentage *= 0.35;
        }

        // Cap max casualties
        $defensiveCasualtiesPercentage = min(
            $defensiveCasualtiesPercentage,
            (static::CASUALTIES_DEFENSIVE_MAX_PERCENTAGE / 100)
        );

        $defensiveUnitsLost = [];

        // Draftees
        if ($this->spellCalculator->isSpellActive($dominion, 'unholy_ghost')) {
            $drafteesLost = 0;
        } else {
            $drafteesLost = (int)floor($target->military_draftees * $defensiveCasualtiesPercentage *
                $this->casualtiesCalculator->getDefensiveCasualtiesMultiplierForUnitSlot($target, $dominion, null, null));
        }
        if ($drafteesLost > 0) {
            $target->military_draftees -= $drafteesLost;

            $this->unitsLost += $drafteesLost; // todo: refactor
            $this->invasionResult['defender']['unitsLost']['draftees'] = $drafteesLost;
        }

        // Non-draftees
        foreach ($target->race->units as $unit) {
            if ($unit->power_defense === 0.0) {
                continue;
            }

            $slotLost = (int)floor($target->{"military_unit{$unit->slot}"} * $defensiveCasualtiesPercentage *
                $this->casualtiesCalculator->getDefensiveCasualtiesMultiplierForUnitSlot($target, $dominion, $unit->slot, $units));

            if ($slotLost > 0) {
                $defensiveUnitsLost[$unit->slot] = $slotLost;

                $this->unitsLost += $slotLost; // todo: refactor
            }
        }

        foreach ($defensiveUnitsLost as $slot => $amount) {
            $target->{"military_unit{$slot}"} -= $amount;

            $this->invasionResult['defender']['unitsLost'][$slot] = $amount;
        }

        return $this->unitsLost;
    }

    /**
     * Handles land grabs and losses upon successful invasion.
     *
     * todo: description
     *
     * @param Dominion $dominion
     * @param Dominion $target
     */
    protected function handleLandGrabs(Dominion $dominion, Dominion $target): void
    {
        $this->invasionResult['attacker']['landSize'] = $this->landCalculator->getTotalLand($dominion);
        $this->invasionResult['defender']['landSize'] = $this->landCalculator->getTotalLand($target);

        $isInvasionSuccessful = $this->invasionResult['result']['success'];

        // Nothing to grab if invasion isn't successful :^)
        if (!$isInvasionSuccessful) {
            return;
        }

        if (!isset($this->invasionResult['attacker']['landConquered'])) {
            $this->invasionResult['attacker']['landConquered'] = [];
        }

        if (!isset($this->invasionResult['attacker']['landGenerated'])) {
            $this->invasionResult['attacker']['landGenerated'] = [];
        }

        $range = $this->rangeCalculator->getDominionRange($dominion, $target);
        $rangeMultiplier = ($range / 100);

        $landGrabRatio = 1;
        $bonusLandRatio = 1.6667;

        // War Bonus
        if ($this->governmentService->isMutualWarEscalated($dominion->realm, $target->realm)) {
            $landGrabRatio = 1.2;
        } elseif ($this->governmentService->isWarEscalated($dominion->realm, $target->realm) || $this->governmentService->isWarEscalated($target->realm, $dominion->realm)) {
            $landGrabRatio = 1.1;
        }

        $attackerLandWithRatioModifier = ($this->landCalculator->getTotalLand($dominion) * $landGrabRatio);

        if ($range < 55) {
            $acresLost = (0.304 * ($rangeMultiplier ** 2) - 0.227 * $rangeMultiplier + 0.048) * $attackerLandWithRatioModifier;
        } elseif ($range < 75) {
            $acresLost = (0.154 * $rangeMultiplier - 0.069) * $attackerLandWithRatioModifier;
        } else {
            $acresLost = (0.129 * $rangeMultiplier - 0.048) * $attackerLandWithRatioModifier;
        }

        $acresLost *= 0.90;

        $acresLost = (int)max(floor($acresLost), 10);

        $landLossRatio = ($acresLost / $this->landCalculator->getTotalLand($target));
        $landAndBuildingsLostPerLandType = $this->landCalculator->getLandLostByLandType($target, $landLossRatio);

        $landGainedPerLandType = [];
        foreach ($landAndBuildingsLostPerLandType as $landType => $landAndBuildingsLost) {
            if (!isset($this->invasionResult['attacker']['landConquered'][$landType])) {
                $this->invasionResult['attacker']['landConquered'][$landType] = 0;
            }

            if (!isset($this->invasionResult['attacker']['landGenerated'][$landType])) {
                $this->invasionResult['attacker']['landGenerated'][$landType] = 0;
            }

            $buildingsToDestroy = $landAndBuildingsLost['buildingsToDestroy'];
            $landLost = $landAndBuildingsLost['landLost'];
            $buildingsLostForLandType = $this->buildingCalculator->getBuildingTypesToDestroy($target, $buildingsToDestroy, $landType);

            // Remove land
            $target->{"land_$landType"} -= $landLost;

            // Add discounted land for buildings destroyed
            $target->discounted_land += $buildingsToDestroy;

            // Destroy buildings
            foreach ($buildingsLostForLandType as $buildingType => $buildingsLost) {
                $builtBuildingsToDestroy = $buildingsLost['builtBuildingsToDestroy'];
                $resourceName = "building_{$buildingType}";
                $target->$resourceName -= $builtBuildingsToDestroy;

                $buildingsInQueueToRemove = $buildingsLost['buildingsInQueueToRemove'];

                if ($buildingsInQueueToRemove !== 0) {
                    $this->queueService->dequeueResource('construction', $target, $resourceName, $buildingsInQueueToRemove);
                }
            }

            $landConquered = (int)round($landLost);
            $landGenerated = (int)round($landConquered * ($bonusLandRatio - 1));

            // Repeat Invasions generate no land
            if ($this->invasionResult['attacker']['repeatInvasion']) {
                $landGenerated = 0;
            }
            $landGained = ($landConquered + $landGenerated);

            // Racial Spell: Erosion (Lizardfolk, Merfolk), Verdant Bloom (Sylvan)
            if ($this->spellCalculator->isSpellActive($dominion, 'erosion') || $this->spellCalculator->isSpellActive($dominion, 'verdant_bloom')) {
                // todo: needs a more generic solution later
                if ($this->spellCalculator->isSpellActive($dominion, 'verdant_bloom')) {
                    $eventName = 'landVerdantBloom';
                    $landRezoneType = 'forest';
                    $landRezonePercentage = 35;
                } else {
                    $eventName = 'landErosion';
                    $landRezoneType = 'water';
                    $landRezonePercentage = 20;
                }

                $landRezonedConquered = (int)ceil($landConquered * ($landRezonePercentage / 100));
                $landRezonedGenerated = (int)round($landRezonedConquered * ($bonusLandRatio - 1));
                $landGenerated -= $landRezonedGenerated;
                $landGained -= ($landRezonedConquered + $landRezonedGenerated);

                if (!isset($landGainedPerLandType["land_{$landRezoneType}"])) {
                    $landGainedPerLandType["land_{$landRezoneType}"] = 0;
                }
                $landGainedPerLandType["land_{$landRezoneType}"] += ($landRezonedConquered + $landRezonedGenerated);

                if (!isset($this->invasionResult['attacker']['landGenerated'][$landRezoneType])) {
                    $this->invasionResult['attacker']['landGenerated'][$landRezoneType] = 0;
                }
                $this->invasionResult['attacker']['landGenerated'][$landRezoneType] += $landRezonedGenerated;

                if (!isset($this->invasionResult['attacker'][$eventName])) {
                    $this->invasionResult['attacker'][$eventName] = 0;
                }
                $this->invasionResult['attacker'][$eventName] += ($landRezonedConquered + $landRezonedGenerated);
            }

            if (!isset($landGainedPerLandType["land_{$landType}"])) {
                $landGainedPerLandType["land_{$landType}"] = 0;
            }
            $landGainedPerLandType["land_{$landType}"] += $landGained;

            $this->invasionResult['attacker']['landConquered'][$landType] += $landConquered;
            $this->invasionResult['attacker']['landGenerated'][$landType] += $landGenerated;
        }

        $this->landLost = $acresLost;

        $queueData = $landGainedPerLandType;

        // Only gain discounted acres at or above prestige range
        if ($range >= 75) {
            $queueData += [
                'discounted_land' => array_sum($landGainedPerLandType)
            ];
        }

        $this->queueService->queueResources(
            'invasion',
            $dominion,
            $queueData
        );
    }

    /**
     * Handles morale changes for attacker.
     *
     * Attacker morale gets reduced by 5%, more so if they attack a target below
     * 75% range (up to 10% reduction at 40% target range).
     *
     * @param Dominion $dominion
     * @param Dominion $target
     */
    protected function handleMoraleChanges(Dominion $dominion, Dominion $target): void
    {
        $range = $this->rangeCalculator->getDominionRange($dominion, $target);

        $dominion->morale -= 5;

        // Increased morale drops for attacking weaker targets
        if ($range < 75) {
            $additionalMoraleChange = max(round((((($range / 100) - 0.4) * 100) / 7) - 5), -5);
            $dominion->morale += $additionalMoraleChange;
        }
    }

    /**
     * @param Dominion $dominion
     * @param float $landRatio
     * @param array $units
     * @param int $totalDefensiveCasualties
     * @return array
     */
    protected function handleConversions(
        Dominion $dominion,
        float $landRatio,
        array $units,
        int $totalDefensiveCasualties
    ): array {
        $isInvasionSuccessful = $this->invasionResult['result']['success'];
        $convertedUnits = array_fill(1, 4, 0);

        if (
            !$isInvasionSuccessful ||
            ($totalDefensiveCasualties === 0) ||
            !in_array($dominion->race->name, ['Lycanthrope', 'Spirit', 'Undead'], true) // todo: might want to check for conversion unit perks here, instead of hardcoded race names
        )
        {
            return $convertedUnits;
        }

        $conversionBaseMultiplier = 0.06;
        $spellParasiticHungerMultiplier = 50;

        $conversionMultiplier = (
            $conversionBaseMultiplier *
            (1 + $this->spellCalculator->getActiveSpellMultiplierBonus($dominion, 'parasitic_hunger', $spellParasiticHungerMultiplier))
        );

        $totalConvertingUnits = 0;

        $unitsWithConversionPerk = $dominion->race->units->filter(static function (Unit $unit) use (
            $landRatio,
            $units,
            $dominion
        )
        {
            if (!array_key_exists($unit->slot, $units) || ($units[$unit->slot] === 0)) {
                return false;
            }

            $staggeredConversionPerk = $dominion->race->getUnitPerkValueForUnitSlot(
                $unit->slot,
                'staggered_conversion'
            );

            if ($staggeredConversionPerk) {
                foreach ($staggeredConversionPerk as $rangeConversionPerk) {
                    $range = ((int)$rangeConversionPerk[0]) / 100;
                    if ($range <= $landRatio) {
                        return true;
                    }
                }

                return false;
            }

            return $unit->getPerkValue('conversion');
        });

        foreach ($unitsWithConversionPerk as $unit) {
            $totalConvertingUnits += $units[$unit->slot];
        }

        $totalConverts = min($totalConvertingUnits * $conversionMultiplier, $totalDefensiveCasualties * 1.65) * $landRatio;

        foreach ($unitsWithConversionPerk as $unit) {
            $conversionPerk = $unit->getPerkValue('conversion');
            $convertingUnitsForSlot = $units[$unit->slot];
            $convertingUnitsRatio = $convertingUnitsForSlot / $totalConvertingUnits;
            $totalConversionsForUnit = floor($totalConverts * $convertingUnitsRatio);

            if (!$conversionPerk) {
                $staggeredConversionPerk = $dominion->race->getUnitPerkValueForUnitSlot(
                    $unit->slot,
                    'staggered_conversion'
                );

                foreach ($staggeredConversionPerk as $rangeConversionPerk) {
                    $range = ((int)$rangeConversionPerk[0]) / 100;
                    $slots = $rangeConversionPerk[1];

                    if ($range > $landRatio) {
                        continue;
                    }

                    $conversionPerk = $slots;
                }
            }

            $slotsToConvertTo = strlen($conversionPerk);
            $totalConvertsForSlot = floor($totalConversionsForUnit / $slotsToConvertTo);

            foreach (str_split($conversionPerk) as $slot) {
                $convertedUnits[(int)$slot] += (int)$totalConvertsForSlot;
            }
        }

        if (!isset($this->invasionResult['attacker']['conversion']) && array_sum($convertedUnits) > 0) {
            $this->invasionResult['attacker']['conversion'] = $convertedUnits;
        }

        return $convertedUnits;
    }

    /**
     * Handles research point generation for attacker.
     *
     * Past day 30 of the round, RP gains by attacking goes up from 1000 and peaks at 1667 on day 50
     *
     * @param Dominion $dominion
     * @param array $units
     */
    protected function handleResearchPoints(Dominion $dominion, Dominion $target, array $units): void
    {
        // Repeat Invasions award no research points
        if ($this->invasionResult['attacker']['repeatInvasion']) {
            return;
        }

        $isInvasionSuccessful = $this->invasionResult['result']['success'];
        if ($isInvasionSuccessful) {
            $researchPointsGained = max(1000, $dominion->round->daysInRound() / 0.03);

            // Recent invasion penalty
            $recentlyInvadedCount = $this->militaryCalculator->getRecentlyInvadedCount($dominion, 24 * 3, true);
            $schoolPenalty = (1 - min(0.75, max(0, $recentlyInvadedCount - 2) * 0.15));

            $range = $this->rangeCalculator->getDominionRange($dominion, $target);
            if ($range < 60) {
                $researchPointsGained = 0;
            } elseif ($range < 75) {
                $researchPointsGained *= 0.5;
            } else {
                $schoolPercentageCap = 20;
                $schoolPercentage = min(
                    $dominion->building_school / $this->landCalculator->getTotalLand($dominion),
                    $schoolPercentageCap / 100
                );
                $researchPointsGained += (130 * $schoolPercentage * 100 * $schoolPenalty);
                $researchPointsGained = max(0, $researchPointsGained);
            }

            $multiplier = 1;

            // Racial Bonus
            $multiplier += $dominion->race->getPerkMultiplier('tech_production');

            // Wonders
            $multiplier += $dominion->getWonderPerkMultiplier('tech_production');

            $researchPointsGained *= $multiplier;

            if($researchPointsGained > 0) {
                $slowestTroopsReturnHours = $this->invasionService->getSlowestUnitReturnHours($dominion, $units);
                $this->queueService->queueResources(
                    'invasion',
                    $dominion,
                    ['resource_tech' => round($researchPointsGained)],
                    $slowestTroopsReturnHours
                );
            }

            $this->invasionResult['attacker']['researchPoints'] = round($researchPointsGained);
        }
    }

    /**
     * Handles perks that trigger on invasion.
     *
     * @param Dominion $dominion
     * @param Dominion $target
     * @param array $units
     */
    protected function handleAfterInvasionUnitPerks(Dominion $dominion, Dominion $target, array $units): void
    {
        // todo: need a refactor later to take into account more post-combat unit-perk-related stuff

        if (!$this->invasionResult['result']['success']) {
            return; // nothing to plunder on unsuccessful invasions
        }

        $unitsSentPerSlot = [];
        $unitsSentPlunderSlot = null;

        // todo: inefficient to do run this code per slot. needs refactoring
        foreach ($dominion->race->units as $unit) {
            $slot = $unit->slot;

            if (!isset($units[$slot])) {
                continue;
            }
            $unitsSentPerSlot[$slot] = $units[$slot];

            if ($unit->getPerkValue('plunders_resources_on_attack') != 0) {
                $unitsSentPlunderSlot = $slot;
            }
        }

        // We have a unit with plunder!
        if ($unitsSentPlunderSlot !== null) {
            $productionCalculator = app(\OpenDominion\Calculators\Dominion\ProductionCalculator::class);

            $totalUnitsSent = array_sum($unitsSentPerSlot);
            $unitsToPlunderWith = $unitsSentPerSlot[$unitsSentPlunderSlot];
            $plunderPlatinum = min($unitsToPlunderWith * 20, (int)floor($productionCalculator->getPlatinumProductionRaw($target)));
            $plunderGems = min($unitsToPlunderWith * 5, (int)floor($productionCalculator->getGemProductionRaw($target)));

            if (!isset($this->invasionResult['attacker']['plunder'])) {
                $this->invasionResult['attacker']['plunder'] = [
                    'platinum' => $plunderPlatinum,
                    'gems' => $plunderGems,
                ];
            }

            $this->queueService->queueResources(
                'invasion',
                $dominion,
                [
                    'resource_platinum' => $plunderPlatinum,
                    'resource_gems' => $plunderGems,
                ]
            );
        }
    }

    /**
     * Handles the surviving units returning home.
     *
     * @param Dominion $dominion
     * @param array $units
     * @param array $convertedUnits
     */
    protected function handleReturningUnits(Dominion $dominion, array $units, array $convertedUnits): void
    {
        for ($i = 1; $i <= 4; $i++) {
            $unitKey = "military_unit{$i}";
            $returningAmount = 0;

            if (array_key_exists($i, $units)) {
                $returningAmount += $units[$i];
                $dominion->$unitKey -= $units[$i];
            }

            if (array_key_exists($i, $convertedUnits)) {
                $returningAmount += $convertedUnits[$i];
            }

            if ($returningAmount === 0) {
                continue;
            }

            $this->queueService->queueResources(
                'invasion',
                $dominion,
                [$unitKey => $returningAmount],
                $this->invasionService->getUnitReturnHoursForSlot($dominion, $i)
            );
        }
    }

    /**
     * Handles the returning boats.
     *
     * @param Dominion $dominion
     * @param Dominion $target
     * @param array $units
     */
    protected function handleBoats(Dominion $dominion, Dominion $target, array $units): void
    {
        $unitsTotal = 0;
        $unitsThatSinkBoats = 0;
        $unitsThatNeedsBoatsByReturnHours = [];
        // Calculate boats sent and attacker sinking perk
        foreach ($dominion->race->units as $unit) {
            if (!isset($units[$unit->slot]) || ((int)$units[$unit->slot] === 0)) {
                continue;
            }

            $unitsTotal += (int)$units[$unit->slot];

            if ($unit->getPerkValue('sink_boats_offense') != 0) {
                $unitsThatSinkBoats += (int)$units[$unit->slot];
            }

            if ($unit->need_boat) {
                $hours = $this->invasionService->getUnitReturnHoursForSlot($dominion, $unit->slot);

                if (!isset($unitsThatNeedsBoatsByReturnHours[$hours])) {
                    $unitsThatNeedsBoatsByReturnHours[$hours] = 0;
                }

                $unitsThatNeedsBoatsByReturnHours[$hours] += (int)$units[$unit->slot];
            }
        }
        if (!$this->invasionResult['result']['overwhelmed'] && $unitsThatSinkBoats > 0) {
            $defenderBoatsProtected = $this->militaryCalculator->getBoatsProtected($target);
            $defenderBoatsSunkPercentage = (static::BOATS_SUNK_BASE_PERCENTAGE / 100) * ($unitsThatSinkBoats / $unitsTotal);
            $targetQueuedBoats = $this->queueService->getInvasionQueueTotalByResource($target, 'resource_boats');
            $targetBoatTotal = $target->resource_boats + $targetQueuedBoats;
            $defenderBoatsSunk = (int)floor(max(0, $targetBoatTotal - $defenderBoatsProtected) * $defenderBoatsSunkPercentage);
            if ($defenderBoatsSunk > $targetQueuedBoats) {
                $this->queueService->dequeueResource('invasion', $target, 'boats', $targetQueuedBoats);
                $target->resource_boats -= $defenderBoatsSunk - $targetQueuedBoats;
            } else {
                $this->queueService->dequeueResource('invasion', $target, 'boats', $defenderBoatsSunk);
            }
            $this->invasionResult['defender']['boatsLost'] = $defenderBoatsSunk;
        }

        $defendingUnitsTotal = 0;
        $defendingUnitsThatSinkBoats = 0;
        $attackerBoatsLost = 0;
        // Defender sinking perk
        foreach ($target->race->units as $unit) {
            $defendingUnitsTotal += $target->{"military_unit{$unit->slot}"};
            if ($unit->getPerkValue('sink_boats_defense') != 0) {
                $defendingUnitsThatSinkBoats += $target->{"military_unit{$unit->slot}"};
            }
        }
        if ($defendingUnitsThatSinkBoats > 0) {
            $attackerBoatsSunkPercentage = (static::BOATS_SUNK_BASE_PERCENTAGE / 100) * ($defendingUnitsThatSinkBoats / $defendingUnitsTotal);
        }

        // Queue returning boats
        foreach ($unitsThatNeedsBoatsByReturnHours as $hours => $amountUnits) {
            $boatsByReturnHourGroup = (int)floor($amountUnits / $this->militaryCalculator->getBoatCapacity($dominion));

            $dominion->resource_boats -= $boatsByReturnHourGroup;

            if ($defendingUnitsThatSinkBoats > 0) {
                $attackerBoatsSunk = (int)ceil($boatsByReturnHourGroup * $attackerBoatsSunkPercentage);
                $attackerBoatsLost += $attackerBoatsSunk;
                $boatsByReturnHourGroup -= $attackerBoatsSunk;
            }

            $this->queueService->queueResources(
                'invasion',
                $dominion,
                ['resource_boats' => $boatsByReturnHourGroup],
                $hours
            );
        }
        if ($attackerBoatsLost > 0) {
            $this->invasionResult['attacker']['boatsLost'] = $attackerBoatsSunk;
        }
    }

    /**
     * Check whether the invasion is successful.
     *
     * @param Dominion $dominion
     * @param Dominion $target
     * @param array $units
     * @return bool
     */
    protected function checkInvasionSuccess(Dominion $dominion, Dominion $target, array $units): void
    {
        $landRatio = $this->rangeCalculator->getDominionRange($dominion, $target) / 100;
        $attackingForceOP = $this->militaryCalculator->getOffensivePower($dominion, $target, $landRatio, $units);
        $targetDP = $this->getDefensivePowerWithTemples($dominion, $target);
        $this->invasionResult['attacker']['op'] = $attackingForceOP;
        $this->invasionResult['defender']['dp'] = $targetDP;
        $this->invasionResult['result']['success'] = ($attackingForceOP > $targetDP);
    }

    /**
     * Check whether the attackers got overwhelmed by the target's defending army.
     *
     * Overwhelmed attackers have increased casualties, while the defending
     * party has reduced casualties.
     *
     */
    protected function checkOverwhelmed(): void
    {
        // Never overwhelm on successful invasions
        $this->invasionResult['result']['overwhelmed'] = false;

        if ($this->invasionResult['result']['success']) {
            return;
        }

        $attackingForceOP = $this->invasionResult['attacker']['op'];
        $targetDP = $this->invasionResult['defender']['dp'];

        $this->invasionResult['result']['overwhelmed'] = ((1 - $attackingForceOP / $targetDP) >= (static::OVERWHELMED_PERCENTAGE / 100));
    }

    protected function getDefensivePowerWithTemples(Dominion $dominion, Dominion $target): float
    {
        $dpMultiplierReduction = $this->militaryCalculator->getTempleReduction($dominion);

        $ignoreDraftees = false;
        if ($this->spellCalculator->isSpellActive($dominion, 'unholy_ghost')) {
            $ignoreDraftees = true;
        }

        return $this->militaryCalculator->getDefensivePower($target, $dominion, null, null, $dpMultiplierReduction, $ignoreDraftees);
    }
}
