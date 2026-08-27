<?php

declare(strict_types=1);

namespace CoolMS\Ooxml\Package;

use CoolMS\Ooxml\Zip\ZipReader;
use RuntimeException;
use SimpleXMLElement;

use function array_key_exists;
use function array_keys;
use function array_slice;
use function implode;
use function libxml_use_internal_errors;
use function ltrim;
use function simplexml_load_string;
use function sprintf;

/**
 * Reading an Open Packaging Conventions container.
 *
 * The counterpart to {@see OpcPackage}. Where that one assembles parts and
 * relationships into bytes, this takes bytes apart into parts and follows the
 * relationships between them — which is the only way to find anything in an
 * OOXML file, because part NAMES are conventions and only the relationship
 * graph is guaranteed.
 *
 * ⚠️ **Never look for `xl/workbook.xml` by name.** Excel writes it there and so
 * do we, but the specification does not require it: the package root has one
 * `officeDocument` relationship and its target is the workbook, wherever the
 * producer chose to put it. Files in the wild do differ — this is the single
 * most common way a hand-rolled reader works on its own output and fails on a
 * real spreadsheet.
 */
final class OpcReader
{
    /** Kept as an alias so callers need not know where it is defined. */
    public const string OFFICE_DOCUMENT = Relationships::OFFICE_DOCUMENT;

    /** @var array<string, string> part name to content */
    private array $parts;

    /** @param array<string, string> $parts */
    private function __construct(array $parts)
    {
        $this->parts = $parts;
    }

    /** @throws RuntimeException when the bytes are not a readable package */
    public static function open(string $bytes): self
    {
        return new self(ZipReader::read($bytes));
    }

    public function has(string $name): bool
    {
        return array_key_exists(ltrim($name, '/'), $this->parts);
    }

    /** @throws RuntimeException when the part is absent */
    public function part(string $name): string
    {
        $name = ltrim($name, '/');
        if (!array_key_exists($name, $this->parts)) {
            throw new RuntimeException(sprintf('The package has no part "%s".', $name));
        }

        return $this->parts[$name];
    }

    /** The part if it is there — many OPC parts are optional. */
    public function partIfPresent(string $name): ?string
    {
        return $this->has($name) ? $this->part($name) : null;
    }

    /** @return list<string> */
    public function names(): array
    {
        return array_keys($this->parts);
    }

    /**
     * A part parsed as XML.
     *
     * ⚠️ Internal errors are turned ON and restored: libxml's default is to
     * emit warnings straight to output, which in a web process means XML
     * fragments printed into an API response.
     *
     * @throws RuntimeException when the part is not well-formed
     */
    public function xml(string $name): SimpleXMLElement
    {
        $previous = libxml_use_internal_errors(true);

        try {
            $parsed = simplexml_load_string($this->part($name));
        } finally {
            libxml_use_internal_errors($previous);
        }

        if (false === $parsed) {
            throw new RuntimeException(sprintf('Part "%s" is not well-formed XML.', $name));
        }

        return $parsed;
    }

    /**
     * The relationships declared BY a part, by id.
     *
     * @param string $source '' for the package root, else the part doing the pointing
     *
     * @return array<string, array{type: string, target: string, external: bool}>
     */
    public function relationshipsOf(string $source): array
    {
        $part = Relationships::partFor($source);

        return $this->has($part) ? Relationships::parse($this->part($part), $source) : [];
    }

    /**
     * The first relationship of a type, or null.
     *
     * @return array{id: string, type: string, target: string, external: bool}|null
     */
    public function relationshipOfType(string $source, string $type): ?array
    {
        foreach ($this->relationshipsOf($source) as $id => $relationship) {
            if ($relationship['type'] === $type) {
                return ['id' => $id] + $relationship;
            }
        }

        return null;
    }

    /**
     * The main document part, found through the relationship graph.
     *
     * @throws RuntimeException when the package declares none
     */
    public function mainPart(): string
    {
        $relationship = $this->relationshipOfType('', self::OFFICE_DOCUMENT);
        if (null === $relationship) {
            throw new RuntimeException('The package root declares no officeDocument relationship, so it names no main part. It holds: ' . implode(', ', array_slice($this->names(), 0, 12)));
        }

        return $relationship['target'];
    }
}
