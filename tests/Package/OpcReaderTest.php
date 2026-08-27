<?php

declare(strict_types=1);

namespace CoolMS\Ooxml\Tests\Package;

use CoolMS\Ooxml\Package\OpcPackage;
use CoolMS\Ooxml\Package\OpcReader;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use ZipArchive;

/**
 * Reading a package apart.
 *
 * ## The archives here are NOT all ours
 *
 * A reader that only ever sees its own writer's output learns that writer's
 * habits and nothing about the format. So the cases below read an archive
 * written by PHP's own `ZipArchive` as well as one written by
 * {@see \CoolMS\Ooxml\Zip\ZipWriter} — different compression choices, different
 * extra fields, different everything except the specification.
 */
#[CoversClass(OpcReader::class)]
final class OpcReaderTest extends TestCase
{
    private const string FIXTURES = __DIR__ . '/../fixtures/docx/';

    private const string WORKSHEET_REL
        = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet';

    #[Test]
    public function itReadsBackWhatOurOwnWriterProduced(): void
    {
        $reader = OpcReader::open($this->ourPackage());

        self::assertTrue($reader->has('xl/workbook.xml'));
        self::assertSame('<workbook/>', $reader->part('xl/workbook.xml'));
        self::assertSame('<worksheet/>', $reader->part('xl/worksheets/sheet1.xml'));
    }

    /**
     * ⚠️ The main part is found through the RELATIONSHIP GRAPH, never by name.
     * `xl/workbook.xml` is a convention, not a requirement, and looking for it
     * by name is the usual way a reader works on its own output and fails on a
     * real spreadsheet.
     */
    #[Test]
    public function theMainPartComesFromTheRelationshipNotFromItsName(): void
    {
        $package = new OpcPackage();
        $package->declareDefault('rels', 'application/vnd.openxmlformats-package.relationships+xml');
        $package->declareDefault('xml', 'application/xml');
        // Deliberately NOT where anyone would look for it.
        $package->addPart('parts/book.xml', '<workbook/>', 'application/xml');
        $package->relate('', OpcReader::OFFICE_DOCUMENT, 'parts/book.xml');

        self::assertSame('parts/book.xml', OpcReader::open($package->toBytes())->mainPart());
    }

    #[Test]
    public function aRelationshipTargetResolvesAgainstItsSourcesFolder(): void
    {
        $reader = OpcReader::open($this->ourPackage());

        // Written as `worksheets/sheet1.xml` inside `xl/`, and it has to come
        // back as the package-absolute name a part lookup takes.
        $sheet = $reader->relationshipOfType('xl/workbook.xml', self::WORKSHEET_REL);

        self::assertNotNull($sheet);
        self::assertSame('xl/worksheets/sheet1.xml', $sheet['target']);
        self::assertFalse($sheet['external']);
    }

    #[Test]
    public function anExternalTargetIsKeptVerbatim(): void
    {
        $package = new OpcPackage();
        $package->declareDefault('rels', 'application/vnd.openxmlformats-package.relationships+xml');
        $package->declareDefault('xml', 'application/xml');
        $package->addPart('xl/worksheets/sheet1.xml', '<worksheet/>', 'application/xml');
        $package->relateExternal('xl/worksheets/sheet1.xml', 'http://x/hyperlink', 'https://example.com/a?b=1&c=2');

        $links = OpcReader::open($package->toBytes())->relationshipsOf('xl/worksheets/sheet1.xml');

        self::assertCount(1, $links);
        self::assertArrayHasKey('rId1', $links);
        $link = $links['rId1'];
        self::assertTrue($link['external']);
        // Not resolved against `xl/worksheets/`, and the `&` survives the
        // escaping it needed to be written at all.
        self::assertSame('https://example.com/a?b=1&c=2', $link['target']);
    }

