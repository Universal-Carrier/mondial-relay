<?php declare(strict_types=1);

namespace UniversalCarrier\MondialRelay;

use UniversalCarrier\MondialRelay\Exception\{MondialRelayConnectionException, MondialRelayShipmentException};

/**
 * Mondial Relay's status codes, and what each one means for the consumer.
 *
 * Every Web Service answer carries a STAT. 0 is success, 80 to 89 describe a parcel's
 * progress, and the rest are errors. Those errors split in two, which is what the contract
 * cares about: a request Mondial Relay rejects will fail again as is, while an account
 * problem or an outage may not.
 *
 * @internal
 */
final class Stat
{

    public const int OK = 0;

    public const int INCORRECT_SHIPMENT_NUMBER = 24;

    public const int INCORRECT_COUNTRY = 37;

    public const int INCORRECT_DELIVERY_COUNTRY = 79;

    public const int TRACKING_RECORDED = 80;

    public const int TRACKING_IN_PROCESS = 81;

    public const int TRACKING_DELIVERED = 82;

    public const int TRACKING_ANOMALY = 83;

    public const int UNKNOWN_PARCEL = 94;

    public const int GENERIC_SERVICE_ERROR = 99;

    private const array MESSAGES = [
        1 => 'Incorrect merchant',
        2 => 'Merchant number empty',
        3 => 'Incorrect merchant account number',
        5 => 'Incorrect merchant shipment reference',
        7 => 'Incorrect consignee reference',
        8 => 'Incorrect password or hash',
        9 => 'Unknown or not unique city',
        10 => 'Incorrect type of collection',
        11 => 'Incorrect collection Point Relais number',
        12 => 'Incorrect collection Point Relais country',
        13 => 'Incorrect type of delivery',
        14 => 'Incorrect delivery Point Relais number',
        15 => 'Incorrect delivery Point Relais country',
        20 => 'Incorrect parcel weight',
        21 => 'Incorrect developed length (length + height)',
        22 => 'Incorrect parcel size',
        24 => 'Incorrect shipment number',
        25 => 'Not enough credit on the account to register this shipment',
        26 => 'Incorrect assembly time',
        27 => 'Incorrect mode of collection or delivery',
        28 => 'Incorrect mode of collection',
        29 => 'Incorrect mode of delivery',
        30 => 'Incorrect address (line 1)',
        31 => 'Incorrect address (line 2)',
        33 => 'Incorrect address (line 3)',
        34 => 'Incorrect address (line 4)',
        35 => 'Incorrect city',
        36 => 'Incorrect postal code',
        37 => 'Incorrect country',
        38 => 'Incorrect phone number',
        39 => 'Incorrect e-mail',
        40 => 'Missing parameters',
        42 => 'Incorrect cash on delivery value',
        43 => 'Incorrect cash on delivery currency',
        44 => 'Incorrect shipment value',
        45 => 'Incorrect shipment value currency',
        46 => 'End of shipment number range reached',
        47 => 'Incorrect number of parcels',
        48 => 'Multi-parcel shipments are not permitted at a Point Relais',
        49 => 'Incorrect action',
        60 => 'Incorrect text field',
        61 => 'Incorrect notification request',
        62 => 'Incorrect extra delivery information',
        63 => 'Incorrect insurance',
        64 => 'Incorrect assembly time',
        65 => 'Incorrect appointment',
        66 => 'Incorrect take back',
        67 => 'Incorrect latitude',
        68 => 'Incorrect longitude',
        69 => 'Incorrect merchant code',
        70 => 'Incorrect Point Relais number',
        71 => 'Incorrect point of sale type',
        74 => 'Incorrect language',
        78 => 'Incorrect collection country',
        79 => 'Incorrect delivery country',
        92 => 'The recipient and Point Relais countries differ, or the prepaid balance is insufficient',
        93 => 'No information given by the sorting plan: check that the Point Relais or postal code exists',
        94 => 'Unknown parcel',
        95 => 'Merchant account not activated',
        96 => 'Incorrect merchant type',
        97 => 'Incorrect security key',
        98 => 'Generic error (incorrect parameters)',
        99 => 'Generic error of the service system',
    ];

    /**
     * Errors that come from the account or from Mondial Relay itself, not from the request:
     * credentials, activation, credit, and the generic system failure.
     */
    private const array NOT_THE_REQUEST = [1, 2, 3, 8, 25, 69, 95, 96, 97, 99];

    public static function message(int $stat): string
    {
        return self::MESSAGES[$stat] ?? sprintf('Unknown status %d', $stat);
    }

    public static function toException(int $stat, string $operation): MondialRelayConnectionException|MondialRelayShipmentException
    {
        $message = sprintf('Mondial Relay %s failed with STAT %d: %s.', $operation, $stat, self::message($stat));

        return in_array($stat, self::NOT_THE_REQUEST, true)
            ? new MondialRelayConnectionException($message, $stat)
            : new MondialRelayShipmentException($message, $stat);
    }
}
