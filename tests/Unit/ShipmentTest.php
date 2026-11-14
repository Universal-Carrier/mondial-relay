<?php declare(strict_types=1);

namespace UniversalCarrier\MondialRelay\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use UniversalCarrier\Core\Dto\{Address, Parcel, ParcelItem, Price, ShipmentRequest};
use UniversalCarrier\Core\Enum\{LabelFormat, LabelSize};
use UniversalCarrier\Core\Exception\{ConnectionExceptionInterface, InvalidArgumentException, UnsupportedOptionExceptionInterface};
use UniversalCarrier\MondialRelay\{LabelDownloader, MondialRelayCarrier};
use UniversalCarrier\MondialRelay\Exception\{MondialRelayLabelException, MondialRelayShipmentException};
use UniversalCarrier\MondialRelay\Mapping\RequestMapper;
use UniversalCarrier\MondialRelay\Tests\Support\{FakeHttpClient, Fixtures};

#[CoversClass(MondialRelayCarrier::class)]
#[CoversClass(RequestMapper::class)]
#[CoversClass(LabelDownloader::class)]
class ShipmentTest extends TestCase
{
    public function testAddressesAreSentTheWayMondialRelayAcceptsThem(): void
    {
        $soap = Fixtures::recorded();
        Fixtures::carrier($soap)->createShipment(Fixtures::homeDelivery());

        $sent = $soap->lastParameters();

        $this->assertSame([
            'ModeCol' => 'CCC',
            'ModeLiv' => 'HOM',
            'NDossier' => 'ORD-1001',
            'Expe_Langage' => 'FR',
            'Expe_Ad1' => 'ACME SAS',
            'Expe_Ad2' => 'JEAN DUPONT',
            'Expe_Ad3' => '51 AVENUE DES CHAMPS-ELYSEES',
            'Expe_Tel1' => '+33123456789',
            'Dest_Ad1' => 'JEANNE MARTIN',
            'Dest_Ad2' => '',
            'Dest_Ad3' => "12 RUE DE L'HOTEL DE VILLE",
            'Dest_Ad4' => 'BATIMENT B',
            'Dest_CP' => '75004',
            'Dest_Tel1' => '0612345678',
            'Poids' => '1450',
            'NbColis' => '1',
            'LIV_Rel_Pays' => '',
            'LIV_Rel' => '',
        ], array_intersect_key($sent, array_flip([
            'ModeCol', 'ModeLiv', 'NDossier', 'Expe_Langage', 'Expe_Ad1', 'Expe_Ad2', 'Expe_Ad3', 'Expe_Tel1',
            'Dest_Ad1', 'Dest_Ad2', 'Dest_Ad3', 'Dest_Ad4', 'Dest_CP', 'Dest_Tel1', 'Poids', 'NbColis', 'LIV_Rel_Pays', 'LIV_Rel',
        ])));
    }

    public function testReferencesAreCutToWhatMondialRelayStores(): void
    {
        $soap = Fixtures::recorded();
        Fixtures::carrier($soap)->createShipment(new ShipmentRequest(
            'HOM',
            Fixtures::merchant(),
            Fixtures::customer(),
            [new Parcel(1.0)],
            'order-2026-000123456',
            customerReference: 'customer#42-B'
        ));

        $this->assertSame('ORDER-2026-0001', $soap->lastParameters()['NDossier']);
        $this->assertSame('CUSTOMER', $soap->lastParameters()['NClient']);
    }

    public function testAPickupPointIsSentAsCountryAndNumber(): void
    {
        $soap = Fixtures::recorded();
        Fixtures::carrier($soap, dropOffPointId: 'FR-016484')->createShipment(Fixtures::pickupPointDelivery());

        $sent = $soap->lastParameters();

        $this->assertSame(['REL', 'FR', '016484', 'FR', '035528'], [$sent['ModeCol'], $sent['COL_Rel_Pays'], $sent['COL_Rel'], $sent['LIV_Rel_Pays'], $sent['LIV_Rel']]);
    }

    public function testTheLabelIsDownloadedInTheConfiguredSize(): void
    {
        $http = new FakeHttpClient();
        $response = Fixtures::carrier(http: $http, labelSize: LabelSize::ROLL_100X150)->createShipment(Fixtures::homeDelivery());
        $package = $response->getFirstPackage();

        $this->assertSame('01225747', $response->shipmentId);
        $this->assertSame('01225747', $package->trackingNumber);
        $this->assertSame('BOX-1', $package->parcelReference);
        $this->assertSame(LabelFormat::PDF, $package->label->format);
        $this->assertSame(LabelSize::ROLL_100X150, $package->label->size);
        $this->assertStringStartsWith('%PDF', $package->label->content);
        $this->assertStringStartsWith('https://www.mondialrelay.fr/StickersProxy/', $http->requestedUrls[0]);
        $this->assertStringContainsString('format=10x15', $http->requestedUrls[0]);
    }

