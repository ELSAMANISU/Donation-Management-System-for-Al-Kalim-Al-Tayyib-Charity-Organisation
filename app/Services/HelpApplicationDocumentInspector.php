<?php

namespace App\Services;

use App\Data\InspectedHelpApplicationDocument;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Throwable;

class HelpApplicationDocumentInspector
{
    public function __construct(private readonly QpdfInspector $qpdf) {}

    public function inspect(UploadedFile $file): InspectedHelpApplicationDocument
    {
        try {
            return $this->inspectStrictly($file);
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw ValidationException::withMessages(['document' => $this->message()]);
        }
    }

    private function inspectStrictly(UploadedFile $file): InspectedHelpApplicationDocument
    {
        $path = $file->getRealPath();
        $size = is_string($path) ? filesize($path) : false;
        if (! $file->isValid() || ! is_string($path) || ! is_int($size) || $size < 1
            || $size > config('help_application_documents.limits.max_file_bytes')) {
            $this->reject();
        }
        $clientExtension = strtolower($file->getClientOriginalExtension());
        $extension = $clientExtension === 'jpeg' ? 'jpg' : $clientExtension;
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($path);
        if (! in_array($clientExtension, ['pdf', 'jpg', 'jpeg', 'png'], true)
            || config("help_application_documents.formats.{$extension}.mime_type") !== $mime) {
            $this->reject();
        }
        if ($extension === 'pdf') {
            $contents = file_get_contents($path);
            if (! is_string($contents) || ! str_starts_with($contents, '%PDF-')
                || substr_count($contents, '%%EOF') !== 1
                || ! str_ends_with(rtrim($contents, "\x00\x09\x0A\x0C\x0D\x20"), '%%EOF')) {
                $this->reject();
            }
            $this->qpdf->inspect($file);
        } else {
            $this->validateImage($path, $extension);
        }
        [$checksum, $count] = $this->streamHash($path);
        if ($count !== $size) {
            $this->reject();
        }

        return new InspectedHelpApplicationDocument($extension, $mime, $size, $checksum);
    }

    private function validateImage(string $path, string $extension): void
    {
        $contents = file_get_contents($path);
        if (! is_string($contents)) {
            $this->reject();
        }
        $extension === 'jpg' ? $this->validateJpegStructure($contents) : $this->validatePngStructure($contents);
        set_error_handler(static fn (): bool => true);
        try {
            $image = imagecreatefromstring($contents);
        } finally {
            restore_error_handler();
        }
        if ($image === false) {
            $this->reject();
        }
        try {
            $width = imagesx($image);
            $height = imagesy($image);
            $maxPixels = config('help_application_documents.limits.max_decoded_image_pixels');
            if ($width < 1 || $height < 1 || $width > config('help_application_documents.limits.max_image_width')
                || $height > config('help_application_documents.limits.max_image_height')
                || $width > intdiv($maxPixels, $height)) {
                $this->reject();
            }
        } finally {
            imagedestroy($image);
        }
    }

    private function validateJpegStructure(string $bytes): void
    {
        $length = strlen($bytes);
        if ($length < 4 || substr($bytes, 0, 2) !== "\xFF\xD8") {
            $this->reject();
        }
        $offset = 2;
        $sawScan = false;
        while ($offset < $length) {
            if (ord($bytes[$offset]) !== 0xFF) {
                $this->reject();
            }
            while ($offset < $length && ord($bytes[$offset]) === 0xFF) {
                $offset++;
            }
            if ($offset >= $length) {
                $this->reject();
            }
            $marker = ord($bytes[$offset++]);
            if ($marker === 0xD9) {
                if (! $sawScan || $offset !== $length) {
                    $this->reject();
                }

                return;
            }
            if ($marker === 0xD8 || $marker === 0x00 || $marker === 0x01 || ($marker >= 0xD0 && $marker <= 0xD7)) {
                $this->reject();
            }
            if ($offset + 2 > $length) {
                $this->reject();
            }
            $segmentLength = unpack('n', substr($bytes, $offset, 2))[1];
            if ($segmentLength < 2 || $segmentLength > $length - $offset) {
                $this->reject();
            }
            $offset += $segmentLength;
            if ($marker !== 0xDA) {
                continue;
            }
            $sawScan = true;
            while ($offset < $length) {
                if (ord($bytes[$offset++]) !== 0xFF) {
                    continue;
                }
                while ($offset < $length && ord($bytes[$offset]) === 0xFF) {
                    $offset++;
                }
                if ($offset >= $length) {
                    $this->reject();
                }
                $entropyMarker = ord($bytes[$offset]);
                if ($entropyMarker === 0x00 || ($entropyMarker >= 0xD0 && $entropyMarker <= 0xD7)) {
                    $offset++;

                    continue;
                }
                $offset--;
                break;
            }
        }
        $this->reject();
    }

