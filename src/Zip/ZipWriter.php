<?php

declare(strict_types=1);

namespace CoolMS\Ooxml\Zip;

use function count;
use function crc32;
use function gzdeflate;
use function pack;
use function strlen;

/**
 * A ZIP container, built in memory and byte-for-byte deterministic.
 *
 * ## Why not `ZipArchive`
 *
 * PHP's own zip extension is correct and fast, and it cannot write to a string:
 * every archive goes through a temporary FILE. Two things follow that matter
 * here. A generated document would touch the disk once per render, in a
 * container where the temp directory is one more thing to get right; and every
 * archive is stamped with the current time, so writing the same document twice
 * produces different bytes and no test may ever compare them.
 *
 * This writer stamps a FIXED timestamp for content it compresses itself. Same
 * document in, same bytes out, which is what lets a test assert on the artifact
 * rather than on a re-reading of it.
 *
 * ## Correctness here is binary
 *
 * A wrong CRC or a wrong offset does not degrade a file, it produces one that
 * nothing opens — Word and Excel reject rather than repair. The layout below is
 * APPNOTE 6.3.x: a local header per entry, then a central directory describing
 * every entry, then the end-of-central-directory record pointing at it.
 */
final class ZipWriter
{
    private const int LOCAL_HEADER_SIGNATURE = 0x04034B50;

    private const int CENTRAL_HEADER_SIGNATURE = 0x02014B50;

    private const int END_OF_CENTRAL_DIRECTORY_SIGNATURE = 0x06054B50;

    /** 2.0 — the version that introduced deflate, which is all this emits. */
    private const int VERSION_NEEDED = 20;

    private const int METHOD_STORED = 0;

    private const int METHOD_DEFLATE = 8;

    /**
     * 1980-01-01 00:00, the earliest a DOS timestamp can express.
     *
     * Fixed rather than "now" ON PURPOSE — see the class note. The encoding is
     * (year - 1980) << 9 | month << 5 | day for the date, and hours << 11 |
     * minutes << 5 | seconds / 2 for the time.
     */
    private const int DOS_DATE = 0x0021;

    private const int DOS_TIME = 0x0000;

    /**
     * @var list<array{
     *     name: string, method: int, crc: int, compressed: string, size: int,
     *     versionMadeBy: int, versionNeeded: int, flags: int, time: int, date: int,
     *     internalAttributes: int, externalAttributes: int
     * }>
     */
    private array $entries = [];

    /**
     * Add a file, compressing it here.
     *
     * Deflate is skipped when it would make the entry LONGER, which happens
     * with very short parts — a `.rels` file of a few hundred bytes is common
     * in OOXML. A real zip writer does the same, and it keeps the package
     * smaller than the thing it describes.
     */
    public function add(string $name, string $content): void
    {
        $deflated = gzdeflate($content, 9);
        $useDeflate = false !== $deflated && strlen($deflated) < strlen($content);

        $this->entries[] = [
            'name' => $name,
            'method' => $useDeflate ? self::METHOD_DEFLATE : self::METHOD_STORED,
            'crc' => crc32($content),
            'compressed' => $useDeflate ? (string) $deflated : $content,
            'size' => strlen($content),
            'versionMadeBy' => self::VERSION_NEEDED,
            'versionNeeded' => self::VERSION_NEEDED,
            'flags' => 0,
            'time' => self::DOS_TIME,
            'date' => self::DOS_DATE,
            'internalAttributes' => 0,
            'externalAttributes' => 0,
        ];
    }

    /**
     * Add an entry EXACTLY as it arrived — content, checksum and headers.
     *
     * The byte-preserving half of an edit. A part nobody touched is copied
     * rather than re-compressed, and its version, flags, timestamp and
     * attributes travel with it, so opening a package and saving it unchanged
     * reproduces the input byte for byte.
     *
     * ⚠️ The headers matter as much as the bytes. Re-stamping them produces a
     * file with identical CONTENT and different bytes — which passes every
     * "does it still open" check and fails the one assertion that would have
     * caught an entry being silently dropped.
     *
     * @param array{
     *     method: int, crc: int, compressed: string, size: int,
     *     versionMadeBy: int, versionNeeded: int, flags: int, time: int, date: int,
     *     internalAttributes: int, externalAttributes: int
     * } $entry
     */
    public function addRaw(string $name, array $entry): void
    {
        $this->entries[] = ['name' => $name] + $entry;
    }

    /** The finished archive. */
    public function toBytes(): string
    {
        $local = '';
        $central = '';

        foreach ($this->entries as $entry) {
            // Where THIS entry's local header begins, which the central
            // directory has to name and which is therefore read before the
            // header is appended.
            $offset = strlen($local);

            $local .= pack(
                'VvvvvvVVVvv',
                self::LOCAL_HEADER_SIGNATURE,
                $entry['versionNeeded'],
                $entry['flags'],
                $entry['method'],
                $entry['time'],
                $entry['date'],
                $entry['crc'],
                strlen($entry['compressed']),
                $entry['size'],
                strlen($entry['name']),
                0,                                  // extra field length
            );
            $local .= $entry['name'];
            $local .= $entry['compressed'];

            $central .= pack(
                'VvvvvvvVVVvvvvvVV',
                self::CENTRAL_HEADER_SIGNATURE,
                $entry['versionMadeBy'],
                $entry['versionNeeded'],
                $entry['flags'],
                $entry['method'],
                $entry['time'],
                $entry['date'],
                $entry['crc'],
                strlen($entry['compressed']),
                $entry['size'],
                strlen($entry['name']),
                0,                                  // extra field length
                0,                                  // comment length
                0,                                  // disk number start
                $entry['internalAttributes'],
                $entry['externalAttributes'],
                $offset,
            );
            $central .= $entry['name'];
        }

        $end = pack(
            'VvvvvVVv',
            self::END_OF_CENTRAL_DIRECTORY_SIGNATURE,
            0,                                      // this disk
            0,                                      // disk holding the directory
            count($this->entries),                  // entries on this disk
            count($this->entries),                  // entries in total
            strlen($central),
            strlen($local),                         // where the directory starts
            0,                                      // comment length
        );

        return $local . $central . $end;
    }
}
