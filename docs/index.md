# omnilab/usgs

## Installation

```sh
composer require omnilab/usgs
```

```php
use Omnilab\Usgs\UsgsSourceFactory;

$usgs = (new UsgsSourceFactory($httpClient))->create([
    'mailto' => 'support@glitchr.io',  // optional
    'radius_km' => 100,                // optional: the circle around a Point, when a query gives none
    'min_magnitude' => 4,              // optional: the smallest magnitude counted, when a query says none; null: every event
]);
```

With the Symfony bundle, the factory is registered as `usgs` when the bundle knows it; otherwise
register `UsgsSourceFactory` as a service (it is autoconfigured as a `SourceFactoryInterface`).

## Which API

The FDSN event web service of the USGS (`https://earthquake.usgs.gov/fdsnws/event/1/`, version
2.8.1): `query?format=geojson&eventid=` for one event, with its products (origin, PAGER,
ShakeMap...), `query?format=csv` for the series (a line an event: time, place, depth, magnitude),
`query?format=geojson` for the occurrences. Times are UTC.

## Its shapes

An earthquake is an **entity**: one thing, identified, with a `time` and a `place` - what the
core's `Entity` holds for "what happens somewhere at a moment". The earthquakes of a place over a
period are a **series** when what is asked is a variable over time - how many a day, the largest
of each day, each one's magnitude (an irregular series, `step` null) - and `series()` gives them
for a `BBox` or around a `Point` (the configured radius). What the series cannot hold is each
event's own place and identity: for that they are **occurrences** (`occurrences()`, the core's
fourth shape), each an entity with its time and its place, a page at a time. ComCat has no text
search, so the source has no `search()`.

`events()`, which listed them before the contract had occurrences, is deprecated: it answers what
it answered (the first page, the period 30 days back from its end by default), through
`occurrences()`, and triggers an `E_USER_DEPRECATED`.

## Calls

