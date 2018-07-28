@extends('layouts.master')

@section('page-header', 'Op Center')

@section('content')
    <div class="row">

        <div class="col-sm-12 col-md-9">
            @component('partials.dominion.op-center.box')
                @php
                    $infoOp = $infoOpService->getInfoOp($selectedDominion->realm, $dominion, 'clear_sight');
                @endphp

                @slot('title', ('Status Screen (' . $dominion->name . ')'))
                @slot('titleIconClass', 'fa fa-bar-chart')

                @if ($infoOp === null)
                    <p>No recent data available.</p>
                    <p>Cast magic spell 'Clear Sight' to reveal information.</p>
                @else
                    @php
                        $race = OpenDominion\Models\Race::findOrFail($infoOp->data['race_id']);
                    @endphp

                    @slot('noPadding', true)

                    <div class="row">
                        <div class="col-xs-12 col-sm-4">
                            <table class="table">
                                <colgroup>
                                    <col width="50%">
                                    <col width="50%">
                                </colgroup>
                                <thead>
                                    <tr>
                                        <th colspan="2">Overview</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr>
                                        <td>Ruler:</td>
                                        <td>{{ $infoOp->data['ruler_name'] }}</td>
                                    </tr>
                                    <tr>
                                        <td>Race:</td>
                                        <td>{{ $race->name }}</td>
                                    </tr>
                                    <tr>
                                        <td>Land:</td>
                                        <td>
                                            {{ number_format($infoOp->data['land']) }}
                                            <span class="{{ $rangeCalculator->getDominionRangeSpanClass($selectedDominion, $dominion) }}">
                                                ({{ number_format($rangeCalculator->getDominionRange($selectedDominion, $dominion), 1) }}%)
                                            </span>
                                        </td>
                                    </tr>
                                    <tr>
                                        <td>Peasants:</td>
                                        <td>{{ number_format($infoOp->data['peasants']) }}</td>
                                    </tr>
                                    <tr>
                                        <td>Employment:</td>
                                        <td>{{ number_format($infoOp->data['employment'], 2) }}%</td>
                                    </tr>
                                    <tr>
                                        <td>Networth:</td>
                                        <td>{{ number_format($infoOp->data['networth']) }}</td>
                                    </tr>
                                    <tr>
                                        <td>Prestige:</td>
                                        <td>{{ number_format($infoOp->data['prestige']) }}</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                        <div class="col-xs-12 col-sm-4">
                            <table class="table">
                                <colgroup>
                                    <col width="50%">
                                    <col width="50%">
                                </colgroup>
                                <thead>
                                    <tr>
                                        <th colspan="2">Resources</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr>
                                        <td>Platinum:</td>
                                        <td>{{ number_format($infoOp->data['resource_platinum']) }}</td>
                                    </tr>
                                    <tr>
                                        <td>Food:</td>
                                        <td>{{ number_format($infoOp->data['resource_food']) }}</td>
                                    </tr>
                                    <tr>
                                        <td>Lumber:</td>
                                        <td>{{ number_format($infoOp->data['resource_lumber']) }}</td>
                                    </tr>
                                    <tr>
                                        <td>Mana:</td>
                                        <td>{{ number_format($infoOp->data['resource_mana']) }}</td>
                                    </tr>
                                    <tr>
                                        <td>Ore:</td>
                                        <td>{{ number_format($infoOp->data['resource_ore']) }}</td>
                                    </tr>
                                    <tr>
                                        <td>Gems:</td>
                                        <td>{{ number_format($infoOp->data['resource_gems']) }}</td>
                                    </tr>
                                    <tr>
                                        <td class="nyi">Research Points:</td>
                                        <td class="nyi">{{ number_format($infoOp->data['resource_tech']) }}</td>
                                    </tr>
                                    <tr>
                                        <td>Boats:</td>
                                        <td>{{ number_format($infoOp->data['resource_boats']) }}</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                        <div class="col-xs-12 col-sm-4">
                            <table class="table">
                                <colgroup>
                                    <col width="50%">
                                    <col width="50%">
                                </colgroup>
                                <thead>
                                    <tr>
                                        <th colspan="2">Military</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr>
                                        <td>Morale:</td>
                                        <td>{{ number_format($infoOp->data['morale']) }}%</td>
                                    </tr>
                                    <tr>
                                        <td>Draftees:</td>
                                        <td>{{ number_format($infoOp->data['military_draftees']) }}</td>
                                    </tr>
                                    <tr>
                                        <td>{{ $race->units->get(0)->name }}</td>
                                        <td>{{ number_format($infoOp->data['military_unit1']) }}</td>
                                    </tr>
                                    <tr>
                                        <td>{{ $race->units->get(1)->name }}</td>
                                        <td>{{ number_format($infoOp->data['military_unit2']) }}</td>
                                    </tr>
                                    <tr>
                                        <td>{{ $race->units->get(2)->name }}</td>
                                        <td>{{ number_format($infoOp->data['military_unit3']) }}</td>
                                    </tr>
                                    <tr>
                                        <td>{{ $race->units->get(3)->name }}</td>
                                        <td>{{ number_format($infoOp->data['military_unit4']) }}</td>
                                    </tr>
                                    <tr>
                                        <td>Spies:</td>
                                        <td>???</td>
                                    </tr>
                                    <tr>
                                        <td>Wizards:</td>
                                        <td>???</td>
                                    </tr>
                                    <tr>
                                        <td>ArchMages:</td>
                                        <td>???</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                @endif

                @slot('boxFooter')
                    @if ($infoOp !== null)
                        <em>Revealed {{ $infoOp->updated_at->diffForHumans() }} by {{ $infoOp->sourceDominion->name }}</em>
                        @if ($infoOp->isStale())
                            <span class="label label-warning">Stale</span>
                        @endif
                    @endif

                    <div class="pull-right">
                        <form action="{{ route('dominion.magic') }}" method="post" role="form">
                            @csrf
                            <input type="hidden" name="target_dominion" value="{{ $dominion->id }}">
                            <input type="hidden" name="spell" value="clear_sight">
                            <button type="submit" class="btn btn-sm btn-primary">Clear Sight ({{ number_format($spellCalculator->getManaCost($selectedDominion, 'clear_sight')) }} mana)</button>
                        </form>
                    </div>
                @endslot
            @endcomponent
        </div>

        <div class="col-sm-12 col-md-3">
            <div class="box">
                <div class="box-header with-border">
                    <h3 class="box-title">Information</h3>
                </div>
                <div class="box-body">
                    <p>This page contains the data that your realmies have gathered about dominion <b>{{ $dominion->name }}</b> from realm {{ $dominion->realm->name }} (#{{ $dominion->realm->number }}).</p>

                    <p>Sections marked as <span class="label label-warning">stale</span> contain data from the previous hour (or earlier) and should be considered inaccurate. Recast your info ops before performing any offensive operations during this hour.</p>

                    <p>Estimated stats:</p>
                    <p>
                        OP: ??? <abbr title="Not yet implemented" class="label label-danger">NYI</abbr><br>
                        DP: ??? <abbr title="Not yet implemented" class="label label-danger">NYI</abbr><br>
                        Land: {{ $infoOpService->getLandString($selectedDominion->realm, $dominion) }}<br>
                        Networth: {{ $infoOpService->getNetworthString($selectedDominion->realm, $dominion) }}<br>
                    </p>

                    {{-- todo: invade button --}}
                </div>
            </div>
        </div>

    </div>
    <div class="row">

        <div class="col-sm-12 col-md-6">
            @component('partials.dominion.op-center.box')
                @php
                    $infoOp = $infoOpService->getInfoOp($selectedDominion->realm, $dominion, 'revelation');
                @endphp

                @slot('title', 'Active Spells')
                @slot('titleIconClass', 'ra ra-magic-wand')

                @if ($infoOp === null)
                    <p>No recent data available.</p>
                    <p>Cast magic spell 'Revelation' to reveal information.</p>
                @else
                    @slot('noPadding', true)

                    <table class="table">
                        <colgroup>
                            <col width="150">
                            <col>
                            <col width="100">
                            <col width="200s">
                        </colgroup>
                        <thead>
                            <tr>
                                <th>Spell</th>
                                <th>Effect</th>
                                <th class="text-center">Duration</th>
                                <th class="text-center">Cast By</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($infoOp->data as $spell)
                                @php
                                    $spellInfo = $spellHelper->getSpellInfo($spell['spell']);
                                    $castByDominion = OpenDominion\Models\Dominion::with('realm')->findOrFail($spell['cast_by_dominion_id']);
                                @endphp
                                <tr>
                                    <td>{{ $spellInfo['name'] }}</td>
                                    <td>{{ $spellInfo['description'] }}</td>
                                    <td class="text-center">{{ $spell['duration'] }}</td>
                                    <td class="text-center">
                                        <a href="{{ route('dominion.realm', $castByDominion->realm->number) }}">{{ $castByDominion->name }} (#{{ $castByDominion->realm->number }})</a>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif

                @slot('boxFooter')
                    @if ($infoOp !== null)
                        <em>Revealed {{ $infoOp->updated_at->diffForHumans() }} by {{ $infoOp->sourceDominion->name }}</em>
                        @if ($infoOp->isStale())
                            <span class="label label-warning">Stale</span>
                        @endif
                    @endif

                    <div class="pull-right">
                        <form action="{{ route('dominion.magic') }}" method="post" role="form">
                            @csrf
                            <input type="hidden" name="target_dominion" value="{{ $dominion->id }}">
                            <input type="hidden" name="spell" value="revelation">
                            <button type="submit" class="btn btn-sm btn-primary">Revelation ({{ number_format($spellCalculator->getManaCost($selectedDominion, 'revelation')) }} mana)</button>
                        </form>
                    </div>
                @endslot
            @endcomponent
        </div>

        <div class="col-sm-12 col-md-6">
            imps
        </div>

    </div>
    <div class="row">

        <div class="col-sm-12 col-md-6">
            military home/training
        </div>
        <div class="col-sm-12 col-md-6">
            military returning
        </div>

    </div>
    <div class="row">

        <div class="col-sm-12 col-md-6">
            buildings
        </div>

        <div class="col-sm-12 col-md-6">
            land
        </div>

    </div>
@endsection
