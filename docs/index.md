# omnilab/usgs

## Installation

```sh
composer require omnilab/usgs
```

```php
use Omnilab\Usgs\UsgsSourceFactory;

$usgs = (new UsgsSourceFactory($httpClient))->create([
    'mailto' => 'support@glitchr.io',  // optional
    'radius_km' => 100,                // optional: the circle around a Point
    'min_magnitude' => 4,              // optional: the smallest magnitude counted; null: every event
]);
```

With the Symfony bundle, the factory is registered as `usgs` when the bundle knows it; otherwise
register `UsgsSourceFactory` as a service (it is autoconfigured as a `SourceFactoryInterface`).

## Which API

The FDSN event web service of the USGS (`https://earthquake.usgs.gov/fdsnws/event/1/`, version
2.8.1): `query?format=geojson&eventid=` for one event, with its products (origin, PAGER,
ShakeMap...), `query?format=csv` for the series (a line an event: time, place, depth, magnitude),
`query?format=geojson` for the list. Times are UTC.

## The three shapes

An earthquake is an **entity**: one thing, identified, with a `time` and a `place` - what the
core's `Entity` holds for "what happens somewhere at a moment". The earthquakes of a place over a
period are a **series** when what is asked is a variable over time - how many a day, the largest
of each day, each one's magnitude (an irregular series, `step` null) - and `series()` gives them
for a `BBox` or around a `Point` (the configured radius). What the series cannot hold is each
event's own place: for that, `events()` lists them as entities. It is a method of this source,
outside `SourceInterface`: the contract's `search()` takes a text, and the core has no query by
place and period that answers entities. ComCat has no text search, so the source has no
`search()`.

## Calls

| Method | Reads | USGS |
|---|---|---|
| `entity()` | an event id (`us7000pn9s`), one of its other networks' ids (`at00sttln4`: the preferred event answers), `usgs.event:us7000pn9s`, its event page | `query?format=geojson&eventid=` |
| `series()` | `count`, `magnitude`, `depth`; a `BBox` or a `Point`; the period (30 days back by default; a date alone is the whole day, in the query's time zone, UTC by default); a step (`SeriesQuery::DAILY`, `HOURLY`, any ISO 8601 duration); an aggregation (`Series::MAX`, `MIN`, `MEAN`, `SUM`) | one `query?format=csv&orderby=time-asc&eventtype=earthquake&starttime=&endtime=` with the place and `minmagnitude`, for every variable |
| `events()` | a `BBox` or a `Point`, the period, a minimum magnitude, a limit, an order (`time`, `time-asc`, `magnitude`, `magnitude-asc`) | `query?format=geojson&orderby=&limit=` |

`count` is always per step (aggregation `sum`); `magnitude` and `depth` are each event's value
without an aggregation, the step's aggregate with one (`magnitude_max`, `depth_mean`...); a step
without an event is 0 for `count`, null for the others. A `Point` is the circle of `radius_km`
around it (`latitude`, `longitude`, `maxradiuskm`); a box across the antimeridian is sent with its
east bound past 180, which the service reads. A variable it does not serve, or another
aggregation, is refused before any call. Unknown is `null` (the service answers 404); an
identifier of another base is refused before any call. More than 20,000 events is the service's
400, a `ProviderException` that says so: narrow the place, the period or raise `min_magnitude`.

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

An event of a list (`events()`) holds the same but what comes from its products (the
uncertainties, `region`, `pager_review`, `horizontal_error`).

## The Collector

No path yet. Wikidata keeps the USGS event id in P3196 (1,511 items on 2026-10-15; the 2025
Myanmar earthquake, Q133574091, has `us7000pn9s`), but omnilab/wikidata does not read P3196 into
the `usgs.event` scheme: until it does, the Collector cannot follow an earthquake from Wikidata
here. The USGS gives no Wikidata item.

## What the catalogue does not give here

The moment tensor and the finite fault, the phase data, the ShakeMap grids and the PAGER
estimates of fatalities and losses (only the alert level is read), the aftershock forecast, the
other event types in the series (`eventtype=earthquake`), the depth and magnitude ranges, the
catalogue and contributor filters, and the real-time feeds are not read.

## Verified, and not

Against the real service on 2026-10-15: the 2025 Mandalay earthquake by its id and by its event
page, the 2025 Kamchatka earthquake (tsunami flag set); an unknown id (404); the M4+ earthquakes
over Europe from 2026-10-01 to 10-07 as a daily count (1, 1, 0, 7, 1, 1, 0), each one's magnitude
(eleven) and the five largest as entities (a M 5.0 near Feke, Turkey, first); every earthquake
within 100 km of Tokyo the same week as daily maxima (M 5.3 on the 1st, 4.9 on the 7th); an
alias (`at00sttln4`) giving the Mandalay event; a box across the antimeridian (170° to -170°, M5+
from 2026-09-01 to 10-14: Fiji, Tonga and Vanuatu, both sides); `count` answering `maxAllowed`
20000. The fixtures in `Tests/Fixtures/` are its answers, the events'
products cut to the two read.

Not verified: a query over 20,000 events (the 400 is the documented one); the service's answer to a client that
calls too fast (no 429 met, no rate published).
