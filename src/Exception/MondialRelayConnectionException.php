<?php declare(strict_types=1);

namespace UniversalCarrier\MondialRelay\Exception;

use RuntimeException;
use Throwable;
use UniversalCarrier\Core\Exception\ConnectionExceptionInterface;

/**
 * Mondial Relay could not be reached, is failing, or refused the account's credentials: the
 * same request may succeed later, or once the configuration is fixed.
 */
final class MondialRelayConnectionException extends RuntimeException implements ConnectionExceptionInterface
{

    /**
     * @param int|null $stat Mondial Relay's own status code, when the failure came from its API.
     */
    public function __construct(string $message, public readonly ?int $stat = null, ?Throwable $previous = null)
    {
        parent::__construct($message, $stat ?? 0, $previous);
    }
}
