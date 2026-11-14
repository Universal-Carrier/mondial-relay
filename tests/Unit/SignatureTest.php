<?php declare(strict_types=1);

namespace UniversalCarrier\MondialRelay\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use UniversalCarrier\Core\Dto\PickupPointQuery;
use UniversalCarrier\MondialRelay\Soap\MondialRelayClient;
use UniversalCarrier\MondialRelay\Tests\Support\Fixtures;

#[CoversClass(MondialRelayClient::class)]
class SignatureTest extends TestCase
{
    public function testTheSignatureCoversTheParametersInTheOrderTheyAreSent(): void
    {
        $soap = Fixtures::recorded();
        Fixtures::carrier($soap)->getPickupPoints(PickupPointQuery::forAddress(Fixtures::customer()));

        // Read back from the XML actually sent: ext-soap must not have reordered anything.
        $sent = $soap->lastParameters();
        $security = $sent['Security'];
        unset($sent['Security']);

        $this->assertSame('Enseigne', array_key_first($sent));
        $this->assertSame('BDTEST13', $sent['Enseigne']);
        $this->assertSame(strtoupper(md5(implode('', $sent) . 'TestAPI1key')), $security);
    }

    public function testTheEndpointIsCalledOverHttpsWhateverTheWsdlSays(): void
    {
        $this->assertStringContainsString('http://api.mondialrelay.com', (string)file_get_contents(MondialRelayClient::WSDL));
        $this->assertSame('https://api.mondialrelay.com/Web_Services.asmx', MondialRelayClient::options()['location']);
    }
}
