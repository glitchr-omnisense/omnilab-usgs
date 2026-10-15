<?php

namespace Omnilab\Usgs;

use Omnilab\Exception\NotSupportedException;
use Omnilab\Model\BBox;
use Omnilab\Model\Domain;
use Omnilab\Model\Entity;
use Omnilab\Model\Identifier;
use Omnilab\Model\Identifiers;
use Omnilab\Model\Point;
use Omnilab\Model\Property;
use Omnilab\Model\Quantity;
use Omnilab\Model\Scheme;
use Omnilab\Model\Series;
use Omnilab\Source\Capability;
use Omnilab\Source\HttpSource;
use Omnilab\Source\OccurrenceQuery;
use Omnilab\Source\Page;
use Omnilab\Source\SeriesQuery;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * The USGS earthquake catalogue (ComCat), through its FDSN event web
 * service (earthquake.usgs.gov/fdsnws/event/1): an event by its id
 * (us7000pn9s) - its magnitude and type, place, depth, time, the felt
 * reports and intensities, the tsunami flag, PAGER's alert level - and,
 * over a box or around a point for a period, the earthquakes as series
 * (their count per step; each one's magnitude or depth, or the step's
 * maximum, mean...) or as occurrences, each an entity with its time and
 * its place, one page at a time (occurrences()).
 *
 * No key. One call per entity, per series query (every variable asked at
 * once), per page of occurrences. The service answers at most 20,000
 * events a query (a page: as many).
 */
final class UsgsSource extends HttpSource
{
    public const BASE_URI = 'https://earthquake.usgs.gov/fdsnws/event/1/';

    /** The most events one query answers: more is a 400 from the service. */
    public const MAX_EVENTS = 20000;

    /** Before the catalogue's first event: an open start (the service's default would be 30 days back). */
    private const FIRST = '0001-01-01T00:00:00';

    /** The variables series() serves, with their unit. */
    public const VARIABLES = ['count' => null, 'magnitude' => null, 'depth' => 'km'];

    /** How occurrences() sorts: the query's sort, the service's orderby. */
    public const SORTS = [OccurrenceQuery::NEWEST => 'time', OccurrenceQuery::OLDEST => 'time-asc', 'magnitude' => 'magnitude', 'magnitude-asc' => 'magnitude-asc'];

    /** PAGER's alert levels, the "alert" filter of occurrences(). */
    public const ALERTS = ['green', 'yellow', 'orange', 'red'];

    /**
     * @param float      $radius       the radius around a Point, in kilometres, for series() and for occurrences() when the query gives none
     * @param float|null $minMagnitude the smallest magnitude series() and occurrences() count when the query says none (null: every event the catalogue has)
     */
    public function __construct(
        HttpClientInterface $http,
        private readonly float $radius = 100.0,
        private readonly ?float $minMagnitude = null,
        string $baseUri = self::BASE_URI,
        array $headers = [],
        float $throttle = 0.0,
    ) {
        parent::__construct($http, $baseUri, $headers, $throttle);
    }

    public function getName(): string
    {
        return 'usgs';
    }

    public function capabilities(): array
    {
        return [Capability::ENTITY, Capability::SERIES, Capability::OCCURRENCES, Capability::HISTORICAL, Capability::AREA];
    }

    /** An event by its ComCat id (us7000pn9s, an alias such as at00sttln4) or its event page. */
    public function entity(Identifier|string $id): ?Entity
    {
        if ($id instanceof Identifier) {
            $identifier = Scheme::USGS_EVENT === $id->scheme ? $id : throw NotSupportedException::identifier('usgs', (string) $id);
        } else {
            $identifier = Identifier::parse($id) ?? Identifier::tryOf(Scheme::USGS_EVENT, $id);
            if (null === $identifier || Scheme::USGS_EVENT !== $identifier->scheme) {
                throw NotSupportedException::identifier('usgs', $id);
            }
        }
        $feature = $this->getJson('query', ['format' => 'geojson', 'eventid' => $identifier->value]);

        return null === $feature || 'Feature' !== ($feature['type'] ?? null) ? null : $this->toEntity($feature);
    }

