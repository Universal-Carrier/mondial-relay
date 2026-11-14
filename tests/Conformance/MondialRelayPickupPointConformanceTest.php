<?php declare(strict_types=1);

namespace UniversalCarrier\MondialRelay\Tests\Conformance;

use PHPUnit\Framework\Attributes\CoversNothing;
use UniversalCarrier\Conformance\PickupPointProviderConformanceTestCase;
use UniversalCarrier\Core\Contract\PickupPointProviderInterface;
use UniversalCarrier\Core\Dto\PickupPointQuery;
use UniversalCarrier\MondialRelay\Tests\Support\Fixtures;

/**
 * Against recorded answers: see tests/Live for the same suite against the real API.
 */
#[CoversNothing]
class MondialRelayPickupPointConformanceTest extends PickupPointProviderConformanceTestCase
{
    protected function createCarrier(): PickupPointProviderInterface
    {
        return Fixtures::carrier();
    }

    protected function searchableQuery(): PickupPointQuery
    {
        return PickupPointQuery::forAddress(Fixtures::customer());
    }

    protected function unmatchedQuery(): PickupPointQuery
    {
        return PickupPointQuery::forAddress(Fixtures::customer('99999'));
    }
}