    private function validatePngStructure(string $bytes): void
    {
        $length = strlen($bytes);
        if ($length < 45 || substr($bytes, 0, 8) !== "\x89PNG\r\n\x1A\n") {
            $this->reject();
        }
        $offset = 8;
        $index = 0;
        $sawPlte = false;
        $sawIdat = false;
        $idatEnded = false;
        while ($offset < $length) {
            if ($length - $offset < 12) {
                $this->reject();
            }
            $chunkLength = unpack('N', substr($bytes, $offset, 4))[1];
            $type = substr($bytes, $offset + 4, 4);
            if ($chunkLength > $length - $offset - 12 || preg_match('/\A[A-Za-z]{4}\z/', $type) !== 1) {
                $this->reject();
            }
            $data = substr($bytes, $offset + 8, $chunkLength);
            $crc = substr($bytes, $offset + 8 + $chunkLength, 4);
            if (! hash_equals(hash('crc32b', $type.$data, true), $crc)) {
                $this->reject();
            }
            if ($index === 0 && ($type !== 'IHDR' || $chunkLength !== 13)) {
                $this->reject();
            }
            if ($index > 0 && $type === 'IHDR') {
                $this->reject();
            }
            if (! ctype_upper($type[2])) {
                $this->reject();
            }
            if (ctype_upper($type[0]) && ! in_array($type, ['IHDR', 'PLTE', 'IDAT', 'IEND'], true)) {
                $this->reject();
            }
            if ($type === 'PLTE') {
                if ($sawPlte || $sawIdat) {
                    $this->reject();
                }
                $sawPlte = true;
            }
            if ($type === 'IDAT') {
                if ($idatEnded) {
                    $this->reject();
                }
                $sawIdat = true;
            } elseif ($sawIdat && $type !== 'IEND') {
                $idatEnded = true;
            }
            $offset += 12 + $chunkLength;
            if ($type === 'IEND') {
                if ($chunkLength !== 0 || ! $sawIdat || $offset !== $length) {
                    $this->reject();
                }

                return;
            }
            $index++;
        }
        $this->reject();
    }

    /** @return array{string,int} */
    private function streamHash(string $path): array
    {
        $stream = fopen($path, 'rb');
        if ($stream === false) {
            throw new \RuntimeException;
        }
        $hash = hash_init('sha256');
        $count = 0;
        try {
            while (! feof($stream)) {
                $chunk = fread($stream, 8192);
                if ($chunk === false) {
                    throw new \RuntimeException;
                }
                $count += strlen($chunk);
                hash_update($hash, $chunk);
            }
        } finally {
            fclose($stream);
        }

        return [hash_final($hash), $count];
    }

    private function reject(): never
    {
        throw ValidationException::withMessages(['document' => $this->message()]);
    }

    private function message(): string
    {
        return 'The document could not be accepted. Upload a valid PDF, JPEG, or PNG within the stated limits. / تعذر قبول المستند. ارفع ملف PDF أو JPEG أو PNG صالحاً ضمن الحدود الموضحة.';
    }
}
