<?php declare(strict_types=1);

namespace UniversalCarrier\MondialRelay\Tests\Support;

use Nyholm\Psr7\Response;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\{RequestInterface, ResponseInterface};

/**
 * A PSR-18 client serving the label fixture, or failures first.
 */
final class FakeHttpClient implements ClientInterface
{

    /** @var list<string> */
    public array $requestedUrls = [];

    /** @var list<array{int, string}> */
    private array $failures;

    /**
     * @param list<array{int, string}> $failures Status and body of the answers to give before
     *   serving the label; the last one is repeated when $forever is true.
     */
    public function __construct(array $failures = [], private readonly bool $forever = false)
    {
        $this->failures = $failures;
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->requestedUrls[] = (string)$request->getUri();
        $failure = $this->forever ? ($this->failures[0] ?? null) : array_shift($this->failures);

        return $failure === null
            ? new Response(200, ['Content-Type' => 'application/pdf'], (string)file_get_contents(__DIR__ . '/../Fixtures/label.pdf'))
            : new Response($failure[0], [], $failure[1]);
    }
}
