<?php

declare(strict_types=1);

namespace CoolMS\Ooxml\Package;

use CoolMS\Ooxml\Xml\Xml;
use CoolMS\Ooxml\Zip\ZipWriter;
use InvalidArgumentException;

use function array_key_exists;
use function count;
use function ltrim;
use function pathinfo;
use function sprintf;
use function str_contains;
use function strtolower;

/**
 * An Open Packaging Conventions container, assembled part by part.
 *
 * OOXML's outer layer is the same for every Office format: a zip whose entries
 * are PARTS, one `[Content_Types].xml` declaring what each part holds, and
 * `.rels` files describing how parts point at each other. Only the parts
 * themselves differ between a workbook and a document, which is why this knows
 * nothing about either.
 *
 * ## The two rules a package must not break
 *
 * **Every part needs a declared content type.** A part with none is not "the
 * default", it is a package Word and Excel refuse to open. Adding a part
 * therefore takes its type, and there is no way to add one without.
 *
 * **A part nothing points at is invisible.** Relationships are the only route
 * from the package root to a document and from a document to its pieces; a
 * worksheet with no relationship from the workbook is simply not in the
 * workbook, and the file opens successfully showing nothing. That failure looks
 * like a bug in the writer that produced the CONTENT, so it costs a long time
 * to find.
 */
final class OpcPackage
{
    /**
     * Part name to content.
     *
     * @var array<string, string>
     */
    private array $parts = [];

    /**
     * Extension to content type, for parts that share one (`rels`, `xml`).
     *
     * @var array<string, string>
     */
    private array $defaults = [];

    /**
     * Part name to content type, for everything else.
     *
     * @var array<string, string>
     */
    private array $overrides = [];

    /**
     * Source part name (or '' for the package root) to its relationships.
     *
     * @var array<string, list<array{id: string, type: string, target: string, external: bool}>>
     */
    private array $relationships = [];

    /**
     * Declare a content type for every part with this extension.
     *
     * Defaults keep `[Content_Types].xml` small where a package holds many
     * parts of one kind — twenty worksheets do not need twenty overrides.
     */
    public function declareDefault(string $extension, string $contentType): void
    {
        $this->defaults[strtolower($extension)] = $contentType;
    }

    /**
     * Add a part and say what it holds.
     *
     * @param string $name        package-absolute, without a leading slash: `xl/workbook.xml`
     * @param string $contentType null only when a {@see declareDefault} already covers the extension
     */
    public function addPart(string $name, string $content, ?string $contentType = null): void
    {
        $name = ltrim($name, '/');
        if ('' === $name) {
            throw new InvalidArgumentException('A part name cannot be empty.');
        }
        if (array_key_exists($name, $this->parts)) {
            throw new InvalidArgumentException(sprintf('Part "%s" was added twice.', $name));
        }

        $extension = strtolower((string) pathinfo($name, PATHINFO_EXTENSION));
        if (null === $contentType && !array_key_exists($extension, $this->defaults)) {
            throw new InvalidArgumentException(sprintf('Part "%s" has no content type and no default covers ".%s". A part without one is a package Office refuses to open.', $name, $extension));
        }

        $this->parts[$name] = $content;
        if (null !== $contentType) {
            $this->overrides[$name] = $contentType;
        }
    }

    /**
     * Point a part at something OUTSIDE the package — a URL.
     *
     * ⚠️ `TargetMode="External"` is what makes the target a destination rather
     * than a part name. Without it a reader looks for a PART called
     * `https://example.com`, does not find one, and reports a corrupt package —
     * so the failure is not a dead link, it is a document that will not open.
     *
     * The target is written verbatim: it is not a path inside the package and
     * must not be resolved against the source's folder.
     *
     * @return string the relationship id, which the source part must carry as `r:id`
     */
    public function relateExternal(string $source, string $type, string $target): string
    {
        $source = ltrim($source, '/');
        $id = 'rId' . (count($this->relationships[$source] ?? []) + 1);

        $this->relationships[$source][] = [
            'id' => $id,
            'type' => $type,
            'target' => $target,
            'external' => true,
        ];

        return $id;
    }

