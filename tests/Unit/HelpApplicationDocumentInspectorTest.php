<?php

namespace Tests\Unit;

use App\Services\HelpApplicationDocumentInspector;
use App\Services\QpdfInspector;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class HelpApplicationDocumentInspectorTest extends TestCase
{
    /** @var list<string> */
    private array $temporaryFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->temporaryFiles as $path) {
            @unlink($path);
        }
        parent::tearDown();
    }

    public function test_valid_jpeg_and_png_are_accepted_without_reencoding(): void
    {
        foreach (['jpg', 'png'] as $extension) {
            $source = UploadedFile::fake()->image("valid.{$extension}", 20, 10);
            $bytes = file_get_contents($source->getRealPath());
            $file = $this->uploaded($bytes, "valid.{$extension}");
            $result = $this->inspector()->inspect($file);

            $this->assertSame($extension, $result->extension);
            $this->assertSame(strlen($bytes), $result->sizeBytes);
            $this->assertSame(hash('sha256', $bytes), $result->checksum);
        }
    }

    public function test_jpeg_parser_rejects_suffix_fake_eoi_truncation_and_invalid_segment_length(): void
    {
        $source = UploadedFile::fake()->image('source.jpg', 10, 10);
        $valid = file_get_contents($source->getRealPath());
        $cases = [
            $valid."payload\xFF\xD9",
            substr($valid, 0, -1),
            "\xFF\xD8\xFF\xE0\x00\x01\xFF\xD9",
            $valid.$valid,
        ];

        foreach ($cases as $index => $bytes) {
            $this->assertRejected($this->uploaded($bytes, "bad-{$index}.jpg"));
        }
    }

    public function test_png_parser_rejects_crc_suffix_truncation_unknown_critical_and_duplicate_content(): void
    {
        $source = UploadedFile::fake()->image('source.png', 10, 10);
        $valid = file_get_contents($source->getRealPath());
        $corruptCrc = $valid;
        $corruptCrc[29] = chr(ord($corruptCrc[29]) ^ 1);
        $unknown = $this->pngChunk('ABCD', '').substr($valid, 8);
        $cases = [$corruptCrc, $valid.'suffix', substr($valid, 0, -1), "\x89PNG\r\n\x1A\n".$unknown, $valid.$valid];

        foreach ($cases as $index => $bytes) {
            $this->assertRejected($this->uploaded($bytes, "bad-{$index}.png"));
        }
    }

    public function test_png_state_machine_rejects_each_invalid_chunk_order_at_its_target_branch(): void
    {
        $source = UploadedFile::fake()->image('state.png', 10, 10);
        $chunks = $this->pngChunks(file_get_contents($source->getRealPath()));
        $ihdr = $chunks[0];
        $idatIndex = array_search('IDAT', array_column($chunks, 'type'), true);
        $iendIndex = array_search('IEND', array_column($chunks, 'type'), true);
        $idat = $chunks[$idatIndex];
        $beforeIdat = array_slice($chunks, 0, $idatIndex);
        $afterIdat = array_slice($chunks, $idatIndex + 1);

        $cases = [
            'unknown-critical-after-ihdr' => array_merge([$ihdr, ['type' => 'ABCD', 'data' => '']], array_slice($chunks, 1)),
            'lowercase-reserved-letter' => array_merge([$ihdr, ['type' => 'ABcD', 'data' => '']], array_slice($chunks, 1)),
            'duplicate-ihdr' => array_merge([$ihdr, $ihdr], array_slice($chunks, 1)),
            'duplicate-plte' => array_merge($beforeIdat, [['type' => 'PLTE', 'data' => "\0\0\0"], ['type' => 'PLTE', 'data' => "\0\0\0"]], array_slice($chunks, $idatIndex)),
            'plte-after-idat' => array_merge($beforeIdat, [$idat, ['type' => 'PLTE', 'data' => "\0\0\0"]], $afterIdat),
            'nonconsecutive-idat' => array_merge($beforeIdat, [
                ['type' => 'IDAT', 'data' => substr($idat['data'], 0, 1)],
                ['type' => 'tEXt', 'data' => 'x'],
                ['type' => 'IDAT', 'data' => substr($idat['data'], 1)],
            ], $afterIdat),
            'missing-idat' => array_values(array_filter($chunks, fn (array $chunk): bool => $chunk['type'] !== 'IDAT')),
            'early-iend' => [$ihdr, ['type' => 'IEND', 'data' => '']],
            'duplicate-iend' => array_merge($chunks, [['type' => 'IEND', 'data' => '']]),
            'nonzero-iend' => array_replace($chunks, [$iendIndex => ['type' => 'IEND', 'data' => 'x']]),
        ];

        foreach ($cases as $label => $caseChunks) {
            $this->assertRejected($this->uploaded($this->buildPng($caseChunks), "{$label}.png"));
        }
    }

    public function test_real_qpdf_accepts_a_deterministic_passive_one_page_pdf(): void
    {
        $bytes = $this->passivePdf();
        $file = $this->uploaded($bytes, 'passive.pdf');
        (new QpdfInspector)->inspect($file);
        $result = $this->inspector()->inspect($file);

        $this->assertSame('pdf', $result->extension);
        $this->assertSame('application/pdf', $result->mimeType);
        $this->assertSame(hash('sha256', $bytes), $result->checksum);
    }

    public function test_pdf_terminal_policy_rejects_prefix_and_suffix_polyglots(): void
    {
        $valid = $this->passivePdf();
        foreach (["prefix\n".$valid, $valid.'payload', $valid."payload\n%%EOF"] as $index => $bytes) {
            $this->assertRejected($this->uploaded($bytes, "polyglot-{$index}.pdf"));
        }
    }

    private function inspector(): HelpApplicationDocumentInspector
    {
        return new HelpApplicationDocumentInspector(new QpdfInspector);
    }

    private function uploaded(string $bytes, string $name): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'document-inspector-');
        file_put_contents($path, $bytes);
        $this->temporaryFiles[] = $path;

        return new UploadedFile($path, $name, null, UPLOAD_ERR_OK, true);
    }

    private function assertRejected(UploadedFile $file): void
    {
        try {
            $this->inspector()->inspect($file);
            $this->fail('Unsafe image was accepted.');
        } catch (ValidationException $exception) {
            $this->assertSame(['document'], array_keys($exception->errors()));
        }
    }

    private function pngChunk(string $type, string $data): string
    {
        return pack('N', strlen($data)).$type.$data.hash('crc32b', $type.$data, true);
    }

    /** @return list<array{type:string,data:string}> */
    private function pngChunks(string $png): array
    {
        $chunks = [];
        $offset = 8;
        while ($offset < strlen($png)) {
            $length = unpack('N', substr($png, $offset, 4))[1];
            $chunks[] = ['type' => substr($png, $offset + 4, 4), 'data' => substr($png, $offset + 8, $length)];
            $offset += 12 + $length;
        }

        return $chunks;
    }

    /** @param list<array{type:string,data:string}> $chunks */
    private function buildPng(array $chunks): string
    {
        return "\x89PNG\r\n\x1A\n".implode('', array_map(fn (array $chunk): string => $this->pngChunk($chunk['type'], $chunk['data']), $chunks));
    }

    private function passivePdf(): string
    {
        $objects = [
            '<< /Type /Catalog /Pages 2 0 R >>',
            '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 100 100] /Resources << >> /Contents 4 0 R >>',
            "<< /Length 0 >>\nstream\n\nendstream",
        ];
        $pdf = "%PDF-1.4\n";
        $offsets = [0];
        foreach ($objects as $index => $object) {
            $offsets[] = strlen($pdf);
            $number = $index + 1;
            $pdf .= "{$number} 0 obj\n{$object}\nendobj\n";
        }
        $xref = strlen($pdf);
        $pdf .= "xref\n0 5\n0000000000 65535 f \n";
        for ($index = 1; $index <= 4; $index++) {
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$index]);
        }

        return $pdf."trailer\n<< /Size 5 /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF\n";
    }
}
