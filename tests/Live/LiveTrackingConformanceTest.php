<?php declare(strict_types=1);

namespace UniversalCarrier\MondialRelay\Tests\Live;

use PHPUnit\Framework\Attributes\{CoversNothing, Group};
use UniversalCarrier\Conformance\TrackingProviderConformanceTestCase;
use UniversalCarrier\Core\Contract\TrackingProviderInterface;

#[CoversNothing]
#[Group('live')]
class LiveTrackingConformanceTest extends TrackingProviderConformanceTestCase
{
    protected function createCarrier(): TrackingProviderInterface
    {
        return LiveCarrier::create();
    }

    protected function trackedNumber(): string
    {
        return LiveCarrier::trackingNumber();
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
