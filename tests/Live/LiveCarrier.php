<?php declare(strict_types=1);

namespace UniversalCarrier\MondialRelay\Tests\Live;

use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use UniversalCarrier\MondialRelay\MondialRelayCarrier;
use UniversalCarrier\MondialRelay\Tests\Support\FakeHttpClient;

/**
 * A driver talking to the real API, configured from the environment.
 *
 * These tests only read - pickup point searches and tracking - and never book: they are safe
 * to run against a production account.
 *
 *     MONDIAL_RELAY_ENSEIGNE=... MONDIAL_RELAY_PRIVATE_KEY=... \
 *     MONDIAL_RELAY_TRACKING_NUMBER=... vendor/bin/phpunit --testsuite Live
 */
final class LiveCarrier
{

    public static function create(): MondialRelayCarrier
    {
        $enseigne = getenv('MONDIAL_RELAY_ENSEIGNE');
        $privateKey = getenv('MONDIAL_RELAY_PRIVATE_KEY');

        if (!is_string($enseigne) || $enseigne === '' || !is_string($privateKey) || $privateKey === '') {
            TestCase::markTestSkipped('Set MONDIAL_RELAY_ENSEIGNE and MONDIAL_RELAY_PRIVATE_KEY to run against the real API.');
        }

        return new MondialRelayCarrier($enseigne, $privateKey, new FakeHttpClient(), new Psr17Factory());
    }

    public static function trackingNumber(): string
    {
        $number = getenv('MONDIAL_RELAY_TRACKING_NUMBER');

        return is_string($number) && $number !== ''
            ? $number
            : TestCase::markTestSkipped('Set MONDIAL_RELAY_TRACKING_NUMBER to a parcel of this account.');
    }
}
