<?php

declare(strict_types=1);

namespace CoolMS\Ooxml\Tests\Xml;

use CoolMS\Ooxml\Xml\Xml;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(Xml::class)]
final class XmlTest extends TestCase
{
    #[Test]
    public function textEscapesTheThreeCharactersThatBreakMarkup(): void
    {
        self::assertSame('a &amp; b', Xml::text('a & b'));
        self::assertSame('&lt;p&gt;', Xml::text('<p>'));
        // An ampersand already part of an entity is escaped AGAIN, which is
        // right: the author typed six characters and six come back.
        self::assertSame('&amp;amp;', Xml::text('&amp;'));
    }

    #[Test]
    public function anAttributeAlsoEscapesQuotes(): void
    {
        self::assertSame('say &quot;hi&quot;', Xml::attribute('say "hi"'));
        self::assertSame('it&apos;s', Xml::attribute("it's"));
    }

    /**
     * ⚠️ The regression that made every attribute in the package come out
     * EMPTY, silently.
     *
     * The strip pattern carried `\x{D800}-\x{DFFF}` for surrogates. Those are
     * not valid Unicode scalar values, so PCRE refuses to compile the pattern
     * in `/u` mode, `preg_replace` returns null, and a `(string)` cast turns
     * that into ''. Ordinary text is the case that proves the pattern compiles
     * at all — which is why this asserts on a plain word rather than on
     * anything exotic.
     */
    #[Test]
    public function ordinaryTextSurvivesIntact(): void
    {
        self::assertSame('worksheets/sheet1.xml', Xml::attribute('worksheets/sheet1.xml'));
        self::assertSame('Quarterly orders', Xml::text('Quarterly orders'));
        self::assertSame('Счета за 2027', Xml::text('Счета за 2027'));
        self::assertSame('日本語', Xml::attribute('日本語'));
    }

    /**
     * XML 1.0 cannot represent these at all — not as entities, not escaped.
     * A stray one from a terminal paste makes the whole part unparseable, and
     * the error names a byte offset rather than a cell.
     */
    #[Test]
    public function charactersXmlCannotCarryAreDropped(): void
    {
        self::assertSame('ab', Xml::text("a\x00b"));
        self::assertSame('ab', Xml::text("a\x0Bb"));
        self::assertSame('ab', Xml::attribute("a\x1Fb"));
    }

    /** The three whitespace controls XML DOES allow are kept. */
    #[Test]
    public function tabNewlineAndCarriageReturnAreKept(): void
    {
        self::assertSame("a\tb", Xml::text("a\tb"));
        self::assertSame("a\nb", Xml::text("a\nb"));
        self::assertSame("a\rb", Xml::text("a\rb"));
    }
}
