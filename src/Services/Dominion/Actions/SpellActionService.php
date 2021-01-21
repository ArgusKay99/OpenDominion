<?php

namespace OpenDominion\Services\Dominion\Actions;

use DB;
use Exception;
use LogicException;
use OpenDominion\Calculators\Dominion\ImprovementCalculator;
use OpenDominion\Calculators\Dominion\LandCalculator;
use OpenDominion\Calculators\Dominion\MilitaryCalculator;
use OpenDominion\Calculators\Dominion\OpsCalculator;
use OpenDominion\Calculators\Dominion\PopulationCalculator;
use OpenDominion\Calculators\Dominion\RangeCalculator;
use OpenDominion\Calculators\Dominion\SpellCalculator;
use OpenDominion\Calculators\NetworthCalculator;
use OpenDominion\Exceptions\GameException;
use OpenDominion\Helpers\SpellHelper;
use OpenDominion\Mappers\Dominion\InfoMapper;
use OpenDominion\Models\Dominion;
use OpenDominion\Models\InfoOp;
use OpenDominion\Services\Dominion\GovernmentService;
use OpenDominion\Services\Dominion\HistoryService;
use OpenDominion\Services\Dominion\ProtectionService;
use OpenDominion\Services\Dominion\QueueService;
use OpenDominion\Services\NotificationService;
use OpenDominion\Traits\DominionGuardsTrait;

class SpellActionService
{
    use DominionGuardsTrait;

    /** @var GovernmentService */
    protected $governmentService;

    /** @var ImprovementCalculator */
    protected $improvementCalculator;

    /** @var InfoMapper */
    protected $infoMapper;

    /** @var LandCalculator */
    protected $landCalculator;

    /** @var MilitaryCalculator */
    protected $militaryCalculator;

    /** @var NetworthCalculator */
    protected $networthCalculator;

    /** @var NotificationService */
    protected $notificationService;

    /** @var OpsCalculator */
    protected $opsCalculator;

    /** @var PopulationCalculator */
    protected $populationCalculator;

    /** @var ProtectionService */
    protected $protectionService;

    /** @var QueueService */
    protected $queueService;

    /** @var RangeCalculator */
    protected $rangeCalculator;

    /** @var SpellCalculator */
    protected $spellCalculator;

    /** @var SpellHelper */
    protected $spellHelper;

    /**
     * SpellActionService constructor.
     */
    public function __construct()
    {
        $this->governmentService = app(GovernmentService::class);
        $this->improvementCalculator = app(ImprovementCalculator::class);
        $this->infoMapper = app(InfoMapper::class);
        $this->landCalculator = app(LandCalculator::class);
        $this->militaryCalculator = app(MilitaryCalculator::class);
        $this->networthCalculator = app(NetworthCalculator::class);
        $this->notificationService = app(NotificationService::class);
        $this->opsCalculator = app(OpsCalculator::class);
        $this->populationCalculator = app(PopulationCalculator::class);
        $this->protectionService = app(ProtectionService::class);
        $this->queueService = app(QueueService::class);
        $this->rangeCalculator = app(RangeCalculator::class);
        $this->spellCalculator = app(SpellCalculator::class);
        $this->spellHelper = app(SpellHelper::class);
    }

    public const BLACK_OPS_HOURS_AFTER_ROUND_START = 24 * 7;

