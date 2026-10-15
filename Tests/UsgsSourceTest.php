<?php

namespace Omnilab\Usgs\Tests;

use Omnilab\Exception\NotSupportedException;
use Omnilab\Exception\ProviderException;
use Omnilab\Exception\RateLimitedException;
use Omnilab\Exception\UnavailableException;
use Omnilab\Model\BBox;
use Omnilab\Model\Domain;
use Omnilab\Model\Identifier;
use Omnilab\Model\Point;
use Omnilab\Model\Quantity;
use Omnilab\Model\Scheme;
use Omnilab\Model\Series;
use Omnilab\Source\Capability;
use Omnilab\Source\OccurrenceQuery;
use Omnilab\Source\Query;
use Omnilab\Source\SeriesQuery;
use Omnilab\Usgs\UsgsSource;
use Omnilab\Usgs\UsgsSourceFactory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Fixtures recorded from earthquake.usgs.gov/fdsnws/event/1 on 2026-10-15
 * (service 2.8.1): the 2025 Mandalay (us7000pn9s) and Kamchatka
 * (us6000qw60) earthquakes, their products cut to the two read (origin,
 * losspager); the M4+ earthquakes over Europe from 2026-10-01 to 10-07, as
 * CSV (eleven) and as GeoJSON (the five largest); every earthquake within
 * 100 km of Tokyo in the same week, as CSV (three). An unknown id answers
 * 404 with an HTML page.
 */
final class UsgsSourceTest extends TestCase
{
    /** @var list<array<string, string>> */
    private array $calls = [];

    private function source(?MockResponse $response = null, array $options = []): UsgsSource
    {
        $http = new MockHttpClient(function (string $method, string $url) use ($response): MockResponse {
            parse_str((string) parse_url($url, \PHP_URL_QUERY), $query);
            $this->calls[] = $query;
            if ($response) {
                return $response;
            }
            $fixture = match (true) {
                isset($query['eventid']) => \in_array($query['eventid'], ['us7000pn9s', 'at00sttln4'], true) ? 'event-us7000pn9s.json' : ('us6000qw60' === $query['eventid'] ? 'event-us6000qw60.json' : null),
                isset($query['latitude']) && 'csv' === $query['format'] => 'query-tokyo.csv',
                'csv' === $query['format'] => 'query-europe-m4.csv',
                default => 'query-europe-m4.json',
            };

            return null === $fixture
                ? new MockResponse("Error 404: Not Found\n\nNot Found\n", ['http_code' => 404])
                : new MockResponse((string) file_get_contents(__DIR__.'/Fixtures/'.$fixture));
        });
        $source = (new UsgsSourceFactory($http))->create($options);
        self::assertInstanceOf(UsgsSource::class, $source);

        return $source;
    }

    public function testAnEventInOneCall(): void
    {
        $quake = $this->source()->entity('us7000pn9s');

        self::assertSame('usgs.event:us7000pn9s', $quake->id->key());
        self::assertSame('M 7.7 - 2025 Mandalay, Burma (Myanmar) Earthquake', $quake->label);
        self::assertSame(Domain::EARTH, $quake->domain);
        self::assertSame('earthquake', $quake->kind);
        self::assertSame('2025-03-28T06:20:52.715+00:00', $quake->time->format('Y-m-d\TH:i:s.vP'));
        self::assertEquals(new Point(22.011, 95.9363), $quake->place);
        self::assertEquals(new Quantity(7.7, null, 0.028), $quake->value('magnitude'));
        self::assertSame('mww', $quake->value('magnitude_type'));
        self::assertEquals(new Quantity(10, 'km', 1.719), $quake->value('depth'));
        self::assertSame('15 km NNW of Sagaing, Burma (Myanmar)', $quake->value('region'));
        self::assertSame([2545, 9.1, 9.953], [$quake->value('felt'), $quake->value('cdi'), $quake->value('mmi')]);
        self::assertSame('red', $quake->value('alert'));
        self::assertFalse($quake->value('tsunami'));
        self::assertSame(2910, $quake->value('significance'));
        self::assertEquals(new Quantity(7.63, 'km'), $quake->value('horizontal_error'));
        self::assertSame('usgs', $quake->property('magnitude')->source);
        self::assertSame('https://earthquake.usgs.gov/earthquakes/eventpage/us7000pn9s', $quake->url);
        self::assertSame([['format' => 'geojson', 'eventid' => 'us7000pn9s']], $this->calls);
    }

