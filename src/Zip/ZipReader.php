<?php

declare(strict_types=1);

namespace CoolMS\Ooxml\Zip;

use RuntimeException;

use function crc32;
use function gzinflate;
use function pack;
use function sprintf;
use function str_ends_with;
use function strlen;
use function strrpos;
use function substr;
use function unpack;

/**
 * Reading a ZIP container from a string.
 *
 * The counterpart to {@see ZipWriter}, and separate from it for the same reason
 * the writer exists at all: PHP's own \`ZipArchive\` cannot open a string, so a
 * workbook arriving over HTTP would have to be written to disk before it could
 * be looked at.
 *
 * ## It reads the CENTRAL DIRECTORY, not the stream
 *
 * A zip can be read two ways: walk the local headers front to back, or read the
 * directory at the end and jump to each entry. The directory is authoritative —
 * it is what every real reader uses, and it is the only one that survives the
 * data-descriptor case, where an entry's local header carries zeroes for the
 * sizes and the real values follow the data. A front-to-back reader meets that
 * and cannot tell where the entry ends.
 *
 * ⚠️ The local header's own name and extra-field lengths must be read to find
 * where the data starts. They are frequently DIFFERENT from the central
 * directory's for the same entry — the extra field is where a writer puts
 * timestamps and Unix permissions, and it is not obliged to put the same ones
 * in both places. Trusting the directory's lengths reads from the wrong offset
 * and inflates garbage.
 */
final class ZipReader
{
    private const int END_OF_CENTRAL_DIRECTORY_SIGNATURE = 0x06054B50;

    private const int CENTRAL_HEADER_SIGNATURE = 0x02014B50;

    private const int LOCAL_HEADER_SIGNATURE = 0x04034B50;

    private const int METHOD_STORED = 0;

    private const int METHOD_DEFLATE = 8;

    /** Fixed part of a central directory header, before the name. */
    private const int CENTRAL_HEADER_LENGTH = 46;

    /** Fixed part of a local header, before the name. */
    private const int LOCAL_HEADER_LENGTH = 30;

    /**
     * General-purpose bit 3: the sizes and CRC follow the data instead of
     * preceding it. LibreOffice sets it on every entry it writes.
     */
    private const int FLAG_DATA_DESCRIPTOR = 0x0008;

    /**
     * Every entry, by name.
     *
     * @throws RuntimeException when the container is not a readable zip
     *
     * @return array<string, string> name to uncompressed content
     */
    public static function read(string $bytes): array
    {
        $entries = [];
        foreach (self::entries($bytes) as $name => $entry) {
            // A directory entry is not a PART -- it has no content and the OPC
            // layer has no use for it. It is still part of the FILE, which is
            // why `entries()` keeps it; see there.
            if (!str_ends_with($name, '/')) {
                $entries[$name] = self::contentOf($entry, $name);
            }
        }

        return $entries;
    }

    /**
     * Every entry as it SITS IN THE FILE — still compressed, with its checksum.
     *
     * What this buys is byte preservation. An entry nobody edited can be copied
     * into a new archive exactly as it arrived, so a package opened and saved
     * without changes comes back IDENTICAL rather than merely equivalent.
     * Re-compressing would give the same content and different bytes, and the
     * imported-template path promises the first while being checked on the
     * second.
     *
     * ⚠️ Unlike {@see read()}, this keeps DIRECTORY entries -- the ones whose
     * names end in `/`. They carry no content and are not parts, and dropping
     * them still changes the operator's file: Word and LibreOffice both write
     * them, and a save that quietly removes entries is not the "nothing was
     * touched" this promises. Found by a no-change save coming back 366 bytes
     * shorter than the file it read.
     *
     * @throws RuntimeException when the container is not a readable zip
     *
     * @return array<string, array{
     *     method: int, crc: int, compressed: string, size: int,
     *     versionMadeBy: int, versionNeeded: int, flags: int, time: int, date: int,
     *     internalAttributes: int, externalAttributes: int
     * }>
     */
    public static function entries(string $bytes): array
    {
        $directory = self::centralDirectoryOffset($bytes);

        $entries = [];
        $at = $directory;
        while ($at + 4 <= strlen($bytes) && self::CENTRAL_HEADER_SIGNATURE === self::int32($bytes, $at)) {
            $nameLength = self::int16($bytes, $at + 28);
            $extraLength = self::int16($bytes, $at + 30);
            $commentLength = self::int16($bytes, $at + 32);
            $name = substr($bytes, $at + self::CENTRAL_HEADER_LENGTH, $nameLength);
            $localOffset = self::int32($bytes, $at + 42);

            // ⚠️ The CRC and the two sizes come from HERE, not from the local
            // header, and that is not a preference -- it is the only place they
            // are always present. An entry written with a DATA DESCRIPTOR
            // (general-purpose bit 3) carries zeroes in its local header and
            // puts the real values in a record AFTER the compressed data.
            //
            // MEASURED 2026-08-26: LibreOffice writes EVERY entry that way --
            // flags `0x0808` -- so a reader taking the local values inflates
            // zero bytes and reports the file as damaged. Twelve of twelve
            // LibreOffice-written workbooks were refused before this.
            //
            // The central directory is the authority the format itself
            // designates; the local header is a convenience copy.
            $entries[$name] = self::entryAt(
                $bytes,
                $localOffset,
                $name,
                self::int32($bytes, $at + 16),
                self::int32($bytes, $at + 20),
                self::int32($bytes, $at + 24),
            ) + [
                'versionMadeBy' => self::int16($bytes, $at + 4),
                'internalAttributes' => self::int16($bytes, $at + 36),
                'externalAttributes' => self::int32($bytes, $at + 38),
            ];

            $at += self::CENTRAL_HEADER_LENGTH + $nameLength + $extraLength + $commentLength;
        }

        if ([] === $entries) {
            throw new RuntimeException('The archive holds no files.');
        }

        return $entries;
    }