| Method | Reads | USGS |
|---|---|---|
| `entity()` | an event id (`us7000pn9s`), one of its other networks' ids (`at00sttln4`: the preferred event answers), `usgs.event:us7000pn9s`, its event page | `query?format=geojson&eventid=` |
| `series()` | `count`, `magnitude`, `depth`; a `BBox` or a `Point`; the period (30 days back by default; a date alone is the whole day, in the query's time zone, UTC by default); a step (`SeriesQuery::DAILY`, `HOURLY`, any ISO 8601 duration); an aggregation (`Series::MAX`, `MIN`, `MEAN`, `SUM`) | one `query?format=csv&orderby=time-asc&eventtype=earthquake&starttime=&endtime=` with the place and `minmagnitude`, for every variable |
| `occurrences()` | an `OccurrenceQuery`: a `BBox`, a `Point` and its radius (`radius_km` by default), or none (the world); the period (a date alone is its whole day, UTC; an open start is the catalogue's first event, an open end now); the filters below; a sort (`newest`, the service's default, `oldest`, `magnitude`, `magnitude-asc`); a page of up to 20,000, the next by offset | `query?format=geojson&orderby=&starttime=&endtime=&limit=&offset=` with the place, `minmagnitude`, `maxmagnitude`, `eventtype`, `alertlevel` |
| `events()` (deprecated) | a `BBox` or a `Point`, the period (30 days back by default), a minimum magnitude, a limit, an order (`time`, `time-asc`, `magnitude`, `magnitude-asc`) | as `occurrences()`'s first page |

`count` is always per step (aggregation `sum`); `magnitude` and `depth` are each event's value
without an aggregation, the step's aggregate with one (`magnitude_max`, `depth_mean`...); a step
without an event is 0 for `count`, null for the others. A `Point` is the circle of `radius_km`
around it (`latitude`, `longitude`, `maxradiuskm`); a box across the antimeridian is sent with its
east bound past 180, which the service reads. A variable it does not serve, or another
aggregation, is refused before any call. Unknown is `null` (the service answers 404); an
identifier of another base is refused before any call. More than 20,000 events in a series is the
service's 400, a `ProviderException` that says so: narrow the place, the period or raise
`min_magnitude`; occurrences page through them.

The filters of `occurrences()`, any other refused before any call:

| Key | FDSN | Value |
|---|---|---|
| `OccurrenceQuery::MIN_MAGNITUDE` | `minmagnitude` | a number; the `min_magnitude` option when absent |
| `OccurrenceQuery::MAX_MAGNITUDE` | `maxmagnitude` | a number |
| `OccurrenceQuery::KIND` | `eventtype` | an event type or a list: `earthquake`, `quarry blast`, `explosion`, `ice quake`... |
| `alert` | `alertlevel` | PAGER's level or a list: `green`, `yellow`, `orange`, `red` |

A page asks one event more than it holds, to know whether another follows (`next`, the offset of
the following page); it has no `total`, which would cost a call to `count`.

## What an entity holds

`id` (`usgs.event:us7000pn9s`), `label` (the catalogue's title: `M 7.7 - 2025 Mandalay, Burma
(Myanmar) Earthquake`), `domain` earth, `kind` (`earthquake`, `quarry blast`, `explosion`...),
`time` (to the millisecond, UTC), `place` (the epicentre; the depth is a property: a `Point`'s
elevation is above sea level), `identifiers` (its id, then the other networks' ids for it), `url`
(its event page), and these properties:

| Key | ComCat | Value |
|---|---|---|
| `magnitude`, `magnitude_type` | mag ± the origin's magnitude-error, magType | number (with its uncertainty for an event), text: `mww`, `mb`, `ml`... |
| `depth` | the geometry's third coordinate ± the origin's vertical-error | Quantity, km |
| `place_name`, `region` | place, the origin's title | text |
| `felt`, `cdi`, `mmi` | felt reports and the highest reported intensity ("Did You Feel It?"), ShakeMap's highest estimated intensity | number |
| `alert`, `pager_review` | PAGER's alert level (green, yellow, orange, red), its review status | text |
| `tsunami` | the tsunami flag: a large event in an oceanic region (not a tsunami warning) | boolean |
| `significance`, `status`, `network`, `stations` | sig, status (automatic, reviewed), net, nst | number, text |
| `azimuthal_gap`, `minimum_distance`, `rms`, `horizontal_error` | gap, dmin, rms, the origin's horizontal-error | deg, deg, s, km |
| `updated` | updated | ISO 8601 |

An occurrence (of `occurrences()`, or `events()`) holds the same but what comes from its products
(the uncertainties, `region`, `pager_review`, `horizontal_error`): `entity($occurrence->id)` reads
it whole.

## The Collector

Wikidata keeps the USGS event id in P3196 (1,511 items on 2026-10-15), which omnilab/wikidata
reads into the `usgs.event` scheme: the Collector follows an earthquake from Wikidata here, and
back. Answered on 2026-10-15, the 2025 Myanmar earthquake:

```php
$collector = new Collector($registry, new Merger(['time' => ['usgs'], 'place' => ['usgs']]));
$quake = $collector->collect('wikidata', 'Q133574091', 1, ['usgs']);
$quake->sources;                      // ['wikidata', 'usgs']
$quake->time;                         // 2025-03-28 06:20:52 UTC [usgs] (Wikidata gives the day)
$quake->property('magnitude');        // 7.7 ± 0.028 [usgs]; moment_magnitude 7.7 [wikidata]
```

3 calls in about 2 s - Wikidata's item and the label of its class, then
`query?format=geojson&eventid=us7000pn9s`. From the USGS (`collect('usgs', 'us7000pn9s', 1,
['wikidata'])`), 4: the event, then `haswbstatement:P3196=us7000pn9s`, the item, its class. For
the great historical earthquakes Wikidata gives the id of a catalogue the USGS keeps
(`official20110311054624120_30`, Tohoku 2011), which the source reads and the USGS answers. The
USGS gives no Wikidata item.

## What the catalogue does not give here

The moment tensor and the finite fault, the phase data, the ShakeMap grids and the PAGER
estimates of fatalities and losses (only the alert level is read), the aftershock forecast, the
other event types in the series (`eventtype=earthquake`), the depth and magnitude ranges, the
catalogue and contributor filters, and the real-time feeds are not read.

## Verified, and not

Against the real service on 2026-10-15: the 2025 Mandalay earthquake by its id and by its event
page, the 2025 Kamchatka earthquake (tsunami flag set); the 2011 Tohoku earthquake by
`official20110311054624120_30` (M 9.1 mww, its other ids `usc0001xgp`, `usp000hvnu`,
`iscgem16461282`...); an unknown id (404); the M4+ earthquakes
over Europe from 2026-10-01 to 10-07 as a daily count (1, 1, 0, 7, 1, 1, 0), each one's magnitude
(eleven) and the five largest as occurrences (a M 5.0 near Feke, Turkey, first, on 2026-10-04 at
11:43:23 UTC; the next page from the sixth); the eleven, oldest first, in three pages of four;
the three earthquakes within 100 km of Tokyo the same week (M 5.3 on the 1st, 4.5 on the 1st, 4.9
on the 7th; as daily maxima too); the catalogue's oldest events by an open start
(`ushis15680101000000000`, Moodus, Connecticut, 1568; then the 1615 Dominican Republic M 7.5);
the 27 quarry blasts of the world that week (`kind`); an alias (`at00sttln4`) giving the Mandalay
event; a box across the antimeridian (170° to -170°, 25° S to 10° S, M5+ from 2026-09-01 to 10-14:
24 events, Fiji, Tonga, Vanuatu, both sides); a query of 107,144 events answered page by page
(`limit` and `offset`, the 400 only without a limit); `count` answering `maxAllowed` 20000. The
fixtures in `Tests/Fixtures/` are its answers, the events' products cut to the two read.

Not verified: a query over 20,000 events (the 400 is the documented one); the service's answer to a client that
calls too fast (no 429 met, no rate published).
