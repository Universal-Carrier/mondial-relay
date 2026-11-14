<?php declare(strict_types=1);

namespace UniversalCarrier\MondialRelay\Tests\Support;

use Nyholm\Psr7\Factory\Psr17Factory;
use UniversalCarrier\Core\Dto\{Address, Parcel, ShipmentRequest};
use UniversalCarrier\Core\Enum\LabelSize;
use UniversalCarrier\MondialRelay\MondialRelayCarrier;

/**
 * A driver wired to recorded answers, and the requests the tests send it.
 */
final class Fixtures
{

    public static function carrier(
        ?ReplaySoapClient $soap = null,
        ?FakeHttpClient $http = null,
        LabelSize $labelSize = LabelSize::A4,
        ?string $dropOffPointId = null
    ): MondialRelayCarrier {
        return new MondialRelayCarrier(
            'BDTEST13',
            'TestAPI1key',
            $http ?? new FakeHttpClient(),
            new Psr17Factory(),
            $labelSize,
            $dropOffPointId,
            soapClient: $soap ?? self::recorded()
        );
    }

    /**
     * Answers every Web Service the way the live API did when the fixtures were recorded.
     */
    public static function recorded(): ReplaySoapClient
    {
        return new ReplaySoapClient(static fn (string $method, array $parameters): string => match ($method) {
            'WSI4_PointRelais_Recherche' => match (true) {
                $parameters['Pays'] !== 'FR' => 'search_unserved_country.xml',
                $parameters['CP'] === '99999' => 'search_no_result.xml',
                $parameters['CP'] === '13001' => 'search_marseille_30.xml',
                default => 'search_paris_75004.xml',
            },
            'WSI2_CreationEtiquette' => $parameters['Dest_CP'] === '00000'
                ? 'creation_incorrect_postal_code.xml'
                : 'creation_recorded.xml',
            'WSI2_TracingColisDetaille' => match (true) {
                preg_match('/^\d{7,8}$/', $parameters['Expedition']) !== 1 => 'tracking_malformed.xml',
                ltrim($parameters['Expedition'], '0') === '1225747' => 'tracking_recorded.xml',
                ltrim($parameters['Expedition'], '0') === '1828410' => 'tracking_delivered.xml',
                default => 'tracking_unknown.xml',
            },
            default => throw new \LogicException(sprintf('No fixture for %s.', $method)),
        });
    }

    public static function merchant(): Address
    {
        return new Address(
            '51 avenue des Champs-Élysées',
            '75008',
            'Paris',
            'FR',
            firstName: 'Jean',
            lastName: 'Dupont',
            company: 'Acme SAS',
            phone: '+33 1 23 45 67 89',
            email: 'shop@example.com'
        );
    }

    public static function customer(string $postalCode = '75004'): Address
    {
        return new Address(
            '12 rue de l\'Hôtel de Ville',
            $postalCode,
            'Paris',
            'FR',
            firstName: 'Jeanne',
            lastName: 'Martin',
            address2: 'Bâtiment B',
            phone: '06 12 34 56 78',
            email: 'jeanne@example.com'
        );
    }

    public static function homeDelivery(): ShipmentRequest
    {
        return new ShipmentRequest('HOM', self::merchant(), self::customer(), [new Parcel(1.45, reference: 'BOX-1')], 'ORD-1001', senderLanguage: 'fr', recipientLanguage: 'fr');
    }

    public static function pickupPointDelivery(): ShipmentRequest
    {
        return new ShipmentRequest('24R', self::merchant(), self::customer(), [new Parcel(1.45)], 'ORD-1002', pickupPointId: 'FR-035528', senderLanguage: 'fr', recipientLanguage: 'fr');
    }
}
