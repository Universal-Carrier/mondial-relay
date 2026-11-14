<?php declare(strict_types=1);

namespace UniversalCarrier\MondialRelay;

/**
 * The text conventions of Mondial Relay's Web Services.
 *
 * Outbound, address and reference fields accept uppercase ASCII letters, digits and a few
 * punctuation marks, within a fixed length. Inbound, apostrophes come back doubled - "L''HOTEL
 * DE VILLE" - and blank fields as a single space.
 *
 * @internal
 */
final class Text
{

    private const array FALLBACK = [
        'À' => 'A', 'Á' => 'A', 'Â' => 'A', 'Ã' => 'A', 'Ä' => 'A', 'Å' => 'A', 'Æ' => 'AE', 'Ç' => 'C',
        'È' => 'E', 'É' => 'E', 'Ê' => 'E', 'Ë' => 'E', 'Ì' => 'I', 'Í' => 'I', 'Î' => 'I', 'Ï' => 'I',
        'Ñ' => 'N', 'Ò' => 'O', 'Ó' => 'O', 'Ô' => 'O', 'Õ' => 'O', 'Ö' => 'O', 'Ø' => 'O', 'Œ' => 'OE',
        'Ù' => 'U', 'Ú' => 'U', 'Û' => 'U', 'Ü' => 'U', 'Ý' => 'Y', 'Ÿ' => 'Y', 'ß' => 'SS',
    ];

    /**
     * Brings a value within what an address or reference field accepts: ASCII, uppercase,
     * letters, digits and `_-'.,/` and spaces, cut at the field's length.
     */
    public static function field(?string $value, int $maxLength, string $allowed = "A-Z0-9_\\-'.,\\/ "): string
    {
        if ($value === null) {
            return '';
        }

        $ascii = self::toUpperAscii($value);
        $cleaned = preg_replace(sprintf('/[^%s]+/', $allowed), ' ', $ascii) ?? '';
        $collapsed = trim(preg_replace('/\s+/', ' ', $cleaned) ?? '');

        return rtrim(substr($collapsed, 0, $maxLength));
    }

    /**
     * Undoes what Mondial Relay does to the text it returns.
     */
    public static function read(string $value): ?string
    {
        $value = trim(str_replace("''", "'", $value));

        return $value === '' ? null : $value;
    }

    private static function toUpperAscii(string $value): string
    {
        if (function_exists('transliterator_transliterate')) {
            $transliterated = transliterator_transliterate('Any-Latin; Latin-ASCII; Upper()', $value);

            if ($transliterated !== false) {
                return $transliterated;
            }
        }

        return strtr(mb_strtoupper($value, 'UTF-8'), self::FALLBACK);
    }
}