    public function testTheMetadataKeepsTheLabelUrl(): void
    {
        $metadata = Fixtures::carrier()->createShipment(Fixtures::homeDelivery())->metadata;

        // Metadata values are mixed: narrowed before being read, as any consumer has to.
        $url = $metadata['mondial_relay.label_url'] ?? null;

        $this->assertIsString($url);
        $this->assertStringStartsWith('https://www.mondialrelay.fr/StickersProxy/', $url);
        $this->assertStringContainsString('format=A4', $url);
    }

    public function testATemporaryDownloadFailureIsRetried(): void
    {
        $http = new FakeHttpClient([[503, 'Service Unavailable'], [502, 'Bad Gateway']]);
        $response = Fixtures::carrier(http: $http)->createShipment(Fixtures::homeDelivery());

        $this->assertCount(3, $http->requestedUrls);
        $this->assertStringStartsWith('%PDF', $response->getFirstPackage()->label->content);
    }

    public function testALabelThatCannotBeFetchedFailsTheCallWithoutLosingTheShipment(): void
    {
        $http = new FakeHttpClient([[503, 'Service Unavailable']], forever: true);

        try {
            Fixtures::carrier(http: $http)->createShipment(Fixtures::homeDelivery());
            $this->fail('A response without its label must not be returned.');
        } catch (MondialRelayLabelException $e) {
            $this->assertInstanceOf(ConnectionExceptionInterface::class, $e);
            $this->assertSame('01225747', $e->expeditionNumber);
            $this->assertStringContainsString('expedition=01225747', $e->labelUrl);
            $this->assertStringContainsString('Do not book it again', $e->getMessage());
            $this->assertCount(3, $http->requestedUrls);
        }
    }

    public function testABrokenLabelLinkIsNotRetried(): void
    {
        $http = new FakeHttpClient([[500, '<!DOCTYPE html><title>(404) Introuvable</title>']], forever: true);

        try {
            Fixtures::carrier(http: $http)->createShipment(Fixtures::homeDelivery());
            $this->fail('A response without its label must not be returned.');
        } catch (MondialRelayLabelException $e) {
            $this->assertSame('01225747', $e->expeditionNumber);
            $this->assertCount(1, $http->requestedUrls);
        }
    }

    public function testARejectionCarriesMondialRelaysStatus(): void
    {
        try {
            Fixtures::carrier()->createShipment(new ShipmentRequest('HOM', Fixtures::merchant(), Fixtures::customer('00000'), [new Parcel(1.0)], 'ORD-1'));
            $this->fail('A rejected shipment must throw.');
        } catch (MondialRelayShipmentException $e) {
            $this->assertSame(36, $e->stat);
            $this->assertStringContainsString('Incorrect postal code', $e->getMessage());
        }
    }

    public function testNoOptionIsSupported(): void
    {
        $carrier = Fixtures::carrier();

        foreach ([
            new ShipmentRequest('HOM', Fixtures::merchant(), Fixtures::customer(), [new Parcel(1.0), new Parcel(2.0)], 'O'),
            new ShipmentRequest('HOM', Fixtures::merchant(), Fixtures::customer(), [new Parcel(1.0, insuranceValue: new Price(5000))], 'O'),
            new ShipmentRequest('HOM', Fixtures::merchant(), Fixtures::customer('97110'), [new Parcel(1.0, items: [new ParcelItem('Book', 1, 0.5, new Price(1500))])], 'O'),
        ] as $request) {
            try {
                $carrier->createShipment($request);
                $this->fail('An unsupported option must be refused.');
            } catch (UnsupportedOptionExceptionInterface) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testItemsWithinTheEuCustomsTerritoryAreNotAnObstacle(): void
    {
        $request = new ShipmentRequest(
            'HOM',
            Fixtures::merchant(),
            new Address('Rue Neuve 1', '1000', 'Bruxelles', 'BE', firstName: 'Anne', lastName: 'Peeters'),
            [new Parcel(1.0, items: [new ParcelItem('Book', 1, 0.5, new Price(1500))])],
            'O'
        );

        $this->assertSame(1, Fixtures::carrier()->createShipment($request)->getPackageCount());
    }

    public function testOnlyThePdfSizesMondialRelayRendersAreAccepted(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Fixtures::carrier(labelSize: LabelSize::A6);
    }
}