    /**
     * The earthquakes over the place for the period, as series, one per
     * variable asked:
     *
     * - "count": how many events each step (SeriesQuery::DAILY, HOURLY or
     *   another ISO 8601 duration), from the start of the period;
     * - "magnitude", "depth": each event's value at its time (no aggregation:
     *   an irregular series), or the step's MAX, MIN, MEAN or SUM.
     *
     * A BBox is the box; a Point, the circle of the configured radius around
     * it. The period defaults to the last 30 days; a date without a time is
     * the whole day, in the query's time zone (UTC by default).
     */
    public function series(SeriesQuery $query): array
    {
        foreach ($query->variables as $variable) {
            if (!\array_key_exists($variable, self::VARIABLES)) {
                throw NotSupportedException::operation('usgs', \sprintf('serve "%s" (it serves %s)', $variable, implode(', ', array_keys(self::VARIABLES))));
            }
        }
        if (null !== $query->aggregation && !\in_array($query->aggregation, [Series::MAX, Series::MIN, Series::MEAN, Series::SUM], true)) {
            throw NotSupportedException::operation('usgs', 'aggregate by '.$query->aggregation);
        }
        $timezone = new \DateTimeZone($query->timezone ?? 'UTC');
        [$from, $to] = self::period($query->from, $query->to, $timezone);
        $csv = $this->get('query', ['format' => 'csv', 'orderby' => 'time-asc', 'eventtype' => 'earthquake', 'starttime' => self::utc($from), 'endtime' => self::utc($to)] + $this->where($query->place)) ?? '';
        $events = self::rows($csv);
        $step = new \DateInterval($query->step);

        $series = [];
        foreach ($query->variables as $variable) {
            if ('count' === $variable) {
                $points = [];
                foreach (self::bins($from, $to, $step) as [$start, $end]) {
                    $points[] = [$start, \count(array_filter($events, static fn (array $e) => $e['time'] >= $start && $e['time'] < $end))];
                }
                $series[] = new Series('count', null, $query->place, $points, $query->step, Series::SUM, 'Earthquakes', false, 'usgs');
                continue;
            }
            $column = 'magnitude' === $variable ? 'mag' : 'depth';
            $label = 'magnitude' === $variable ? 'Magnitude' : 'Depth';
            if (null === $query->aggregation) {
                $points = array_map(static fn (array $e) => [$e['time'], $e[$column]], $events);
                $series[] = new Series($variable, self::VARIABLES[$variable], $query->place, $points, null, null, $label.' of each earthquake', false, 'usgs');
                continue;
            }
            $points = [];
            foreach (self::bins($from, $to, $step) as [$start, $end]) {
                $values = array_values(array_filter(array_map(static fn (array $e) => $e['time'] >= $start && $e['time'] < $end ? $e[$column] : null, $events), static fn ($v) => null !== $v));
                $points[] = [$start, $values ? match ($query->aggregation) {
                    Series::MAX => max($values),
                    Series::MIN => min($values),
                    Series::MEAN => round(array_sum($values) / \count($values), 3),
                    Series::SUM => array_sum($values),
                } : null];
            }
            $series[] = new Series($variable.'_'.$query->aggregation, self::VARIABLES[$variable], $query->place, $points, $query->step, $query->aggregation, $label.' ('.$query->aggregation.')', false, 'usgs');
        }

        return $series;
    }