    public function testItsIdsInTheOtherNetworks(): void
    {
        $quake = $this->source()->entity(Identifier::of(Scheme::USGS_EVENT, 'us7000pn9s'));

        self::assertSame(['usgs.event:us7000pn9s', 'usgs.event:usauto7000pn9s', 'usgs.event:pt25087002', 'usgs.event:at00sttln4'], $quake->identifiers()->keys());
        self::assertSame('usgs.event:us7000pn9s', $this->source()->entity('at00sttln4')?->id->key(), 'an alias gives the preferred event');
    }

    public function testATsunamiFlagAndAPageAsId(): void
    {
        $quake = $this->source()->entity('https://earthquake.usgs.gov/earthquakes/eventpage/us6000qw60');

        self::assertSame('M 8.8 - 2025 Kamchatka Peninsula, Russia Earthquake', $quake->label);
        self::assertTrue($quake->value('tsunami'));
        self::assertEquals(new Point(52.4948, 160.2395), $quake->place);
    }

    public function testUnknownIsNull(): void
    {
        self::assertNull($this->source()->entity('us0000nonexist'));
    }

    public function testTheLongIdOfACatalogueTheUsgsKeepsIsAsked(): void
    {
        // As Wikidata's P3196 gives Tohoku 2011: the service is asked, not refused by the scheme.
        $this->source()->entity('official20110311054624120_30');
        self::assertSame('official20110311054624120_30', $this->calls[0]['eventid'] ?? null);
    }

    public function testAnIdentifierOfAnotherBaseIsRefusedBeforeAnyCall(): void
    {
        foreach ([Identifier::wikidata('Q131536000'), 'Q131536000', Identifier::of(Scheme::SIMBAD, 'M 31'), '58-08-2', 'not an id!'] as $id) {
            try {
                $this->source()->entity($id);
                self::fail('not read: '.$id);
            } catch (NotSupportedException) {
            }
        }
        self::assertSame([], $this->calls);
    }

    public function testTheCountPerDayAndEachMagnitudeOverABox(): void
    {
        $europe = new BBox(-25, 35, 45, 72);
        [$count, $magnitude] = $this->source(null, ['min_magnitude' => 4])->series(new SeriesQuery(['count', 'magnitude'], $europe, new \DateTimeImmutable('2026-10-01'), new \DateTimeImmutable('2026-10-07'), SeriesQuery::DAILY));

        self::assertSame(['format' => 'csv', 'orderby' => 'time-asc', 'eventtype' => 'earthquake', 'starttime' => '2026-10-01T00:00:00', 'endtime' => '2026-10-08T00:00:00', 'minlatitude' => '35', 'maxlatitude' => '72', 'minlongitude' => '-25', 'maxlongitude' => '45', 'minmagnitude' => '4'], $this->calls[0], 'one call for every variable; the last day whole');
        self::assertSame('count', $count->variable);
        self::assertSame([1, 1, 0, 7, 1, 1, 0], $count->values());
        self::assertSame('2026-10-04', $count->times()[3]->format('Y-m-d'));
        self::assertSame([SeriesQuery::DAILY, Series::SUM, false, 'usgs'], [$count->step, $count->aggregation, $count->forecast, $count->source]);
        self::assertSame($europe, $count->place);

        self::assertCount(11, $magnitude, 'each earthquake');
        self::assertNull($magnitude->step, 'irregular');
        self::assertSame(5.0, $magnitude->max());
        self::assertSame('2026-10-04T11:43:23', $magnitude->times()[5]->format('Y-m-d\TH:i:s'));
    }

