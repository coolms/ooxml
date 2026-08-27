<?php

declare(strict_types=1);

namespace CoolMS\Ooxml\Package;

use CoolMS\Ooxml\Zip\ZipReader;
use CoolMS\Ooxml\Zip\ZipWriter;
use RuntimeException;

use function array_key_exists;
use function array_keys;
use function ltrim;
use function max;
use function pathinfo;
use function preg_match;
use function preg_quote;
use function sprintf;
use function str_starts_with;
use function strlen;
use function strrpos;
use function strtolower;
use function substr;
use function substr_replace;

use const PATHINFO_DIRNAME;
use const PATHINFO_EXTENSION;
use const PREG_OFFSET_CAPTURE;

/**
 * Editing a package in place: open, replace a part, save.
 *
 * ## Why this is not "read then write"
 *
 * Rebuilding a package from the parts a reader understood is how a document
 * loses everything the reader did not. This never builds anything: it copies
 * every entry across EXACTLY AS IT ARRIVED — still compressed, same checksum —
 * and re-compresses only what was replaced. A package opened and saved without
 * changes comes back byte-identical, which is the strongest form of "nothing
 * was lost" available.
 *
 * That is what the imported-template path needs. An operator's `.docx` carries
 * charts, revision history, custom XML and fonts that nothing here models, and
 * the promise is that filling `{var:}` tokens leaves all of it alone.
 *
 * ## Adding a part takes all three steps, or none
 *
 * This used to refuse outright, for a good reason: a part added without a
 * `[Content_Types].xml` declaration and a relationship is invisible to Word — a
 * silent no-op that looks like it worked. It now offers exactly the three verbs
 * {@see OpcPackage} does — {@see declareDefault()}, {@see addPart()},
 * {@see relate()} — so the reason is honoured rather than worked around, and
 * the two classes read the same.
 *
 * ⚠️ {@see addPart()} REFUSES a name already in the package. Adding and
 * replacing are different intentions and conflating them is how a fill quietly
 * overwrites an operator's part.
 */
final class OpcEditor
{
    private const string CONTENT_TYPES = '[Content_Types].xml';

    /** @var array<string, array<string, mixed>> raw entries, exactly as the file holds them */
    private array $entries;

    /** @var array<string, string> part name to its replacement content */
    private array $replacements = [];

    /** @var array<string, string> parts that were NOT in the file, in the order they were added */
    private array $added = [];

    /** @param array<string, array<string, mixed>> $entries */
    private function __construct(array $entries)
    {
        $this->entries = $entries;
    }

    /** @throws RuntimeException when the bytes are not a readable package */
    public static function open(string $bytes): self
    {
        return new self(ZipReader::entries($bytes));
    }

    public function has(string $name): bool
    {
        $name = ltrim($name, '/');

        return array_key_exists($name, $this->entries) || array_key_exists($name, $this->added);
    }

    /** @throws RuntimeException when the part is absent */
    public function part(string $name): string
    {
        $name = ltrim($name, '/');
        if (array_key_exists($name, $this->replacements)) {
            return $this->replacements[$name];
        }
        if (array_key_exists($name, $this->added)) {
            return $this->added[$name];
        }
        if (!array_key_exists($name, $this->entries)) {
            throw new RuntimeException(sprintf('The package has no part "%s".', $name));
        }

        /* @phpstan-ignore argument.type */
        return ZipReader::contentOf($this->entries[$name], $name);
    }

    /**
     * The main document part, found through the relationship graph.
     *
     * ⚠️ Never by name. `word/document.xml` is a convention Word happens to
     * follow; the specification only guarantees one `officeDocument`
     * relationship from the package root. Uses the same
     * {@see Relationships} the reader does, so the two cannot drift.
     *
     * @throws RuntimeException when the package declares none
     */
    public function mainPart(): string
    {
        $rels = Relationships::partFor('');
        if (!$this->has($rels)) {
            throw new RuntimeException('The package has no root relationships part, so it names no main part.');
        }

        foreach (Relationships::parse($this->part($rels), '') as $relationship) {
            if (Relationships::OFFICE_DOCUMENT === $relationship['type']) {
                return $relationship['target'];
            }
        }

        throw new RuntimeException('The package root declares no officeDocument relationship, so it names no main part.');
    }