    /**
     * The seismic events over the place for the period, each an entity with
     * its time and its place, one page at a time - what the catalogue's list
     * gives, without an event's products (entity() reads those: the
     * uncertainties, the region, PAGER's review).
     *
     * A BBox is the box (across the antimeridian too); a Point, the circle
     * of the query's radius around it (the configured one by default); no
     * place, the world. A period's end left out is open: from the
     * catalogue's first event, up to now; a date without a time is its whole
     * day, in UTC. Filters: OccurrenceQuery::MIN_MAGNITUDE (the configured
     * one by default), MAX_MAGNITUDE, KIND (the event type, or a list:
     * "earthquake", "quarry blast", "explosion"...) and "alert" (PAGER's
     * level, or a list: green, yellow, orange, red). Sorts: newest (the
     * service's default), oldest, magnitude (the largest first),
     * magnitude-asc. Up to 20,000 a page; no total (the count would cost a
     * call more).
     */
    public function occurrences(OccurrenceQuery $query): Page
    {
        $filters = $this->filters($query, OccurrenceQuery::MIN_MAGNITUDE, OccurrenceQuery::MAX_MAGNITUDE, OccurrenceQuery::KIND, 'alert');
        $orderBy = null === $query->sort ? null : (self::SORTS[$query->sort] ?? throw NotSupportedException::operation('usgs', \sprintf('sort occurrences by "%s" (it sorts by %s)', $query->sort, implode(', ', array_keys(self::SORTS)))));
        foreach ([OccurrenceQuery::MIN_MAGNITUDE, OccurrenceQuery::MAX_MAGNITUDE] as $key) {
            if (isset($filters[$key]) && !is_numeric($filters[$key])) {
                throw new \InvalidArgumentException(\sprintf('The "%s" filter is a number, not "%s".', $key, get_debug_type($filters[$key])));
            }
        }
        $alerts = array_map(static fn (mixed $a) => strtolower((string) $a), (array) ($filters['alert'] ?? []));
        if (array_diff($alerts, self::ALERTS)) {
            throw new \InvalidArgumentException(\sprintf('PAGER\'s alert levels are %s, not "%s".', implode(', ', self::ALERTS), implode('", "', array_diff($alerts, self::ALERTS))));
        }
        $utc = new \DateTimeZone('UTC');
        $start = null === $query->from ? null : self::local($query->from, $utc);
        $end = null === $query->to ? null : self::local($query->to, $utc);
        if (null !== $end && '00:00:00' === $end->format('H:i:s')) {
            $end = $end->modify('+1 day');
        }

        return $this->list($query->place, $start, $end, [
            'maxmagnitude' => $filters[OccurrenceQuery::MAX_MAGNITUDE] ?? null,
            'eventtype' => isset($filters[OccurrenceQuery::KIND]) ? implode(',', (array) $filters[OccurrenceQuery::KIND]) : null,
            'alertlevel' => $alerts ? implode(',', $alerts) : null,
        ], isset($filters[OccurrenceQuery::MIN_MAGNITUDE]) ? (float) $filters[OccurrenceQuery::MIN_MAGNITUDE] : null, $query->radius, $query->limit, max(1, (int) $query->cursor), $orderBy);
    }

    /**
     * The earthquakes over the place for the period, as entities.
     *
     * @deprecated since omnilab/usgs 1.x: use occurrences(), the contract's
     *             query by place and period - this answers its first page,
     *             the period 30 days back from its end by default, as before
     *
     * @param int    $limit   the most events (the service's cap: 20,000)
     * @param string $orderBy "time" (newest first), "time-asc", "magnitude" (largest first), "magnitude-asc"
     *
     * @return list<Entity>
     */
    public function events(Point|BBox $place, ?\DateTimeInterface $from = null, ?\DateTimeInterface $to = null, ?float $minMagnitude = null, int $limit = 100, string $orderBy = 'time'): array
    {
        @trigger_error('UsgsSource::events() is deprecated since omnilab/usgs 1.x: use occurrences(new OccurrenceQuery($place, $from, $to, [OccurrenceQuery::MIN_MAGNITUDE => $minMagnitude], sort: ...)).', \E_USER_DEPRECATED);
        if (!\in_array($orderBy, self::SORTS, true)) {
            throw NotSupportedException::operation('usgs', \sprintf('order events by "%s" (time, time-asc, magnitude, magnitude-asc)', $orderBy));
        }
        [$start, $end] = self::period($from, $to, new \DateTimeZone('UTC'));

        return $this->list($place, $start, $end, [], $minMagnitude, null, $limit, 1, $orderBy)->items;
    }

    /**
     * One page of the catalogue's list: one more event asked than the page
     * holds, to know whether another follows.
     *
     * @param array<string, mixed> $parameters the service's own: maxmagnitude, eventtype, alertlevel
     *
     * @return Page<Entity>
     */
    private function list(Point|BBox|null $place, ?\DateTimeImmutable $start, ?\DateTimeImmutable $end, array $parameters, ?float $minMagnitude, ?float $radius, int $limit, int $offset, ?string $orderBy): Page
    {
        $limit = max(1, min(self::MAX_EVENTS, $limit));
        $data = $this->getJson('query', [
            'format' => 'geojson',
            'orderby' => $orderBy,
            // An open end: now, the service's default.
            'starttime' => null === $start ? self::FIRST : self::utc($start),
            'endtime' => null === $end ? null : self::utc($end),
            'limit' => min(self::MAX_EVENTS, $limit + 1),
            'offset' => $offset > 1 ? $offset : null,
        ] + $parameters + $this->where($place, $minMagnitude, $radius)) ?? [];
        $features = array_values(array_filter($data['features'] ?? [], 'is_array'));
        $more = \count($features) > $limit || (self::MAX_EVENTS === $limit && \count($features) === $limit);
        $entities = array_map($this->toEntity(...), \array_slice($features, 0, $limit));

        return new Page($entities, $more ? (string) ($offset + $limit) : null);
    }

