<?php declare(strict_types=1);

namespace UniversalCarrier\MondialRelay\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use UniversalCarrier\Core\Dto\TrackingCheckpoint;
use UniversalCarrier\Core\Enum\TrackingStatus;
use UniversalCarrier\Core\Exception\ShipmentExceptionInterface;
use UniversalCarrier\MondialRelay\Mapping\ResponseMapper;
use UniversalCarrier\MondialRelay\MondialRelayCarrier;
use UniversalCarrier\MondialRelay\Soap\Result;
use UniversalCarrier\MondialRelay\Tests\Support\Fixtures;

#[CoversClass(MondialRelayCarrier::class)]
#[CoversClass(ResponseMapper::class)]
class TrackingTest extends TestCase
{
    /**
     * @param list<array{string, string, string}> $events Label, date, time.
     */
    private function answer(int $stat, array $events, string $current = ''): Result
    {
        return new Result((object)[
            'STAT' => (string)$stat,
            'Libelle02' => $current,
            'Tracing' => (object)['ret_WSI2_sub_TracingColisDetaille' => array_map(
                static fn (array $e): object => (object)['Libelle' => $e[0], 'Date' => $e[1], 'Heure' => $e[2], 'Emplacement' => '', 'Relais_Num' => '', 'Relais_Pays' => ''],
                [...$events, ['', '', '']]
            )],
        ], 'test');
    }

    public function testARecordedParcelIsPending(): void
    {
        $tracking = Fixtures::carrier()->track('01225747');

        $this->assertSame('01225747', $tracking->trackingNumber);
        $this->assertSame(TrackingStatus::PENDING, $tracking->status);
        $this->assertSame('Colis en cours de préparation', $tracking->description);
        $this->assertCount(1, $tracking->checkpoints, 'The padding rows are dropped.');
        $this->assertSame('RÉCEPTION DES DONNÉES', $tracking->checkpoints[0]->description);
        $this->assertSame('2026-06-20T17:04:00+02:00', $tracking->checkpoints[0]->at->format(DATE_ATOM));
    }

    /**
     * The full history of a parcel dropped off in Bordeaux and collected in Cucq, recorded
     * from the live API. It is the reference for the label table: every status below comes
     * from a wording Mondial Relay really sends.
     */
    public function testADeliveredParcelReadsAsADeliveryEndToEnd(): void
    {
        $tracking = Fixtures::carrier()->track('01828410');

        $this->assertSame(TrackingStatus::DELIVERED, $tracking->status);
        $this->assertTrue($tracking->isFinal());
        $this->assertSame('COLIS LIVRÉ', $tracking->description);
        $this->assertSame('Point Relais FR-026595', $tracking->location, 'Delivery happens at the pickup point, not at the hub that scanned it.');
        $this->assertCount(20, $tracking->checkpoints, 'The thirty padding rows are dropped.');

        $this->assertSame(
            [
                TrackingStatus::PENDING,             // RÉCEPTION DES DONNÉES
                TrackingStatus::PICKED_UP,           // PRISE EN CHARGE POINT RELAIS
                TrackingStatus::PICKED_UP,           // AVISAGE PAR EMAIL - a notification
                TrackingStatus::IN_TRANSIT,          // PRISE EN CHARGE POINT RELAIS, again
                TrackingStatus::IN_TRANSIT,          // Colis pris en charge sur le si[te]
                TrackingStatus::IN_TRANSIT,          // PRISE EN CHARGE PAR LE TRANSPO[RTEUR]
                TrackingStatus::IN_TRANSIT,          // EXPEDIE VERS LE HUB
                TrackingStatus::IN_TRANSIT,
                TrackingStatus::IN_TRANSIT,
                TrackingStatus::IN_TRANSIT,
                TrackingStatus::IN_TRANSIT,
                TrackingStatus::IN_TRANSIT,
                TrackingStatus::IN_TRANSIT,
                TrackingStatus::IN_TRANSIT,
                TrackingStatus::OUT_FOR_DELIVERY,    // EN COURS DE LIVRAISON
                TrackingStatus::IN_TRANSIT,          // COLIS REMIS AU TRANSPORTEUR
                TrackingStatus::OUT_FOR_DELIVERY,
                TrackingStatus::AVAILABLE_FOR_PICKUP, // DISPONIBLE AU POINT RELAIS
                TrackingStatus::AVAILABLE_FOR_PICKUP, // AVISAGE PAR EMAIL - still at the point
                TrackingStatus::DELIVERED,            // COLIS LIVRÉ
            ],
            array_map(static fn (TrackingCheckpoint $c): TrackingStatus => $c->status, $tracking->checkpoints)
        );

        $this->assertSame('2026-07-28T10:23:00+02:00', $tracking->checkpoints[0]->at->format(DATE_ATOM));
        $this->assertSame('AGEN', $tracking->checkpoints[4]->location, 'A hub scan names the site.');
        $this->assertSame(TrackingStatus::DELIVERED, $tracking->getLatestCheckpoint()?->status);
    }