    /**
     * Replace an existing part's content.
     *
     * @throws RuntimeException when the part is not already there
     */
    public function replace(string $name, string $content): void
    {
        $name = ltrim($name, '/');
        if (array_key_exists($name, $this->added)) {
            $this->added[$name] = $content;

            return;
        }
        if (!array_key_exists($name, $this->entries)) {
            throw new RuntimeException(sprintf('Cannot replace "%s": the package has no such part. Use addPart(), which declares its content type too.', $name));
        }

        $this->replacements[$name] = $content;
    }

    /**
     * Declare a content type for every part with this extension.
     *
     * Idempotent: a `Default` the package already carries is left alone, so
     * asking twice is safe and asking for something Word already declared
     * changes nothing.
     *
     * @throws RuntimeException when the package has no `[Content_Types].xml`
     */
    public function declareDefault(string $extension, string $contentType): void
    {
        $extension = strtolower($extension);
        $types = $this->contentTypes();

        if (1 === preg_match('/<Default\s[^>]*Extension="' . preg_quote($extension, '/') . '"/i', $types)) {
            return;
        }

        $this->replace(self::CONTENT_TYPES, $this->insertIntoTypes(
            $types,
            sprintf('<Default Extension="%s" ContentType="%s"/>', $extension, $contentType),
        ));
    }

    /**
     * Add a part that was not there, and say what it holds.
     *
     * @param string  $name        package-absolute, without a leading slash
     * @param ?string $contentType null only when a {@see declareDefault} covers the extension
     *
     * @throws RuntimeException when the part is already there, or nothing declares its type
     */
    public function addPart(string $name, string $content, ?string $contentType = null): void
    {
        $name = ltrim($name, '/');
        if ('' === $name) {
            throw new RuntimeException('A part name cannot be empty.');
        }
        if ($this->has($name)) {
            throw new RuntimeException(sprintf('Part "%s" is already in the package; use replace() to change it.', $name));
        }

        $extension = strtolower((string) pathinfo($name, PATHINFO_EXTENSION));
        $types = $this->contentTypes();
        $covered = 1 === preg_match('/<Default\s[^>]*Extension="' . preg_quote($extension, '/') . '"/i', $types);

        if (null === $contentType && !$covered) {
            throw new RuntimeException(sprintf('Part "%s" has no content type and no default covers ".%s". A part without one is a package Office refuses to open.', $name, $extension));
        }

        $this->added[$name] = $content;

        if (null !== $contentType) {
            $this->replace(self::CONTENT_TYPES, $this->insertIntoTypes(
                $this->contentTypes(),
                sprintf('<Override PartName="/%s" ContentType="%s"/>', $name, $contentType),
            ));
        }
    }

