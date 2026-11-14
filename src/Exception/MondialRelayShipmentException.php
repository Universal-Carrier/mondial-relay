<?php declare(strict_types=1);

namespace UniversalCarrier\MondialRelay\Exception;

use RuntimeException;
use Throwable;
use UniversalCarrier\Core\Exception\ShipmentExceptionInterface;

/**
 * Mondial Relay rejected the request content: sending it again will fail the same way.
 */
final class MondialRelayShipmentException extends RuntimeException implements ShipmentExceptionInterface
{

    /**
     * @param int|null $stat Mondial Relay's own status code, when the rejection came from its API.
     */
    public function __construct(string $message, public readonly ?int $stat = null, ?Throwable $previous = null)
    {
        parent::__construct($message, $stat ?? 0, $previous);
    }
}
