<?php declare(strict_types=1);

namespace UniversalCarrier\MondialRelay\Tests\Support;

use Closure;
use SoapClient;
use UniversalCarrier\MondialRelay\Soap\MondialRelayClient;

/**
 * A SoapClient answering from recorded envelopes instead of the network.
 *
 * Only the transport is replaced: requests are still built, and answers still decoded, by
 * ext-soap from the real WSDL - so the tests exercise exactly what production does with
 * Mondial Relay's XML.
 */
final class ReplaySoapClient extends SoapClient
{

    /** @var list<array{method: string, parameters: array<string, string>}> */
    public array $calls = [];

    /**
     * @param Closure(string, array<string, string>): string $answer Given the method and its
     *   parameters, returns the name of the fixture to answer with.
     */
    public function __construct(private readonly Closure $answer)
    {
        parent::__construct(MondialRelayClient::WSDL, MondialRelayClient::options());
    }

    public function __doRequest(string $request, string $location, string $action, int $version, bool $oneWay = false, ?string $uriParserClass = null): string
    {
        $method = substr($action, (int)strrpos($action, '/') + 1);
        $parameters = [];

        // Parameters in the order they were serialised, empty ones included.
        preg_match_all('#<ns1:(\w+)>([^<]*)</ns1:\1>|<ns1:(\w+)/>#', $request, $matches, PREG_SET_ORDER | PREG_UNMATCHED_AS_NULL);

        foreach ($matches as [, $name, $value, $emptyName]) {
            if ($emptyName !== null) {
                $parameters[$emptyName] = '';
            } elseif ($name !== null && $name !== $method) {
                $parameters[$name] = html_entity_decode((string)$value, ENT_XML1);
            }
        }

        $this->calls[] = ['method' => $method, 'parameters' => $parameters];
        $fixture = ($this->answer)($method, $parameters);

        return (string)file_get_contents(__DIR__ . '/../Fixtures/' . $fixture);
    }

    /**
     * @return array<string, string>
     */
    public function lastParameters(): array
    {
        return $this->calls[array_key_last($this->calls) ?? 0]['parameters'] ?? [];
    }
}
