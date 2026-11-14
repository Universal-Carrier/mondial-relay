<?php declare(strict_types=1);

namespace UniversalCarrier\MondialRelay\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use UniversalCarrier\MondialRelay\Text;

#[CoversClass(Text::class)]
class TextTest extends TestCase
{
    public function testFieldsAreUppercaseAsciiWithinTheirLength(): void
    {
        $this->assertSame('51 AVENUE DES CHAMPS-ELYSEES', Text::field('51 avenue des Champs-Élysées', 32));
        $this->assertSame('KONIGSSTRASSE 12', Text::field('Königsstraße 12', 32));
        $this->assertSame('RUE DE L\'HOTEL', Text::field('rue de l’Hôtel', 32));
        $this->assertSame('BATIMENT B, ESCALIE', Text::field('Bâtiment B, escalier 4', 19));
    }

    public function testCharactersOutsideTheAllowedSetBecomeSpaces(): void
    {
        $this->assertSame('ORD 1001', Text::field('ord#1001', 15, 'A-Z0-9_\\- '));
        $this->assertSame('', Text::field(null, 32));
    }

    public function testReadingUndoesDoubledApostrophesAndBlanks(): void
    {
        $this->assertSame("48 RUE DE L'HOTEL DE VILLE", Text::read("48 RUE DE L''HOTEL DE VILLE"));
        $this->assertNull(Text::read(' '));
    }
}