    public function testAnUnknownNumberIsPendingAndAMalformedOneIsRejected(): void
    {
        $carrier = Fixtures::carrier();

        $this->assertSame(TrackingStatus::PENDING, $carrier->track('99999999')->status);

        $this->expectException(ShipmentExceptionInterface::class);

        $carrier->track('ABC');
    }

    public function testInProcessIsRefinedByTheLatestEvent(): void
    {
        $tracking = (new ResponseMapper())->tracking($this->answer(81, [
            ['RÉCEPTION DES DONNÉES', '20/06/26', '17:04'],
            ['PRIS EN CHARGE EN AGENCE', '21/06/26', '09:12'],
            ['COLIS DISPONIBLE AU POINT RELAIS', '23/06/26', '10:40'],
        ]), '01225747');

        $this->assertSame(TrackingStatus::AVAILABLE_FOR_PICKUP, $tracking->status);
        $this->assertSame(
            [TrackingStatus::PENDING, TrackingStatus::PICKED_UP, TrackingStatus::AVAILABLE_FOR_PICKUP],
            array_map(static fn (TrackingCheckpoint $c): TrackingStatus => $c->status, $tracking->checkpoints)
        );
    }

    public function testInProcessStaysInTransitWhenTheLatestEventSaysNoMore(): void
    {
        $tracking = (new ResponseMapper())->tracking($this->answer(81, [
            ['RÉCEPTION DES DONNÉES', '20/06/26', '17:04'],
        ]), '01225747');

        $this->assertSame(TrackingStatus::IN_TRANSIT, $tracking->status);
    }

    public function testAReturnIsReportedAsSoonAsItIsDecided(): void
    {
        $tracking = (new ResponseMapper())->tracking($this->answer(83, [
            ['COLIS NON RÉCLAMÉ, RETOUR À L\'EXPÉDITEUR', '02/07/26', '08:00'],
        ]), '01225747');

        $this->assertSame(TrackingStatus::RETURNED, $tracking->status);
    }

    /**
     * The five stages the Web Service documentation names (V5.14, "Tracing des colis"). The
     * API sends finer events than these, but a driver that cannot read the documented
     * wordings reads nothing.
     */
    public function testTheFiveDocumentedStagesAreMapped(): void
    {
        $tracking = (new ResponseMapper())->tracking($this->answer(81, [
            ['Annonce expédition', '20/06/26', '17:04'],
            ['Prise en charge dans le réseau Mondial Relay', '21/06/26', '09:12'],
            ['Mise en tournée', '23/06/26', '06:30'],
            ['Disponible au Point Relais®', '23/06/26', '10:40'],
            ['Livré', '24/06/26', '11:02'],
        ]), '01225747');

        $this->assertSame(
            [
                TrackingStatus::PENDING,
                TrackingStatus::PICKED_UP,
                TrackingStatus::OUT_FOR_DELIVERY,
                TrackingStatus::AVAILABLE_FOR_PICKUP,
                TrackingStatus::DELIVERED,
            ],
            array_map(static fn (TrackingCheckpoint $c): TrackingStatus => $c->status, $tracking->checkpoints)
        );
    }

    public function testAnEventTheDriverCannotReadKeepsThePreviousStatus(): void
    {
        $tracking = (new ResponseMapper())->tracking($this->answer(81, [
            ['RÉCEPTION DES DONNÉES', '20/06/26', '17:04'],
            ['MISE EN TOURNÉE', '21/06/26', '06:30'],
            ['QUELQUE CHOSE D\'INÉDIT', '21/06/26', '09:12'],
        ]), '01225747');

        $this->assertSame(
            [TrackingStatus::PENDING, TrackingStatus::OUT_FOR_DELIVERY, TrackingStatus::OUT_FOR_DELIVERY],
            array_map(static fn (TrackingCheckpoint $c): TrackingStatus => $c->status, $tracking->checkpoints),
            'An unknown wording is a missing line in the table, not an incident on the parcel.'
        );

        $this->assertSame('QUELQUE CHOSE D\'INÉDIT', $tracking->checkpoints[2]->description, 'The carrier\'s wording is kept, so the gap can be seen.');
        $this->assertSame(TrackingStatus::OUT_FOR_DELIVERY, $tracking->status);
    }

    public function testAnUnreadableFirstEventIsPending(): void
    {
        $tracking = (new ResponseMapper())->tracking($this->answer(81, [
            ['QUELQUE CHOSE D\'INÉDIT', '21/06/26', '09:12'],
        ]), '01225747');

        $this->assertSame(TrackingStatus::PENDING, $tracking->checkpoints[0]->status, 'Nothing is known to be true yet.');
        $this->assertSame(TrackingStatus::IN_TRANSIT, $tracking->status, 'STAT 81 still says the parcel is in process.');
    }

    public function testDeliveredAndAnomalyComeFromTheStatus(): void
    {
        $mapper = new ResponseMapper();

        $this->assertSame(TrackingStatus::DELIVERED, $mapper->tracking($this->answer(82, []), '1')->status);
        $this->assertSame(TrackingStatus::EXCEPTION, $mapper->tracking($this->answer(83, []), '1')->status);
    }
}