    /**
     * Point one part at another, or the package root at a part.
     *
     * @param string $source '' for the package root, else the part doing the pointing
     * @param string $target package-absolute; it is written relative to the source's folder
     *
     * @return string the relationship id, which the source part must carry as `r:id`
     */
    public function relate(string $source, string $type, string $target): string
    {
        $source = ltrim($source, '/');
        $id = 'rId' . (count($this->relationships[$source] ?? []) + 1);

        $this->relationships[$source][] = [
            'id' => $id,
            'type' => $type,
            'target' => $this->relativise($source, ltrim($target, '/')),
            'external' => false,
        ];

        return $id;
    }

    /** The finished package. */
    public function toBytes(): string
    {
        $zip = new ZipWriter();

        // FIRST, by convention: readers are permitted to stop looking for it
        // once they have read another part, and some do.
        $zip->add('[Content_Types].xml', $this->contentTypesXml());

        foreach ($this->relationships as $source => $entries) {
            $zip->add($this->relationshipPartFor($source), $this->relationshipsXml($entries));
        }

        foreach ($this->parts as $name => $content) {
            $zip->add($name, $content);
        }

        return $zip->toBytes();
    }

    private function contentTypesXml(): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">';

        foreach ($this->defaults as $extension => $type) {
            $xml .= sprintf(
                '<Default Extension="%s" ContentType="%s"/>',
                Xml::attribute($extension),
                Xml::attribute($type),
            );
        }
        foreach ($this->overrides as $name => $type) {
            $xml .= sprintf(
                '<Override PartName="/%s" ContentType="%s"/>',
                Xml::attribute($name),
                Xml::attribute($type),
            );
        }

        return $xml . '</Types>';
    }

    /** @param list<array{id: string, type: string, target: string, external: bool}> $entries */
    private function relationshipsXml(array $entries): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">';

        foreach ($entries as $entry) {
            $xml .= sprintf(
                '<Relationship Id="%s" Type="%s" Target="%s"%s/>',
                Xml::attribute($entry['id']),
                Xml::attribute($entry['type']),
                Xml::attribute($entry['target']),
                $entry['external'] ? ' TargetMode="External"' : '',
            );
        }

        return $xml . '</Relationships>';
    }

    /**
     * Where a part's relationships live: `xl/workbook.xml` keeps them in
     * `xl/_rels/workbook.xml.rels`, and the package root in `_rels/.rels`.
     */
    private function relationshipPartFor(string $source): string
    {
        if ('' === $source) {
            return '_rels/.rels';
        }

        $folder = (string) pathinfo($source, PATHINFO_DIRNAME);
        $file = (string) pathinfo($source, PATHINFO_BASENAME);

        return ('.' === $folder ? '' : $folder . '/') . '_rels/' . $file . '.rels';
    }

    /**
     * A relationship target is resolved against the SOURCE's folder, not the
     * package root — `xl/workbook.xml` reaches a sheet as `worksheets/sheet1.xml`.
     *
     * Only the common case is handled: a target inside the source's own folder,
     * or anywhere when the source is the package root. Anything else would need
     * `../` segments, and rather than emit a path this has not reasoned about,
     * it refuses.
     */
    private function relativise(string $source, string $target): string
    {
        if ('' === $source) {
            return $target;
        }

        $folder = (string) pathinfo($source, PATHINFO_DIRNAME);
        if ('.' === $folder) {
            return $target;
        }

        $prefix = $folder . '/';
        if (!str_contains($target, $prefix) || 0 !== strpos($target, $prefix)) {
            throw new InvalidArgumentException(sprintf('Relationship from "%s" to "%s" would need to climb out of "%s", which this package does not emit.', $source, $target, $folder));
        }

        return substr($target, strlen($prefix));
    }
}
