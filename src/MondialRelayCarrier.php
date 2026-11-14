<?php declare(strict_types=1);

namespace UniversalCarrier\MondialRelay;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use SensitiveParameter;
use SoapClient;
use UniversalCarrier\Core\Contract\{PickupPointProviderInterface, ShipmentCreatorInterface, TrackingProviderInterface};
use UniversalCarrier\Core\Dto\{Label,
    PickupPoint,
    PickupPointQuery,
    ShipmentPackage,
    ShipmentRequest,
    ShipmentResponse,
    Tracking};
use UniversalCarrier\Core\Enum\{LabelFormat, LabelSize, PickupPointType, ShipmentOption, TrackingStatus};
use UniversalCarrier\Core\Exception\{CarrierExceptionInterface, InvalidArgumentException, UnsupportedOptionException};
use UniversalCarrier\MondialRelay\Exception\{MondialRelayConnectionException,
    MondialRelayLabelException,
    MondialRelayProtocolException,
    MondialRelayShipmentException};
use UniversalCarrier\MondialRelay\Mapping\{RequestMapper, ResponseMapper};
use UniversalCarrier\MondialRelay\Soap\MondialRelayClient;

/**
 * Mondial Relay, through its SOAP Web Services.
 *
 * It lists Points Relais and lockers, books shipments to them or to the door, and tracks
 * parcels. It does not quote: Mondial Relay has no rating API, prices come from the merchant's
 * contract, so this driver does not implement RateProviderInterface.
 */
