<?php declare(strict_types=1);

namespace UniversalCarrier\MondialRelay\Tests\Unit;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use UniversalCarrier\Core\Dto\{Address, BusinessDay, PickupPoint, PickupPointQuery, TimeSlot};
use UniversalCarrier\Core\Enum\{DayOfWeek, PickupPointType};
use UniversalCarrier\MondialRelay\Mapping\{RequestMapper, ResponseMapper};
use UniversalCarrier\MondialRelay\MondialRelayCarrier;
use UniversalCarrier\MondialRelay\Tests\Support\Fixtures;

#[CoversClass(MondialRelayCarrier::class)]
#[CoversClass(RequestMapper::class)]
#[CoversClass(ResponseMapper::class)]
class PickupPointTest extends TestCase
{
    /**
     * @return list<PickupPoint>
     */
    private function paris(?PickupPointQuery $query = null): array
    {
        return array_values(Fixtures::carrier()->getPickupPoints($query ?? PickupPointQuery::forAddress(Fixtures::customer())));
    }

    private function hours(PickupPoint $point, DayOfWeek $day): string
    {
        $businessDay = $point->getBusinessDay($day);

        if ($businessDay === null) {
            return 'unknown';
        }

        return $businessDay->isClosed() ? 'closed' : implode(' ', array_map(
            static fn (TimeSlot $slot): string => $slot->open . '-' . $slot->close,
            $businessDay->timeSlots
        ));
    }

    public function testALockerIsMappedFromTheRecordedAnswer(): void
    {
        $locker = $this->paris()[0];

        $this->assertSame('FR-035528', $locker->id);
        $this->assertSame(PickupPointType::LOCKER, $locker->type);
        $this->assertSame('LOCKER 24/7 INDIGO PONT MARIE', $locker->name);
        $this->assertSame("48 RUE DE L'HOTEL DE VILLE", $locker->address1, 'Doubled apostrophes are undone.');
        $this->assertSame('Europe/Paris', $locker->timezone);
        $this->assertSame(48.8543157, $locker->latitude);
        $this->assertSame(2.3560894, $locker->longitude);
        $this->assertSame('00:01-23:59', $this->hours($locker, DayOfWeek::SUNDAY));
        $this->assertArrayHasKey('mondial_relay.photo_url', $locker->metadata);
    }

    public function testAShopHasItsLunchBreakAndItsClosedDays(): void
    {
        $shop = $this->paris()[1];

        $this->assertSame(PickupPointType::SHOP, $shop->type);
        $this->assertSame('09:30-13:00 15:30-19:00', $this->hours($shop, DayOfWeek::MONDAY));
        $this->assertSame('15:30-19:00', $this->hours($shop, DayOfWeek::SATURDAY));
        $this->assertSame('closed', $this->hours($shop, DayOfWeek::SUNDAY));
        $this->assertFalse($shop->isOpenAt(new DateTimeImmutable('2026-09-21 14:00', new DateTimeZone('Europe/Paris'))));
        $this->assertTrue($shop->isOpenAt(new DateTimeImmutable('2026-09-21 16:00', new DateTimeZone('Europe/Paris'))));
    }

    public function testTheSearchSendsThePostalCodeButNotTheCity(): void
    {
        $soap = Fixtures::recorded();
        Fixtures::carrier($soap)->getPickupPoints(new PickupPointQuery(address: Fixtures::customer(), weightInKilogram: 1.45, limit: 5));

        $sent = $soap->lastParameters();

        $this->assertSame(['FR', '75004', '', '1450', '5'], [$sent['Pays'], $sent['CP'], $sent['Ville'], $sent['Poids'], $sent['NombreResultats']]);
    }

    public function testCoordinatesAreSentWithADecimalPoint(): void
    {
        $soap = Fixtures::recorded();
        Fixtures::carrier($soap)->getPickupPoints(PickupPointQuery::forCoordinates(48.856614, 2.352222, 'FR'));

        $sent = $soap->lastParameters();

        $this->assertSame(['48.8566140', '2.3522220', ''], [$sent['Latitude'], $sent['Longitude'], $sent['CP']]);
    }

    public function testTypesAreFilteredByTheDriverOverAWiderSearch(): void
    {
        $soap = Fixtures::recorded();
        $points = Fixtures::carrier($soap)->getPickupPoints(new PickupPointQuery(
            address: Fixtures::customer('13001'),
            limit: 5,
            types: [PickupPointType::SHOP]
        ));

        $this->assertSame('30', $soap->lastParameters()['NombreResultats']);
        $this->assertCount(5, $points);
        $this->assertSame([PickupPointType::SHOP], array_values(array_unique(array_map(static fn (PickupPoint $p) => $p->type, $points), SORT_REGULAR)));
    }

    public function testTypesAndServicesMondialRelayDoesNotHaveAnswerEmptyWithoutACall(): void
    {
        $soap = Fixtures::recorded();
        $carrier = Fixtures::carrier($soap);

        $this->assertSame([], $carrier->getPickupPoints(new PickupPointQuery(address: Fixtures::customer(), types: [PickupPointType::POST_OFFICE])));
        $this->assertSame([], $carrier->getPickupPoints(new PickupPointQuery(address: Fixtures::customer(), serviceCode: 'HOM')));
        $this->assertSame([], $soap->calls);
    }

    public function testAnUnservedCountryAndAnEmptyAreaHaveNoPoint(): void
    {
        $carrier = Fixtures::carrier();

        $this->assertSame([], $carrier->getPickupPoints(PickupPointQuery::forAddress(new Address('1-1 Marunouchi', '1000005', 'Tokyo', 'JP', company: 'X'))));
        $this->assertSame([], $carrier->getPickupPoints(PickupPointQuery::forAddress(Fixtures::customer('99999'))));
    }

    public function testTheLimitIsAppliedEvenWhenMondialRelayReturnsMore(): void
    {
        $this->assertCount(2, $this->paris(new PickupPointQuery(address: Fixtures::customer(), limit: 2)));
    }

    public function testEveryDayIsKnown(): void
    {
        foreach ($this->paris() as $point) {
            $this->assertCount(7, $point->openingHours, $point->name);
            $this->assertContainsOnlyInstancesOf(BusinessDay::class, $point->openingHours);
        }
    }
}
