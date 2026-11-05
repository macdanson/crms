<?php

namespace App\Services;

use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Encryption, blind indexes and masking for restricted personal data
 * (developer guide §9 to §11).
 *
 * Keys are versioned and separate from APP_KEY, so the app key can change
 * without losing records and data keys can be rotated: every stored value says
 * which key version encrypted it. In production they must come from the
 * approved KMS or secret manager, not from a file beside the database.
 */
class DataProtection
{
    /**
     * Encrypt with the newest key, as "v<version>:<payload>".
     */
    public function encrypt(#[\SensitiveParameter] string $plaintext): string
    {
        $version = $this->currentKeyVersion();

        return "v{$version}:".$this->encrypter($version)->encryptString($plaintext);
    }

    public function decrypt(string $stored): string
    {
        if (preg_match('/^v(\d+):(.+)$/s', $stored, $matches) !== 1) {
            throw new RuntimeException('A protected value has no key version.');
        }

        return $this->encrypter((int) $matches[1])->decryptString($matches[2]);
    }

    /**
     * Whether a stored value was encrypted with an older key.
     */
    public function needsRotation(string $stored): bool
    {
        return ! str_starts_with($stored, 'v'.$this->currentKeyVersion().':');
    }

    public function currentKeyVersion(): int
    {
        $versions = array_keys($this->keys());

        return max($versions);
    }

    /**
     * The one way an identity number is compared: no spaces or hyphens, upper
     * case.
     */
    public function normaliseIdentifier(string $identifier): string
    {
        return Str::upper((string) preg_replace('/[\s\-]+/u', '', trim($identifier)));
    }

    /**
     * A keyed token for exact lookup of an identifier of one type (§10). It has
     * its own secret: an ordinary hash of a short number could be reversed by
     * trying them all. Never send it to the client.
     */
    public function lookupToken(string $type, #[\SensitiveParameter] string $identifier): string
    {
        $secret = config('crms.data_protection.lookup_key');

        if (! is_string($secret) || $secret === '') {
            throw new RuntimeException('No identifier lookup key is configured (CRMS_IDENTIFIER_LOOKUP_KEY).');
        }

        return hash_hmac('sha256', $type.'|'.$this->normaliseIdentifier($identifier), $secret);
    }

    /**
     * For example CM***********89.
     */
    public function maskIdentifier(#[\SensitiveParameter] string $identifier): string
    {
        $identifier = $this->normaliseIdentifier($identifier);
        $length = mb_strlen($identifier);

        if ($length <= 4) {
            return str_repeat('*', $length);
        }

        return mb_substr($identifier, 0, 2).str_repeat('*', $length - 4).mb_substr($identifier, -2);
    }

    /**
     * For example +256 7** *** 483, or j***@example.org.
     */
    public function maskContact(#[\SensitiveParameter] string $value): string
    {
        $value = trim($value);

        if (str_contains($value, '@')) {
            [$local, $domain] = explode('@', $value, 2);

            return mb_substr($local, 0, 1).'***@'.$domain;
        }

        $length = mb_strlen($value);

        if ($length <= 6) {
            return str_repeat('*', $length);
        }

        return (string) preg_replace('/\d/', '*', mb_substr($value, 0, $length - 3), -1).mb_substr($value, -3);
    }

    /**
     * Only that an address is held, and the start of it.
     */
    public function maskAddress(#[\SensitiveParameter] string $address): string
    {
        return mb_substr(trim($address), 0, 3).'*****';
    }

    private function encrypter(int $version): Encrypter
    {
        $keys = $this->keys();

        if (! isset($keys[$version])) {
            throw new RuntimeException("Data key version {$version} is not available.");
        }

        return new Encrypter($keys[$version], 'aes-256-gcm');
    }

    /**
     * @return non-empty-array<int, string> Raw keys by version.
     */
    private function keys(): array
    {
        $keys = [];

        foreach (explode(',', (string) config('crms.data_protection.keys')) as $entry) {
            if (preg_match('/^\s*(\d+):(\S+)\s*$/', $entry, $matches) !== 1) {
                continue;
            }

            $key = base64_decode($matches[2], true);

            if ($key === false || strlen($key) !== 32) {
                throw new RuntimeException("Data key version {$matches[1]} is not 32 bytes of base64.");
            }

            $keys[(int) $matches[1]] = $key;
        }

        if ($keys === []) {
            throw new RuntimeException('No data keys are configured (CRMS_DATA_KEYS). Run `php artisan security:generate-keys`.');
        }

        return $keys;
    }
}
