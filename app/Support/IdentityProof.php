<?php

namespace App\Support;

/**
 * The donor identity proof recorded against a donation.
 *
 * The four types are the ones Form 10BD accepts for Indian donation reporting,
 * which is why an INR donation cannot be taken without one: the trust has to
 * file the donor's ID against the receipt. A foreign-currency donation is not
 * reported that way, so it does not need one.
 *
 * Values are normalised before they are stored or checked — donors type Aadhaar
 * as "1234 5678 9012" and licences as "DL-0420110149646", and the separators
 * are presentation, not data.
 */
class IdentityProof
{
    /** @var array<string, string> */
    public const TYPES = [
        'aadhaar' => 'Aadhaar No.',
        'pan' => 'PAN',
        'voter_id' => 'Voter ID',
        'driving_licence' => 'Driving License No.',
    ];

    /**
     * Patterns apply to the NORMALISED value.
     *
     * Aadhaar never begins 0 or 1 — those ranges are reserved — and carries a
     * Verhoeff check digit, which is checked separately. Driving licence is
     * deliberately the loosest: the format is set per state rather than
     * nationally, and a strict pattern would reject real licences.
     */
    private const PATTERNS = [
        'aadhaar' => '/^[2-9][0-9]{11}$/',
        'pan' => '/^[A-Z]{5}[0-9]{4}[A-Z]$/',
        'voter_id' => '/^[A-Z]{3}[0-9]{7}$/',
        'driving_licence' => '/^[A-Z]{2}[0-9]{2}[A-Z0-9]{9,13}$/',
    ];

    /** Shown under the field, so a donor knows what shape is expected. */
    private const EXAMPLES = [
        'aadhaar' => '1234 5678 9012',
        'pan' => 'ABCDE1234F',
        'voter_id' => 'ABC1234567',
        'driving_licence' => 'DL-0420110149646',
    ];

    private const MAX_LENGTHS = [
        'aadhaar' => 12,
        'pan' => 10,
        'voter_id' => 10,
        'driving_licence' => 16,
    ];

    public static function isKnownType(?string $type): bool
    {
        return $type !== null && array_key_exists($type, self::TYPES);
    }

    public static function label(?string $type): ?string
    {
        return self::TYPES[$type] ?? null;
    }

    public static function example(?string $type): ?string
    {
        return self::EXAMPLES[$type] ?? null;
    }

    public static function maxLength(?string $type): int
    {
        return self::MAX_LENGTHS[$type] ?? 32;
    }

    /** Uppercased, with the separators donors type stripped out. */
    public static function normalise(?string $value): string
    {
        return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) $value));
    }

    public static function isValid(?string $type, ?string $value): bool
    {
        if (! self::isKnownType($type)) {
            return false;
        }

        $value = self::normalise($value);

        if (! preg_match(self::PATTERNS[$type], $value)) {
            return false;
        }

        // Only Aadhaar carries a checksum; the rest are format-only.
        return $type !== 'aadhaar' || self::passesVerhoeff($value);
    }

    /**
     * The regex a browser should enforce, without delimiters.
     *
     * The field strips separators as the donor types, so the same pattern that
     * guards the server can guard the input.
     */
    public static function browserPattern(string $type): string
    {
        return trim(self::PATTERNS[$type], '/^$');
    }

    /**
     * How an identity number may be shown.
     *
     * UIDAI's position is that a full Aadhaar number should not be displayed or
     * printed, so only the last four digits are ever rendered. The whole value
     * is still stored, because Form 10BD reporting needs it.
     */
    public static function forDisplay(?string $type, ?string $value): ?string
    {
        if (blank($value)) {
            return null;
        }

        if ($type !== 'aadhaar') {
            return $value;
        }

        return 'XXXX XXXX '.substr(self::normalise($value), -4);
    }

    /** "Aadhaar No. XXXX XXXX 9012", or null when there is nothing to show. */
    public static function describe(?string $type, ?string $value): ?string
    {
        $shown = self::forDisplay($type, $value);

        return $shown === null ? null : trim(self::label($type).' '.$shown);
    }

    /**
     * The Verhoeff checksum UIDAI appends to every Aadhaar number. It catches
     * single-digit typos and adjacent transpositions, which is most of what a
     * donor gets wrong.
     */
    private static function passesVerhoeff(string $digits): bool
    {
        static $d = [
            [0, 1, 2, 3, 4, 5, 6, 7, 8, 9],
            [1, 2, 3, 4, 0, 6, 7, 8, 9, 5],
            [2, 3, 4, 0, 1, 7, 8, 9, 5, 6],
            [3, 4, 0, 1, 2, 8, 9, 5, 6, 7],
            [4, 0, 1, 2, 3, 9, 5, 6, 7, 8],
            [5, 9, 8, 7, 6, 0, 4, 3, 2, 1],
            [6, 5, 9, 8, 7, 1, 0, 4, 3, 2],
            [7, 6, 5, 9, 8, 2, 1, 0, 4, 3],
            [8, 7, 6, 5, 9, 3, 2, 1, 0, 4],
            [9, 8, 7, 6, 5, 4, 3, 2, 1, 0],
        ];

        static $p = [
            [0, 1, 2, 3, 4, 5, 6, 7, 8, 9],
            [1, 5, 7, 6, 2, 8, 3, 0, 9, 4],
            [5, 8, 0, 3, 7, 9, 6, 1, 4, 2],
            [8, 9, 1, 6, 0, 4, 3, 5, 2, 7],
            [9, 4, 5, 3, 1, 2, 6, 8, 7, 0],
            [4, 2, 8, 6, 5, 7, 3, 9, 0, 1],
            [2, 7, 9, 3, 8, 0, 6, 4, 1, 5],
            [7, 0, 4, 6, 9, 1, 3, 2, 5, 8],
        ];

        $check = 0;

        foreach (array_reverse(str_split($digits)) as $i => $digit) {
            $check = $d[$check][$p[$i % 8][(int) $digit]];
        }

        return $check === 0;
    }
}