    /**
     * Casts a magic spell for a dominion, optionally aimed at another dominion.
     *
     * @param Dominion $dominion
     * @param string $spellKey
     * @param null|Dominion $target
     * @return array
     * @throws GameException
     * @throws LogicException
     */
    public function castSpell(Dominion $dominion, string $spellKey, ?Dominion $target = null): array
    {
        $this->guardLockedDominion($dominion);
        if ($target !== null) {
            $this->guardLockedDominion($target);
        }

        $spellInfo = $this->spellHelper->getSpellInfo($spellKey);

        if (!$spellInfo) {
            throw new LogicException("Cannot cast unknown spell '{$spellKey}'");
        }

        if ($dominion->wizard_strength < 30) {
            throw new GameException("Your wizards to not have enough strength to cast {$spellInfo['name']}.");
        }

        $manaCost = $this->spellCalculator->getManaCost($dominion, $spellKey);

        if ($dominion->resource_mana < $manaCost) {
            throw new GameException("You do not have enough mana to cast {$spellInfo['name']}.");
        }

        if ($this->spellCalculator->isOnCooldown($dominion, $spellKey)) {
            throw new GameException("You can only cast {$spellInfo['name']} every {$spellInfo['cooldown']} hours.");
        }

        if ($this->spellHelper->isOffensiveSpell($spellKey)) {
            if ($target === null) {
                throw new GameException("You must select a target when casting offensive spell {$spellInfo['name']}");
            }

            if ($this->protectionService->isUnderProtection($dominion)) {
                throw new GameException('You cannot cast offensive spells while under protection');
            }

            if ($this->protectionService->isUnderProtection($target)) {
                throw new GameException('You cannot cast offensive spells to targets which are under protection');
            }

            if (!$this->rangeCalculator->isInRange($dominion, $target) && !in_array($target->id, $this->militaryCalculator->getRecentlyInvadedBy($dominion, 12))) {
                throw new GameException('You cannot cast offensive spells to targets outside of your range');
            }

            if ($dominion->round->id !== $target->round->id) {
                throw new GameException('Nice try, but you cannot cast spells cross-round');
            }

            if ($dominion->realm->id === $target->realm->id) {
                throw new GameException('Nice try, but you cannot cast spells on your realmies');
            }
        }

        $result = null;

        DB::transaction(function () use ($dominion, $manaCost, $spellKey, &$result, $target) {
            if ($this->spellHelper->isSelfSpell($spellKey, $dominion->race)) {
                $result = $this->castSelfSpell($dominion, $spellKey);

            } elseif ($this->spellHelper->isInfoOpSpell($spellKey)) {
                $result = $this->castInfoOpSpell($dominion, $spellKey, $target);

            } elseif ($this->spellHelper->isHostileSpell($spellKey)) {
                $result = $this->castHostileSpell($dominion, $spellKey, $target);

            } else {
                throw new LogicException("Unknown type for spell {$spellKey}");
            }

            $dominion->resource_mana -= $manaCost;
            $dominion->wizard_strength -= ($result['wizardStrengthCost'] ?? 5);

            if (!$this->spellHelper->isSelfSpell($spellKey, $dominion->race)) {
                if ($result['success']) {
                    $dominion->stat_spell_success += 1;
                } else {
                    $dominion->stat_spell_failure += 1;
                }
            }

            if ($target == null) {
                $dominion->save([
                    'event' => HistoryService::EVENT_ACTION_CAST_SPELL,
                    'action' => $spellKey
                ]);
            } else {
                $dominion->save([
                    'event' => HistoryService::EVENT_ACTION_CAST_SPELL,
                    'action' => $spellKey,
                    'target_dominion_id' => $target->id
                ]);

                if ($dominion->fresh()->wizard_strength < 25) {
                    throw new GameException('Your wizards have run out of strength');
                }

                $target->save([
                    'event' => HistoryService::EVENT_ACTION_CAST_SPELL,
                    'action' => $spellKey,
                    'source_dominion_id' => $dominion->id
                ]);
            }
        });

        if ($target !== null) {
            $this->rangeCalculator->checkGuardApplications($dominion, $target);
        }

        return [
                'message' => $result['message'], /* sprintf(
                    $this->getReturnMessageString($dominion), // todo
                    $spellInfo['name'],
                    number_format($manaCost)
                ),*/
                'data' => [
                    'spell' => $spellKey,
                    'manaCost' => $manaCost,
                ],
                'redirect' =>
                    $this->spellHelper->isInfoOpSpell($spellKey) && $result['success']
                        ? $result['redirect']
                        : null,
            ] + $result;
    }

