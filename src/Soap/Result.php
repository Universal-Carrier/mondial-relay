<?php declare(strict_types=1);

namespace UniversalCarrier\MondialRelay\Soap;

use UniversalCarrier\MondialRelay\Exception\MondialRelayProtocolException;

/**
 * A typed reader over the objects ext-soap builds from Mondial Relay's answers.
 *
 * Every field of these answers is declared as a string; an empty element comes back as an
 * empty string or not at all, depending on the service. This reader smooths both into ''.
 *
 * @internal
 */
final readonly class Result
{

    public function __construct(
        private object $data,
        private string $path
    ) {}

    public function string(string $name): string
    {
        $value = $this->data->{$name} ?? '';

        return is_scalar($value) ? trim((string)$value) : throw $this->unexpected($name, 'a string');
    }

    public function stat(): int
    {
        $stat = $this->string('STAT');

        return ctype_digit($stat) ? (int)$stat : throw $this->unexpected('STAT', 'a number');
    }

    public function has(string $name): bool
    {
        return isset($this->data->{$name});
    }

    /**
     * Reads a list, such as `PointsRelais->PointRelais_Details`.
     *
     * @return list<self>
     */
    public function list(string $name, string $item): array
    {
        $container = $this->data->{$name} ?? null;

        if ($container === null) {
            return [];
        }

        $items = is_object($container) ? ($container->{$item} ?? []) : throw $this->unexpected($name, 'an object');
        $items = is_array($items) ? $items : [$items];
        $results = [];

        foreach (array_values($items) as $index => $entry) {
            $results[] = is_object($entry)
                ? new self($entry, sprintf('%s.%s.%s[%d]', $this->path, $name, $item, $index))
                : throw $this->unexpected(sprintf('%s.%s[%d]', $name, $item, $index), 'an object');
        }

        return $results;
    }

    /**
     * Reads an `ArrayOfString`, such as the opening hours of one day.
     *
     * @return list<string>
     */
    public function strings(string $name): array
    {
        $container = $this->data->{$name} ?? null;

        if ($container === null) {
            return [];
        }

        $items = is_object($container) ? ($container->string ?? []) : throw $this->unexpected($name, 'an object');
        $items = is_array($items) ? $items : [$items];

        return array_values(array_map(
            fn (mixed $value): string => is_scalar($value) || $value === null
                ? trim((string)$value)
                : throw $this->unexpected($name, 'a list of strings'),
            $items
        ));
    }

    public function raw(): object
    {
        return $this->data;
    }

    private function unexpected(string $name, string $expected): MondialRelayProtocolException
    {
        return new MondialRelayProtocolException(sprintf(
            'Expected "%s.%s" to be %s in the Mondial Relay answer.',
            $this->path,
            $name,
            $expected
        ));
    }
}
