<?php declare(strict_types=1);

namespace UniversalCarrier\MondialRelay\Mapping;

use DateTimeImmutable;
use UniversalCarrier\Core\Dto\{Address, PickupPointQuery, ShipmentRequest};
use UniversalCarrier\MondialRelay\Exception\MondialRelayShipmentException;
use UniversalCarrier\MondialRelay\Text;

/**
 * Builds the parameters of Mondial Relay's Web Services from the package's DTOs.
 *
 * Every array is returned in the order the WSDL declares its parameters, because that order
 * is part of the request signature.
 *
 * @internal
 */
final readonly class RequestMapper
{

    /** Delivery modes that deliver to a Point Relais or a locker rather than to the door. */
    public const array PICKUP_POINT_SERVICES = ['24R', '24L', '24X', 'DRI'];

    /**
     * @param string|null $dropOffPointId The Point Relais where the merchant drops parcels off
     *   ("FR-012345"), or null when Mondial Relay collects them from the merchant.
     */
    public function __construct(
        private ?string $dropOffPointId = null
    ) {}

    /**
     * @return array<string, string>
     */
    public function pickupPointSearch(PickupPointQuery $query, int $count): array
    {
        $byCoordinates = $query->hasCoordinates();

        return [
            'Pays' => $query->countryCode,
            'NumPointRelais' => '',
            // The postal code is enough, and a city spelt differently from Mondial Relay's own
            // reference (accents, "Saint"/"St") would fail the search with STAT 9.
            'Ville' => '',
            'CP' => $byCoordinates || $query->address === null ? '' : self::postalCode($query->address),
            // Mondial Relay answers with decimal commas but only accepts decimal points.
            'Latitude' => $byCoordinates ? number_format((float)$query->latitude, 7, '.', '') : '',
            'Longitude' => $byCoordinates ? number_format((float)$query->longitude, 7, '.', '') : '',
            'Taille' => '',
            'Poids' => $query->weightInKilogram === null ? '' : (string)max(1, (int)round($query->weightInKilogram * 1000)),
            'Action' => $query->serviceCode !== null && in_array($query->serviceCode, self::PICKUP_POINT_SERVICES, true)
                ? $query->serviceCode
                : '',
            'DelaiEnvoi' => (string)self::daysUntil($query->shippingDate),
            'RayonRecherche' => $query->radiusInKilometer === null ? '' : (string)max(1, (int)ceil($query->radiusInKilometer)),
            'TypeActivite' => '',
            'NACE' => '',
            'NombreResultats' => (string)$count,
        ];
    }

    /**
     * @return array<string, string>
     */
    public function shipment(ShipmentRequest $request): array
    {
        [$deliveryCountry, $deliveryPoint] = $request->pickupPointId === null
            ? ['', '']
            : self::pickupPoint($request->pickupPointId);
        [$dropOffCountry, $dropOffPoint] = $this->dropOffPointId === null
            ? ['', '']
            : self::pickupPoint($this->dropOffPointId);

        return [
            'ModeCol' => $this->dropOffPointId === null ? 'CCC' : 'REL',
            'ModeLiv' => Text::field($request->serviceCode, 3, 'A-Z0-9'),
            'NDossier' => Text::field($request->orderReference, 15, 'A-Z0-9_\\- '),
            'NClient' => Text::field($request->customerReference, 9, 'A-Z0-9'),
            ...self::party('Expe', $request->sender, $request->senderLanguage, businessFirst: true),
            ...self::party('Dest', $request->recipient, $request->recipientLanguage, businessFirst: false),
            'Poids' => (string)array_sum(array_map(
                static fn ($parcel): int => $parcel->getWeightInGram(),
                $request->parcels
            )),
            'Longueur' => '',
            'Taille' => '',
            'NbColis' => (string)$request->getParcelCount(),
            'CRT_Valeur' => '0',
            'CRT_Devise' => 'EUR',
            'Exp_Valeur' => '',
            'Exp_Devise' => '',
            'COL_Rel_Pays' => $dropOffCountry,
            'COL_Rel' => $dropOffPoint,
            'LIV_Rel_Pays' => $deliveryCountry,
            'LIV_Rel' => $deliveryPoint,
            'TAvisage' => '',
            'TReprise' => '',
            'Montage' => '',
            'TRDV' => '',
            'Assurance' => '',
            'Instructions' => Text::field($request->deliveryInstructions, 31),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function tracking(string $trackingNumber, string $language): array
    {
        return ['Expedition' => trim($trackingNumber), 'Langue' => $language];
    }

    /**
     * Pickup point ids are "<country>-<number>": Mondial Relay needs both to find a point again.
     *
     * @return array{string, string}
     */
    public static function pickupPoint(string $id): array
    {
        if (preg_match('/^([A-Z]{2})-(\d{6})$/', $id, $matches) !== 1) {
            throw new MondialRelayShipmentException(sprintf('"%s" is not a Mondial Relay pickup point id.', $id));
        }

        return [$matches[1], $matches[2]];
    }

    /**
     * Mondial Relay has one name line and one complement line. The sender is usually a
     * business, so its company name comes first; the recipient is usually a person.
     *
     * @return array<string, string>
     */
    private static function party(string $prefix, Address $address, string $language, bool $businessFirst): array
    {
        $person = $address->getFullName();
        $first = $businessFirst ? ($address->company ?? $person) : ($person ?? $address->company);
        $second = $businessFirst ? ($address->company === null ? null : $person) : ($person === null ? null : $address->company);
        $complement = trim(implode(' ', array_filter([$address->address2, $address->address3])));

        return [
            $prefix . '_Langage' => strtoupper($language),
            $prefix . '_Ad1' => Text::field($first, 32),
            $prefix . '_Ad2' => Text::field($second, 32),
            $prefix . '_Ad3' => Text::field($address->address1, 32),
            $prefix . '_Ad4' => Text::field($complement, 32),
            $prefix . '_Ville' => Text::field($address->city, 26),
            $prefix . '_CP' => self::postalCode($address),
            $prefix . '_Pays' => $address->countryCode,
            $prefix . '_Tel1' => preg_replace('/[^0-9+]/', '', $address->phone ?? '') ?? '',
            $prefix . '_Tel2' => '',
            $prefix . '_Mail' => $address->email ?? '',
        ];
    }

    private static function postalCode(Address $address): string
    {
        return Text::field(str_replace(' ', '-', $address->postalCode), 10, 'A-Z0-9\\-');
    }

    private static function daysUntil(?DateTimeImmutable $date): int
    {
        if ($date === null) {
            return 0;
        }

        $today = new DateTimeImmutable('today', $date->getTimezone());

        return max(0, (int)$today->diff($date->setTime(0, 0))->format('%r%a'));
    }
}
