<?php

namespace OpenDominion\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Notifications\Notifiable;
use OpenDominion\Services\Dominion\HistoryService;
use OpenDominion\Services\Dominion\SelectorService;

class Dominion extends AbstractModel
{
    use Notifiable;

    protected $casts = [
        'prestige' => 'integer',
        'peasants' => 'integer',
        'peasants_last_hour' => 'integer',
        'draft_rate' => 'integer',
        'morale' => 'integer',
        'spy_strength' => 'float',
        'wizard_strength' => 'float',
        'resource_platinum' => 'integer',
        'resource_food' => 'integer',
        'resource_lumber' => 'integer',
        'resource_mana' => 'integer',
        'resource_ore' => 'integer',
        'resource_gems' => 'integer',
        'resource_tech' => 'integer',
        'resource_boats' => 'float',
        'improvement_science' => 'integer',
        'improvement_keep' => 'integer',
        'improvement_towers' => 'integer',
        'improvement_forges' => 'integer',
        'improvement_walls' => 'integer',
        'improvement_harbor' => 'integer',
        'military_draftees' => 'integer',
        'military_unit1' => 'integer',
        'military_unit2' => 'integer',
        'military_unit3' => 'integer',
        'military_unit4' => 'integer',
        'military_spies' => 'integer',
        'military_wizards' => 'integer',
        'military_archmages' => 'integer',
        'land_plain' => 'integer',
        'land_mountain' => 'integer',
        'land_swamp' => 'integer',
        'land_cavern' => 'integer',
        'land_forest' => 'integer',
        'land_hill' => 'integer',
        'land_water' => 'integer',
        'building_home' => 'integer',
        'building_alchemy' => 'integer',
        'building_farm' => 'integer',
        'building_smithy' => 'integer',
        'building_masonry' => 'integer',
        'building_ore_mine' => 'integer',
        'building_gryphon_nest' => 'integer',
        'building_tower' => 'integer',
        'building_wizard_guild' => 'integer',
        'building_temple' => 'integer',
        'building_diamond_mine' => 'integer',
        'building_school' => 'integer',
        'building_lumberyard' => 'integer',
        'building_forest_haven' => 'integer',
        'building_factory' => 'integer',
        'building_guard_tower' => 'integer',
        'building_shrine' => 'integer',
        'building_barracks' => 'integer',
        'building_dock' => 'integer',
        'daily_platinum' => 'boolean',
        'daily_land' => 'boolean',
    ];

    // Relations

    public function councilThreads()
    {
        return $this->hasMany(Council\Thread::class);
    }

    // todo: info op target/source?

    public function gameEventsSource()
    {
        return $this->morphMany(GameEvent::class, 'source');
    }

    public function gameEventsTarget()
    {
        return $this->morphMany(GameEvent::class, 'target');
    }

    public function history()
    {
        return $this->hasMany(Dominion\History::class);
    }

    public function pack()
    {
        return $this->belongsTo(Pack::class);
    }

    public function race()
    {
        return $this->belongsTo(Race::class);
    }

    public function realm()
    {
        return $this->belongsTo(Realm::class);
    }

    public function round()
    {
        return $this->belongsTo(Round::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    // Eloquent Query Scopes

    public function scopeActive(Builder $query)
    {
        return $query->whereHas('round', function (Builder $query) {
            $query->where('start_date', '<=', now())
                ->where('end_date', '>', now());
        });
    }

    // Methods

    // todo: move to eloquent events, see $dispatchesEvents
    public function save(array $options = [])
    {
        $recordChanges = isset($options['event']);

        if ($recordChanges) {
            $dominionHistoryService = app(HistoryService::class);
            $deltaAttributes = $dominionHistoryService->getDeltaAttributes($this);
        }

        $saved = parent::save($options);

        if ($saved && $recordChanges) {
            /** @noinspection PhpUndefinedVariableInspection */
            $dominionHistoryService->record($this, $deltaAttributes, $options['event']);
        }

        return $saved;
    }

    /**
     * Route notifications for the mail channel.
     *
     * @return string
     */
    public function routeNotificationForMail(): string
    {
        // todo: test this
        return $this->user->email;
    }

    /**
     * Returns whether this Dominion instance is selected by the logged in user.
     *
     * @return bool
     */
    public function isSelectedByAuthUser()
    {
        // todo: move to SelectorService
        $dominionSelectorService = app(SelectorService::class);

        $selectedDominion = $dominionSelectorService->getUserSelectedDominion();

        if ($selectedDominion === null) {
            return false;
        }

        return ($this->id === $selectedDominion->id);
    }

    /**
     * Returns whether this Dominion is locked due to the round having ended.
     *
     * Locked Dominions cannot perform actions and are read-only.
     *
     * @return bool
     */
    public function isLocked()
    {
        return (now() >= $this->round->end_date);
    }

    /**
     * Returns the unit production bonus for a specific resource type (across all eligible units) for this dominion.
     *
     * @param string $resourceType
     * @return float
     */
    public function getUnitPerkProductionBonus(string $resourceType): float
    {
        $bonus = 0;

        foreach ($this->race->units as $unit) {
            if ($unit->perkType === null) {
                continue;
            }

            if ($unit->perkType->key === $resourceType) {
                $bonus += ($this->{'military_unit' . $unit->slot} * (float)$unit->unit_perk_type_values);
            }
        }

        return $bonus;
    }
}
