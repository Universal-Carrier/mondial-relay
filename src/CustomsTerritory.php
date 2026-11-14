<?php declare(strict_types=1);

namespace UniversalCarrier\MondialRelay;

use UniversalCarrier\Core\Dto\Address;

/**
 * Whether a route crosses a customs border, by Mondial Relay's network.
 *
 * Mondial Relay serves countries of the EU customs territory, where goods move without a
 * declaration. What remains are the territories outside it that share a country code with a
 * member state - French overseas departments, the Canary Islands, Ceuta and Melilla - and any
 * country outside the EU.
 *
 * @internal
 */
final class CustomsTerritory
{

    private const array EU_MEMBER_STATES = ['AT', 'BE', 'BG', 'CY', 'CZ', 'DE', 'DK', 'EE', 'ES', 'FI', 'FR',
        'GR', 'HR', 'HU', 'IE', 'IT', 'LT', 'LU', 'LV', 'MT', 'NL', 'PL', 'PT', 'RO', 'SE', 'SI', 'SK'];

    public static function requiresDeclaration(Address $from, Address $to): bool
    {
        return self::of($from) !== self::of($to);
    }

    private static function of(Address $address): string
    {
        return match (true) {
            $address->countryCode === 'FR' && preg_match('/^9[78](?!0)/', $address->postalCode) === 1 => 'FR-' . substr($address->postalCode, 0, 3),
            $address->countryCode === 'ES' && preg_match('/^(35|38|51|52)/', $address->postalCode) === 1 => 'ES-' . substr($address->postalCode, 0, 2),
            in_array($address->countryCode, self::EU_MEMBER_STATES, true) => 'EU',
            default => $address->countryCode,
        };
    }
}
