<?php

declare(strict_types=1);

namespace Smartlabsys\SlsConnectorBundle\Provisioning;

/**
 * Claim codes (Phase 13): the proof that an SLS organization may take over an existing standalone
 * company in this app. A company admin creates one in the app (or confirms on `/sls/claim`), SLS
 * sends it back as `claim.code` in `POST /tenants` and `/tenants/preview`.
 *
 * Store only {@see hash()} of a code, with its company, creator, expiry ({@see TTL_SECONDS}) and
 * "used at"; one live code per company.
 */
final class ClaimCodes
{
    /** No 0/O, 1/I: easy to read out and type. */
    public const ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    public const LENGTH = 12;
    public const GROUP = 4;
    public const TTL_SECONDS = 1800;
    public const TTL = 'PT30M';

    private function __construct() {}

    /** A new code, formatted `XXXX-XXXX-XXXX`. Show it once; store {@see hash()} of it. */
    public static function generate(): string
    {
        $max  = strlen(self::ALPHABET) - 1;
        $code = '';
        for ($i = 0; $i < self::LENGTH; ++$i) {
            $code .= self::ALPHABET[random_int(0, $max)];
        }

        return self::format($code);
    }

    /** `ABCDEFGHJKLM` → `ABCD-EFGH-JKLM` (input normalized first). */
    public static function format(string $code): string
    {
        return implode('-', str_split(self::normalize($code), self::GROUP));
    }

    /** Uppercase, without spaces and dashes — what is hashed and compared. */
    public static function normalize(string $input): string
    {
        return strtoupper(str_replace([' ', '-', "\t", "\n", "\r"], '', $input));
    }

    /** Whether a normalized code has the right length and alphabet. */
    public static function isWellFormed(string $normalized): bool
    {
        return strlen($normalized) === self::LENGTH && strspn($normalized, self::ALPHABET) === self::LENGTH;
    }

    /** sha256 hex of the normalized code: the only thing to store. */
    public static function hash(string $code): string
    {
        return hash('sha256', self::normalize($code));
    }

    /** Constant-time check of an entered / received code against a stored {@see hash()}. */
    public static function verify(string $input, string $storedHash): bool
    {
        return $storedHash !== '' && hash_equals($storedHash, self::hash($input));
    }

    /** When a code created at `$now` expires. */
    public static function expiresAt(\DateTimeImmutable $now = new \DateTimeImmutable()): \DateTimeImmutable
    {
        return $now->add(new \DateInterval(self::TTL));
    }
}
