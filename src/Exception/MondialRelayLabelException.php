<?php declare(strict_types=1);

namespace UniversalCarrier\MondialRelay\Exception;

use RuntimeException;
use Throwable;
use UniversalCarrier\Core\Exception\ConnectionExceptionInterface;

/**
 * The shipment was created, but its label could not be downloaded.
 *
 * The shipment exists at Mondial Relay and will be invoiced: booking it again would create a
 * second one. The expedition number and the label URL are kept here so that the label can be
 * fetched again later.
 */
final class MondialRelayLabelException extends RuntimeException implements ConnectionExceptionInterface
{

    public function __construct(
        public readonly string $expeditionNumber,
        public readonly string $labelUrl,
        Throwable $previous
    ) {
        parent::__construct(sprintf(
            'Mondial Relay shipment %s was created, but its label could not be downloaded from %s: %s'
            . ' Do not book it again; fetch the label from that URL once Mondial Relay answers.',
            $expeditionNumber,
            $labelUrl,
            $previous->getMessage()
        ), 0, $previous);
    }
}
