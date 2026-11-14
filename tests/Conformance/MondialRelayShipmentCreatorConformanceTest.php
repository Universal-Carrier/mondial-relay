<?php declare(strict_types=1);

namespace UniversalCarrier\MondialRelay\Tests\Conformance;

use PHPUnit\Framework\Attributes\CoversNothing;
use UniversalCarrier\Conformance\ShipmentCreatorConformanceTestCase;
use UniversalCarrier\Core\Contract\ShipmentCreatorInterface;
use UniversalCarrier\Core\Dto\{Parcel, ParcelItem, Price, ShipmentRequest};
use UniversalCarrier\MondialRelay\Tests\Support\Fixtures;

/**
 * Against recorded answers only: booking is never exercised against the live API.
 */
#[CoversNothing]
class MondialRelayShipmentCreatorConformanceTest extends ShipmentCreatorConformanceTestCase
{
    protected function createCarrier(): ShipmentCreatorInterface
    {
        return Fixtures::carrier();
    }

    protected function bookableRequest(): ShipmentRequest
    {
        return Fixtures::homeDelivery();
    }

    protected function pickupPointRequest(): ShipmentRequest
    {
        return Fixtures::pickupPointDelivery();
    }

    protected function customsRequest(): ShipmentRequest
    {
        return new ShipmentRequest(
            'HOM',
            Fixtures::merchant(),
            Fixtures::customer('97110'),
            [new Parcel(1.0, items: [new ParcelItem('Book', 1, 0.5, new Price(1500))])],
            'ORD-1003'
        );
    }
}
