<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;

class QpdfInspector
{
    public function inspect(UploadedFile $file): int
    {
        $binary = config('help_application_documents.qpdf.binary');
        if (! is_string($binary) || $binary === '' || str_contains($binary, "\0") || ! is_file($binary)
            || config('help_application_documents.qpdf.version') !== '12.4.1') {
            throw new RuntimeException('PDF inspection unavailable.');
        }
        $version = $this->run([$binary, '--version']);
        if ($version['status'] !== 0 || trim($version['stderr']) !== ''
            || preg_match('/\Aqpdf version 12\.4\.1(?:\R|\z)/', $version['stdout']) !== 1) {
            throw new RuntimeException('PDF inspection unavailable.');
        }
        $path = $file->getRealPath();
        if (! is_string($path) || $path === '') {
            throw new RuntimeException('PDF inspection failed.');
        }
        $check = $this->run([$binary, '--check', '--', $path]);
        $successMessage = 'No syntax or stream encoding errors found';
        $diagnostics = preg_replace(
            '/No\s+syntax\s+or\s+stream\s+encoding\s+errors\s+found(?:;|\.)?\s*the\s+file\s+may\s+still\s+contain\s+errors\s+that\s+qpdf\s+cannot\s+detect\.?/i',
            '',
            $check['stdout'],
        );
        if ($check['status'] !== 0 || trim($check['stderr']) !== ''
            || ! str_contains($check['stdout'], $successMessage)
            || ! is_string($diagnostics)
            || preg_match('/warning|repair|recover(?:y)?|damaged|errors?/i', $diagnostics) === 1) {
            throw new RuntimeException('PDF inspection failed.');
        }
        $result = $this->run([$binary, '--json', '--json-stream-data=none', '--', $path]);
        if ($result['status'] !== 0 || trim($result['stderr']) !== '') {
            throw new RuntimeException('PDF inspection failed.');
        }
        try {
            $json = json_decode($result['stdout'], true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            throw new RuntimeException('PDF inspection failed.');
        }

        return $this->validateJson($json);
    }

    /** @param list<string> $command @return array{status:int,stdout:string,stderr:string} */
    protected function run(array $command): array
    {
        $timeout = config('help_application_documents.qpdf.timeout_seconds');
        $limit = config('help_application_documents.qpdf.max_output_bytes');
        if (! is_int($timeout) || $timeout < 1 || $timeout > 30 || ! is_int($limit) || $limit < 1024 || $limit > 16777216) {
            throw new RuntimeException('PDF inspection unavailable.');
        }
        $process = new Process($command);
        $process->setTimeout($timeout);
        $stdout = $stderr = '';
        try {
            $process->run(function (string $type, string $data) use (&$stdout, &$stderr, $limit, $process): void {
                $type === Process::ERR ? $stderr .= $data : $stdout .= $data;
                if (strlen($stdout) + strlen($stderr) > $limit) {
                    $process->stop(0);
                    throw new RuntimeException('PDF inspection failed.');
                }
            });
        } catch (Throwable) {
            if ($process->isRunning()) {
                $process->stop(0);
            }
            throw new RuntimeException('PDF inspection failed.');
        }

        return ['status' => $process->getExitCode() ?? -1, 'stdout' => $stdout, 'stderr' => $stderr];
    }

    private function validateJson(mixed $json): int
    {
        $supportedVersions = config('help_application_documents.qpdf.supported_json_versions');
        $maximumPages = config('help_application_documents.limits.max_pdf_pages');
        if (! is_array($supportedVersions) || ! array_is_list($supportedVersions) || $supportedVersions === []
            || count($supportedVersions) !== count(array_unique($supportedVersions, SORT_REGULAR))
            || collect($supportedVersions)->contains(fn (mixed $version): bool => ! is_int($version) || $version < 1)
            || ! is_int($maximumPages) || $maximumPages < 1 || $maximumPages > 10000
            || ! is_array($json) || ! is_int($json['version'] ?? null)
            || ! in_array($json['version'], $supportedVersions, true)
            || ($json['encrypt']['encrypted'] ?? null) !== false || ! is_array($json['pages'] ?? null)
            || ! is_array($json['qpdf'] ?? null) || ! array_is_list($json['qpdf']) || count($json['qpdf']) !== 2
            || ($json['qpdf'][0]['jsonversion'] ?? null) !== $json['version']
            || ($json['attachments'] ?? null) !== [] || ($json['acroform']['hasacroform'] ?? null) !== false) {
            throw new RuntimeException('Unsafe PDF structure.');
        }
        $metadata = $json['qpdf'][0];
        if (! is_array($metadata)
            || ! is_bool($metadata['calledgetallpages'] ?? null)
            || ! is_int($metadata['maxobjectid'] ?? null) || $metadata['maxobjectid'] < 0
            || ! is_string($metadata['pdfversion'] ?? null) || preg_match('/\A\d+\.\d+\z/', $metadata['pdfversion']) !== 1
            || ! is_bool($metadata['pushedinheritedpageresources'] ?? null)) {
            throw new RuntimeException('Unsafe PDF structure.');
        }
        foreach ($json['pages'] as $page) {
            if (! is_array($page) || ! is_string($page['object'] ?? null)
                || preg_match('/\A\d+ \d+ R\z/', $page['object']) !== 1
                || ! is_int($page['pageposfrom1'] ?? null) || $page['pageposfrom1'] < 1
                || ! is_array($page['contents'] ?? null) || ! is_array($page['images'] ?? null)
                || ! is_array($page['outlines'] ?? null) || ! array_key_exists('label', $page)) {
                throw new RuntimeException('Unsafe PDF structure.');
            }
        }
        $pages = count($json['pages']);
        if ($pages < 1 || $pages > $maximumPages) {
            throw new RuntimeException('Unsafe PDF structure.');
        }
        $objects = $json['qpdf'][1];
        if (! is_array($objects) || ! array_key_exists('trailer', $objects)) {
            throw new RuntimeException('Unsafe PDF structure.');
        }
        foreach ($objects as $key => $object) {
            if (! is_string($key) || preg_match('/\A(?:trailer|obj:\d+ \d+ R)\z/', $key) !== 1 || ! is_array($object)) {
                throw new RuntimeException('Unsafe PDF structure.');
            }
            if ($key === 'trailer') {
                if (array_keys($object) !== ['value'] || ! is_array($object['value'])) {
                    throw new RuntimeException('Unsafe PDF structure.');
                }
            } elseif (array_keys($object) === ['value']) {
                // Direct and object-stream-originating objects are represented by value.
            } elseif (array_keys($object) === ['stream']) {
                if (! is_array($object['stream']) || ! is_array($object['stream']['dict'] ?? null)) {
                    throw new RuntimeException('Unsafe PDF structure.');
                }
            } else {
                throw new RuntimeException('Unsafe PDF structure.');
            }
        }
        $this->walk($objects);

        return $pages;
    }

    private function walk(mixed $value): void
    {
        if (! is_array($value)) {
            return;
        }
        $keys = ['/EmbeddedFiles', '/EmbeddedFile', '/Filespec', '/EF', '/JavaScript', '/JS', '/OpenAction', '/AA', '/AcroForm', '/XFA', '/RichMedia', '/Movie', '/Sound', '/3D'];
        foreach ($value as $key => $child) {
            if ((is_string($key) && (in_array($key, $keys, true) || strtolower($key) === 'unresolved'))
                || ($key === '/Subtype' && in_array($child, ['/FileAttachment', '/RichMedia', '/Movie', '/Sound', '/3D', '/Screen', '/Rendition'], true))
                || ($key === '/S' && in_array($child, ['/Launch', '/SubmitForm', '/ImportData', '/GoToR', '/GoToE', '/URI', '/Rendition', '/JavaScript'], true))
                || ($key === '/Type' && in_array($child, ['/Filespec', '/Action'], true))
                || (is_string($child) && preg_match('/\Aunresolved\b/i', $child) === 1)) {
                throw new RuntimeException('Unsafe PDF structure.');
            }
            $this->walk($child);
        }
    }
}
