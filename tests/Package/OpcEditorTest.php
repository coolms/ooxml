<?php

declare(strict_types=1);

namespace CoolMS\Ooxml\Tests\Package;

use CoolMS\Ooxml\Package\OpcEditor;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use ZipArchive;

/**
 * Editing a package without disturbing it.
 *
 * The fixtures are REAL `.docx` files from the document-engine's suite —
 * written by Word, PHPWord and LibreOffice — because the property under test is
 * "everything I did not touch is exactly as it was", and that is only
 * interesting on packages full of things this code does not understand.
 */
#[CoversClass(OpcEditor::class)]
final class OpcEditorTest extends TestCase
{
    private const string FIXTURES = __DIR__ . '/../../../document-engine/tests/fixtures/docx/';

    /**
     * ⚠️ The whole point: opening and saving with no changes reproduces the
     * input EXACTLY. A rebuild-from-what-I-understood would pass a "still
     * opens" check and quietly drop the charts.
     */
    #[Test]
    public function openingAndSavingWithNoChangesReproducesTheFile(): void
    {
        foreach (['word-authored.docx', 'sdt-table.docx', 'page-gutter.docx'] as $name) {
            $original = $this->fixture($name);

            self::assertSame(
                $original,
                OpcEditor::open($original)->toBytes(),
                $name,
            );
        }
    }

    #[Test]
    public function replacingOnePartLeavesEveryOtherPartUntouched(): void
    {
        $original = $this->fixture('word-authored.docx');

        $editor = OpcEditor::open($original);
        $editor->replace('word/document.xml', '<w:document/>');
        $edited = $editor->toBytes();

        $before = $this->entries($original);
        $after = $this->entries($edited);

        self::assertSame(array_keys($before), array_keys($after), 'no part appeared or vanished');
        self::assertSame('<w:document/>', $after['word/document.xml']);

        foreach ($before as $part => $content) {
            if ('word/document.xml' === $part) {
                continue;
            }
            self::assertSame($content, $after[$part], $part);
        }
    }

    #[Test]
    public function aReplacedPartReadsBackAsWhatWasWritten(): void
    {
        $editor = OpcEditor::open($this->fixture('word-authored.docx'));

        self::assertStringContainsString('<w:document', $editor->part('word/document.xml'));

        $editor->replace('word/document.xml', '<w:document>edited</w:document>');

        self::assertSame('<w:document>edited</w:document>', $editor->part('word/document.xml'));
    }

    /**
     * `replace()` is for parts that are THERE. Adding one means declaring its
     * content type too, and a part without that is invisible to Word -- which
     * looks exactly like the edit having worked.
     */
    #[Test]
    public function replacingAPartThatIsNotThereIsRefused(): void
    {
        $editor = OpcEditor::open($this->fixture('word-authored.docx'));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/has no such part/');
        $editor->replace('word/invented.xml', '<x/>');
    }

    /** And the same in reverse: `addPart()` will not overwrite. */
    #[Test]
    public function addingAPartThatIsAlreadyThereIsRefused(): void
    {
        $editor = OpcEditor::open($this->fixture('word-authored.docx'));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/already in the package/');
        $editor->addPart('word/document.xml', '<x/>', 'text/xml');
    }

    /**
     * ⚠️ The whole reason this class used to refuse outright: a part with no
     * content type is one Office declines to open, so it is refused at the
     * door rather than written and discovered later.
     */
    #[Test]
    public function addingAPartNothingDeclaresIsRefused(): void
    {
        $editor = OpcEditor::open($this->fixture('word-authored.docx'));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/no content type/');
        $editor->addPart('word/invented.qqq', 'anything');
    }