    /** @param array<string, mixed> $feature a GeoJSON feature, an event's detail or an item of a list */
    private function toEntity(array $feature): Entity
    {
        $p = $feature['properties'] ?? [];
        $coordinates = $feature['geometry']['coordinates'] ?? [];
        $products = $p['products'] ?? [];
        $origin = $products['origin'][0]['properties'] ?? [];
        $pager = $products['losspager'][0]['properties'] ?? [];
        $properties = [];
        $add = static function (string $key, string $label, mixed $value) use (&$properties): void {
            if (null !== $value && '' !== $value) {
                $properties[] = new Property($key, $label, $value, 'usgs');
            }
        };
        $number = static fn (mixed $v): int|float|null => is_numeric($v) ? (preg_match('~^-?\d+$~', (string) $v) ? (int) $v : (float) $v) : null;
        $quantity = static fn (mixed $v, ?string $unit, mixed $error = null): ?Quantity => null === ($n = $number($v)) ? null : new Quantity($n, $unit, is_numeric($error) ? (float) $error : null);

        // A magnitude is a decimal, even when the catalogue writes 5 for 5.0.
        $magnitude = is_numeric($p['mag'] ?? null) ? (float) $p['mag'] : null;
        $add('magnitude', 'Magnitude', null === $magnitude ? null : (is_numeric($origin['magnitude-error'] ?? null) ? new Quantity($magnitude, null, (float) $origin['magnitude-error']) : $magnitude));
        $add('magnitude_type', 'Magnitude type', self::text($p['magType'] ?? null));
        $add('depth', 'Depth', $quantity($coordinates[2] ?? null, 'km', $origin['vertical-error'] ?? null));
        $add('place_name', 'Place', self::text($p['place'] ?? null));
        $add('region', 'Nearest place (origin)', self::text($origin['title'] ?? null));
        $add('felt', 'Felt reports ("Did You Feel It?")', $number($p['felt'] ?? null));
        $add('cdi', 'Maximum reported intensity (CDI, "Did You Feel It?")', $number($p['cdi'] ?? null));
        $add('mmi', 'Maximum estimated intensity (MMI, ShakeMap)', $number($p['mmi'] ?? null));
        $add('alert', 'PAGER alert level', self::text($p['alert'] ?? null));
        $add('pager_review', 'PAGER review status', self::text($pager['review-status'] ?? null));
        $add('tsunami', 'Tsunami flag (oceanic region, large enough for a tsunami message)', isset($p['tsunami']) ? 1 === (int) $p['tsunami'] : null);
        $add('significance', 'Significance (0 to 1000+)', $number($p['sig'] ?? null));
        $add('status', 'Review status', self::text($p['status'] ?? null));
        $add('network', 'Contributing network', self::text($p['net'] ?? null));
        $add('stations', 'Stations used', $number($p['nst'] ?? null));
        $add('azimuthal_gap', 'Azimuthal gap', $quantity($p['gap'] ?? null, 'deg'));
        $add('minimum_distance', 'Distance to the nearest station', $quantity($p['dmin'] ?? null, 'deg'));
        $add('rms', 'Travel-time residual (RMS)', $quantity($p['rms'] ?? null, 's'));
        $add('horizontal_error', 'Horizontal location uncertainty', $quantity($origin['horizontal-error'] ?? null, 'km'));
        $add('updated', 'Last updated', null === ($u = self::millis($p['updated'] ?? null)) ? null : $u->format(\DATE_ATOM));

        // Its id first, then the ids the other networks gave it (at, pt, usauto...).
        $id = Identifier::tryOf(Scheme::USGS_EVENT, (string) ($feature['id'] ?? '')) ?? Identifier::of(Scheme::USGS_EVENT, (string) ($p['net'] ?? '').($p['code'] ?? ''));
        $aliases = array_map(static fn (string $alias) => Identifier::tryOf(Scheme::USGS_EVENT, $alias), array_filter(explode(',', (string) ($p['ids'] ?? ''))));
        $place = is_numeric($coordinates[1] ?? null) && is_numeric($coordinates[0] ?? null) ? new Point((float) $coordinates[1], self::longitude((float) $coordinates[0])) : null;

        return new Entity(
            id: $id,
            label: self::text($p['title'] ?? null) ?? $id->value,
            domain: Domain::EARTH,
            kind: self::text($p['type'] ?? null),
            properties: $properties,
            identifiers: new Identifiers($aliases),
            url: self::text($p['url'] ?? null) ?? $id->url(),
            time: self::millis($p['time'] ?? null),
            place: $place,
            source: 'usgs',
        );
    }

