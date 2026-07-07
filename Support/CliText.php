<?php

namespace Pinoox\Support;

/**
 * Terminal output helpers (BiDi-safe labels in mixed LTR/RTL rows).
 */
final class CliText
{
    /** Right-to-left mark — start an RTL run in LTR terminal rows. */
    private const RLM = "\u{200F}";

    /** Left-to-right mark — close the RTL run before following LTR cells. */
    private const LRM = "\u{200E}";

    /**
     * Wrap RTL script runs so Persian/Arabic labels render correctly in LTR terminal tables.
     */
    public static function isolateRtl(string $text): string
    {
        if ($text === '') {
            return $text;
        }

        if (!preg_match('/\p{Script=Arabic}/u', $text)) {
            return $text;
        }

        return self::RLM . $text . self::LRM;
    }
}