    #[Test]
    public function anAddedPartArrivesWithItsOverrideAndItsRelationship(): void
    {
        $editor = OpcEditor::open($this->fixture('word-authored.docx'));
        $editor->addPart('word/invented.xml', '<x/>', 'application/xml');
        $id = $editor->relate('word/document.xml', 'http://example.test/rel/invented', 'word/invented.xml');

        $entries = $this->entries($editor->toBytes());

        self::assertSame('<x/>', $entries['word/invented.xml']);
        self::assertStringContainsString(
            '<Override PartName="/word/invented.xml" ContentType="application/xml"/>',
            $entries['[Content_Types].xml'],
        );
        // ⚠️ The target is written RELATIVE to the source part's folder, which
        // is what Word writes and what every reader resolves against.
        self::assertStringContainsString(
            sprintf('Id="%s" Type="http://example.test/rel/invented" Target="invented.xml"', $id),
            $entries['word/_rels/document.xml.rels'],
        );
    }

    /**
     * ⚠️ `Default` before `Override`. The schema sequences them, and a package
     * that interleaves them is one Word reports as corrupt rather than reads
     * leniently.
     */
    #[Test]
    public function aDeclaredDefaultLandsBeforeEveryOverride(): void
    {
        $editor = OpcEditor::open($this->fixture('word-authored.docx'));
        $editor->declareDefault('zzz', 'application/x-invented');

        $types = $this->entries($editor->toBytes())['[Content_Types].xml'];

        $default = strpos($types, '<Default Extension="zzz"');
        self::assertIsInt($default);
        $override = strpos($types, '<Override');
        self::assertIsInt($override);
        self::assertLessThan($override, $default);
    }

    /**
     * Asking twice changes nothing -- the caller does not have to check first.
     *
     * ⚠️ The second half uses `odttf`, which this fixture ALREADY declares,
     * because the attribute order is Word's: `ContentType` before `Extension`.
     * A check written against our own spelling would find nothing there and
     * declare it a second time.
     */
    #[Test]
    public function declaringADefaultTwiceWritesItOnce(): void
    {
        $editor = OpcEditor::open($this->fixture('word-authored.docx'));
        $editor->declareDefault('zzz', 'application/x-one');
        $editor->declareDefault('zzz', 'application/x-two');
        $editor->declareDefault('odttf', 'application/x-three');

        $types = $this->entries($editor->toBytes())['[Content_Types].xml'];

        self::assertSame(1, substr_count($types, 'Extension="zzz"'));
        self::assertStringNotContainsString('application/x-two', $types);

        self::assertSame(1, substr_count($types, 'Extension="odttf"'));
        self::assertStringNotContainsString('application/x-three', $types);
    }

    /**
     * ⚠️ Idempotent by (type, target). A second `rId` to the same place is a
     * package Word opens and LibreOffice complains about, and the caller that
     * relates once per face has no way to know the first one is already there.
     */
    #[Test]
    public function relatingTheSameTargetTwiceReturnsTheFirstId(): void
    {
        $editor = OpcEditor::open($this->fixture('word-authored.docx'));
        $editor->addPart('word/invented.xml', '<x/>', 'application/xml');

        $first = $editor->relate('word/document.xml', 'http://example.test/rel/x', 'word/invented.xml');
        $again = $editor->relate('word/document.xml', 'http://example.test/rel/x', 'word/invented.xml');

        self::assertSame($first, $again);
        self::assertSame(
            1,
            substr_count($this->entries($editor->toBytes())['word/_rels/document.xml.rels'], 'http://example.test/rel/x'),
        );
    }

    /**
     * ⚠️ ABOVE every id in use, not "one more than how many there are". Word
     * writes 1, 2, 4 after an edit deletes one, and counting would hand back
     * `rId3` -- an id that collides with nothing today and with the next
     * relationship the same pass adds.
     */
    #[Test]
    public function aNewRelationshipIdClearsEveryIdAlreadyInUse(): void
    {
        $editor = OpcEditor::open($this->fixture('word-authored.docx'));
        $rels = 'word/_rels/document.xml.rels';
        $editor->replace($rels, str_replace(
            '</Relationships>',
            '<Relationship Id="rId97" Type="http://example.test/rel/high" Target="high.xml"/></Relationships>',
            $editor->part($rels),
        ));
        $editor->addPart('word/invented.xml', '<x/>', 'application/xml');

        $id = $editor->relate('word/document.xml', 'http://example.test/rel/x', 'word/invented.xml');

        self::assertSame('rId98', $id);
    }

