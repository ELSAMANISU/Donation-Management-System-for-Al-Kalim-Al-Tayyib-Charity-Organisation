<?php

namespace App\Services;

use App\Enums\IdentityDocumentType;
use Normalizer;
use RuntimeException;

class IdentityBlindIndex
{
    private const NORMALIZATION_VERSION = 1;

    public function compute(
        IdentityDocumentType $documentType,
        string $issuingCountry,
        string $identifier,
        ?int $keyVersion = null,
    ): string {
        $version = $keyVersion ?? $this->currentKeyVersion();
        $secret = $this->secretForVersion($version);
        $country = $this->normalizeCountry($issuingCountry);
        $normalizedIdentifier = $this->normalizeIdentifier($identifier);

        $input = implode("\0", [
            'help_application_identity',
            'normalization_v'.self::NORMALIZATION_VERSION,
            $documentType->value,
            $country,
            $normalizedIdentifier,
        ]);

        return hash_hmac('sha256', $input, $secret);
    }

    public function currentKeyVersion(): int
    {
        $version = config('identity.blind_index.current_version');

        if (is_string($version) && preg_match('/\A[1-9][0-9]*\z/', $version) === 1) {
            $version = filter_var($version, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 65535]]);
        }

        if (! is_int($version) || $version < 1 || $version > 65535) {
            throw $this->configurationException();
        }

        return $version;
    }

    public function normalizationVersion(): int
    {
        return self::NORMALIZATION_VERSION;
    }

    /** @return list<int> */
    public function configuredKeyVersions(): array
    {
        $configured = config('identity.blind_index.keys');

        if (! is_array($configured) || $configured === []) {
            throw $this->configurationException();
        }

        $versions = [];

        foreach ($configured as $rawVersion => $encoded) {
            if (is_int($rawVersion)) {
                $version = $rawVersion;
            } elseif (is_string($rawVersion) && preg_match('/\A[1-9][0-9]*\z/', $rawVersion) === 1) {
                $version = (int) $rawVersion;
            } else {
                throw $this->configurationException();
            }

            if ($version < 1 || $version > 65535 || isset($versions[$version]) || ! is_string($encoded) || $encoded === '') {
                throw $this->configurationException();
            }

            $secret = base64_decode($encoded, true);

            if ($secret === false || strlen($secret) < 32) {
                throw $this->configurationException();
            }

            $versions[$version] = $version;
        }

        if (! isset($versions[$this->currentKeyVersion()])) {
            throw $this->configurationException();
        }

        ksort($versions, SORT_NUMERIC);

        return array_values($versions);
    }

    public function normalizeIdentifier(string $identifier): string
    {
        $normalized = trim($identifier);

        if (class_exists(Normalizer::class)) {
            $nfc = Normalizer::normalize($normalized, Normalizer::FORM_C);

            if ($nfc === false) {
                throw new RuntimeException('The identity identifier could not be normalized.');
            }

            $normalized = $nfc;
        }

        return mb_strtoupper($normalized, 'UTF-8');
    }

    private function normalizeCountry(string $issuingCountry): string
    {
        $country = mb_strtoupper(trim($issuingCountry), 'UTF-8');

        if (preg_match('/\A[A-Z]{2}\z/', $country) !== 1) {
            throw new RuntimeException('The identity issuing country is invalid.');
        }

        return $country;
    }

    private function secretForVersion(int $version): string
    {
        $encoded = config("identity.blind_index.keys.{$version}");

        if (! is_string($encoded) || $encoded === '') {
            throw $this->configurationException();
        }

        $secret = base64_decode($encoded, true);

        if ($secret === false || strlen($secret) < 32) {
            throw $this->configurationException();
        }

        return $secret;
    }

    private function configurationException(): RuntimeException
    {
        return new RuntimeException('Identity blind-index configuration is invalid.');
    }
}
