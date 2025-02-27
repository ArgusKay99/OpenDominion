@extends('layouts.topnav')

@section('content')
    @include('partials.scribes.nav')
    <div class="box box-primary">
        <div class="box-header with-border">
            <h3 class="box-title">{{ $race->name }}</h3>
        </div>
        <div class="box-body table-responsive">
            <div class="row">
                <div class="col-md-12 col-md-9">
                    {{-- Description --}}
                    <h4 style="border-bottom: 1px solid #f4f4f4; margin-top: 0; padding: 10px 0">Description</h4>
                    <em>
                        {!! $race->description !!}
                    </em>

                    <div class="row">
                        <div class="col-md-12">
                            {{-- Racial Spells --}}
                            @php
                                $racialSpells = $spellHelper->getSpells($race)->where('races', '!=', []);
                            @endphp
                            <table class="table">
                                <thead>
                                    <tr>
                                        <th>Racial Spell</th>
                                        <th>Description</th>
                                        <th>Category</th>
                                        <th>Duration</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($racialSpells as $spell)
                                        <tr>
                                            <td>{{ $spell->name }}</td>
                                            <td>{{ $spellHelper->getSpellDescription($spell) }}</td>
                                            <td>{{ $spellHelper->getSpellType($spell) }}</td>
                                            <td>{{ $spell->duration ? $spell->duration.' hours' : '--' }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
                <div class="col-md-12 col-md-3">
                    {{-- Home land --}}
                    <h4 style="border-bottom: 1px solid #f4f4f4; margin-top: 0; padding: 10px 0">Home land</h4>
                    <p>
                        {!! $landHelper->getLandTypeIconHtml($race->home_land_type) !!} {{ ucfirst($race->home_land_type) }}
                    </p>
                    {{-- Racial Perks --}}
                    <table class="table table-striped">
                        <colgroup>
                            <col>
                            <col width="50px">
                        </colgroup>
                        <thead>
                            <tr>
                                <th>Race Perk</th>
                                <th>Value</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($race->perks as $perk)
                                @php
                                    $perkDescription = $raceHelper->getPerkDescriptionHtmlWithValue($perk);
                                @endphp
                                <tr>
                                    <td>
                                        {!! $perkDescription['description'] !!}
                                    </td>
                                    <td class="text-center">
                                        {!! $perkDescription['value']  !!}
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="row">
                <div class="col-md-12">
                    {{-- Military Units --}}
                    <h4 style="border-bottom: 1px solid #f4f4f4; margin-top: 0; padding: 10px 0">Military Units</h4>

                    <table class="table table-striped">
                        <colgroup>
                            <col width="200px">
                            <col width="50px">
                            <col width="50px">
                            <col>
                            <col width="100px">
                        </colgroup>
                        <thead>
                            <tr>
                                <th>Unit</th>
                                <th class="text-center">OP</th>
                                <th class="text-center">DP</th>
                                <th>Perks</th>
                                <th class="text-center">Cost</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($race->units as $unit)
                                <tr>
                                    <td>
                                        {!! $unitHelper->getUnitTypeIconHtml("unit{$unit->slot}", $race) !!}
                                        {{ $unit->name }}
                                    </td>
                                    <td class="text-center">
                                        {{ $unit->power_offense }}
                                    </td>
                                    <td class="text-center">
                                        {{ $unit->power_defense }}
                                    </td>
                                    <td>
                                        {!! $unitHelper->getUnitHelpString("unit{$unit->slot}", $race) !!}
                                    </td>
                                    <td class="text-center">
                                        {{ $unitHelper->getUnitCostString($unit) }}
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
@endsection