final readonly class MondialRelayCarrier implements
    PickupPointProviderInterface,
    ShipmentCreatorInterface,
    TrackingProviderInterface
{

    /** Mondial Relay returns at most this many points per search. */
    private const int MAX_RESULTS = 30;

    /** Download attempts for a label, when the failure may be temporary. */
    private const int LABEL_ATTEMPTS = 3;

    private MondialRelayClient $client;

    private RequestMapper $requests;

    private ResponseMapper $responses;

    private LabelDownloader $labels;

    /**
     * @param string $enseigne The merchant code ("code enseigne") given by Mondial Relay.
     * @param string $privateKey The private key attached to it, used to sign every call.
     * @param ClientInterface $httpClient Any PSR-18 client, used to download labels.
     * @param RequestFactoryInterface $requestFactory Any PSR-17 request factory.
     * @param LabelSize $labelSize A4, A5 or ROLL_100X150 (10x15): the PDF formats Mondial Relay renders.
     * @param string|null $dropOffPointId The Point Relais where parcels are dropped off
     *   ("FR-012345"), or null for Mondial Relay to collect them from the merchant.
     * @param string $trackingLanguage FR or EN: the language of tracking descriptions.
     * @param SoapClient|null $soapClient A preconfigured SOAP client; see {@see MondialRelayClient::options()}.
     * @throws InvalidArgumentException
     */
    public function __construct(
        string $enseigne,
        #[SensitiveParameter] string $privateKey,
        ClientInterface $httpClient,
        RequestFactoryInterface $requestFactory,
        private LabelSize $labelSize = LabelSize::A4,
        ?string $dropOffPointId = null,
        private string $trackingLanguage = 'FR',
        ?SoapClient $soapClient = null
    ) {
        if (!in_array($labelSize, [LabelSize::A4, LabelSize::A5, LabelSize::ROLL_100X150], true)) {
            throw new InvalidArgumentException(sprintf('Mondial Relay renders labels in A4, A5 or 10x15, not %s.', $labelSize->name));
        }

        if (!in_array($trackingLanguage, ['FR', 'EN'], true)) {
            throw new InvalidArgumentException(sprintf('Mondial Relay tracks parcels in FR or EN, not "%s".', $trackingLanguage));
        }

        if ($dropOffPointId !== null) {
            RequestMapper::pickupPoint($dropOffPointId);
        }

        $this->client = new MondialRelayClient($enseigne, $privateKey, $soapClient);
        $this->requests = new RequestMapper($dropOffPointId);
        $this->responses = new ResponseMapper();
        $this->labels = new LabelDownloader($httpClient, $requestFactory);
    }

    public function getCode(): string
    {
        return 'mondial_relay';
    }

    public function getName(): string
    {
        return 'Mondial Relay';
    }

    /**
     * None of the three: several parcels are refused at a Point Relais and would share one
     * tracking number at the door, insurance levels are contract-specific, and the network does
     * not leave the EU customs territory.
     */
    public function supports(ShipmentOption $option): bool
    {
        return match ($option) {
            ShipmentOption::MULTI_PARCEL, ShipmentOption::INSURANCE, ShipmentOption::CUSTOMS_DECLARATION => false,
        };
    }

    public function getPickupPoints(PickupPointQuery $query): array
    {
        $types = array_values(array_filter(
            $query->types,
            static fn (PickupPointType $type): bool => in_array($type, [PickupPointType::SHOP, PickupPointType::LOCKER], true)
        ));

        // Mondial Relay has shops and lockers, and serves them with its pickup point services only.
        if (($query->types !== [] && $types === [])
            || ($query->serviceCode !== null && !in_array($query->serviceCode, RequestMapper::PICKUP_POINT_SERVICES, true))) {
            return [];
        }

        // Types are filtered here, so ask for enough points to fill the limit after filtering.
        $count = $types === [] ? min($query->limit ?? 10, self::MAX_RESULTS) : self::MAX_RESULTS;
        $result = $this->client->call('WSI4_PointRelais_Recherche', $this->requests->pickupPointSearch($query, $count));
        $stat = $result->stat();

        if ($stat === Stat::INCORRECT_COUNTRY || $stat === Stat::INCORRECT_DELIVERY_COUNTRY) {
            return []; // A country Mondial Relay does not serve has no point: an answer, not an error.
        }

        if ($stat !== Stat::OK) {
            throw Stat::toException($stat, 'pickup point search');
        }

        $points = array_map(
            $this->responses->pickupPoint(...),
            $result->list('PointsRelais', 'PointRelais_Details')
        );

        if ($types !== []) {
            $points = array_values(array_filter($points, static fn (PickupPoint $p): bool => in_array($p->type, $types, true)));
        }

        usort($points, static fn (PickupPoint $a, PickupPoint $b): int
            => ($a->distanceInKilometer ?? PHP_FLOAT_MAX) <=> ($b->distanceInKilometer ?? PHP_FLOAT_MAX));

        return $query->limit === null ? $points : array_slice($points, 0, $query->limit);
    }

    public function createShipment(ShipmentRequest $request): ShipmentResponse
    {
        $this->assertOptionsAreSupported($request);
        $this->assertPickupPointMatchesService($request);

        $result = $this->client->call('WSI2_CreationEtiquette', $this->requests->shipment($request));
        $stat = $result->stat();

        if ($stat !== Stat::OK) {
            throw Stat::toException($stat, 'shipment creation');
        }

        $expedition = $result->string('ExpeditionNum');
        $labelPath = $result->string('URL_Etiquette');

        if ($expedition === '' || $labelPath === '') {
            throw new MondialRelayProtocolException(sprintf(
                'Mondial Relay created shipment "%s" without returning its number or its label URL.',
                $expedition
            ));
        }

        return new ShipmentResponse(
            shipmentId: $expedition,
            packages: [new ShipmentPackage(
                trackingNumber: $expedition,
                label: $this->downloadLabel($expedition, $labelPath),
                parcelReference: ($request->parcels[0] ?? null)?->reference
            )],
            // The label was downloaded above; its semi-permanent URL is kept so that a
            // support case can be traced back to what Mondial Relay actually served.
            metadata: ['mondial_relay.label_url' => LabelDownloader::url($labelPath, $this->labelSize)]
        );
    }

    public function track(string $trackingNumber): Tracking
    {
        $result = $this->client->call(
            'WSI2_TracingColisDetaille',
            $this->requests->tracking($trackingNumber, $this->trackingLanguage)
        );
        $stat = $result->stat();

        // A well-formed number Mondial Relay does not know yet is answered 94, or - observed on
        // numbers never issued - 99. Neither is an error for the contract (section 5.6).
        if ($stat === Stat::UNKNOWN_PARCEL || $stat === Stat::GENERIC_SERVICE_ERROR) {
            return new Tracking($trackingNumber, TrackingStatus::PENDING);
        }

        if ($stat < Stat::TRACKING_RECORDED || $stat > 89) {
            throw Stat::toException($stat, 'tracking');
        }

        return $this->responses->tracking($result, $trackingNumber);
    }

    /**
     * The Web Service returns a link, and the contract requires the label itself.
     *
     * By the time the link is followed, the shipment exists and will be invoiced. A temporary
     * failure is therefore retried; if the label still cannot be fetched, the exception carries
     * the expedition number and the URL, so that nothing is lost and nobody books twice.
     */
    private function downloadLabel(string $expedition, string $path): Label
    {
        $url = LabelDownloader::url($path, $this->labelSize);

        for ($attempt = 1; ; $attempt++) {
            try {
                return new Label($this->labels->download($url), LabelFormat::PDF, $this->labelSize);
            } catch (MondialRelayConnectionException $e) {
                if ($attempt >= self::LABEL_ATTEMPTS) {
                    throw new MondialRelayLabelException($expedition, $url, $e);
                }
            } catch (CarrierExceptionInterface $e) {
                throw new MondialRelayLabelException($expedition, $url, $e);
            }
        }
    }

    private function assertOptionsAreSupported(ShipmentRequest $request): void
    {
        if ($request->isMultiParcel()) {
            throw UnsupportedOptionException::forOption(ShipmentOption::MULTI_PARCEL, $this->getName());
        }

        foreach ($request->parcels as $parcel) {
            if ($parcel->isInsured()) {
                throw UnsupportedOptionException::forOption(ShipmentOption::INSURANCE, $this->getName());
            }

            if ($parcel->items !== [] && CustomsTerritory::requiresDeclaration($request->sender, $request->recipient)) {
                throw UnsupportedOptionException::forOption(ShipmentOption::CUSTOMS_DECLARATION, $this->getName());
            }
        }
    }

    /**
     * Mondial Relay rejects a Point Relais service without a point, but books a home delivery
     * sent with one - the point is ignored and the parcel goes to the door.
     */
    private function assertPickupPointMatchesService(ShipmentRequest $request): void
    {
        $needsPickupPoint = in_array($request->serviceCode, RequestMapper::PICKUP_POINT_SERVICES, true);

        if ($needsPickupPoint && !$request->isPickupPointDelivery()) {
            throw new MondialRelayShipmentException(sprintf('Service "%s" delivers to a Point Relais: a pickup point is required.', $request->serviceCode));
        }

        if (!$needsPickupPoint && $request->isPickupPointDelivery()) {
            throw new MondialRelayShipmentException(sprintf(
                'Service "%s" delivers to the door; use a Point Relais service (%s) to deliver to "%s".',
                $request->serviceCode,
                implode(', ', RequestMapper::PICKUP_POINT_SERVICES),
                $request->pickupPointId
            ));
        }
    }
}
