<?php

namespace Omnilab\Usgs;

use Omnilab\Config;
use Omnilab\Source\SourceFactory;
use Omnilab\Source\SourceInterface;
use Symfony\Component\HttpClient\HttpClient;

/**
 * The USGS earthquake catalogue: an event by its id, and the earthquakes
 * of a place and a period as series or as a list - no key needed.
 *
 *   options:
 *     mailto: 'support@glitchr.io'     # in the User-Agent
 *     radius_km: 100                   # the circle around a Point, for series() and events()
 *     min_magnitude: null              # the smallest magnitude counted (null: every event, down to the microquakes some networks report)
 *     throttle: 0                      # seconds between two calls: the USGS publishes no figure
 *     base_uri: 'https://earthquake.usgs.gov/fdsnws/event/1/'
 */
final class UsgsSourceFactory extends SourceFactory
{
    protected function populate(Config $c): void
    {
        $c->defaults([
            'omnilab.factory_name' => 'usgs',
            'omnilab.required_options' => [],
            'radius_km' => 100.0,
            'min_magnitude' => null,
            'throttle' => 0.0,
            'base_uri' => UsgsSource::BASE_URI,
        ]);
    }

    protected function build(Config $c): SourceInterface
    {
        $minimum = $c->get('min_magnitude');

        return new UsgsSource(
            $this->http ?? HttpClient::create(),
            (float) $c->get('radius_km'),
            null === $minimum || '' === $minimum ? null : (float) $minimum,
            (string) $c->get('base_uri', UsgsSource::BASE_URI),
            ['User-Agent' => self::userAgent($c)],
            (float) $c->get('throttle'),
        );
    }
}
