<?php

namespace OpenDominion\Services\Dominion\Actions;

use DB;
use Exception;
use Illuminate\Support\Str;
use LogicException;
use OpenDominion\Calculators\Dominion\HeroCalculator;
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
use OpenDominion\Models\DominionSpell;
use OpenDominion\Models\InfoOp;
use OpenDominion\Models\Spell;
use OpenDominion\Services\Dominion\GovernmentService;
use OpenDominion\Services\Dominion\GuardMembershipService;
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

    /** @var GuardMembershipService */
    protected $guardMembershipService;

    /** @var HeroCalculator */
    protected $heroCalculator;

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
        $this->guardMembershipService = app(GuardMembershipService::class);
        $this->heroCalculator = app(HeroCalculator::class);
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

    public const BLACK_OPS_HOURS_AFTER_ROUND_START = 24 * 3;

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
        $this->guardActionsDuringTick($dominion);

        $spell = $this->spellHelper->getSpells($dominion->race)->get($spellKey);

        if ($spell == null) {
            throw new LogicException("Cannot cast unknown spell '{$spellKey}'");
        }

        if ($dominion->wizard_strength < 30) {
            throw new GameException("Your wizards to not have enough strength to cast {$spell->name}");
        }

        $manaCost = $this->spellCalculator->getManaCost($dominion, $spell);

        if ($dominion->resource_mana < $manaCost) {
            throw new GameException("You do not have enough mana to cast {$spell->name}");
        }

        if ($this->spellCalculator->isOnCooldown($dominion, $spell)) {
            throw new GameException("You can only cast {$spell->name} every {$spell->cooldown} hours");
        }

        if ($this->protectionService->isUnderProtection($dominion) && $spell->hasPerk('invalid_protection')) {
            throw new GameException('You cannot cast this spell while under protection');
        }

        if ($this->spellHelper->isOffensiveSpell($spell)) {
            if ($target === null) {
                throw new GameException("You must select a target when casting offensive spell {$spell->name}");
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

        DB::transaction(function () use ($dominion, $manaCost, $spell, &$result, $target) {
            $xpGain = 0;
            $wizardStrengthLost = $spell->cost_strength;

            if ($this->spellHelper->isSelfSpell($spell)) {
                $result = $this->castSelfSpell($dominion, $spell);
            } elseif ($this->spellHelper->isInfoOpSpell($spell)) {
                $xpGain = 2;
                $result = $this->castInfoOpSpell($dominion, $spell, $target);
                if ($this->guardMembershipService->isBlackGuardMember($dominion)) {
                    $xpGain = 1;
                    $wizardStrengthLost = 1;
                }
            } elseif ($this->spellHelper->isHostileSpell($spell)) {
                if ($this->spellHelper->isWarSpell($spell)) {
                    $xpGain = 6;
                } else {
                    $xpGain = 4;
                }
                $result = $this->castHostileSpell($dominion, $spell, $target);
                if (isset($result['damage']) && $result['damage'] == 0) {
                    $xpGain = 0;
                }
                $dominion->resetAbandonment();
            } else {
                throw new LogicException("Unknown type for spell {$spell->key}");
            }

            // No XP for bots
            if ($target && $target->user_id == null) {
                $xpGain = 0;
            }

            // Amplify Magic
            if ($this->spellCalculator->isSpellActive($dominion, 'amplify_magic')) {
                if ($this->spellHelper->isSelfSpell($spell) && !$spell->cooldown) {
                    $activeSpell = $dominion->spells->where('key', 'amplify_magic')->first();
                    if ($activeSpell) {
                        $activeSpell->pivot->delete();
                    }
                }
            }

            $dominion->resource_mana -= $manaCost;
            $dominion->wizard_strength -= $wizardStrengthLost;

            if (!$this->spellHelper->isSelfSpell($spell)) {
                if ($result['success']) {
                    $dominion->stat_spell_success += 1;
                    // Hero Experience
                    if ($dominion->hero && $xpGain) {
                        $xpGain = $this->heroCalculator->getExperienceGain($dominion, $xpGain);
                        $dominion->hero->experience += $xpGain;
                        $dominion->hero->save();
                        $result['message'] .=  vsprintf(' You gain %.3g XP.', $xpGain);
                    }
                } else {
                    $dominion->stat_spell_failure += 1;
                }
            }

            if ($target == null) {
                $dominion->save([
                    'event' => HistoryService::EVENT_ACTION_CAST_SPELL,
                    'action' => $spell->key,
                    'queue' => ['active_spells' => [$spell->key => $result['duration']]]
                ]);
            } else {
                $dominion->save([
                    'event' => HistoryService::EVENT_ACTION_CAST_SPELL,
                    'action' => $spell->key,
                    'target_dominion_id' => $target->id
                ]);

                if ($dominion->fresh()->wizard_strength < 25) {
                    throw new GameException('Your wizards have run out of strength');
                }

                $target->save([
                    'event' => HistoryService::EVENT_ACTION_RECEIVE_SPELL,
                    'action' => $spell->key,
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
                    $spell->name,
                    number_format($manaCost)
                ),*/
                'data' => [
                    'spell' => $spell->key,
                    'manaCost' => $manaCost,
                ],
                'redirect' =>
                    $this->spellHelper->isInfoOpSpell($spell) && $result['success']
                        ? $result['redirect']
                        : null,
            ] + $result;
    }

    /**
     * Casts a self spell for $dominion.
     *
     * @param Dominion $dominion
     * @param Spell $spell
     * @return array
     * @throws GameException
     * @throws LogicException
     */
    protected function castSelfSpell(Dominion $dominion, Spell $spell): array
    {
        $duration = $this->spellCalculator->getSpellDuration($dominion, $spell);

        // Wonders
        $duration += $dominion->getWonderPerkValue('spell_duration');

        $where = [
            'dominion_id' => $dominion->id,
            'spell_id' => $spell->id,
        ];
        $activeSpell = DominionSpell::firstWhere($where);
        $activeSpellDuration = $duration;

        if ($activeSpell !== null) {
            if ((int)$activeSpell->duration >= $duration || $spell->key == 'amplify_magic') {
                throw new GameException("Your wizards refused to recast {$spell->name}, since it is already at maximum duration.");
            }
            $activeSpellDuration = $activeSpell->duration;
            $activeSpell->where($where)->update(['duration' => $duration]);
        } else {
            DominionSpell::insert([
                'dominion_id' => $dominion->id,
                'spell_id' => $spell->id,
                'duration' => $duration,
                'cast_by_dominion_id' => $dominion->id,
            ]);
        }

        return [
            'success' => true,
            'duration' => $activeSpellDuration,
            'message' => sprintf(
                'Your wizards cast the spell successfully, and it will continue to affect your dominion for the next %s hours.',
                $duration
            )
        ];
    }

    /**
     * Casts an info op spell for $dominion to $target.
     *
     * @param Dominion $dominion
     * @param Spell $spell
     * @param Dominion $target
     * @return array
     * @throws GameException
     * @throws Exception
     */
    protected function castInfoOpSpell(Dominion $dominion, Spell $spell, Dominion $target): array
    {
        $selfWpa = $this->militaryCalculator->getWizardRatio($dominion, 'offense');
        $targetWpa = $this->militaryCalculator->getWizardRatio($target, 'defense');

        // You need at least some positive WPA to cast info ops
        if ($selfWpa == 0) {
            // Don't reduce mana by throwing an exception here
            throw new GameException("Your wizard force is too weak to cast {$spell->name}. Please train more wizards.");
        }

        $successRate = $this->opsCalculator->infoOperationSuccessChance($selfWpa, $targetWpa);

        // Wonders
        $successRate *= (1 - $target->getWonderPerkMultiplier('enemy_spell_chance'));

        if (!random_chance($successRate)) {
            // Inform target that they repelled a hostile spell
            $this->notificationService
                ->queueNotification('repelled_hostile_spell', [
                    'sourceDominionId' => $dominion->id,
                    'spellKey' => $spell->key,
                    'spellName' => $spell->name,
                    'unitsKilled' => '',
                ])
                ->sendNotifications($target, 'irregular_dominion');

            // Return here, thus completing the spell cast and reducing the caster's mana
            return [
                'success' => false,
                'message' => sprintf(
                    'The enemy wizards have repelled our %s attempt.',
                    $spell->name
                ),
                'alert-type' => 'warning',
            ];
        }

        $infoOp = new InfoOp([
            'source_realm_id' => $dominion->realm->id,
            'target_realm_id' => $target->realm->id,
            'type' => $spell->key,
            'source_dominion_id' => $dominion->id,
            'target_dominion_id' => $target->id,
        ]);

        switch ($spell->key) {
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
                $infoOp->data = $this->infoMapper->mapSpells($target);
                break;

            case 'disclosure':
                $infoOp->data = $this->infoMapper->mapHeroes($target);
                break;

            case 'clairvoyance':
                $infoOp->data = [
                    'targetRealmId' => $target->realm->id
                ];
                break;

            default:
                throw new LogicException("Unknown info op spell {$spell->key}");
        }

        // Surreal Perception
        if ($target->getSpellPerkValue('surreal_perception') || $target->getWonderPerkValue('surreal_perception')) {
            $this->notificationService
                ->queueNotification('received_hostile_spell', [
                    'sourceDominionId' => $dominion->id,
                    'spellKey' => $spell->key,
                    'spellName' => $spell->name,
                ])
                ->sendNotifications($target, 'irregular_dominion');
        }

        $infoOp->save();

        $redirect = route('dominion.op-center.show', $target);
        if ($spell->key === 'clairvoyance') {
            $redirect = route('dominion.op-center.clairvoyance', $target->realm->number);
        }

        return [
            'success' => true,
            'message' => 'Your wizards cast the spell successfully, and a wealth of information appears before you.',
            'redirect' => $redirect,
        ];
    }

    /**
     * Casts a hostile spell for $dominion to $target.
     *
     * @param Dominion $dominion
     * @param Spell $spell
     * @param Dominion $target
     * @return array
     * @throws GameException
     * @throws LogicException
     */
    protected function castHostileSpell(Dominion $dominion, Spell $spell, Dominion $target): array
    {
        if ($dominion->round->hasOffensiveActionsDisabled()) {
            throw new GameException('Black ops have been disabled for the remainder of the round');
        }

        if (now()->diffInHours($dominion->round->start_date) < self::BLACK_OPS_HOURS_AFTER_ROUND_START) {
            throw new GameException('You cannot perform black ops for the first three days of the round');
        }

        if ($target->user_id == null) {
            throw new GameException('You cannot perform black ops on bots');
        }

        $warDeclared = $this->governmentService->isAtWar($dominion->realm, $target->realm);
        $mutualWarDeclared = $this->governmentService->isAtMutualWar($dominion->realm, $target->realm);
        $blackGuard = $this->guardMembershipService->isBlackGuardMember($dominion) && $this->guardMembershipService->isBlackGuardMember($target);
        if ($this->spellHelper->isWarSpell($spell)) {
            $recentlyInvaded = in_array($target->id, $this->militaryCalculator->getRecentlyInvadedBy($dominion, 12));
            if (!$warDeclared && !$recentlyInvaded) {
                if ($blackGuard) {
                    $this->guardMembershipService->checkLeaveApplication($dominion);
                } else {
                    throw new GameException("You cannot cast {$spell->name} outside of war.");
                }
            }
        }

        $selfWpa = $this->militaryCalculator->getWizardRatio($dominion, 'offense');
        $targetWpa = $this->militaryCalculator->getWizardRatio($target, 'defense');

        // You need at least some positive WPA to cast black ops
        if ($selfWpa == 0) {
            // Don't reduce mana by throwing an exception here
            throw new GameException("Your wizard force is too weak to cast {$spell->name}. Please train more wizards.");
        }

        $successRate = $this->opsCalculator->blackOperationSuccessChance($selfWpa, $targetWpa);

        // Wonders
        $successRate *= (1 - $target->getWonderPerkMultiplier('enemy_spell_chance'));

        if (!random_chance($successRate)) {
            list($unitsKilled, $unitsKilledString) = $this->handleLosses($dominion, $target, 'hostile');

            // Inform target that they repelled a hostile spell
            $this->notificationService
                ->queueNotification('repelled_hostile_spell', [
                    'sourceDominionId' => $dominion->id,
                    'spellKey' => $spell->key,
                    'spellName' => $spell->name,
                    'unitsKilled' => $unitsKilledString,
                ])
                ->sendNotifications($target, 'irregular_dominion');

            if ($unitsKilledString) {
                $message = sprintf(
                    'The enemy wizards have repelled our %s attempt and managed to kill %s.',
                    $spell->name,
                    $unitsKilledString
                );
            } else {
                $message = sprintf(
                    'The enemy wizards have repelled our %s attempt.',
                    $spell->name
                );
            }

            return [
                'success' => false,
                'message' => $message,
                'alert-type' => 'warning',
            ];
        }

        $spellReflected = false;
        if ($target->getSpellPerkValue('energy_mirror') && random_chance(0.2)) {
            $spellReflected = true;
            $reflectedBy = $target;
            $target = $dominion;
            $dominion = $reflectedBy;
            $dominion->stat_spells_reflected += 1;
            $target->stat_spells_deflected += 1;
        }

        if ($spell->duration > 0) {
            // Cast spell with duration (increased during war)
            $duration = $spell->duration;
            if ($mutualWarDeclared) {
                $duration *= 2;
            } elseif ($warDeclared || $blackGuard) {
                $duration *= 1.5;
            }

            if ($target->getTechPerkValue('enemy_spell_duration') !== 0) {
                $duration += $target->getTechPerkValue('enemy_spell_duration');
            }

            $activeSpell = $target->spells->find($spell->id);

            if ($activeSpell !== null) {
                $durationAdded = max(0, $duration - $activeSpell->pivot->duration);
                $activeSpell->pivot->duration += $durationAdded;
                $activeSpell->pivot->cast_by_dominion_id = $dominion->id;
                $activeSpell->pivot->save();
            } else {
                $durationAdded = $duration;
                DominionSpell::insert([
                    'dominion_id' => $target->id,
                    'spell_id' => $spell->id,
                    'duration' => $duration,
                    'cast_by_dominion_id' => $dominion->id,
                ]);
            }

            // Update statistics
            if (isset($dominion->{"stat_{$spell->key}_hours"})) {
                $dominion->{"stat_{$spell->key}_hours"} += $durationAdded;
                $target->{"stat_{$spell->key}_hours_received"} += $durationAdded;
            }

            $damageDealtString = '';
            $warRewardsString = '';
            if (!$spellReflected && $durationAdded > 0 && (
                $this->spellHelper->isWarSpell($spell) ||
                ($this->spellHelper->isBlackOpSpell($spell) && ($warDeclared || $blackGuard))
            )) {
                $modifier = min(1, $durationAdded / 9);
                $results = $this->handleWarResults($dominion, $target, $spell->key, $durationAdded / 9);
                $warRewardsString = $results['warRewards'];
                if ($results['damageDealt'] !== '') {
                    $damageDealtString = "Your target lost {$results['damageDealt']}.";
                }
            }

            // Surreal Perception
            $sourceDominionId = null;
            if ($target->getSpellPerkValue('surreal_perception') || $target->getWonderPerkValue('surreal_perception')) {
                $sourceDominionId = $dominion->id;
            }

            $this->notificationService
                ->queueNotification('received_hostile_spell', [
                    'sourceDominionId' => $sourceDominionId,
                    'spellKey' => $spell->key,
                    'spellName' => $spell->name,
                ])
                ->sendNotifications($target, 'irregular_dominion');

            if ($spellReflected) {
                // Notification for Energy Mirror deflection
                $this->notificationService
                    ->queueNotification('reflected_hostile_spell', [
                        'sourceDominionId' => $target->id,
                        'spellKey' => $spell->key,
                        'spellName' => $spell->name,
                    ])
                    ->sendNotifications($dominion, 'irregular_dominion');

                return [
                    'success' => true,
                    'message' => sprintf(
                        'Your wizards cast the spell successfully, but it was reflected and it will now affect your dominion for an additional %s hours.',
                        $durationAdded
                    ),
                    'alert-type' => 'danger'
                ];
            } else {
                return [
                    'success' => true,
                    'message' => sprintf(
                        'Your wizards cast the spell successfully, and it will continue to affect your target for an additional %s hours. %s %s',
                        $durationAdded,
                        $damageDealtString,
                        $warRewardsString
                    ),
                    'damage' => $durationAdded
                ];
            }
        } else {
            // Cast spell instantly
            $damageDealt = [];
            $totalDamage = 0;
            $baseDamageReductionMultiplier = $this->opsCalculator->getDamageReduction($target, 'wizard');

            // Towers
            $baseDamageReductionMultiplier += $this->improvementCalculator->getImprovementMultiplierBonus($target, 'towers');

            // Techs
            $baseDamageReductionMultiplier -= $target->getTechPerkMultiplier("enemy_{$spell->key}_damage");

            // Wonders
            $baseDamageReductionMultiplier -= $target->getWonderPerkMultiplier('enemy_spell_damage');

            foreach ($spell->perks as $perk) {
                if (Str::startsWith($perk->key, 'destroy_')) {
                    $attr = str_replace('destroy_', '', $perk->key);
                    $convertAttr = null;
                } elseif (Str::startsWith($perk->key, 'convert_')) {
                    $components = Str::of($perk->key)->replace('convert_', '')->explode('_to_');
                    list($attr, $convertAttr) = $components;
                } elseif ($perk->key == 'scale_by_day') {
                    continue;
                } else {
                    throw new GameException("Unrecognized perk {$perk->key}.");
                }
                $baseDamage = $perk->pivot->value / 100;
                if ($spell->getPerkValue('scale_by_day') == 1) {
                    $baseDamage *= (1.625 - 0.025 * clamp($dominion->round->daysInRound(), 10, 40));
                }
                $damageReductionMultiplier = $baseDamageReductionMultiplier;

                // Fireball damage reduction from Forest Havens
                if ($attr == 'peasants') {
                    $forestHavenFireballReduction = 10;
                    $forestHavenFireballReductionMax = 80;
                    $damageMultiplier = min(
                        (($target->building_forest_haven / $this->landCalculator->getTotalLand($target)) * $forestHavenFireballReduction),
                        ($forestHavenFireballReductionMax / 100)
                    );
                    $damageReductionMultiplier += $damageMultiplier;
                }

                // Disband Spies damage reduction from Forest Havens
                if ($attr == 'military_spies') {
                    $forestHavenDisbandSpyReduction = 10;
                    $forestHavenDisbandSpyReductionMax = 50;
                    $damageMultiplier = min(
                        (($target->building_forest_haven / $this->landCalculator->getTotalLand($target)) * $forestHavenDisbandSpyReduction),
                        ($forestHavenDisbandSpyReductionMax / 100)
                    );
                    $damageReductionMultiplier += $damageMultiplier;
                }

                // Cap damage reduction at 80%
                $damage = ceil(
                    $target->{$attr} *
                    $baseDamage *
                    (1 - min(0.8, $damageReductionMultiplier))
                );

                // Immortal Wizards
                if ($attr == 'military_wizards' && $target->race->getPerkValue('immortal_wizards') != 0) {
                    $damage = 0;
                }

                $target->{$attr} -= $damage;
                if ($convertAttr !== null) {
                    if (Str::startsWith($convertAttr, 'self_') && !$spellReflected) {
                        $convertAttr = str_replace('self_', '', $convertAttr);
                        $converted = $damage;
                        if (Str::startsWith($convertAttr, 'military_')) {
                            // Military Conversions
                            $converted = round($damage * 0.05);
                        }
                        $this->queueService->queueResources(
                            'invasion',
                            $dominion,
                            [$convertAttr => $converted],
                            12
                        );
                    } else {
                        $target->{$convertAttr} += $damage;
                    }
                }

                $totalDamage += $damage;
                $damageDealt[] = sprintf('%s %s', number_format($damage), dominion_attr_display($attr, $damage));

                // Update statistics
                if (isset($dominion->{"stat_{$spell->key}_damage"})) {
                    // Only count peasants killed by fireball
                    if (!($spell->key == 'fireball' && $attr == 'resource_food')) {
                        $dominion->{"stat_{$spell->key}_damage"} += $damage;
                        $target->{"stat_{$spell->key}_damage_received"} += $damage;
                    }
                }
            }

            // Combine lightning bolt damage into single string
            if ($spell->key === 'lightning_bolt') {
                // Combine lightning bold damage into single string
                $damageDealt = [sprintf('%s %s', number_format($totalDamage), dominion_attr_display('improvement', $totalDamage))];
            }

            $warRewardsString = '';
            if (!$spellReflected && $totalDamage > 0 && (
                $this->spellHelper->isWarSpell($spell) ||
                ($this->spellHelper->isBlackOpSpell($spell) && ($warDeclared || $blackGuard))
            )) {
                $results = $this->handleWarResults($dominion, $target, $spell->key);
                $warRewardsString = $results['warRewards'];
                if ($results['damageDealt'] !== '') {
                    $damageDealt[] = $results['damageDealt'];
                }
            }

            // Surreal Perception
            $sourceDominionId = null;
            if ($target->getSpellPerkValue('surreal_perception') || $target->getWonderPerkValue('surreal_perception')) {
                $sourceDominionId = $dominion->id;
            }

            $damageString = generate_sentence_from_array($damageDealt);

            $this->notificationService
                ->queueNotification('received_hostile_spell', [
                    'sourceDominionId' => $sourceDominionId,
                    'spellKey' => $spell->key,
                    'spellName' => $spell->name,
                    'damageString' => $damageString,
                ])
                ->sendNotifications($target, 'irregular_dominion');

            if ($spellReflected) {
                // Notification for Energy Mirror defelection
                $this->notificationService
                    ->queueNotification('reflected_hostile_spell', [
                        'sourceDominionId' => $target->id,
                        'spellKey' => $spell->key,
                        'spellName' => $spell->name,
                    ])
                    ->sendNotifications($dominion, 'irregular_dominion');

                return [
                    'success' => true,
                    'message' => sprintf(
                        'Your wizards cast the spell successfully, but it was reflected and your dominion lost %s.',
                        $damageString
                    ),
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
                    'damage' => $totalDamage
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
        $archmagesKilledPercentage = $this->opsCalculator->getArchmageLosses($dominion, $target, $type);
        // Cap losses by land size
        $totalLand = $this->landCalculator->getTotalLand($dominion);
        $wizardsKilledCap = $totalLand * 0.02;
        $archmagesKilledCap = $totalLand * 0.002;
        $unitsKilledCap = $totalLand * 0.01;

        $wizardsKilledModifier = 1;
        // Losses re-queued in Black Guard
        $blackGuard = $this->guardMembershipService->isBlackGuardMember($dominion) && $this->guardMembershipService->isBlackGuardMember($target);

        $unitsKilled = [];
        $wizardsKilled = (int)floor($dominion->military_wizards * $wizardsKilledPercentage);
        $wizardsKilled = round(min($wizardsKilled, $wizardsKilledCap) * $wizardsKilledModifier);
        $archmagesKilled = (int)floor($dominion->military_archmages * $archmagesKilledPercentage);
        $archmagesKilled = round(min($archmagesKilled, $archmagesKilledCap) * $wizardsKilledModifier);

        // Check for immortal wizards
        if ($dominion->race->getPerkValue('immortal_wizards') != 0) {
            $wizardsKilled = 0;
            $archmagesKilled = 0;
        }

        if ($wizardsKilled > 0) {
            $unitsKilled['wizards'] = $wizardsKilled;
            $dominion->military_wizards -= $wizardsKilled;
            if ($blackGuard && $wizardsKilled > 1) {
                $this->queueService->queueResources('training', $dominion, ['military_wizards' => floor(0.75 * $wizardsKilled)]);
            }
        }

        if ($archmagesKilled > 0) {
            $unitsKilled['archmages'] = $archmagesKilled;
            $dominion->military_archmages -= $archmagesKilled;
            if ($blackGuard && $archmagesKilled > 1) {
                $this->queueService->queueResources('training', $dominion, ['military_archmages' => floor(0.75 * $archmagesKilled)]);
            }
        }

        foreach ($dominion->race->units as $unit) {
            if ($unit->getPerkValue('counts_as_wizard_offense')) {
                $unitKilledMultiplier = ((float)$unit->getPerkValue('counts_as_wizard_offense') / 2) * $wizardsKilledPercentage;
                $unitKilled = (int)floor($dominion->{"military_unit{$unit->slot}"} * $unitKilledMultiplier);
                $unitKilled = round(min($unitKilled, $unitsKilledCap) * $wizardsKilledModifier);
                if ($unitKilled > 0) {
                    $unitsKilled[strtolower($unit->name)] = $unitKilled;
                    $dominion->{"military_unit{$unit->slot}"} -= $unitKilled;
                    if ($blackGuard && $unitKilled > 1) {
                        $this->queueService->queueResources('training', $dominion, ["military_unit{$unit->slot}" => floor(0.75 * $unitKilled)]);
                    }
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

    /**
     * @param Dominion $dominion
     * @param Dominion $target
     * @param string $spellKey
     * @param float $modifier
     * @return array
     * @throws Exception
     */
    protected function handleWarResults(Dominion $dominion, Dominion $target, string $spellKey, float $modifier = 1): array
    {
        $damageDealtString = '';
        $warRewardsString = '';

        // Infamy and Resilience Gains
        $infamyGain = $this->opsCalculator->getInfamyGain($dominion, $target, 'wizard', $modifier);
        if ($spellKey == 'fireball') {
            $resilienceGain = $this->opsCalculator->getResilienceGain($target, 'wizard');
        } else {
            $resilienceGain = 0;
        }

        // Mutual War
        $mutualWarDeclared = $this->governmentService->isAtMutualWar($dominion->realm, $target->realm);
        if ($mutualWarDeclared) {
            $infamyGain = round(1.2 * $infamyGain);
            $resilienceGain = round(0.5 * $resilienceGain);
        }

        if ($dominion->infamy + $infamyGain > 1000) {
            $infamyGain = max(0, 1000 - $dominion->infamy);
        }
        $dominion->infamy += $infamyGain;
        $target->wizard_resilience += $resilienceGain;

        // Mastery Gains
        $masteryGain = $this->opsCalculator->getMasteryGain($dominion, $target, 'wizard', $modifier);
        $dominion->wizard_mastery += $masteryGain;

        // Mastery Loss
        $masteryLoss = min($this->opsCalculator->getMasteryLoss($dominion, $target, 'wizard'), $target->wizard_mastery);
        $target->wizard_mastery -= $masteryLoss;

        $warRewardsString = "You gained {$infamyGain} infamy and {$masteryGain} wizard mastery.";
        if ($masteryLoss > 0) {
            $damageDealtString = "{$masteryLoss} wizard mastery";
        }

        return [
            'damageDealt' => $damageDealtString,
            'warRewards' => $warRewardsString,
        ];
    }
}
