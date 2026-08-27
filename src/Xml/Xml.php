<?php

declare(strict_types=1);

namespace CoolMS\Ooxml\Xml;

use RuntimeException;

use function preg_last_error_msg;
use function preg_replace;
use function str_replace;

/**
 * Escaping, which is the whole of what OOXML writing needs from an XML layer.
 *
 * ## Why not `XMLWriter` or `DOMDocument`
 *
 * Both are available and both are correct. Neither is used for the BODY of a
 * part, because OOXML parts are long, repetitive and written in one pass — a
 * worksheet is rows of the same three elements — and building a DOM to
 * serialise it once costs memory proportional to the document for no benefit.
 * What is genuinely needed is that no author's text can break the markup, and
 * that is this file.
 *
 * ## The five characters, and the ones that are not characters at all
 *
 * Text and attributes escape differently: an attribute value cannot contain a
 * quote, and text cannot contain `<` or `&`. Both are handled here rather than
 * at call sites, because a single missed escape produces a part that fails to
 * parse and takes the whole document with it.
 *
 * ⚠️ XML 1.0 cannot represent most control characters AT ALL — not as entities,
 * not escaped, not any way. A `\x0B` in a cell (paste from a terminal, a stray
 * vertical tab) makes the file unparseable, and the error names the byte offset
 * rather than the cell. They are dropped, because a document missing one
 * invisible character is worth more than one that will not open.
 */
final class Xml
{
    /** Text content: `&` first, or the escapes escape each other. */
    public static function text(string $value): string
    {
        return str_replace(
            ['&', '<', '>'],
            ['&amp;', '&lt;', '&gt;'],
            self::strip($value),
        );
    }

    /** An attribute value, which additionally cannot hold quotes. */
    public static function attribute(string $value): string
    {
        return str_replace(
            ['&', '<', '>', '"', "'"],
            ['&amp;', '&lt;', '&gt;', '&quot;', '&apos;'],
            self::strip($value),
        );
    }

    /**
     * Remove what XML 1.0 cannot carry.
     *
     * Everything below 0x20 except tab, newline and carriage return, plus the
     * two non-characters at the end of the BMP. Kept in one place so text and
     * attributes cannot disagree about it.
     *
     * ⚠️ The surrogate range is NOT in the class, and leaving it out is the
     * fix rather than an oversight. `\x{D800}-\x{DFFF}` are not valid Unicode
     * scalar values, so PCRE refuses to compile the pattern in `/u` mode at
     * all — `preg_replace` then returns null, and a `(string)` cast turns that
     * into an empty string. Every attribute in the package came out blank, and
     * nothing reported an error. Surrogates cannot appear in valid UTF-8
     * anyway, so the class does not need them.
     *
     * @throws RuntimeException if the pattern fails, rather than returning ''
     */
    private static function strip(string $value): string
    {
        $stripped = preg_replace(
            '/[\x{0000}-\x{0008}\x{000B}\x{000C}\x{000E}-\x{001F}\x{FFFE}\x{FFFF}]/u',
            '',
            $value,
        );

        // Never silently. The empty string this used to fall back to is
        // indistinguishable from a cell the author left blank.
        if (null === $stripped) {
            throw new RuntimeException('Could not strip control characters: ' . preg_last_error_msg() . '. Refusing to write a part whose text may have been emptied.');
        }

        return $stripped;
    }
}