    /**
     * Point one part at another, or the package root at a part.
     *
     * ⚠️ Idempotent BY (type, target). A relationship the source already
     * declares is not written twice -- its existing id comes back instead --
     * because a second `rId` to the same target is a package Word opens and
     * LibreOffice complains about.
     *
     * ⚠️ The new id is picked ABOVE every id the part already uses, numeric or
     * not. Counting the existing relationships would reuse `rId3` in a package
     * whose ids run 1, 2, 4 -- which is what Word writes after an edit deletes
     * one.
     *
     * @param string $source '' for the package root, else the part doing the pointing
     * @param string $target package-absolute; written relative to the source's folder
     *
     * @return string the relationship id the source part must carry as `r:id`
     */
    public function relate(string $source, string $type, string $target): string
    {
        $source = ltrim($source, '/');
        $target = ltrim($target, '/');
        $part = Relationships::partFor($source);

        $existing = $this->has($part) ? Relationships::parse($this->part($part), $source) : [];
        foreach ($existing as $id => $relationship) {
            if ($relationship['type'] === $type && $relationship['target'] === $target) {
                return $id;
            }
        }

        $highest = 0;
        foreach (array_keys($existing) as $id) {
            if (1 === preg_match('/(\d+)/', $id, $digits)) {
                $highest = max($highest, (int) $digits[1]);
            }
        }
        $id = 'rId' . ($highest + 1);

        $entry = sprintf(
            '<Relationship Id="%s" Type="%s" Target="%s"/>',
            $id,
            $type,
            $this->relativise($source, $target),
        );

        if ($this->has($part)) {
            $this->replace($part, $this->insertBeforeClose($this->part($part), 'Relationships', $entry));
        } else {
            $this->addPart(
                $part,
                '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
                . $entry
                . '</Relationships>',
                'application/vnd.openxmlformats-package.relationships+xml',
            );
        }

        return $id;
    }

    /**
     * The package, with the replacements applied and everything else untouched.
     *
     * ⚠️ Entry ORDER is preserved along with the bytes. It carries no meaning to
     * the format, but reproducing the input exactly is a much easier property to
     * test than "equivalent", and a test that can assert equality catches things
     * a looser one waves through.
     */
    public function toBytes(): string
    {
        $zip = new ZipWriter();

        foreach ($this->entries as $name => $entry) {
            if (array_key_exists($name, $this->replacements)) {
                $zip->add($name, $this->replacements[$name]);
                continue;
            }

            /* @phpstan-ignore argument.type */
            $zip->addRaw($name, $entry);
        }

        // ⚠️ After the originals, so every entry that WAS in the file keeps its
        // position as well as its bytes.
        foreach ($this->added as $name => $content) {
            $zip->add($name, $content);
        }

        return $zip->toBytes();
    }

    private function contentTypes(): string
    {
        if (!$this->has(self::CONTENT_TYPES)) {
            throw new RuntimeException('The package has no [Content_Types].xml, so nothing can say what a new part holds.');
        }

        return $this->part(self::CONTENT_TYPES);
    }

    /**
     * ⚠️ `Default` elements before `Override` ones.
     *
     * The schema sequences them, and a package that interleaves them is one
     * Word reports as corrupt rather than reads leniently.
     */
    private function insertIntoTypes(string $types, string $entry): string
    {
        if (str_starts_with($entry, '<Default') && 1 === preg_match('/<Override[\s>]/', $types, $found, PREG_OFFSET_CAPTURE)) {
            return substr_replace($types, $entry, (int) $found[0][1], 0);
        }

        return $this->insertBeforeClose($types, 'Types', $entry);
    }

    private function insertBeforeClose(string $xml, string $element, string $entry): string
    {
        $close = '</' . $element . '>';
        $at = strrpos($xml, $close);
        if (false === $at) {
            // A root written as `<Types .../>` -- legal, and what an empty
            // relationships part looks like.
            $selfClosing = '/^(.*<' . preg_quote($element, '/') . '\b[^>]*)\/>\s*$/s';
            if (1 === preg_match($selfClosing, $xml, $parts)) {
                return $parts[1] . '>' . $entry . $close;
            }

            throw new RuntimeException(sprintf('Cannot insert into <%s>: the part does not close one.', $element));
        }

        return substr_replace($xml, $entry, $at, 0);
    }

    /** A package-absolute target, written relative to the source part's folder. */
    private function relativise(string $source, string $target): string
    {
        if ('' === $source) {
            return $target;
        }

        $folder = (string) pathinfo($source, PATHINFO_DIRNAME);
        if ('.' === $folder || '' === $folder) {
            return $target;
        }

        return str_starts_with($target, $folder . '/')
            ? substr($target, strlen($folder) + 1)
            : '/' . $target;
    }
}