    /**
     * Casts a self spell for $dominion.
     *
     * @param Dominion $dominion
     * @param string $spellKey
     * @return array
     * @throws GameException
     * @throws LogicException
     */
    protected function castSelfSpell(Dominion $dominion, string $spellKey): array
    {
        $spellInfo = $this->spellHelper->getSpellInfo($spellKey);

        $where = [
            'dominion_id' => $dominion->id,
            'spell' => $spellKey,
        ];

        $activeSpell = DB::table('active_spells')
            ->where($where)
            ->first();

        if ($activeSpell !== null) {
            if ((int)$activeSpell->duration === $spellInfo['duration']) {
                throw new GameException("Your wizards refused to recast {$spellInfo['name']}, since it is already at maximum duration.");
            }
            DB::table('active_spells')
                ->where($where)
                ->update([
                    'duration' => $spellInfo['duration'],
                    'updated_at' => now(),
                ]);
        } else {
            DB::table('active_spells')
                ->insert([
                    'dominion_id' => $dominion->id,
                    'spell' => $spellKey,
                    'duration' => $spellInfo['duration'],
                    'cast_by_dominion_id' => $dominion->id,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
        }

        return [
            'success' => true,
            'message' => sprintf(
                'Your wizards cast the spell successfully, and it will continue to affect your dominion for the next %s hours.',
                $spellInfo['duration']
            )
        ];
    }

    /**
     * Casts an info op spell for $dominion to $target.
     *
     * @param Dominion $dominion
     * @param string $spellKey
     * @param Dominion $target
     * @return array
     * @throws GameException
     * @throws Exception
     */
    protected function castInfoOpSpell(Dominion $dominion, string $spellKey, Dominion $target): array
    {
        $spellInfo = $this->spellHelper->getSpellInfo($spellKey);

        if ($dominion->pack !== null && $dominion->pack->size > 2) {
            $wizardStrengthLost = 2;
        } else {
            $wizardStrengthLost = 1.5;
        }

        $selfWpa = $this->militaryCalculator->getWizardRatio($dominion, 'offense');
        $targetWpa = $this->militaryCalculator->getWizardRatio($target, 'defense');

        // You need at least some positive WPA to cast info ops
        if ($selfWpa === 0.0) {
            // Don't reduce mana by throwing an exception here
            throw new GameException("Your wizard force is too weak to cast {$spellInfo['name']}. Please train more wizards.");
        }

        // 100% spell success if target has a WPA of 0
        if ($targetWpa !== 0.0) {
            $successRate = $this->opsCalculator->infoOperationSuccessChance($dominion, $target, 'wizard');

            // Wonders
            $successRate *= (1 - $target->getWonderPerkMultiplier('enemy_spell_chance'));

            if (!random_chance($successRate)) {
                // Inform target that they repelled a hostile spell
                $this->notificationService
                    ->queueNotification('repelled_hostile_spell', [
                        'sourceDominionId' => $dominion->id,
                        'spellKey' => $spellKey,
                        'unitsKilled' => '',
                    ])
                    ->sendNotifications($target, 'irregular_dominion');

                // Return here, thus completing the spell cast and reducing the caster's mana
                return [
                    'success' => false,
                    'message' => "The enemy wizards have repelled our {$spellInfo['name']} attempt.",
                    'wizardStrengthCost' => $wizardStrengthLost,
                    'alert-type' => 'warning',
                ];
            }
        }

        $infoOp = new InfoOp([
            'source_realm_id' => $dominion->realm->id,
            'target_realm_id' => $target->realm->id,
            'type' => $spellKey,
            'source_dominion_id' => $dominion->id,
            'target_dominion_id' => $target->id,
        ]);

        switch ($spellKey) {
            case 'clear_sight':
                $infoOp->data = $this->infoMapper->mapStatus($target);
                break;

            case 'vision':
                $infoOp->data = [
                    'techs' => $this->infoMapper->mapTechs($target),
                    'heroes' => []
                ];
                break;

            case 'revelation':
                $infoOp->data = $this->spellCalculator->getActiveSpells($target);
                break;

            case 'clairvoyance':
                $infoOp->data = [
                    'targetRealmId' => $target->realm->id
                ];
                break;

//            case 'disclosure':
//                $infoOp->data = [];
//                break;

            default:
                throw new LogicException("Unknown info op spell {$spellKey}");
        }

        // Surreal Perception
        if ($this->spellCalculator->isSpellActive($target, 'surreal_perception')) {
            $this->notificationService
                ->queueNotification('received_hostile_spell', [
                    'sourceDominionId' => $dominion->id,
                    'spellKey' => $spellKey,
                ])
                ->sendNotifications($target, 'irregular_dominion');
        }

        $infoOp->save();

        $redirect = route('dominion.op-center.show', $target);
        if ($spellKey === 'clairvoyance') {
            $redirect = route('dominion.op-center.clairvoyance', $target->realm->number);
        }

        return [
            'success' => true,
            'message' => 'Your wizards cast the spell successfully, and a wealth of information appears before you.',
            'wizardStrengthCost' => $wizardStrengthLost,
            'redirect' => $redirect,
        ];
    }

    /**
     * Casts a hostile spell for $dominion to $target.
     *
     * @param Dominion $dominion
     * @param string $spellKey
     * @param Dominion $target
     * @return array
     * @throws GameException
     * @throws LogicException
     */
    protected function castHostileSpell(Dominion $dominion, string $spellKey, Dominion $target): array
    {
        if ($dominion->round->hasOffensiveActionsDisabled()) {
            throw new GameException('Black ops have been disabled for the remainder of the round');
        }

        if (now()->diffInHours($dominion->round->start_date) < self::BLACK_OPS_HOURS_AFTER_ROUND_START) {
            throw new GameException('You cannot perform black ops for the first seven days of the round');
        }

        $spellInfo = $this->spellHelper->getSpellInfo($spellKey);

        if ($this->spellHelper->isWarSpell($spellKey)) {
            $warDeclared = $this->governmentService->isAtWar($dominion->realm, $target->realm);
            if (!$warDeclared && !in_array($target->id, $this->militaryCalculator->getRecentlyInvadedBy($dominion, 12))) {
                throw new GameException("You cannot cast {$spellInfo['name']} outside of war.");
            }
        }

        $selfWpa = $this->militaryCalculator->getWizardRatio($dominion, 'offense');
        $targetWpa = $this->militaryCalculator->getWizardRatio($target, 'defense');

        // You need at least some positive WPA to cast black ops
        if ($selfWpa === 0.0) {
            // Don't reduce mana by throwing an exception here
            throw new GameException("Your wizard force is too weak to cast {$spellInfo['name']}. Please train more wizards.");
        }

        // 100% spell success if target has a WPA of 0
        if ($targetWpa !== 0.0) {
            $successRate = $this->opsCalculator->blackOperationSuccessChance($dominion, $target, 'wizard');

            // Wonders
            $successRate *= (1 - $target->getWonderPerkMultiplier('enemy_spell_chance'));

            if (!random_chance($successRate)) {
                list($unitsKilled, $unitsKilledString) = $this->handleLosses($dominion, $target, 'hostile');

                // Inform target that they repelled a hostile spell
                $this->notificationService
                    ->queueNotification('repelled_hostile_spell', [
                        'sourceDominionId' => $dominion->id,
                        'spellKey' => $spellKey,
                        'unitsKilled' => $unitsKilledString,
                    ])
                    ->sendNotifications($target, 'irregular_dominion');

                if ($unitsKilledString) {
                    $message = sprintf(
                        'The enemy wizards have repelled our %s attempt and managed to kill %s.',
                        $spellInfo['name'],
                        $unitsKilledString
                    );
                } else {
                    $message = sprintf(
                        'The enemy wizards have repelled our %s attempt.',
                        $spellInfo['name']
                    );
                }

                // Return here, thus completing the spell cast and reducing the caster's mana
                return [
                    'success' => false,
                    'message' => $message,
                    'wizardStrengthCost' => 5,
                    'alert-type' => 'warning',
                ];
            }
        }

        $spellReflected = false;
        if ($this->spellCalculator->isSpellActive($target, 'energy_mirror') && random_chance(0.3)) {
            $spellReflected = true;
            $reflectedBy = $target;
            $target = $dominion;
            $dominion = $reflectedBy;
            $dominion->stat_spells_reflected += 1;
        }

        if (isset($spellInfo['duration'])) {
            // Cast spell with duration
            $duration = $spellInfo['duration'];
            if ($target->getTechPerkValue('enemy_spell_duration') !== 0) {
                $duration += $target->getTechPerkValue('enemy_spell_duration');
            }

            if ($this->spellCalculator->isSpellActive($target, $spellKey)) {
                $where = [
                    'dominion_id' => $target->id,
                    'spell' => $spellKey,
                ];

                $activeSpell = DB::table('active_spells')
                    ->where($where)
                    ->first();

                if ($activeSpell === null) {
                    throw new LogicException("Active spell '{$spellKey}' for dominion id {$target->id} not found");
                }

                DB::table('active_spells')
                    ->where($where)
                    ->update([
                        'duration' => $duration,
                        'cast_by_dominion_id' => $dominion->id,
                        'updated_at' => now(),
                    ]);
            } else {
                DB::table('active_spells')
                    ->insert([
                        'dominion_id' => $target->id,
                        'spell' => $spellKey,
                        'duration' => $duration,
                        'cast_by_dominion_id' => $dominion->id,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
            }

            // Update statistics
            if (isset($dominion->{"stat_{$spellInfo['key']}_hours"})) {
                $dominion->{"stat_{$spellInfo['key']}_hours"} += $duration;
            }

            // Surreal Perception
            $sourceDominionId = null;
            if ($this->spellCalculator->isSpellActive($target, 'surreal_perception')) {
                $sourceDominionId = $dominion->id;
            }

            $this->notificationService
                ->queueNotification('received_hostile_spell', [
                    'sourceDominionId' => $sourceDominionId,
                    'spellKey' => $spellKey,
                ])
                ->sendNotifications($target, 'irregular_dominion');

            if ($spellReflected) {
                // Notification for Energy Mirror deflection
                $this->notificationService
                    ->queueNotification('reflected_hostile_spell', [
                        'sourceDominionId' => $target->id,
                        'spellKey' => $spellKey,
                    ])
                    ->sendNotifications($dominion, 'irregular_dominion');

                return [
                    'success' => true,
                    'message' => sprintf(
                        'Your wizards cast the spell successfully, but it was reflected and it will now affect your dominion for the next %s hours.',
                        $duration
                    ),
                    'alert-type' => 'danger'
                ];
            } else {
                return [
                    'success' => true,
                    'message' => sprintf(
                        'Your wizards cast the spell successfully, and it will continue to affect your target for the next %s hours.',
                        $duration
                    )
                ];
            }
        } else {
            // Cast spell instantly
            $damageDealt = [];
            $totalDamage = 0;
            $baseDamage = (isset($spellInfo['percentage']) ? $spellInfo['percentage'] : 1) / 100;
            $baseDamageReductionMultiplier = 1;

            // Resilience
            $baseDamageReductionMultiplier *= (1 - $this->opsCalculator->getResilienceBonus($dominion->wizard_resilience));

            // Towers
            $baseDamageReductionMultiplier *= (1 - $this->improvementCalculator->getImprovementMultiplierBonus($target, 'towers'));

            // Techs
            $baseDamageReductionMultiplier *= (1 + $target->getTechPerkMultiplier("enemy_{$spellInfo['key']}_damage"));

            // Wonders
            $baseDamageReductionMultiplier *= (1 + $target->getWonderPerkMultiplier('enemy_spell_damage'));

            if (isset($spellInfo['decreases'])) {
                foreach ($spellInfo['decreases'] as $attr) {
                    $damage = $target->{$attr} * $baseDamage;
                    $damageReductionMultiplier = $baseDamageReductionMultiplier;

                    // Fireball damage reduction from Forest Havens
                    if ($attr == 'peasants') {
                        $forestHavenFireballReduction = 10;
                        $forestHavenFireballReductionMax = 80;
                        $damageMultiplier = (1 - min(
                            (($target->building_forest_haven / $this->landCalculator->getTotalLand($target)) * $forestHavenFireballReduction),
                            ($forestHavenFireballReductionMax / 100)
                        ));
                        $damageReductionMultiplier *= $damageMultiplier;
                    }

                    // Disband Spies damage reduction from Forest Havens
                    if ($attr == 'military_spies') {
                        $forestHavenSpyCasualtyReduction = 3;
                        $forestHavenSpyCasualtyReductionMax = 30;
                        $damageMultiplier = (1 - min(
                            (($target->building_forest_haven / $this->landCalculator->getTotalLand($target)) * $forestHavenSpyCasualtyReduction),
                            ($forestHavenSpyCasualtyReductionMax / 100)
                        ));
                        $damageReductionMultiplier *= $damageMultiplier;
                    }

                    // Damage reduction from Masonries
                    if (strpos($attr, 'improvement_') === 0) {
                        $masonryLightningBoltReduction = 1;
                        $masonryLightningBoltReductionMax = 25;
                        $damageMultiplier = (1 - min(
                            (($target->building_masonry / $this->landCalculator->getTotalLand($target)) * $masonryLightningBoltReduction),
                            ($masonryLightningBoltReductionMax / 100)
                        ));
                        $damageReductionMultiplier *= $damageMultiplier;
                    }

                    if ($damageReductionMultiplier == 0) {
                        // Damage immunity
                        $damage = 0;
                    } else {
                        // Cap damage reduction at 80%
                        $damage *= max(0.2, $damageReductionMultiplier);
                        $damage = round($damage);
                    }

                    $target->{$attr} -= $damage;
                    $totalDamage += $damage;
                    $damageDealt[] = sprintf('%s %s', number_format($damage), dominion_attr_display($attr, $damage));

                    // Update statistics
                    if (isset($dominion->{"stat_{$spellInfo['key']}_damage"})) {
                        // Only count peasants killed by fireball
                        if (!($spellInfo['key'] == 'fireball' && $attr == 'resource_food')) {
                            $dominion->{"stat_{$spellInfo['key']}_damage"} += $damage;
                        }
                    }
                }

                // Combine lightning bolt damage into single string
                if ($spellInfo['key'] === 'lightning_bolt') {
                    // Combine lightning bold damage into single string
                    $damageDealt = [sprintf('%s %s', number_format($totalDamage), dominion_attr_display('improvement', $totalDamage))];
                }
            }
            if (isset($spellInfo['increases'])) {
                foreach ($spellInfo['increases'] as $attr) {
                    if ($attr == 'military_draftees') {
                        $target->{$attr} += $totalDamage;
                    }
                }
            }

            $warRewardsString = '';
            if ($this->spellHelper->isWarSpell($spellKey) && !$spellReflected && $totalDamage > 0) {
                // Resilience
                $target->wizard_resilience += $this->opsCalculator->getResilienceGain($dominion, 'wizard');

                // Infamy Gains
                $infamyGain = $this->opsCalculator->getInfamyGain($dominion, $target, 'wizard');
                $dominion->infamy += $infamyGain;

                // Mastery Gains
                $masteryGain = $this->opsCalculator->getMasteryGain($dominion, $target, 'wizard');
                $dominion->wizard_mastery += $masteryGain;

                // Mastery Loss
                $masteryLoss = min($this->opsCalculator->getMasteryLoss($dominion, $target, 'wizard'), $target->wizard_mastery);
                $target->wizard_mastery -= $masteryLoss;

                $warRewardsString = "You gained {$infamyGain} infamy and {$masteryGain} wizard mastery.";
                if ($masteryLoss > 0) {
                    $damageDealt[] = "{$masteryLoss} wizard mastery";
                }
            }

            // Surreal Perception
            $sourceDominionId = null;
            if ($this->spellCalculator->isSpellActive($target, 'surreal_perception')) {
                $sourceDominionId = $dominion->id;
            }

            $damageString = generate_sentence_from_array($damageDealt);

            $this->notificationService
                ->queueNotification('received_hostile_spell', [
                    'sourceDominionId' => $sourceDominionId,
                    'spellKey' => $spellKey,
                    'damageString' => $damageString,
                ])
                ->sendNotifications($target, 'irregular_dominion');

            if ($spellReflected) {
                // Notification for Energy Mirror defelection
                $this->notificationService
                    ->queueNotification('reflected_hostile_spell', [
                        'sourceDominionId' => $target->id,
                        'spellKey' => $spellKey,
                    ])
                    ->sendNotifications($dominion, 'irregular_dominion');

                return [
                    'success' => true,
                    'message' => sprintf(
                        'Your wizards cast the spell successfully, but it was reflected and your dominion lost %s.',
                        $damageString
                    ),
                    'wizardStrengthCost' => 5,
                    'alert-type' => 'danger'
                ];
            } else {
                return [
                    'success' => true,
                    'message' => sprintf(
                        'Your wizards cast the spell successfully, your target lost %s. %s',
                        $damageString,
                        $warRewardsString
                    ),
                    'wizardStrengthCost' => 5,
                ];
            }
        }
    }

    /**
     * Returns the successful return message.
     *
     * Little e a s t e r e g g because I was bored.
     *
     * @param Dominion $dominion
     * @return string
     */
    protected function getReturnMessageString(Dominion $dominion): string
    {
        $wizards = $dominion->military_wizards;
        $archmages = $dominion->military_archmages;
        $spies = $dominion->military_spies;

        if (($wizards === 0) && ($archmages === 0)) {
            return 'You cast %s at a cost of %s mana.';
        }

        if ($wizards === 0) {
            if ($archmages > 1) {
                return 'Your archmages successfully cast %s at a cost of %s mana.';
            }

            $thoughts = [
                'mumbles something about being the most powerful sorceress in the dominion is a lonely job, "but somebody\'s got to do it"',
                'mumbles something about the food being quite delicious',
                'feels like a higher spiritual entity is watching her',
                'winks at you',
            ];

            if ($this->queueService->getTrainingQueueTotalByResource($dominion, 'military_wizards') > 0) {
                $thoughts[] = 'carefully observes the trainee wizards';
            } else {
                $thoughts[] = 'mumbles something about the lack of student wizards to teach';
            }

            if ($this->queueService->getTrainingQueueTotalByResource($dominion, 'military_archmages') > 0) {
                $thoughts[] = 'mumbles something about being a bit sad because she probably won\'t be the single most powerful sorceress in the dominion anymore';
                $thoughts[] = 'mumbles something about looking forward to discuss the secrets of arcane knowledge with her future peers';
            } else {
                $thoughts[] = 'mumbles something about not having enough peers to properly conduct her studies';
                $thoughts[] = 'mumbles something about feeling a bit lonely';
            }

            return ('Your archmage successfully casts %s at a cost of %s mana. In addition, she ' . $thoughts[array_rand($thoughts)] . '.');
        }

        if ($archmages === 0) {
            if ($wizards > 1) {
                return 'Your wizards successfully cast %s at a cost of %s mana.';
            }

            $thoughts = [
                'mumbles something about the food being very tasty',
                'has the feeling that an omnipotent being is watching him',
            ];

            if ($this->queueService->getTrainingQueueTotalByResource($dominion, 'military_wizards') > 0) {
                $thoughts[] = 'mumbles something about being delighted by the new wizard trainees so he won\'t be lonely anymore';
            } else {
                $thoughts[] = 'mumbles something about not having enough peers to properly conduct his studies';
                $thoughts[] = 'mumbles something about feeling a bit lonely';
            }

            if ($this->queueService->getTrainingQueueTotalByResource($dominion, 'military_archmages') > 0) {
                $thoughts[] = 'mumbles something about looking forward to his future teacher';
            } else {
                $thoughts[] = 'mumbles something about not having an archmage master to study under';
            }

            if ($spies === 1) {
                $thoughts[] = 'mumbles something about fancying that spy lady';
            } elseif ($spies > 1) {
                $thoughts[] = 'mumbles something about thinking your spies are complotting against him';
            }

            return ('Your wizard successfully casts %s at a cost of %s mana. In addition, he ' . $thoughts[array_rand($thoughts)] . '.');
        }

        if (($wizards === 1) && ($archmages === 1)) {
            $strings = [
                'Your wizards successfully cast %s at a cost of %s mana.',
                'Your wizard and archmage successfully cast %s together in harmony at a cost of %s mana. It was glorious to behold.',
                'Your wizard watches in awe while his teacher archmage blissfully casts %s at a cost of %s mana.',
                'Your archmage facepalms as she observes her wizard student almost failing to cast %s at a cost of %s mana.',
                'Your wizard successfully casts %s at a cost of %s mana, while his teacher archmage watches him with pride.',
            ];

            return $strings[array_rand($strings)];
        }

        if (($wizards === 1) && ($archmages > 1)) {
            $strings = [
                'Your wizards successfully cast %s at a cost of %s mana.',
                'Your wizard was sleeping, so your archmages successfully cast %s at a cost of %s mana.',
                'Your wizard watches carefully while your archmages successfully cast %s at a cost of %s mana.',
            ];

            return $strings[array_rand($strings)];
        }

        if (($wizards > 1) && ($archmages === 1)) {
            $strings = [
                'Your wizards successfully cast %s at a cost of %s mana.',
                'Your archmage found herself lost in her study books, so your wizards successfully cast %s at a cost of %s mana.',
            ];

            return $strings[array_rand($strings)];
        }

        return 'Your wizards successfully cast %s at a cost of %s mana.';
    }

    /**
     * @param Dominion $dominion
     * @param Dominion $target
     * @param string $type
     * @return array
     * @throws Exception
     */
    protected function handleLosses(Dominion $dominion, Dominion $target, string $type): array
    {
        $wizardsKilledPercentage = $this->opsCalculator->getWizardLosses($dominion, $target, $type);

        $unitsKilled = [];
        $wizardsKilled = (int)floor($dominion->military_wizards * $wizardsKilledPercentage);

        // Check for immortal wizards
        if ($dominion->race->getPerkValue('immortal_wizards') != 0) {
            $wizardsKilled = 0;
        }

        if ($wizardsKilled > 0) {
            $unitsKilled['wizards'] = $wizardsKilled;
            $dominion->military_wizards -= $wizardsKilled;
        }

        foreach ($dominion->race->units as $unit) {
            if ($unit->getPerkValue('counts_as_wizard_offense')) {
                $unitKilledMultiplier = ((float)$unit->getPerkValue('counts_as_wizard_offense') / 2) * $wizardsKilledPercentage;
                $unitKilled = (int)floor($dominion->{"military_unit{$unit->slot}"} * $unitKilledMultiplier);
                if ($unitKilled > 0) {
                    $unitsKilled[strtolower($unit->name)] = $unitKilled;
                    $dominion->{"military_unit{$unit->slot}"} -= $unitKilled;
                }
            }
        }

        $target->stat_wizards_executed += array_sum($unitsKilled);
        $dominion->stat_wizards_lost += array_sum($unitsKilled);

        $unitsKilledStringParts = [];
        foreach ($unitsKilled as $name => $amount) {
            $amountLabel = number_format($amount);
            $unitLabel = str_plural(str_singular($name), $amount);
            $unitsKilledStringParts[] = "{$amountLabel} {$unitLabel}";
        }
        $unitsKilledString = generate_sentence_from_array($unitsKilledStringParts);

        return [$unitsKilled, $unitsKilledString];
    }
}
