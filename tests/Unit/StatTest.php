<?php declare(strict_types=1);

namespace UniversalCarrier\MondialRelay\Tests\Unit;

use PHPUnit\Framework\Attributes\{CoversClass, DataProvider};
use PHPUnit\Framework\TestCase;
use UniversalCarrier\Core\Exception\{ConnectionExceptionInterface, ShipmentExceptionInterface};
use UniversalCarrier\MondialRelay\Stat;

#[CoversClass(Stat::class)]
class StatTest extends TestCase
{
    /**
     * @return iterable<string, array{int}>
     */
    public static function accountOrServiceProblems(): iterable
    {
        yield 'incorrect merchant' => [1];
        yield 'incorrect hash' => [8];
        yield 'not enough credit' => [25];
        yield 'account not activated' => [95];
        yield 'incorrect security key' => [97];
        yield 'generic service error' => [99];
    }

    #[DataProvider('accountOrServiceProblems')]
    public function testProblemsOutsideTheRequestAreConnectionErrors(int $stat): void
    {
        $this->assertInstanceOf(ConnectionExceptionInterface::class, Stat::toException($stat, 'test'));
    }

    public function testARejectedRequestIsAShipmentError(): void
    {
        $exception = Stat::toException(36, 'shipment creation');

        $this->assertInstanceOf(ShipmentExceptionInterface::class, $exception);
        $this->assertSame(36, $exception->stat);
        $this->assertSame('Mondial Relay shipment creation failed with STAT 36: Incorrect postal code.', $exception->getMessage());
    }

    public function testAnUnknownStatIsStillDescribed(): void
    {
        $this->assertSame('Unknown status 1234', Stat::message(1234));
    }
}
