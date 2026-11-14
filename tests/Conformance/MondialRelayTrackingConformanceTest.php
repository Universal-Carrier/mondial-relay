<?php declare(strict_types=1);

namespace UniversalCarrier\MondialRelay\Tests\Conformance;

use PHPUnit\Framework\Attributes\CoversNothing;
use UniversalCarrier\Conformance\TrackingProviderConformanceTestCase;
use UniversalCarrier\Core\Contract\TrackingProviderInterface;
use UniversalCarrier\MondialRelay\Tests\Support\Fixtures;

#[CoversNothing]
class MondialRelayTrackingConformanceTest extends TrackingProviderConformanceTestCase
{
    protected function createCarrier(): TrackingProviderInterface
    {
        return Fixtures::carrier();
    }

    protected function trackedNumber(): string
    {
        return '01225747';
    }

    protected function unscannedNumber(): string
    {
        return '99999999';
    }

    protected function malformedNumber(): string
    {
        return 'ABC';
    }
}