    /**
     * The case that matters: an archive from a DIFFERENT zip writer.
     *
     * `ZipArchive` chooses its own compression and writes its own extra fields,
     * including ones whose length differs between the local header and the
     * central directory — which is exactly where a reader that trusts the
     * wrong length starts inflating garbage.
     */
    #[Test]
    public function itReadsAnArchiveWrittenByPhpsOwnZipExtension(): void
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'opc');

        $zip = new ZipArchive();
        $zip->open($path, ZipArchive::OVERWRITE);
        $zip->addFromString('[Content_Types].xml', '<Types/>');
        $zip->addFromString('_rels/.rels', sprintf(
            '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="%s" Target="xl/workbook.xml"/></Relationships>',
            OpcReader::OFFICE_DOCUMENT,
        ));
        // Long enough that the writer will deflate it rather than store it.
        $zip->addFromString('xl/workbook.xml', '<workbook>' . str_repeat('<sheet/>', 400) . '</workbook>');
        $zip->close();

        $reader = OpcReader::open((string) file_get_contents($path));
        unlink($path);

        self::assertSame('xl/workbook.xml', $reader->mainPart());
        self::assertStringStartsWith('<workbook>', $reader->part('xl/workbook.xml'));
        self::assertSame(400, substr_count($reader->part('xl/workbook.xml'), '<sheet/>'));
    }

    #[Test]
    public function aPartCanBeParsedAsXml(): void
    {
        $reader = OpcReader::open($this->ourPackage());

        self::assertSame('workbook', $reader->xml('xl/workbook.xml')->getName());
    }

    #[Test]
    public function anAbsentPartIsReportedRatherThanReturnedEmpty(): void
    {
        $reader = OpcReader::open($this->ourPackage());

        self::assertNull($reader->partIfPresent('xl/styles.xml'));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/has no part/');
        $reader->part('xl/styles.xml');
    }

    #[Test]
    public function bytesThatAreNotAZipAreRefused(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/Not a zip archive/');
        OpcReader::open('this is a text file, not a workbook');
    }

    /** A package with no officeDocument relationship names no main part. */
    #[Test]
    public function aPackageWithNoMainPartSaysSo(): void
    {
        $package = new OpcPackage();
        $package->declareDefault('xml', 'application/xml');
        $package->addPart('xl/orphan.xml', '<x/>');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/declares no officeDocument relationship/');
        OpcReader::open($package->toBytes())->mainPart();
    }
    // ── the shape a spreadsheet APPLICATION writes ───────────────────────

    /**
     * ⚠️ LibreOffice writes every entry with a DATA DESCRIPTOR.
     *
     * General-purpose bit 3 says the CRC and the two sizes follow the
     * compressed data instead of preceding it, and the local header carries
     * zeroes for all three. A reader taking its sizes from there inflates
     * nothing and reports a perfectly good file as damaged.
     *
     * MEASURED 2026-08-26 while building a two-producer corpus: **12 of 12**
     * LibreOffice-written workbooks were refused, and the same shape appears in
     * every `.docx` it saves. Nothing this project wrote had ever produced it,
     * and neither had PhpSpreadsheet -- which is exactly why auditing our own
     * output proved nothing.
     *
     * The fixture is a real file LibreOffice saved, not one built here: the
     * point is bytes we did not choose.
     */
    #[Test]
    public function readsAPackageWhoseEntriesUseADataDescriptor(): void
    {
        $bytes = (string) file_get_contents(self::FIXTURES . 'libreoffice-saved.docx');

        // The fixture must actually BE the shape under test, or this passes for
        // the wrong reason the day somebody replaces it.
        self::assertTrue(
            $this->hasDataDescriptorEntries($bytes),
            'the fixture must carry entries with general-purpose bit 3 set',
        );

        $reader = OpcReader::open($bytes);
        $main = $reader->mainPart();

        self::assertStringContainsString('<w:document', $reader->part($main));
        self::assertStringContainsString('Relationship', $reader->part('_rels/.rels'));
    }

    /** Every entry, not just the one the main part happens to be. */
    #[Test]
    public function readsEveryPartOfSuchAPackage(): void
    {
        $reader = OpcReader::open((string) file_get_contents(self::FIXTURES . 'libreoffice-saved.docx'));

        $empty = [];
        foreach ($reader->names() as $name) {
            if ('' === $reader->part($name)) {
                $empty[] = $name;
            }
        }

        self::assertSame([], $empty, 'a part that inflates to nothing is the defect this covers');
    }

    /** Big-endian is the wire; a zip local header is LITTLE-endian. */
    private static function uint16(string $bytes, int $at): int
    {
        return ord($bytes[$at]) | (ord($bytes[$at + 1]) << 8);
    }

    private function ourPackage(): string
    {
        $package = new OpcPackage();
        $package->declareDefault('rels', 'application/vnd.openxmlformats-package.relationships+xml');
        $package->declareDefault('xml', 'application/xml');
        $package->addPart('xl/worksheets/sheet1.xml', '<worksheet/>', 'application/xml');
        $package->relate('xl/workbook.xml', self::WORKSHEET_REL, 'xl/worksheets/sheet1.xml');
        $package->addPart('xl/workbook.xml', '<workbook/>', 'application/xml');
        $package->relate('', OpcReader::OFFICE_DOCUMENT, 'xl/workbook.xml');

        return $package->toBytes();
    }

    /** True when any local header sets general-purpose bit 3. */
    private function hasDataDescriptorEntries(string $bytes): bool
    {
        $offset = 0;
        while (false !== ($offset = strpos($bytes, "PK\x03\x04", $offset))) {
            $flags = self::uint16($bytes, $offset + 6);
            if (0 !== ($flags & 0x08)) {
                return true;
            }
            $offset += 4;
        }

        return false;
    }
}