    /** A part this editor added is readable back before it is ever written. */
    #[Test]
    public function anAddedPartReadsBackWithoutASaveRoundTrip(): void
    {
        $editor = OpcEditor::open($this->fixture('word-authored.docx'));
        $editor->addPart('word/invented.xml', '<x/>', 'application/xml');

        self::assertTrue($editor->has('word/invented.xml'));
        self::assertSame('<x/>', $editor->part('word/invented.xml'));

        $editor->replace('word/invented.xml', '<y/>');

        self::assertSame('<y/>', $editor->part('word/invented.xml'));
    }

    #[Test]
    public function bytesThatAreNotAPackageAreRefused(): void
    {
        $this->expectException(RuntimeException::class);
        OpcEditor::open('not a docx');
    }

    /**
     * ⚠️ An archive must not CLAIM a data descriptor it does not write.
     *
     * LibreOffice sets general-purpose bit 3 on every entry, which says the CRC
     * and sizes follow the compressed data. This editor re-emits entries with
     * the real sizes in the local header and writes no descriptor -- so the bit
     * has to come off, or the output describes a layout that is not there and
     * every reader downstream has to guess.
     *
     * Found by MUTATION: clearing the bit was in the fix from the start and
     * NOTHING failed when it was removed. This is that test.
     */
    #[Test]
    public function stripsTheDataDescriptorFlagFromAPackageItRewrites(): void
    {
        $source = $this->fixture('libreoffice-saved.docx');
        self::assertNotSame([], $this->descriptorEntries($source), 'the fixture must set bit 3 to begin with');

        $editor = OpcEditor::open($source);
        $editor->replace($editor->mainPart(), '<w:document/>');

        self::assertSame([], $this->descriptorEntries($editor->toBytes()));
    }

    /**
     * And the rewritten package is still readable by somebody ELSE'S zip.
     *
     * The rest of this file reads back with PHP's own extension for the same
     * reason: a mistake shared by our reader and our writer cancels itself out
     * and looks like success.
     */
    #[Test]
    public function rewritesADataDescriptorPackageIntoOneAnyReaderOpens(): void
    {
        $editor = OpcEditor::open($this->fixture('libreoffice-saved.docx'));
        $editor->replace($editor->mainPart(), '<w:document/>');

        $entries = $this->entries($editor->toBytes());

        self::assertArrayHasKey('[Content_Types].xml', $entries);
        self::assertSame('<w:document/>', $entries[$editor->mainPart()]);
        // Untouched parts still come back as themselves, not as nothing.
        self::assertStringContainsString('Relationship', $entries['_rels/.rels']);
    }

    /** Big-endian is the wire; a zip local header is LITTLE-endian. */
    private static function uint16(string $bytes, int $at): int
    {
        return ord($bytes[$at]) | (ord($bytes[$at + 1]) << 8);
    }

    /**
     * The names of every entry whose local header sets general-purpose bit 3.
     *
     * @return list<string>
     */
    private function descriptorEntries(string $bytes): array
    {
        $found = [];
        $offset = 0;
        while (false !== ($offset = strpos($bytes, "PK\x03\x04", $offset))) {
            $flags = self::uint16($bytes, $offset + 6);
            $nameLength = self::uint16($bytes, $offset + 26);
            if (0 !== ($flags & 0x08)) {
                $found[] = substr($bytes, $offset + 30, $nameLength);
            }
            $offset += 4;
        }

        return $found;
    }

    private function fixture(string $name): string
    {
        $path = self::FIXTURES . $name;
        self::assertFileExists($path);

        return (string) file_get_contents($path);
    }

    /**
     * Read back with PHP's own zip extension rather than ours — a bug shared by
     * our reader and our writer would otherwise cancel out.
     *
     * @return array<string, string>
     */
    private function entries(string $bytes): array
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'edit');
        file_put_contents($path, $bytes);

        $zip = new ZipArchive();
        self::assertTrue(true === $zip->open($path), 'the edited package is not a readable archive');

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
