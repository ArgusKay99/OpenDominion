<?php

use Illuminate\Routing\Router;

/** @var Router $router */
$router->group(['prefix' => 'v1'], function (Router $router) {

    $router->get('pbbg', function () {
        return [
            'name' => 'OpenDominion',
            'version' => (Cache::get('version') ?? 'unknown'),
            'description' => 'A text-based, persistent browser-based strategy game (PBBG) in a fantasy war setting',
            'tags' => ['fantasy', 'multiplayer', 'strategy'],
            'status' => 'up',
            'dates' => [
                'born' => '2013-02-04',
                'updated' => (Cache::has('version-date') ? carbon(Cache::get('version-date'))->format('Y-m-d') : null),
            ],
            'players' => [
                'registered' => \OpenDominion\Models\User::whereActivated(true)->count(),
                'active' => \OpenDominion\Models\Dominion::whereHas('round', function ($q) {
                    $q->where('start_date', '<=', now())
                        ->where('end_date', '>', now());
                })->count(),
            ],
            'links' => [
                'beta' => 'https://beta.opendominion.net',
                'github' => 'https://github.com/WaveHack/OpenDominion',
            ],
        ];
    });

});
