<?php

namespace Tests\Unit;

use App\Services\QpdfInspector;
use Illuminate\Http\UploadedFile;
use RuntimeException;
use Tests\TestCase;

class QpdfInspectorSchemaTest extends TestCase
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

    public function test_missing_and_malformed_binary_configuration_fail_closed(): void
    {
        $original = config('help_application_documents.qpdf.binary');
        try {
            foreach ([null, '', "bad\0path", __DIR__.'/missing-qpdf'] as $binary) {
                config()->set('help_application_documents.qpdf.binary', $binary);
                $this->expectRuntimeFailure(fn () => (new QpdfInspector)->inspect($this->file()));
            }
        } finally {
            config()->set('help_application_documents.qpdf.binary', $original);
        }
    }

    public function test_wrong_version_nonzero_stderr_invalid_json_and_unsupported_schema_fail_closed(): void
    {
        $cases = [
            [['status' => 0, 'stdout' => "qpdf version 12.4.0\n", 'stderr' => '']],
            [$this->version(), ['status' => 2, 'stdout' => '', 'stderr' => '']],
            [$this->version(), ['status' => 0, 'stdout' => $this->checkText(), 'stderr' => 'warning']],
            [$this->version(), $this->check(), ['status' => 0, 'stdout' => '{', 'stderr' => '']],
            [$this->version(), $this->check(), $this->jsonResponse(['version' => 99])],
        ];
        foreach ($cases as $responses) {
            $this->expectRuntimeFailure(fn () => $this->inspector($responses)->inspect($this->file()));
        }
    }

    public function test_qpdf_success_sentence_is_allowed_but_every_standalone_diagnostic_class_is_rejected(): void
    {
        $accepted = $this->inspector([$this->version(), $this->check(), $this->jsonResponse()])->inspect($this->file());
        $this->assertSame(1, $accepted);

        foreach (['warning', 'repair', 'recover', 'recovery', 'damaged', 'error', 'errors'] as $diagnostic) {
            $check = $this->check();
            $check['stdout'] .= "{$diagnostic}\n";
            $this->expectRuntimeFailure(fn () => $this->inspector([$this->version(), $check])->inspect($this->file()));
        }
    }

    public function test_every_prohibited_structured_pdf_feature_fails_closed(): void
    {
        $features = [
            ['/EmbeddedFiles' => []], ['/EmbeddedFile' => []], ['/Filespec' => []], ['/EF' => []],
            ['/JavaScript' => []], ['/JS' => 'x'], ['/OpenAction' => []], ['/AA' => []],
            ['/AcroForm' => []], ['/XFA' => []], ['/RichMedia' => []], ['/Movie' => []], ['/Sound' => []], ['/3D' => []],
            ['/Subtype' => '/FileAttachment'], ['/Subtype' => '/Screen'], ['/Subtype' => '/Rendition'],
            ['/S' => '/Launch'], ['/S' => '/SubmitForm'], ['/S' => '/ImportData'], ['/S' => '/GoToR'],
            ['/S' => '/GoToE'], ['/S' => '/URI'], ['/S' => '/Rendition'], ['/S' => '/JavaScript'],
            ['/Type' => '/Filespec'], ['/Type' => '/Action'], ['unresolved' => '5 0 R'],
        ];
        foreach ($features as $feature) {
            $schema = $this->safeSchema();
            $schema['qpdf'][1]['obj:4 0 R']['value'] = $feature;
            $this->expectRuntimeFailure(fn () => $this->inspector([$this->version(), $this->check(), $this->jsonResponse($schema)])->inspect($this->file()));
        }
    }

    public function test_encryption_forms_attachments_page_limits_and_malformed_objects_fail_closed(): void
    {
        $mutators = [
            fn (array $s): array => $this->set($s, ['encrypt', 'encrypted'], true),
            fn (array $s): array => $this->set($s, ['acroform', 'hasacroform'], true),
            fn (array $s): array => $this->set($s, ['attachments'], ['a' => []]),
            fn (array $s): array => $this->set($s, ['pages'], []),
            fn (array $s): array => $this->set($s, ['pages'], array_fill(0, 101, [])),
            fn (array $s): array => $this->set($s, ['qpdf', 1], ['bad-key' => []]),
        ];
        foreach ($mutators as $mutate) {
            $schema = $mutate($this->safeSchema());
            $this->expectRuntimeFailure(fn () => $this->inspector([$this->version(), $this->check(), $this->jsonResponse($schema)])->inspect($this->file()));
        }
    }

    public function test_actual_runner_enforces_timeout_and_output_bounds_and_reports_process_channels(): void
    {
        $runner = new class extends QpdfInspector
        {
            public function execute(array $command): array
            {
                return $this->run($command);
            }
        };
        $timeout = config('help_application_documents.qpdf.timeout_seconds');
        $limit = config('help_application_documents.qpdf.max_output_bytes');
        try {
            config()->set('help_application_documents.qpdf.timeout_seconds', 2);
            config()->set('help_application_documents.qpdf.max_output_bytes', 1024);
            $success = $runner->execute([PHP_BINARY, '-r', 'fwrite(STDOUT, "bounded");']);
            $this->assertSame(0, $success['status']);
            $this->assertSame('bounded', $success['stdout']);
            $this->assertSame('', $success['stderr']);

            $nonzero = $runner->execute([PHP_BINARY, '-r', 'exit(7);']);
            $this->assertSame(7, $nonzero['status']);
            $stderr = $runner->execute([PHP_BINARY, '-r', 'fwrite(STDERR, "diagnostic");']);
            $this->assertSame('diagnostic', $stderr['stderr']);

            config()->set('help_application_documents.qpdf.timeout_seconds', 1);
            $this->expectRuntimeFailure(fn () => $runner->execute([PHP_BINARY, '-r', 'sleep(2);']));
            $this->expectRuntimeFailure(fn () => $runner->execute([PHP_BINARY, '-r', 'fwrite(STDOUT, str_repeat("x", 2048));']));
        } finally {
            config()->set('help_application_documents.qpdf.timeout_seconds', $timeout);
            config()->set('help_application_documents.qpdf.max_output_bytes', $limit);
        }
    }

    /** @param list<array{status:int,stdout:string,stderr:string}> $responses */
    private function inspector(array $responses): QpdfInspector
    {
        return new class($responses) extends QpdfInspector
        {
            public function __construct(private array $responses) {}

            protected function run(array $command): array
            {
                if ($this->responses === []) {
                    throw new RuntimeException('Unexpected process call.');
                }

                return array_shift($this->responses);
            }
        };
    }

    private function file(): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'qpdf-schema-');
        file_put_contents($path, "%PDF-1.4\n%%EOF\n");
        $this->temporaryFiles[] = $path;

        return new UploadedFile($path, 'synthetic.pdf', 'application/pdf', UPLOAD_ERR_OK, true);
    }

    private function version(): array
    {
        return ['status' => 0, 'stdout' => "qpdf version 12.4.1\n", 'stderr' => ''];
    }

    private function check(): array
    {
        return ['status' => 0, 'stdout' => $this->checkText(), 'stderr' => ''];
    }

    private function checkText(): string
    {
        return "checking synthetic.pdf\nNo syntax or stream encoding errors found; the file may still contain\nerrors that qpdf cannot detect\n";
    }

    private function jsonResponse(?array $schema = null): array
    {
        return ['status' => 0, 'stdout' => json_encode($schema ?? $this->safeSchema(), JSON_THROW_ON_ERROR), 'stderr' => ''];
    }

    private function safeSchema(): array
    {
        return ['version' => 2, 'encrypt' => ['encrypted' => false], 'pages' => [[
            'object' => '3 0 R', 'pageposfrom1' => 1, 'contents' => [], 'images' => [], 'outlines' => [], 'label' => null,
        ]], 'attachments' => [], 'acroform' => ['hasacroform' => false], 'qpdf' => [[
            'calledgetallpages' => true, 'jsonversion' => 2, 'maxobjectid' => 4, 'pdfversion' => '1.4', 'pushedinheritedpageresources' => false,
        ], ['trailer' => ['value' => []], 'obj:4 0 R' => ['value' => []]]]];
    }

    private function set(array $array, array $path, mixed $value): array
    {
        $target = &$array;
        foreach ($path as $key) {
            $target = &$target[$key];
        }
        $target = $value;

        return $array;
    }

    private function expectRuntimeFailure(callable $callback): void
    {
        try {
            $callback();
            $this->fail('Unsafe qpdf condition was accepted.');
        } catch (RuntimeException) {
            $this->assertTrue(true);
        }
    }
}
