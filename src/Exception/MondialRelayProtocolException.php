<?php declare(strict_types=1);

namespace UniversalCarrier\MondialRelay\Exception;

use RuntimeException;
use UniversalCarrier\Core\Exception\CarrierExceptionInterface;

/**
 * Mondial Relay answered, but not in the shape the driver expects.
 */
final class MondialRelayProtocolException extends RuntimeException implements CarrierExceptionInterface {}