    public function testTheDailyMaximaAroundAPoint(): void
    {
        $tokyo = new Point(35.68, 139.77);
        [$magnitude, $depth] = $this->source()->series(new SeriesQuery(['magnitude', 'depth'], $tokyo, new \DateTimeImmutable('2026-10-01'), new \DateTimeImmutable('2026-10-07'), SeriesQuery::DAILY, Series::MAX));

        self::assertSame(['35.68', '139.77', '100'], [$this->calls[0]['latitude'], $this->calls[0]['longitude'], $this->calls[0]['maxradiuskm']]);
        self::assertArrayNotHasKey('minmagnitude', $this->calls[0]);
        self::assertSame('magnitude_max', $magnitude->variable);
        self::assertSame([5.3, null, null, null, null, null, 4.9], $magnitude->values());
        self::assertSame('depth_max', $depth->variable);
        self::assertSame('km', $depth->unit);
        self::assertSame(65.032, $depth->values()[0]);
    }

    public function testAVariableOrAnAggregationItDoesNotServeIsRefused(): void
    {
        try {
            $this->source()->series(new SeriesQuery(['temperature_2m'], new Point(35.68, 139.77)));
            self::fail('not served');
        } catch (NotSupportedException) {
        }
        self::assertSame([], $this->calls);
    }

    public function testTheEarthquakesOfAPlaceAndAPeriodAsOccurrences(): void
    {
        $europe = new BBox(-25, 35, 45, 72);
        $page = $this->source()->occurrences(new OccurrenceQuery($europe, new \DateTimeImmutable('2026-10-01'), new \DateTimeImmutable('2026-10-07'), [OccurrenceQuery::MIN_MAGNITUDE => 4], limit: 5, sort: 'magnitude'));

        self::assertSame(['format' => 'geojson', 'orderby' => 'magnitude', 'starttime' => '2026-10-01T00:00:00', 'endtime' => '2026-10-08T00:00:00', 'limit' => '6', 'minlatitude' => '35', 'maxlatitude' => '72', 'minlongitude' => '-25', 'maxlongitude' => '45', 'minmagnitude' => '4'], $this->calls[0], 'one more asked than the page holds');
        self::assertCount(5, $page);
        self::assertNull($page->next, 'five answered for six asked: the last page');
        self::assertNull($page->total, 'no count without a call more');
        $first = $page->first();
        self::assertSame('M 5.0 - 21 km ESE of Feke, Turkey', $first->label);
        self::assertSame(5.0, $first->value('magnitude'), 'no uncertainty in a list');
        self::assertNull($first->property('region'), 'nor the origin\'s region: entity() reads it');
        self::assertEquals(new Point(64.5091, -17.3115), $page->items[3]->place);
        self::assertSame('2026-10-05T17:28:24', $page->items[3]->time->format('Y-m-d\TH:i:s'));
        foreach ($page as $occurrence) {
            self::assertTrue(null !== $occurrence->time && $europe->contains($occurrence->place), 'each with its time, in the box');
        }
    }

    public function testThePagesFollowByOffset(): void
    {
        $source = $this->source();
        $query = new OccurrenceQuery(new BBox(-25, 35, 45, 72), new \DateTimeImmutable('2026-10-01'), new \DateTimeImmutable('2026-10-07'), limit: 3);
        $page = $source->occurrences($query);

        self::assertCount(3, $page);
        self::assertSame('4', $page->next, 'more than three answered: the next starts at the fourth');
        $source->occurrences($query->with(['cursor' => $page->next]));
        self::assertSame(['4', '4'], [$this->calls[0]['limit'], $this->calls[1]['offset']]);
        self::assertArrayNotHasKey('offset', $this->calls[0], 'the first page from the first event');
        self::assertArrayNotHasKey('orderby', $this->calls[0], 'the service\'s own order: the newest first');
        self::assertArrayNotHasKey('minmagnitude', $this->calls[0], 'every magnitude unless configured');
    }

    public function testAroundAPointTheQuerysRadiusAnOpenPeriodAndTheFilters(): void
    {
        $this->source(null, ['min_magnitude' => 2.5])->occurrences(new OccurrenceQuery(new Point(35.6812, 139.7671), from: new \DateTimeImmutable('2026-10-01T12:30:00'), filters: [OccurrenceQuery::MAX_MAGNITUDE => 6, OccurrenceQuery::KIND => ['earthquake', 'quarry blast'], 'alert' => 'Red'], radius: 50, sort: OccurrenceQuery::OLDEST));
        $this->source()->occurrences(new OccurrenceQuery());

        self::assertSame(['format' => 'geojson', 'orderby' => 'time-asc', 'starttime' => '2026-10-01T12:30:00', 'limit' => '26', 'maxmagnitude' => '6', 'eventtype' => 'earthquake,quarry blast', 'alertlevel' => 'red', 'latitude' => '35.6812', 'longitude' => '139.7671', 'maxradiuskm' => '50', 'minmagnitude' => '2.5'], $this->calls[0], 'no end: up to now');
        self::assertSame(['format' => 'geojson', 'starttime' => '0001-01-01T00:00:00', 'limit' => '26'], $this->calls[1], 'no start: the whole catalogue, not the service\'s 30 days; no place: the world');
    }

