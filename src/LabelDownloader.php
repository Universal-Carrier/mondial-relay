<?php declare(strict_types=1);

namespace UniversalCarrier\MondialRelay;

use Psr\Http\Client\{ClientExceptionInterface, ClientInterface};
use Psr\Http\Message\RequestFactoryInterface;
use UniversalCarrier\Core\Enum\LabelSize;
use UniversalCarrier\MondialRelay\Exception\{MondialRelayConnectionException, MondialRelayProtocolException};

/**
 * Fetches the PDF behind the URL Mondial Relay returns when a shipment is created.
 *
 * The Web Service does not return the label itself, only a signed link to it, whose "format"
 * parameter selects the page size.
 *
 * @internal
 */
final readonly class LabelDownloader
{

    public const string BASE_URL = 'https://www.mondialrelay.fr';

    public function __construct(
        private ClientInterface $http,
        private RequestFactoryInterface $requests
    ) {}

    public static function url(string $path, LabelSize $size): string
    {
        $url = str_starts_with($path, 'http') ? $path : self::BASE_URL . $path;
        $format = match ($size) {
            LabelSize::A5 => 'A5',
            LabelSize::ROLL_100X150 => '10x15',
            default => 'A4',
        };

        return preg_replace('/([?&]format=)[^&]*/', '${1}' . $format, $url) ?? $url;
    }

    public function download(string $url): string
    {
        try {
            $response = $this->http->sendRequest($this->requests->createRequest('GET', $url));
        } catch (ClientExceptionInterface $e) {
            throw new MondialRelayConnectionException(sprintf('The Mondial Relay label could not be downloaded: %s', $e->getMessage()), previous: $e);
        }

        $content = (string)$response->getBody();

        if (in_array($response->getStatusCode(), [502, 503, 504], true)) {
            throw new MondialRelayConnectionException(sprintf('Mondial Relay answered HTTP %d for the label.', $response->getStatusCode()));
        }

        // An invalid link is answered with an HTTP 500 and an HTML page wrapping a 404: not
        // something a retry would fix.
        return $response->getStatusCode() === 200 && str_starts_with($content, '%PDF')
            ? $content
            : throw new MondialRelayProtocolException(sprintf('Mondial Relay did not return a PDF label (HTTP %d).', $response->getStatusCode()));
    }
}