    /**
     * An entry's uncompressed content.
     *
     * @param array{method: int, crc: int, compressed: string, size: int} $entry
     */
    public static function contentOf(array $entry, string $name): string
    {
        $content = match ($entry['method']) {
            self::METHOD_STORED => $entry['compressed'],
            self::METHOD_DEFLATE => self::inflate($entry['compressed'], $name),
            default => throw new RuntimeException(sprintf('Entry "%s" uses compression method %d, which this reader does not implement.', $name, $entry['method'])),
        };

        // A CRC mismatch means the bytes are not what the writer put in, and
        // continuing produces a document built from corruption rather than an
        // error anybody can act on.
        if (0 !== $entry['crc'] && crc32($content) !== $entry['crc']) {
            throw new RuntimeException(sprintf('Entry "%s" fails its checksum; the archive is damaged.', $name));
        }

        return $content;
    }

    /**
     * One entry as it sits in the file.
     *
     * ⚠️ The local header is read for everything that describes HOW the bytes
     * are laid out -- the method, the flags, and the two lengths that say where
     * the data starts. The CRC and the sizes are handed in from the CENTRAL
     * directory instead, because an entry written with a data descriptor
     * carries zeroes for all three here. See the caller.
     *
     * ⚠️ Bit 3 is CLEARED from the flags. The sizes are now known and are
     * written into the local header when this entry is re-emitted, so an
     * archive claiming a descriptor that no longer follows the data would be
     * one every reader has to guess about.
     *
     * @return array{method: int, crc: int, compressed: string, size: int, versionNeeded: int, flags: int, time: int, date: int}
     */
    private static function entryAt(
        string $bytes,
        int $offset,
        string $name,
        int $crc,
        int $compressedSize,
        int $size,
    ): array {
        if (self::LOCAL_HEADER_SIGNATURE !== self::int32($bytes, $offset)) {
            throw new RuntimeException(sprintf('Entry "%s" has no local header at offset %d.', $name, $offset));
        }

        $method = self::int16($bytes, $offset + 8);
        $versionNeeded = self::int16($bytes, $offset + 4);
        $flags = self::int16($bytes, $offset + 6);
        $time = self::int16($bytes, $offset + 10);
        $date = self::int16($bytes, $offset + 12);
        // ⚠️ The LOCAL lengths, not the directory's -- see the class note.
        $nameLength = self::int16($bytes, $offset + 26);
        $extraLength = self::int16($bytes, $offset + 28);

        $start = $offset + self::LOCAL_HEADER_LENGTH + $nameLength + $extraLength;

        return [
            'method' => $method,
            'crc' => $crc,
            'compressed' => substr($bytes, $start, $compressedSize),
            'size' => $size,
            'versionNeeded' => $versionNeeded,
            'flags' => $flags & ~self::FLAG_DATA_DESCRIPTOR,
            'time' => $time,
            'date' => $date,
        ];
    }

    private static function inflate(string $compressed, string $name): string
    {
        $content = @gzinflate($compressed);
        if (false === $content) {
            throw new RuntimeException(sprintf('Entry "%s" could not be decompressed.', $name));
        }

        return $content;
    }

    /**
     * Where the central directory starts.
     *
     * The end record sits at the very end unless the archive carries a comment,
     * which may be up to 64KB — so the signature is searched for backwards
     * rather than assumed to be at a fixed offset.
     */
    private static function centralDirectoryOffset(string $bytes): int
    {
        $signature = pack('V', self::END_OF_CENTRAL_DIRECTORY_SIGNATURE);
        $at = strrpos($bytes, $signature);
        if (false === $at) {
            throw new RuntimeException('Not a zip archive: no end-of-central-directory record.');
        }

        return self::int32($bytes, $at + 16);
    }

    private static function int16(string $bytes, int $at): int
    {
        $parsed = unpack('v', substr($bytes, $at, 2));
        if (false === $parsed) {
            throw new RuntimeException(sprintf('Truncated archive: no 16-bit field at offset %d.', $at));
        }

        return $parsed[1];
    }

    private static function int32(string $bytes, int $at): int
    {
        $parsed = unpack('V', substr($bytes, $at, 4));
        if (false === $parsed) {
            throw new RuntimeException(sprintf('Truncated archive: no 32-bit field at offset %d.', $at));
        }

        return $parsed[1];
    }
}
