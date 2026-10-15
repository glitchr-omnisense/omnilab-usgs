# omnilab/usgs

The USGS earthquake catalogue (ComCat) for [glitchr/omnilab](https://github.com/glitchr-studio/omnilab):
an event by its id - its magnitude and type, its place and depth, its time, the felt reports and
intensities, the tsunami flag, PAGER's alert level, its ids in the other networks - and the
earthquakes of a box or around a point over a period, as series (their count per day or hour;
each one's magnitude or depth, or the step's maximum, mean...) or as occurrences - the contract's
fourth shape: entities, each with its time and its place, a page at a time. Through the FDSN event
web service, no key.

```php
$usgs = (new UsgsSourceFactory($http))->create(['mailto' => 'support@glitchr.io']);
$quake = $usgs->entity('us7000pn9s');
$quake->label;                      // M 7.7 - 2025 Mandalay, Burma (Myanmar) Earthquake
$quake->time;                       // 2025-03-28 06:20:52.715 UTC
$quake->place;                      // 22.011,95.9363
$quake->property('depth');          // 10 ± 1.719 km
$quake->value('alert');             // red (PAGER)
$quake->value('mmi');               // 9.953 (ShakeMap)

$europe = new BBox(-25, 35, 45, 72);
[$count] = $usgs->series(new SeriesQuery(['count'], $europe, new \DateTimeImmutable('2026-10-01'), new \DateTimeImmutable('2026-10-07'), SeriesQuery::DAILY));
$count->values();                   // M4+ (min_magnitude: 4): 1 1 0 7 1 1 0
$largest = $usgs->occurrences(new OccurrenceQuery($europe, new \DateTimeImmutable('2026-10-01'), new \DateTimeImmutable('2026-10-07'),
    [OccurrenceQuery::MIN_MAGNITUDE => 4], limit: 5, sort: 'magnitude'));
$largest->first()->label;           // M 5.0 - 21 km ESE of Feke, Turkey
$largest->first()->time;            // 2026-10-04 11:43:23 UTC
```

```yaml
omnilab:
    sources:
        usgs:
            factory: usgs
            options:
                mailto: 'support@glitchr.io'   # in the User-Agent
                radius_km: 100                 # the circle around a Point, when a query gives none
                min_magnitude: 4               # the smallest magnitude series() and occurrences() count, when a query says none; null: all
```

## Terms of use

The USGS's earthquake data are in the U.S. public domain; credit the USGS where you show them.
The FDSN service is free and without a key; it publishes no rate, answers at most 20,000 events
a query (a 400 beyond), and points heavy pollers to its real-time GeoJSON feeds. The source
makes one call per entity, per series query and per page of occurrences; cache what it answers.

See [docs/](docs/index.md) for what is read, how it maps, and what the catalogue does not give here.

License: MIT.
