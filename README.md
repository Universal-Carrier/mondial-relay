# Universal Carrier — Mondial Relay

A [Universal Carrier](../README.md) driver for **Mondial Relay**, over its SOAP Web Services.

```bash
composer require universal-carrier/mondial-relay
```

Requires PHP 8.4+ with `ext-soap`, a PSR-18 HTTP client and a PSR-17 request factory (to
download labels). `ext-intl` is used for transliteration when available.

## Usage

```php
use UniversalCarrier\MondialRelay\MondialRelayCarrier;
use UniversalCarrier\Core\Enum\LabelSize;

$carrier = new MondialRelayCarrier(
    enseigne: 'YOUR_CODE',             // "code enseigne"
    privateKey: 'YOUR_PRIVATE_KEY',
    httpClient: $psr18Client,
    requestFactory: $psr17Factory,
    labelSize: LabelSize::ROLL_100X150, // A4 (default), A5 or 10x15
    dropOffPointId: 'FR-012345',        // optional: where you drop parcels off; collected otherwise
    trackingLanguage: 'FR',             // FR or EN
);
```

| Capability | Interface | Web Service |
|---|---|---|
| Pickup points (Points Relais and lockers) | `PickupPointProviderInterface` | `WSI4_PointRelais_Recherche` |
| Labels | `ShipmentCreatorInterface` | `WSI2_CreationEtiquette` |
| Tracking | `TrackingProviderInterface` | `WSI2_TracingColisDetaille` |

**No rates.** Mondial Relay has no rating API — prices come from your contract — so this
driver does not implement `RateProviderInterface`.

**No shipment option.** `supports()` answers `false` for all three, and requests using them
are refused:
- multi-parcel: refused at a Point Relais, and sharing one tracking number at the door;
- insurance: levels are contract-specific;
- customs declaration: Mondial Relay's network stays within the EU customs territory.
  Items on those routes are accepted and ignored; a route leaving it (French overseas
  departments, Canary Islands) with items is refused.

### Services

`serviceCode` is Mondial Relay's delivery mode, passed as is:

| Code | Delivery | Pickup point required |
|---|---|---|
| `24R`, `24L`, `24X` | Point Relais or locker (by parcel size) | yes |
| `DRI` | Drive | yes |
| `HOM`, `LD1`, `LDS` | Home | no |

Pickup point ids are `<country>-<number>` (`FR-035528`), as returned by `getPickupPoints()`.

## What the driver takes care of

| Mondial Relay | Universal Carrier |
|---|---|
| Requests signed with an MD5 over every parameter, in WSDL order | `Soap\MondialRelayClient` |
| The WSDL declares `http://`; calls go to `https://` | `MondialRelayClient::ENDPOINT` |
| Uppercase ASCII fields, 32 characters at most (15 for the order reference) | `Text::field()` |
| Apostrophes returned doubled (`L''HOTEL`) | `Text::read()` |
| Coordinates returned with a decimal comma, accepted only with a point | `RequestMapper`, `ResponseMapper` |
| Opening hours as four `HHMM` strings per day, `0001`–`2359` for 24/7 lockers | `ResponseMapper::openingHours()` |
| Lockers flagged in a free-text `Information` field | `PickupPointType::LOCKER` |
| Type filters ignored by the API | filtered by the driver, over a wider search |
| An unserved country answered STAT 37 | no point, `[]` |
| A label link, not a label; its size chosen in the URL | `LabelDownloader` |
| A label link answered 502/503/504 | retried, up to three attempts |
| A label that still cannot be fetched | `MondialRelayLabelException`, carrying the expedition number and the URL |
| Tracking events without codes, dated `20/06/26 17:04` French time | patterns in `ResponseMapper` |
| Event labels cut at 30 characters (`PRISE EN CHARGE PAR LE TRANSPO`) | patterns match on the beginning of a label |
| A "prise en charge" scanned at every handover | the first is `picked_up`, the next ones `in_transit` |
| Notification events (`AVISAGE PAR EMAIL`) | keep the status the parcel is already in |
| `Emplacement` naming the hub that scanned the parcel, not the shop | a pickup point event is located by its point number |
| History padded with empty rows | dropped |
| The label URL, the destination point, the service | `$metadata`, under `mondial_relay.` keys |
| An unknown number answered STAT 94 or 99 | `pending` |

## When the label cannot be downloaded

The Web Service answers with a link, and the contract requires the PDF itself, so the driver
downloads it before returning. By then the shipment exists and will be invoiced. If the
download still fails after the retries, `createShipment()` throws a
`MondialRelayLabelException` — a `ConnectionExceptionInterface` — carrying `$expeditionNumber`
and `$labelUrl`: log them, and **do not book again**, which would create a second shipment.

On success, `ShipmentResponse::$metadata` keeps that same URL under
`mondial_relay.label_url`, so a support case can be traced back to the file Mondial Relay
served. Tracking answers publish `mondial_relay.destination_point_id`,
`destination_point_name` and `service` the same way.

## Known limits

- **Tracking patterns are provisional.** Events carry only a label. The patterns mapping them
  onto statuses were written from the labels observed so far — a complete delivery to a
  pickup point, recorded in `tests/Fixtures/tracking_delivered.xml` — and a label they miss
  keeps the status of the event before it, as the specification asks, until it is added. The five stages named
  by the Web Service documentation (V5.14, "Tracing des colis") are covered too, but Mondial
  Relay does not publish the wordings it actually sends — and sends far finer events than
  those five. Failed deliveries, returns and home deliveries have not been observed yet, so
  their wordings are guesses.
- **A home collection reads as `in_transit`, not `picked_up`,** when the carrier scans it as
  a handover between sites rather than a first pickup. Only the wording distinguishes the two,
  and only the pickup point drop-off has been seen.
- **Distances are rounded to the kilometre** by Mondial Relay: 0 m or 1,000 m apart is common.
- **Tracking URLs** are not provided: Mondial Relay's public tracking page needs the
  recipient's postal code, which `track()` does not have.

## Tests

```bash
composer check                   # PHPStan + unit and conformance tests, on recorded answers

MONDIAL_RELAY_ENSEIGNE=... MONDIAL_RELAY_PRIVATE_KEY=... MONDIAL_RELAY_TRACKING_NUMBER=... \
  vendor/bin/phpunit --testsuite Live   # the same conformance suites, against the real API
```

The live suite only searches pickup points and tracks parcels: it never books, and is safe
to run against a production account. Booking is covered against recorded answers only.
