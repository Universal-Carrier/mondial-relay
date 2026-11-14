<?php declare(strict_types=1);

namespace UniversalCarrier\MondialRelay\Mapping;

use DateInterval;
use DatePeriod;
use DateTimeImmutable;
use DateTimeZone;
use Exception;
use UniversalCarrier\Core\Dto\{BusinessDay, PickupPoint, TimeSlot, Tracking, TrackingCheckpoint};
use UniversalCarrier\Core\Enum\{DayOfWeek, PickupPointType, TrackingStatus};
use UniversalCarrier\MondialRelay\Exception\MondialRelayProtocolException;
use UniversalCarrier\MondialRelay\Soap\Result;
use UniversalCarrier\MondialRelay\Stat;
use UniversalCarrier\MondialRelay\Text;

/**
 * Turns Mondial Relay's answers into the package's DTOs.
 *
 * @internal
 */
final readonly class ResponseMapper
{

    private const array OPENING_HOURS = [
        'Horaires_Lundi' => DayOfWeek::MONDAY,
        'Horaires_Mardi' => DayOfWeek::TUESDAY,
        'Horaires_Mercredi' => DayOfWeek::WEDNESDAY,
        'Horaires_Jeudi' => DayOfWeek::THURSDAY,
        'Horaires_Vendredi' => DayOfWeek::FRIDAY,
        'Horaires_Samedi' => DayOfWeek::SATURDAY,
        'Horaires_Dimanche' => DayOfWeek::SUNDAY,
    ];

    /**
     * Tracking events carry no code, only a label in the requested language. These patterns
     * are matched against the label, in this order. A label they miss keeps the status of the
     * event before it, as the specification asks (section 5.6): an unknown wording is a
     * missing line here, not an incident on the parcel. Real incidents are named by the
     * patterns below, and by STAT 83.
     *
     * Mondial Relay cuts its labels at thirty characters - "PRISE EN CHARGE PAR LE TRANSPO",
     * "Colis pris en charge sur le si" - so a pattern must match on the beginning of a label
     * and never on its last word.
     *
     * The Web Service documentation (V5.14, "Tracing des colis") names five stages - "Annonce
     * expédition", "Prise en charge dans le réseau Mondial Relay", "Mise en tournée",
     * "Disponible au Point Relais®", "Livré" - without promising those exact wordings, and
     * the API sends many more. Both the documented five and the observed ones are covered.
     */
    private const array EVENT_PATTERNS = [
        '/\bRETOUR|\bRETURN/' => TrackingStatus::RETURNED,
        '/ANOMALIE|ANOMALY|REFUS|ENDOMMAG|DAMAGED|INCIDENT|PERDU/' => TrackingStatus::EXCEPTION,
        '/ABSENT|AVIS DE PASSAGE|NOT DELIVERED|NON LIVRE/' => TrackingStatus::DELIVERY_ATTEMPTED,
        '/DISPONIBLE|AVAILABLE|MIS A DISPOSITION/' => TrackingStatus::AVAILABLE_FOR_PICKUP,
        '/EN COURS DE LIVRAISON|MISE EN TOURNEE|OUT FOR DELIVERY/' => TrackingStatus::OUT_FOR_DELIVERY,
        '/\bLIVRE|\bDELIVERED/' => TrackingStatus::DELIVERED,
        '/RECEPTION DES DONNEES|ANNONCE|DATA RECEPTION|PREPARATION/' => TrackingStatus::PENDING,
        '/PRIS EN CHARGE|PRISE EN CHARGE|TAKEN OVER|COLLECTED|DEPOSE/' => TrackingStatus::PICKED_UP,
        '/REMIS AU|ACHEMINEMENT|EXPEDIE|EN TRANSIT|IN TRANSIT|\bTRI\b|SORTING|PLATEFORME|HUB/' => TrackingStatus::IN_TRANSIT,
    ];

    /**
     * Labels that announce nothing new: they tell the recipient about the state the parcel is
     * already in - "AVISAGE PAR EMAIL" follows the arrival at the pickup point. Matched before
     * the patterns, so that a notification never reads as the event it is about.
     */
    private const string NOTIFICATION = '/AVISAGE|NOTIFICATION|\bEMAIL\b|\bSMS\b/';

    /** Statuses STAT 81 ("in process at Mondial Relay") may be refined into. */
    private const array IN_PROCESS = [
        TrackingStatus::PICKED_UP,
        TrackingStatus::IN_TRANSIT,
        TrackingStatus::OUT_FOR_DELIVERY,
        TrackingStatus::DELIVERY_ATTEMPTED,
        TrackingStatus::AVAILABLE_FOR_PICKUP,
    ];

    public function pickupPoint(Result $point): PickupPoint
    {
        $country = $point->string('Pays');
        $timezone = self::timezoneOf($country);

        return new PickupPoint(
            id: sprintf('%s-%s', $country, $point->string('Num')),
            name: Text::read($point->string('LgAdr1')) ?? $point->string('Num'),
            type: strtoupper($point->string('Information')) === 'LOCKER' ? PickupPointType::LOCKER : PickupPointType::SHOP,
            address1: Text::read($point->string('LgAdr3')) ?? '',
            postalCode: $point->string('CP'),
            city: $point->string('Ville'),
            countryCode: $country,
            address2: Text::read($point->string('LgAdr4')),
            latitude: self::coordinate($point, 'Latitude'),
            longitude: self::coordinate($point, 'Longitude'),
            // Mondial Relay rounds distances to the kilometre.
            distanceInKilometer: $point->string('Distance') === '' ? null : (int)$point->string('Distance') / 1000,
            openingHours: self::openingHours($point),
            holidays: self::holidays($point, $timezone),
            timezone: $timezone,
            metadata: array_filter([
                'mondial_relay.location_hint' => Text::read(trim($point->string('Localisation1') . ' ' . $point->string('Localisation2'))),
                'mondial_relay.photo_url' => Text::read($point->string('URL_Photo')),
                'mondial_relay.map_url' => Text::read($point->string('URL_Plan')),
            ], static fn (?string $value): bool => $value !== null)
        );
    }

    public function tracking(Result $result, string $trackingNumber): Tracking
    {
        $checkpoints = [];
        $previous = null;
        $pickedUp = false;

        foreach ($result->list('Tracing', 'ret_WSI2_sub_TracingColisDetaille') as $event) {
            // The answer is padded with empty rows.
            if ($event->string('Libelle') === '' || $event->string('Date') === '') {
                continue;
            }

            $status = self::eventStatus($event->string('Libelle'));

            if ($status === null) {
                // A notification, or a wording the table does not know: the parcel is where
                // the last readable event left it.
                $status = $previous ?? TrackingStatus::PENDING;
            } elseif ($status === TrackingStatus::PICKED_UP) {
                // Mondial Relay scans a "prise en charge" at every handover - the sender's
                // pickup point, then each hub along the way. Only the first one is the moment
                // the carrier took the parcel; the rest are transit scans.
                $status = $pickedUp ? TrackingStatus::IN_TRANSIT : $status;
                $pickedUp = true;
            }

            $checkpoints[] = new TrackingCheckpoint(
                status: $previous = $status,
                at: self::eventTime($event->string('Date'), $event->string('Heure')),
                description: $event->string('Libelle'),
                location: self::eventLocation($event)
            );
        }

        $latest = $checkpoints === [] ? null : $checkpoints[array_key_last($checkpoints)];

        return new Tracking(
            trackingNumber: $trackingNumber,
            status: self::currentStatus($result->stat(), $latest?->status),
            description: Text::read($result->string('Libelle02')) ?? $latest?->description,
            location: $latest?->location,
            checkpoints: $checkpoints,
            // The destination point and the service, which the contract has no place for and
            // which a customer service screen wants: "where do I go and what was booked?".
            metadata: array_filter([
                'mondial_relay.destination_point_id' => Text::read($result->string('Relais_Num')),
                'mondial_relay.destination_point_name' => Text::read($result->string('Relais_Libelle')),
                'mondial_relay.service' => Text::read($result->string('Libelle01')),
            ], static fn (?string $value): bool => $value !== null)
        );
    }

    /**
     * STAT gives the overall progress, authoritatively but coarsely; the latest event refines
     * "in process" into something more precise, and reveals a return.
     */
    private static function currentStatus(int $stat, ?TrackingStatus $latest): TrackingStatus
    {
        if ($latest === TrackingStatus::RETURNED && $stat !== Stat::TRACKING_DELIVERED) {
            return TrackingStatus::RETURNED;
        }

        return match ($stat) {
            Stat::TRACKING_RECORDED => TrackingStatus::PENDING,
            Stat::TRACKING_IN_PROCESS => in_array($latest, self::IN_PROCESS, true) ? $latest : TrackingStatus::IN_TRANSIT,
            Stat::TRACKING_DELIVERED => TrackingStatus::DELIVERED,
            default => TrackingStatus::EXCEPTION,
        };
    }

    /**
     * @return TrackingStatus|null Null when the label says nothing the driver can place: a
     *   notification, or a wording no pattern matches. Both keep the previous status
     *   (specification, section 5.6).
     */
    private static function eventStatus(string $label): ?TrackingStatus
    {
        $normalized = Text::field($label, 255, 'A-Z0-9 ');

        if (preg_match(self::NOTIFICATION, $normalized) === 1) {
            return null;
        }

        foreach (self::EVENT_PATTERNS as $pattern => $status) {
            if (preg_match($pattern, $normalized) === 1) {
                return $status;
            }
        }

        return null;
    }

    /**
     * Where the parcel is. An event at a pickup point names the point, whose number is the
     * only unambiguous answer: `Emplacement` is the depot that scanned the parcel, which for
     * a delivery is the regional hub rather than the shop the recipient walks into.
     */
    private static function eventLocation(Result $event): ?string
    {
        if ($event->string('Relais_Num') !== '') {
            return sprintf('Point Relais %s-%s', $event->string('Relais_Pays'), $event->string('Relais_Num'));
        }

        return Text::read($event->string('Emplacement'));
    }

    /**
     * Events are dated "20/06/26" and timed "17:04", in French time.
     */
    private static function eventTime(string $date, string $time): DateTimeImmutable
    {
        $parsed = DateTimeImmutable::createFromFormat(
            '!d/m/y H:i',
            sprintf('%s %s', $date, $time === '' ? '00:00' : $time),
            new DateTimeZone('Europe/Paris')
        );

        return $parsed !== false
            ? $parsed
            : throw new MondialRelayProtocolException(sprintf('Unreadable tracking date "%s %s".', $date, $time));
    }

    /**
     * Each day comes as four times - open, close, open, close - with "" or "0000" for an
     * unused slot. Mondial Relay always sends all seven days, so a day with no slot is closed.
     *
     * @return list<BusinessDay>
     */
    private static function openingHours(Result $point): array
    {
        $days = [];

        foreach (self::OPENING_HOURS as $field => $day) {
            if (!$point->has($field)) {
                continue;
            }

            $times = array_values(array_filter($point->strings($field), static fn (string $t): bool => $t !== '' && $t !== '0000'));
            $slots = [];

            foreach (array_chunk($times, 2) as $pair) {
                if (count($pair) === 2) {
                    $slots[] = new TimeSlot(self::time($pair[0]), self::time($pair[1]));
                }
            }

            $days[] = new BusinessDay($day, $slots);
        }

        return $days;
    }

    /**
     * Closure periods, expanded into the individual dates the package expects.
     *
     * @return list<DateTimeImmutable>
     */
    private static function holidays(Result $point, ?string $timezone): array
    {
        $zone = new DateTimeZone($timezone ?? 'Europe/Paris');
        $dates = [];

        foreach ($point->list('Informations_Dispo', 'Periode') as $period) {
            if ($period->string('Debut') === '' || $period->string('Fin') === '') {
                continue;
            }

            try {
                $start = new DateTimeImmutable($period->string('Debut'), $zone);
                $end = new DateTimeImmutable($period->string('Fin'), $zone);
            } catch (Exception $e) {
                throw new MondialRelayProtocolException(sprintf('Unreadable closure period of point %s.', $point->string('Num')), 0, $e);
            }

            foreach (new DatePeriod($start->setTime(0, 0), new DateInterval('P1D'), $end->setTime(0, 0), DatePeriod::INCLUDE_END_DATE) as $day) {
                $dates[] = $day;
            }
        }

        return $dates;
    }

    private static function coordinate(Result $point, string $field): ?float
    {
        $value = str_replace(',', '.', $point->string($field));

        return is_numeric($value) ? (float)$value : null;
    }

    private static function time(string $hhmm): string
    {
        return preg_match('/^\d{4}$/', $hhmm) === 1
            ? substr($hhmm, 0, 2) . ':' . substr($hhmm, 2, 2)
            : throw new MondialRelayProtocolException(sprintf('Unreadable opening time "%s".', $hhmm));
    }

    private static function timezoneOf(string $countryCode): ?string
    {
        $zones = DateTimeZone::listIdentifiers(DateTimeZone::PER_COUNTRY, $countryCode);

        return count($zones) === 1 ? $zones[0] : null;
    }
}
