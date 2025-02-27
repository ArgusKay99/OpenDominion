<?php

namespace OpenDominion\Factories;

use Carbon\Carbon;
use OpenDominion\Models\Realm;
use OpenDominion\Models\Round;
use OpenDominion\Models\RoundLeague;
use OpenDominion\Services\WonderService;

class RoundFactory
{
    // todo: move to config somewhere?
    private const ROUND_DURATION_IN_DAYS = 47;

    /**
     * Creates and returns a new Round in a RoundLeague.
     *
     * @param RoundLeague $league
     * @param Carbon $startDate
     * @param int $realmSize
     * @param int $packSize
     * @param int $playersPerRace
     * @param bool $mixedAlignment
     * @return Round
     */
    public function create(
        RoundLeague $league,
        Carbon $startDate,
        int $realmSize,
        int $packSize,
        int $playersPerRace,
        bool $mixedAlignment
    ): Round {
        $number = ($this->getLastRoundNumber($league) + 1);
        $endDate = (clone $startDate)->addDays(static::ROUND_DURATION_IN_DAYS);

        $invasionEndHours = [
            9,
            10,
            11,
            12,
            13,
            13,
            14,
            14,
            15,
            15,
            16,
            16,
            17,
            17,
        ];

        $hoursBeforeRoundEnd = array_random($invasionEndHours);
        $secondsBeforeRoundEnd = rand(0, 3599);

        $offensiveActionsEndDate =
            (clone $endDate)->addHours(-$hoursBeforeRoundEnd)->addSeconds(-$secondsBeforeRoundEnd);

        $round = Round::create([
            'round_league_id' => $league->id,
            'number' => $number,
            'name' => "Round {$number}",
            'start_date' => $startDate,
            'end_date' => $endDate,
            'offensive_actions_prohibited_at' => $offensiveActionsEndDate,
            'realm_size' => $realmSize,
            'pack_size' => $packSize,
            'players_per_race' => $playersPerRace,
            'mixed_alignment' => $mixedAlignment
        ]);

        // Create special realm for realm assignment and inactives
        Realm::create([
            'round_id' => $round->id,
            'alignment' => 'neutral',
            'number' => 0,
            'name' => 'The Graveyard'
        ]);

        // Spawn Starting Wonders
        $wonderService = app(WonderService::class);
        $startingWonders = $wonderService->getStartingWonders($round);
        foreach ($startingWonders as $wonder) {
            $wonderService->createWonder($round, $wonder);
        }

        return $round;
    }

    /**
     * Returns the last round number in a round league.
     *
     * @param RoundLeague $league
     * @return int
     */
    protected function getLastRoundNumber(RoundLeague $league): int
    {
        $round = Round::where('round_league_id', $league->id)
            ->orderBy('number', 'desc')
            ->first();

        if ($round) {
            return $round->number;
        }

        return 0;
    }
}
