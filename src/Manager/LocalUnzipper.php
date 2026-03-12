<?php

namespace Peopleaps\Scorm\Manager;

use Exception;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Log;
use Peopleaps\Scorm\Contract\UnzipperInterface;
use Peopleaps\Scorm\Exception\StorageNotFoundException;
use ZipArchive;

/**
 * Extracts a SCORM zip from the archive disk to the scorm disk via a local
 * temp file and PHP's built-in ZipArchive.
 */
class LocalUnzipper implements UnzipperInterface
{
    public function __construct(
        private readonly FilesystemAdapter $archiveDisk,
        private readonly FilesystemAdapter $scormDisk,
    ) {}

    /** {@inheritdoc} */
    public function extract(string $archiveKey, string $targetUuid): void
    {
        if (!$this->archiveDisk->exists($archiveKey)) {
            throw new StorageNotFoundException('scorm_archive_not_found: ' . $archiveKey);
        }

        $stream = $this->archiveDisk->readStream($archiveKey);
        if (!is_resource($stream)) {
            throw new StorageNotFoundException('failed_to_read_scorm_archive_stream: ' . $archiveKey);
        }

        $tmpPath = $this->writeStreamToTempFile($stream);

        try {
            $this->unzipToScormDisk($tmpPath, $targetUuid);
        } finally {
            @unlink($tmpPath);
        }
    }

    /** @param resource $stream */
    private function writeStreamToTempFile($stream): string
    {
        $tmpPath = tempnam(sys_get_temp_dir(), 'scorm_');
        if ($tmpPath === false) {
            throw new Exception('Could not create temporary file.');
        }

        $handle = fopen($tmpPath, 'wb');
        if ($handle === false) {
            unlink($tmpPath);
            throw new Exception('Could not open temporary file for writing: ' . $tmpPath);
        }

        try {
            stream_copy_to_stream($stream, $handle);
        } finally {
            fclose($handle);
            fclose($stream);
        }

        return $tmpPath;
    }

    private function unzipToScormDisk(string $zipPath, string $targetUuid): void
    {
        $prefix = $this->normalizeKey($targetUuid);
        $zip    = new ZipArchive();

        if ($zip->open($zipPath) !== true) {
            throw new StorageNotFoundException('zip_open_failed: ' . $zipPath);
        }

        try {
            for ($i = 0; $i < $zip->numFiles; ++$i) {
                $name        = $zip->getNameIndex($i);
                $destination = $this->joinPath($prefix, $this->normalizeKey($name));

                if (str_ends_with($name, '/')) {
                    $this->scormDisk->createDirectory($destination);
                    continue;
                }

                $stream = $zip->getStream($name);
                if (!is_resource($stream)) {
                    Log::warning('LocalUnzipper: could not open stream for zip entry: ' . $name);
                    continue;
                }

                $this->scormDisk->writeStream($destination, $stream);
                fclose($stream);
            }
        } finally {
            $zip->close();
        }
    }

    private function joinPath(string ...$segments): string
    {
        return implode('/', array_filter($segments, fn($s) => $s !== ''));
    }

    private function normalizeKey(string $path): string
    {
        return rtrim(str_replace(DIRECTORY_SEPARATOR, '/', $path), '/');
    }
}
