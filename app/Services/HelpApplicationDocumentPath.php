<?php

namespace App\Services;

use InvalidArgumentException;

final class HelpApplicationDocumentPath
{
    /** @var list<string> */
    private const EXTENSIONS = ['pdf', 'jpg', 'png'];

    private const UUID_PATTERN = '[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}';

    public static function make(string $applicationReference, string $documentReference, string $extension): string
    {
        if (! self::isCanonicalUuid($applicationReference)
            || ! self::isCanonicalUuid($documentReference)
            || ! in_array($extension, self::EXTENSIONS, true)) {
            throw new InvalidArgumentException('Invalid Help Application document path components.');
        }

        return "applications/{$applicationReference}/documents/{$documentReference}.{$extension}";
    }

    public static function isOwnedBy(
        string $path,
        string $applicationReference,
        string $documentReference,
        string $extension,
    ): bool {
        if (! self::isCanonicalUuid($applicationReference)
            || ! self::isCanonicalUuid($documentReference)
            || ! in_array($extension, self::EXTENSIONS, true)) {
            return false;
        }

        $pattern = sprintf(
            '\\Aapplications/(%s)/documents/(%s)\\.(pdf|jpg|png)\\z',
            self::UUID_PATTERN,
            self::UUID_PATTERN,
        );

        if (preg_match("~{$pattern}~D", $path, $matches) !== 1) {
            return false;
        }

        return hash_equals($applicationReference, $matches[1])
            && hash_equals($documentReference, $matches[2])
            && hash_equals($extension, $matches[3]);
    }

    private static function isCanonicalUuid(string $reference): bool
    {
        return preg_match('~\\A'.self::UUID_PATTERN.'\\z~D', $reference) === 1;
    }
}
