<?php

namespace Tests\Unit;

use App\Services\QpdfInspector;
use Illuminate\Http\UploadedFile;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class RealQpdfSecurityIntegrationTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'qpdf-security-'.bin2hex(random_bytes(8));
        mkdir($this->directory, 0700);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory.DIRECTORY_SEPARATOR.'*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->directory);
        parent::tearDown();
    }

    public function test_real_qpdf_rejects_encryption_attachment_and_object_stream_content(): void
    {
        $passive = $this->write('passive.pdf', $this->pdf());

        $encrypted = $this->path('encrypted.pdf');
        $this->qpdf(['--encrypt', 'user-password', 'owner-password', '256', '--', $passive, $encrypted]);
        $probe = $this->qpdf(['--is-encrypted', $encrypted], [0, 2, 3]);
        $this->assertSame(0, $probe->getExitCode(), 'encrypted/password-protected fixture');
        $this->assertRejected($encrypted, 'encrypted/password-protected');

        $attachmentPayload = $this->write('attachment.txt', 'synthetic attachment');
        $attachment = $this->path('attachment.pdf');
        $this->qpdf([$passive, $attachment, '--add-attachment', $attachmentPayload, '--']);
        $attachmentJson = $this->parsedJson($attachment);
        $this->assertNotSame([], $attachmentJson['attachments'], 'attachment/EmbeddedFiles fixture');
        $this->assertRejected($attachment, 'attachment/EmbeddedFiles');

        $javascript = $this->write('javascript.pdf', $this->pdf('/OpenAction 4 0 R', ['<< /Type /Action /S /JavaScript /JS (synthetic) >>']));
        $compressed = $this->path('object-stream.pdf');
        $this->qpdf(['--object-streams=generate', $javascript, $compressed]);
        $json = $this->parsedJson($compressed);
        $this->assertTrue($this->contains($json, '/JavaScript'), 'prohibited content survives object-stream generation');
        $this->assertRejected($compressed, 'object-stream JavaScript');
    }

    public function test_real_qpdf_rejects_forms_and_action_catalog_structures(): void
    {
        $fixtures = [
            'acroform' => ['/AcroForm 4 0 R', ['<< /Fields [] >>'], '/AcroForm'],
            'javascript' => ['/Names << /JavaScript << /Names [(x) 4 0 R] >> >>', ['<< /S /JavaScript /JS (synthetic) >>'], '/JavaScript'],
            'open-action' => ['/OpenAction 4 0 R', ['<< /S /GoTo /D [3 0 R /Fit] >>'], '/OpenAction'],
            'additional-actions' => ['/AA << /O 4 0 R >>', ['<< /S /GoTo /D [3 0 R /Fit] >>'], '/AA'],
            'launch' => ['/OpenAction 4 0 R', ['<< /S /Launch /F (synthetic.txt) >>'], '/Launch'],
            'uri' => ['/OpenAction 4 0 R', ['<< /S /URI /URI (https://invalid.example/) >>'], '/URI'],
        ];
        foreach ($fixtures as $label => [$catalog, $objects, $needle]) {
            $path = $this->write("{$label}.pdf", $this->pdf($catalog, $objects));
            $json = $this->parsedJson($path);
            $this->assertTrue($this->contains($json, $needle), "{$label} fixture contains intended structure");
            $this->assertRejected($path, $label);
        }
    }

    public function test_real_qpdf_rejects_zero_pages_101_pages_and_recoverable_damage(): void
    {
        $zero = $this->write('zero.pdf', $this->pdf('', [], 0));
        $this->assertCount(0, $this->parsedJson($zero)['pages']);
        $this->assertRejected($zero, 'zero pages');

        $many = $this->write('many.pdf', $this->pdf('', [], 101));
        $this->assertCount(101, $this->parsedJson($many)['pages']);
        $this->assertRejected($many, '101 pages');

        $valid = $this->pdf();
        $damaged = preg_replace('/startxref\n\d+/', "startxref\n1", $valid);
        $path = $this->write('recoverable.pdf', $damaged);
        $check = $this->qpdf(['--check', '--', $path], [0, 2, 3]);
        $this->assertMatchesRegularExpression('/warning|recover|repair|damaged/i', $check->getOutput().$check->getErrorOutput());
        $this->assertRejected($path, 'recoverable warning');
    }

    private function assertRejected(string $path, string $label): void
    {
        try {
            (new QpdfInspector)->inspect(new UploadedFile($path, basename($path), 'application/pdf', UPLOAD_ERR_OK, true));
            $this->fail("{$label} fixture was accepted.");
        } catch (RuntimeException) {
            $this->assertTrue(true, $label);
        }
    }

    private function parsedJson(string $path): array
    {
        $process = $this->qpdf(['--json', '--json-stream-data=none', '--', $path]);

        return json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);
    }

    private function qpdf(array $arguments, array $allowed = [0]): Process
    {
        $process = new Process([config('help_application_documents.qpdf.binary'), ...$arguments]);
        $process->setTimeout(10);
        $process->run();
        $this->assertContains($process->getExitCode(), $allowed, 'qpdf fixture command failed');

        return $process;
    }

    private function contains(mixed $value, string $needle): bool
    {
        if (is_string($value) && $value === $needle) {
            return true;
        }
        if (! is_array($value)) {
            return false;
        }
        foreach ($value as $key => $child) {
            if ($key === $needle || $this->contains($child, $needle)) {
                return true;
            }
        }

        return false;
    }

    private function write(string $name, string $bytes): string
    {
        $path = $this->path($name);
        file_put_contents($path, $bytes);

        return $path;
    }

    private function path(string $name): string
    {
        return $this->directory.DIRECTORY_SEPARATOR.$name;
    }

    /** @param list<string> $extraObjects */
    private function pdf(string $catalogExtra = '', array $extraObjects = [], int $pageCount = 1): string
    {
        $kids = [];
        $objects = ['<< /Type /Catalog /Pages 2 0 R '.$catalogExtra.' >>', ''];
        for ($index = 0; $index < $pageCount; $index++) {
            $number = count($objects) + 1;
            $kids[] = "{$number} 0 R";
            $objects[] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 100 100] /Resources << >> >>';
        }
        $objects[1] = '<< /Type /Pages /Kids ['.implode(' ', $kids)."] /Count {$pageCount} >>";
        $objects = [...$objects, ...$extraObjects];
        $pdf = "%PDF-1.4\n";
        $offsets = [0];
        foreach ($objects as $index => $object) {
            $offsets[] = strlen($pdf);
            $number = $index + 1;
            $pdf .= "{$number} 0 obj\n{$object}\nendobj\n";
        }
        $xref = strlen($pdf);
        $size = count($objects) + 1;
        $pdf .= "xref\n0 {$size}\n0000000000 65535 f \n";
        for ($index = 1; $index < $size; $index++) {
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$index]);
        }

        return $pdf."trailer\n<< /Size {$size} /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF\n";
    }
}