    public function testWhatOccurrencesRefuseBeforeAnyCall(): void
    {
        $refused = [
            'a filter of another domain' => [new OccurrenceQuery(filters: [OccurrenceQuery::TAXON => '4CGXP']), NotSupportedException::class],
            'a sort it does not know' => [new OccurrenceQuery(sort: 'depth'), NotSupportedException::class],
            'an alert level PAGER does not have' => [new OccurrenceQuery(filters: ['alert' => 'purple']), \InvalidArgumentException::class],
            'a magnitude that is no number' => [new OccurrenceQuery(filters: [OccurrenceQuery::MIN_MAGNITUDE => 'strong']), \InvalidArgumentException::class],
        ];
        foreach ($refused as $what => [$query, $exception]) {
            try {
                $this->source()->occurrences($query);
                self::fail($what);
            } catch (\Exception $e) {
                self::assertInstanceOf($exception, $e, $what);
            }
        }
        self::assertSame([], $this->calls);
    }

    public function testEventsIsTheFirstPageOfOccurrencesDeprecated(): void
    {
        $this->expectUserDeprecationMessageMatches('~^UsgsSource::events\(\) is deprecated since omnilab/usgs 1\.x: use occurrences\(~');
        $events = $this->source()->events(new BBox(-25, 35, 45, 72), new \DateTimeImmutable('2026-10-01'), new \DateTimeImmutable('2026-10-07'), 4.0, 5, 'magnitude');

        self::assertSame(['format' => 'geojson', 'orderby' => 'magnitude', 'starttime' => '2026-10-01T00:00:00', 'endtime' => '2026-10-08T00:00:00', 'limit' => '6', 'minlatitude' => '35', 'maxlatitude' => '72', 'minlongitude' => '-25', 'maxlongitude' => '45', 'minmagnitude' => '4'], $this->calls[0]);
        self::assertCount(5, $events);
        self::assertSame('M 5.0 - 21 km ESE of Feke, Turkey', $events[0]->label);
    }

    public function testABoxAcrossTheAntimeridian(): void
    {
        $this->source()->occurrences(new OccurrenceQuery(new BBox(170, -50, -170, -30)));

        self::assertSame(['170', '190'], [$this->calls[0]['minlongitude'], $this->calls[0]['maxlongitude']]);
    }

    public function testErrorsMapTheSameWayAsEverySource(): void
    {
        try {
            $this->source(new MockResponse("Error 400: Bad Request\n\n20001 matching events exceeds search limit of 20000. Modify the search to match fewer events.\n", ['http_code' => 400]))->series(new SeriesQuery(['count'], new BBox(-180, -90, 180, 90)));
            self::fail('a 400 is a ProviderException');
        } catch (ProviderException $e) {
            self::assertStringContainsString('exceeds search limit of 20000', $e->getMessage());
        }
        try {
            $this->source(new MockResponse('', ['http_code' => 503]))->entity('us7000pn9s');
            self::fail('a 503 is an UnavailableException');
        } catch (UnavailableException $e) {
            self::assertSame(503, $e->status);
        }
        $this->expectException(RateLimitedException::class);
        $this->source(new MockResponse('', ['http_code' => 429]))->entity('us7000pn9s');
    }

    public function testWhatItCanDo(): void
    {
        self::assertSame([Capability::ENTITY, Capability::SERIES, Capability::OCCURRENCES, Capability::HISTORICAL, Capability::AREA], $this->source()->capabilities());
        $this->expectException(NotSupportedException::class);
        $this->source()->search(new Query('Mandalay'));
    }
}
