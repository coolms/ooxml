<?php

declare(strict_types=1);

namespace CoolMS\Ooxml\Package;

use RuntimeException;

use function libxml_use_internal_errors;
use function ltrim;
use function pathinfo;
use function simplexml_load_string;
use function str_starts_with;
use function substr;

/**
 * The `.rels` graph, parsed and resolved.
 *
 * Shared by {@see OpcReader} and {@see OpcEditor} rather than written twice.
 * Both need to answer "what does this part point at", and two implementations
 * of a resolution rule agree right up until one of them is fixed.
 */
final class Relationships
{
    public const string OFFICE_DOCUMENT
        = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument';

    /** Where a part's relationships live: the package root's are `_rels/.rels`. */
    public static function partFor(string $source): string
    {
        $source = ltrim($source, '/');
        if ('' === $source) {
            return '_rels/.rels';
        }

        $folder = (string) pathinfo($source, PATHINFO_DIRNAME);
        $file = (string) pathinfo($source, PATHINFO_BASENAME);

        return ('.' === $folder ? '' : $folder . '/') . '_rels/' . $file . '.rels';
    }

    /**
     * Parse one `.rels` part.
     *
     * @param string $source the part these belong TO, which targets resolve against
     *
     * @throws RuntimeException when the part is not well-formed
     *
     * @return array<string, array{type: string, target: string, external: bool}>
     */
    public static function parse(string $xml, string $source): array
    {
        $previous = libxml_use_internal_errors(true);

        try {
            $parsed = simplexml_load_string($xml);
        } finally {
            libxml_use_internal_errors($previous);
        }

        if (false === $parsed) {
            throw new RuntimeException('A relationships part is not well-formed XML.');
        }

        $relationships = [];
        foreach ($parsed->children() as $relationship) {
            $id = (string) ($relationship['Id'] ?? '');
            if ('' === $id) {
                continue;
            }

            $external = 'External' === (string) ($relationship['TargetMode'] ?? '');
            $target = (string) ($relationship['Target'] ?? '');

            $relationships[$id] = [
                'type' => (string) ($relationship['Type'] ?? ''),
                // An external target is a URL and must not be resolved against
                // a folder; an internal one is relative to the SOURCE's.
                'target' => $external ? $target : self::resolve(ltrim($source, '/'), $target),
                'external' => $external,
            ];
        }

        return $relationships;
    }

    /**
     * A relationship target resolved to a package-absolute part name.
     *
     * Targets are relative to the source part's FOLDER, and a leading `/` means
     * the package root — both forms appear in files written by Excel.
     */
    public static function resolve(string $source, string $target): string
    {
        if (str_starts_with($target, '/')) {
            return ltrim($target, '/');
        }

        $folder = '' === $source ? '.' : (string) pathinfo($source, PATHINFO_DIRNAME);
        if ('.' === $folder || '' === $folder) {
            return $target;
        }

        // `../` climbs out, which a real package uses to reach a sibling folder.
        while (str_starts_with($target, '../')) {
            $target = substr($target, 3);
            $folder = (string) pathinfo($folder, PATHINFO_DIRNAME);
            if ('.' === $folder) {
                $folder = '';
                break;
            }
        }

        return '' === $folder ? $target : $folder . '/' . $target;
    }
}