    /**
     * The FDSN parameters of a place: a box, or a circle around a point;
     * none for the world.
     *
     * @return array<string, mixed>
     */
    private function where(Point|BBox|null $place, ?float $minMagnitude = null, ?float $radius = null): array
    {
        $where = match (true) {
            // A box across the antimeridian: its east bound past 180, which the service reads.
            $place instanceof BBox => ['minlatitude' => $place->south, 'maxlatitude' => $place->north, 'minlongitude' => $place->west, 'maxlongitude' => $place->west <= $place->east ? $place->east : $place->east + 360],
            $place instanceof Point => ['latitude' => $place->latitude, 'longitude' => $place->longitude, 'maxradiuskm' => $radius ?? $this->radius],
            default => [],
        };

        return $where + ['minmagnitude' => $minMagnitude ?? $this->minMagnitude];
    }

    /**
     * The events of a CSV answer, in time order: time, magnitude, depth.
     *
     * @return list<array{time: \DateTimeImmutable, mag: float|null, depth: float|null}>
     */
    private static function rows(string $csv): array
    {
        $lines = preg_split('~\R~', trim($csv)) ?: [];
        $header = str_getcsv((string) array_shift($lines), escape: '');
        $events = [];
        foreach ($lines as $line) {
            if ('' === $line) {
                continue;
            }
            $row = array_combine($header, array_pad(str_getcsv($line, escape: ''), \count($header), null));
            if (null !== ($time = self::time($row['time'] ?? null))) {
                $events[] = ['time' => $time, 'mag' => is_numeric($row['mag'] ?? null) ? (float) $row['mag'] : null, 'depth' => is_numeric($row['depth'] ?? null) ? (float) $row['depth'] : null];
            }
        }

        return $events;
    }

    /**
     * The steps from the start of the period to its end.
     *
     * @return list<array{\DateTimeImmutable, \DateTimeImmutable}>
     */
    private static function bins(\DateTimeImmutable $from, \DateTimeImmutable $to, \DateInterval $step): array
    {
        $bins = [];
        for ($start = $from; $start < $to && \count($bins) < 100_000; $start = $end) {
            $end = $start->add($step);
            $bins[] = [$start, $end];
        }

        return $bins;
    }

    /**
     * The period as two instants: a date without a time stands for the whole
     * day (from its midnight to the next); no start is 30 days before the end,
     * no end is now.
     *
     * @return array{\DateTimeImmutable, \DateTimeImmutable}
     */
    private static function period(?\DateTimeInterface $from, ?\DateTimeInterface $to, \DateTimeZone $timezone): array
    {
        $end = null === $to ? new \DateTimeImmutable('now', $timezone) : self::local($to, $timezone);
        if (null !== $to && '00:00:00' === $end->format('H:i:s')) {
            $end = $end->modify('+1 day');
        }
        $start = null === $from ? $end->modify('-30 days') : self::local($from, $timezone);

        return [$start, $end];
    }

    /** A time as written, read in a zone: a date and its time of day, the zone it was given in left aside. */
    private static function local(\DateTimeInterface $time, \DateTimeZone $timezone): \DateTimeImmutable
    {
        return new \DateTimeImmutable($time->format('Y-m-d H:i:s.u'), $timezone);
    }

    private static function utc(\DateTimeImmutable $time): string
    {
        return $time->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s');
    }

    /** A time the service gives in milliseconds since 1970, in UTC. */
    private static function millis(mixed $millis): ?\DateTimeImmutable
    {
        return is_numeric($millis) ? \DateTimeImmutable::createFromFormat('U.v', \sprintf('%d.%03d', intdiv((int) $millis, 1000), (int) $millis % 1000)) ?: null : null;
    }

    /** A longitude back within -180..180 (the catalogue may write 181 past the antimeridian). */
    private static function longitude(float $longitude): float
    {
        return $longitude > 180 ? $longitude - 360 : ($longitude < -180 ? $longitude + 360 : $longitude);
    }
}
