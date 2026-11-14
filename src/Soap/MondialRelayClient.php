<?php declare(strict_types=1);

namespace UniversalCarrier\MondialRelay\Soap;

use SensitiveParameter;
use SoapClient;
use SoapFault;
use UniversalCarrier\MondialRelay\Exception\{MondialRelayConnectionException, MondialRelayProtocolException};

/**
 * Calls Mondial Relay's SOAP Web Services and signs every request.
 *
 * Mondial Relay authenticates each call with a "Security" parameter: the uppercase MD5 of every
 * parameter value, in the order the WSDL declares them, followed by the merchant's private
 * key. Parameters are therefore passed as ordered arrays, and nothing may reorder them
 * between signing and sending.
 *
 * @internal
 */
final class MondialRelayClient
{

    /** A copy of Mondial Relay's WSDL, so that no call has to fetch it first. */
    public const string WSDL = __DIR__ . '/../../resources/Web_Services.wsdl';

    /** The WSDL declares a plain-http address; the signed requests carry customer data. */
    public const string ENDPOINT = 'https://api.mondialrelay.com/Web_Services.asmx';

    private ?SoapClient $soap;

    /**
     * @param SoapClient|null $soap A preconfigured client; built from {@see self::options()} when null.
     */
    public function __construct(
        private readonly string $enseigne,
        #[SensitiveParameter] private readonly string $privateKey,
        ?SoapClient $soap = null
    ) {
        $this->soap = $soap;
    }

    /**
     * The options any SoapClient talking to Mondial Relay should be built with.
     *
     * @return array<string, mixed>
     */
    public static function options(): array
    {
        return [
            'location' => self::ENDPOINT,
            'exceptions' => true,
            'trace' => false,
            'connection_timeout' => 10,
            'cache_wsdl' => WSDL_CACHE_MEMORY,
            'encoding' => 'UTF-8',
            // A list of one element would otherwise come back as the element itself.
            'features' => SOAP_SINGLE_ELEMENT_ARRAYS,
        ];
    }

    /**
     * @param string $method The Web Service, e.g. "WSI4_PointRelais_Recherche".
     * @param array<string, string> $parameters Every parameter but Enseigne and Security, in WSDL order.
     * @throws MondialRelayConnectionException When Mondial Relay could not be reached.
     * @throws MondialRelayProtocolException When the answer is not the expected envelope.
     */
    public function call(string $method, array $parameters): Result
    {
        $parameters = ['Enseigne' => $this->enseigne, ...$parameters];
        $parameters['Security'] = self::sign($parameters, $this->privateKey);

        try {
            $response = ($this->soap ??= new SoapClient(self::WSDL, self::options()))->__soapCall($method, [$parameters]);
        } catch (SoapFault $e) {
            throw new MondialRelayConnectionException(
                sprintf('Mondial Relay %s could not be reached: %s', $method, $e->getMessage()),
                previous: $e
            );
        }

        $result = is_object($response) ? ($response->{$method . 'Result'} ?? null) : null;

        return is_object($result)
            ? new Result($result, $method . 'Result')
            : throw new MondialRelayProtocolException(sprintf('Mondial Relay %s returned no result.', $method));
    }

    /**
     * @param array<string, string> $parameters Every parameter, Enseigne first, in WSDL order.
     */
    public static function sign(array $parameters, #[SensitiveParameter] string $privateKey): string
    {
        return strtoupper(md5(implode('', $parameters) . $privateKey));
    }
}
