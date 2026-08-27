<?php

declare(strict_types=1);

namespace CoolMS\Ooxml\Tests\Package;

use CoolMS\Ooxml\Package\OpcPackage;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ZipArchive;

/**
 * The container, checked with a zip reader that is not ours.
 *
 * Reading our own archive back with our own code would prove the two agree and
 * nothing else. `ZipArchive` is PHP's, written against the same spec Office
 * reads, so a header this gets wrong shows up here rather than in Excel.
 */
#[CoversClass(OpcPackage::class)]
final class OpcPackageTest extends TestCase
{
    private const string SHEET_TYPE = 'application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml';

    #[Test]
    public function itProducesAnArchivePhpsOwnZipReaderOpens(): void
    {
        $package = $this->minimal();

        $entries = $this->read($package->toBytes());

        self::assertArrayHasKey('[Content_Types].xml', $entries);
        self::assertArrayHasKey('_rels/.rels', $entries);
        self::assertArrayHasKey('xl/workbook.xml', $entries);
        self::assertArrayHasKey('xl/_rels/workbook.xml.rels', $entries);
        self::assertArrayHasKey('xl/worksheets/sheet1.xml', $entries);
    }

    /**
     * ⚠️ The reason this writer exists rather than `ZipArchive`: a fixed
     * timestamp, so the same document twice is the same bytes and a test may
     * compare artifacts instead of re-reading them.
     */
    #[Test]
    public function theSamePackageTwiceIsByteIdentical(): void
    {
        self::assertSame($this->minimal()->toBytes(), $this->minimal()->toBytes());
    }

    #[Test]
    public function everyPartIsDeclaredInContentTypes(): void
    {
        $types = $this->read($this->minimal()->toBytes())['[Content_Types].xml'];

        self::assertStringContainsString('<Default Extension="rels"', $types);
        self::assertStringContainsString('<Default Extension="xml"', $types);
        self::assertStringContainsString('<Override PartName="/xl/workbook.xml"', $types);
        self::assertStringContainsString('<Override PartName="/xl/worksheets/sheet1.xml"', $types);
    }

    /**
     * A relationship target is resolved against the SOURCE's folder, so the
     * workbook reaches its sheet as `worksheets/sheet1.xml` — an absolute path
     * here opens as an empty workbook rather than as an error.
     */
    #[Test]
    public function aRelationshipTargetIsRelativeToTheSourcePart(): void
    {
        $entries = $this->read($this->minimal()->toBytes());

        self::assertStringContainsString('Target="xl/workbook.xml"', $entries['_rels/.rels']);
        self::assertStringContainsString('Target="worksheets/sheet1.xml"', $entries['xl/_rels/workbook.xml.rels']);
        self::assertStringNotContainsString('Target="xl/worksheets', $entries['xl/_rels/workbook.xml.rels']);
    }

    #[Test]
    public function relationshipIdsAreUniquePerSourcePart(): void
    {
        $package = new OpcPackage();
        $package->declareDefault('xml', 'application/xml');
        $package->addPart('xl/a.xml', '<a/>');
        $package->addPart('xl/b.xml', '<b/>');

        self::assertSame('rId1', $package->relate('xl/workbook.xml', 'http://x', 'xl/a.xml'));
        self::assertSame('rId2', $package->relate('xl/workbook.xml', 'http://x', 'xl/b.xml'));
        // A different source starts its own numbering -- ids are scoped to the
        // .rels file they live in, not to the package.
        self::assertSame('rId1', $package->relate('', 'http://x', 'xl/a.xml'));
    }

    #[Test]
    public function aPartWithNoContentTypeIsRefused(): void
    {
        $package = new OpcPackage();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/no content type/');
        $package->addPart('xl/workbook.xml', '<workbook/>');
    }

    #[Test]
    public function theSamePartTwiceIsRefused(): void
    {
        $package = new OpcPackage();
        $package->declareDefault('xml', 'application/xml');
        $package->addPart('xl/a.xml', '<a/>');

        $this->expectException(InvalidArgumentException::class);
        $package->addPart('xl/a.xml', '<a/>');
    }

    /**
     * Refused rather than guessed. A `../` target is legal OOXML and this has
     * not reasoned about emitting one, and a path written by guesswork opens as
     * a document with a piece silently missing.
     */
    #[Test]
    public function aTargetOutsideTheSourcesFolderIsRefused(): void
    {
        $package = new OpcPackage();
        $package->declareDefault('xml', 'application/xml');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/climb out of/');
        $package->relate('xl/workbook.xml', 'http://x', 'docProps/core.xml');
    }

    /** Content survives the round trip through deflate, including a part too small to compress. */
    #[Test]
    public function partContentComesBackExactly(): void
    {
        $long = '<t>' . str_repeat('CoolMS ', 500) . '</t>';
        $package = new OpcPackage();
        $package->declareDefault('xml', 'application/xml');
        $package->addPart('xl/long.xml', $long);
        $package->addPart('xl/tiny.xml', '<a/>');

        $entries = $this->read($package->toBytes());

        self::assertSame($long, $entries['xl/long.xml']);
        self::assertSame('<a/>', $entries['xl/tiny.xml']);
    }

    // ── helpers ──────────────────────────────────────────────────────────

    /** The smallest thing shaped like a workbook: root -> workbook -> sheet. */
    private function minimal(): OpcPackage
    {
        $package = new OpcPackage();
        $package->declareDefault('rels', 'application/vnd.openxmlformats-package.relationships+xml');
        $package->declareDefault('xml', 'application/xml');

        $package->addPart(
            'xl/workbook.xml',
            '<workbook/>',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml',
        );
        $package->addPart('xl/worksheets/sheet1.xml', '<worksheet/>', self::SHEET_TYPE);

        $package->relate(
            '',
            'http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument',
            'xl/workbook.xml',
        );
        $package->relate(
            'xl/workbook.xml',
            'http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet',
            'xl/worksheets/sheet1.xml',
        );

        return $package;
    }

    /** @return array<string, string> part name to content */
    private function read(string $bytes): array
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'opc');
        file_put_contents($path, $bytes);

        $zip = new ZipArchive();
        self::assertTrue(true === $zip->open($path), 'PHP\'s own zip reader could not open the package.');

        $entries = [];
        for ($i = 0; $i < $zip->numFiles; ++$i) {
            $name = (string) $zip->getNameIndex($i);
            $entries[$name] = (string) $zip->getFromIndex($i);
        }
        $zip->close();
        unlink($path);

        return $entries;
    }
}
