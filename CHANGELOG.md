# Changelog

## [Unreleased]

## [1.0.0] - 2026-11-13

The first stable release: it installs with Composer's default minimum-stability (stable).

### Added

- Omnilab USGS: an earthquake of the USGS catalogue (ComCat) by its event id - magnitude and type,
  place, depth, time, felt reports and intensities (DYFI, ShakeMap), tsunami flag, PAGER alert
  level, its ids in the other networks - and the earthquakes of a box or around a point over a
  period as series (count per step, each one's magnitude or depth, or the step's maximum, mean...)
  or as occurrences, entities with their time and place a page at a time, through the FDSN event web
  service, no key needed.

### Release

- Requires `omnisense/omnilab` `^1.0`, stable versions, where it required `@dev` ones:
  `composer require omnilab/usgs` installs in a project at Composer's default minimum-stability
  (stable). `extra.branch-alias` names the branch (`dev-1.x`: `1.x-dev`).
- CI: `.github/workflows/ci.yml` (glitchr-studio/ci's php-package.yml, pinned: the package
  installed alone, PHPUnit, PHPStan against its baseline, a consumer install) and `release.yml`
  (that CI on the tag, then glitchr-studio/ci's php-release.yml); `phpunit.xml.dist` runs the
  package's own tests.
- README: the install line and a first call.
