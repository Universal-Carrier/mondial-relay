<?php declare(strict_types=1);

namespace UniversalCarrier\MondialRelay\Tests\Conformance;

use PHPUnit\Framework\Attributes\CoversNothing;
use UniversalCarrier\Conformance\CarrierConformanceTestCase;
use UniversalCarrier\Core\Contract\CarrierInterface;
use UniversalCarrier\MondialRelay\Tests\Support\Fixtures;

#[CoversNothing]
class MondialRelayCarrierConformanceTest extends CarrierConformanceTestCase
{
    protected function createCarrier(): CarrierInterface
    {
        return Fixtures::carrier();
    }
}
