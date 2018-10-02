# Changelog
All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](http://keepachangelog.com/en/1.0.0/). This project uses its own versioning system.

## [Unreleased]

## [0.5.0-8] - 2018-10-01
### Changed
- Info gathering ops on Op Center page now show exact time upon hover. ([#337](https://github.com/WaveHack/OpenDominion/issues/337))
- Significantly reduced spy losses on failed ops.

### Fixed
- Fixed networth sometimes showing incorrect values on realm page. ([#310](https://github.com/WaveHack/OpenDominion/issues/310))
- Fixed construction cost calculation. As a result, construction costs are significantly higher than before. ([#347](https://github.com/WaveHack/OpenDominion/issues/347))
- Barracks Spy now shows number of draftees. ([#331](https://github.com/WaveHack/OpenDominion/issues/331))
- Fixed an division by zero error if you have 0 peasants. ([#349](https://github.com/WaveHack/OpenDominion/issues/349))

## [0.5.0] - 2018-08-04
### Added
- Added new races: Dwarf and Goblin.
- Added ruler name for round registration. ([#254](https://github.com/WaveHack/OpenDominion/issues/254))
- Added packs. ([#280](https://github.com/WaveHack/OpenDominion/issues/280))
- Added racial spells. ([#157](https://github.com/WaveHack/OpenDominion/issues/157))
- Added Op Center. ([#24](https://github.com/WaveHack/OpenDominion/issues/24))
- Added Espionage with info gathering operations: Barracks Spy, Castle Spy, Survey Dominion, and Land Spy. ([#21](https://github.com/WaveHack/OpenDominion/issues/21))
- Added Clear Sight spell. ([#220](https://github.com/WaveHack/OpenDominion/issues/220))
- Added Revelation spell. ([#221](https://github.com/WaveHack/OpenDominion/issues/221))
- Added unit OP/DP stats on military page ([#234](https://github.com/WaveHack/OpenDominion/issues/234))
- Added NYI/PI indicator and help text icon on Construction Advisor page
- Added Wizard Guild building, reducing wizard/AM plat cost and increasing wizard strength regen per hour. ([#305](https://github.com/WaveHack/OpenDominion/issues/305))
- Added failed spy op losses reduction on Forest Havens. ([#306](https://github.com/WaveHack/OpenDominion/issues/306))

### Changed
- Updated round registration page with better help texts and racial descriptions.
- Employment percentage on status screen now shows 2 decimals.
- Updated Re-zone Land icon in the sidebar.
- Net OP/DP now scales with morale, down to -10% at 0% morale.

## [0.4.2] - 2018-06-03
### Fixed
- Fixed dashboard page sometimes showing incorrect duration for round start/end dates.
- Fixed internal server error on realm page using invalid realm number. ([#270](https://github.com/WaveHack/OpenDominion/issues/270))

## [0.4.1] - 2018-05-23
### Changed
- Updated `version:update` command to support Git tags.

## 0.4.0 - 2018-05-22
### Added
- This CHANGELOG file.

[Unreleased]: https://github.com/WaveHack/OpenDominion/compare/0.5.0-8...HEAD
[0.5.0-8]: https://github.com/WaveHack/OpenDominion/compare/0.5.0...0.5.0-8
[0.5.0]: https://github.com/WaveHack/OpenDominion/compare/0.4.2...0.5.0
[0.4.2]: https://github.com/WaveHack/OpenDominion/compare/0.4.1...0.4.2
[0.4.1]: https://github.com/WaveHack/OpenDominion/compare/0.4.0...0.4.1
