<?php declare(strict_types=1);

namespace UniversalCarrier\MondialRelay\Tests\Live;

use PHPUnit\Framework\Attributes\{CoversNothing, Group};
use UniversalCarrier\Conformance\PickupPointProviderConformanceTestCase;
use UniversalCarrier\Core\Contract\PickupPointProviderInterface;
use UniversalCarrier\Core\Dto\{Address, PickupPointQuery};

#[CoversNothing]
#[Group('live')]
class LivePickupPointConformanceTest extends PickupPointProviderConformanceTestCase
{
    protected function createCarrier(): PickupPointProviderInterface
    {
        return LiveCarrier::create();
    }

    protected function searchableQuery(): PickupPointQuery
    {
        return PickupPointQuery::forAddress(new Address('12 rue de Rivoli', '75004', 'Paris', 'FR', company: 'Test'));
    }

    protected function unmatchedQuery(): PickupPointQuery
    {
        return PickupPointQuery::forAddress(new Address('1-1 Marunouchi', '1000005', 'Tokyo', 'JP', company: 'Test'));
    }
}
