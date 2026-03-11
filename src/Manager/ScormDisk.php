<?php

namespace Peopleaps\Scorm\Manager;

use Exception;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Peopleaps\Scorm\Exception\StorageNotFoundException;
use ZipArchive;

class ScormDisk
{
    /**
     * Extract a zip from the archive S3 bucket and stream each entry
     * directly into the scorm S3 bucket — no local app-storage I/O.
     *
     * Strategy:
     *   1. Stream the zip from archive-S3 into a PHP temporary file
     *      (php://temp or sys_get_temp_dir). This is an OS-level tmp file
     *      completely separate from Laravel's Storage / app disk.
     *   2. Open the tmp file with ZipArchive (which requires a real path).
     *   3. Stream every entry straight to the scorm S3 bucket.
     *   4. Delete the tmp file immediately — it is never written to the
     *      Laravel default disk or app storage directory.
     *
     * @param  UploadedFile|string  $file        Local path or UploadedFile for
     *                                           an already-downloaded zip. Pass
     *                                           null and use readScormArchive()
     *                                           when the zip lives on S3.
     * @param  string               $target_dir  S3 key prefix (folder) for output.
     * @return bool
     */
    public function unzipper($file, string $target_dir): bool
    {
        $target_dir = $this->normalizeS3Key($target_dir);

        $zip = new ZipArchive();

        if ($zip->open($file) !== true) {
            Log::error('ScormDisk::unzipper — ZipArchive could not open file: ' . $file);
            return false;
        }

        try {
            /** @var FilesystemAdapter $disk */
            $disk = $this->getDisk();

            for ($i = 0; $i < $zip->numFiles; ++$i) {
                $zipEntryName = $zip->getNameIndex($i);

                // Normalise the entry name to a clean S3 key segment.
                $entryKey = $this->normalizeS3Key($zipEntryName);
                $destination = $this->joinS3($target_dir, $entryKey);

                if ($this->isDirectory($zipEntryName)) {
                    // S3 has no real directories; creating a zero-byte placeholder
                    // is optional but kept for compatibility with code that checks
                    // directory existence via $disk->directoryExists().
                    $disk->createDirectory($destination);
                    continue;
                }

                $stream = $zip->getStream($zipEntryName);
                if (!is_resource($stream)) {
                    Log::warning('ScormDisk::unzipper — could not get stream for entry: ' . $zipEntryName);
                    continue;
                }

                // writeStream() hands the resource straight to the S3 SDK —
                // no intermediate local write.
                $disk->writeStream($destination, $stream);
                fclose($stream);
            }
        } finally {
            $zip->close();
        }

        return true;
    }

    /**
     * Read a SCORM zip from the archive S3 bucket, expose its local tmp path
     * to $fn, then clean up — without writing to Laravel's app Storage disk.
     *
     * Flow:
     *   archive-S3 ──stream──► php://temp (OS tmp, not app disk)
     *                                │
     *                           ZipArchive::open()
     *                                │
     *                         call $fn($tmpPath)  ← unzipper() is called here
     *                                │
     *                           unlink($tmpPath)
     *
     * @param  string    $file  S3 object key inside the archive bucket.
     * @param  callable  $fn    Receives the real local tmp file path.
     * @throws StorageNotFoundException|Exception
     */
    public function readScormArchive(string $file, callable $fn): void
    {
        $archiveDisk = $this->getArchiveDisk();

        Log::info('ScormDisk::readScormArchive — processing: ' . $file);

        if (!$archiveDisk->exists($file)) {
            Log::error('ScormDisk::readScormArchive — not found on archive disk: ' . $file);
            throw new StorageNotFoundException('scorm_archive_not_found_on_archive_disk: ' . $file);
        }

        // Pull the S3 object as a stream.
        $s3Stream = $archiveDisk->readStream($file);
        if (!is_resource($s3Stream)) {
            Log::error('ScormDisk::readScormArchive — failed to open stream for: ' . $file);
            throw new StorageNotFoundException('failed_to_read_scorm_archive_stream: ' . $file);
        }

        // Write into a true OS temp file (never touches app Storage disk).
        $tmpPath = $this->streamToTempFile($s3Stream);

        try {
            call_user_func($fn, $tmpPath);
        } finally {
            // Always clean up the tmp file, even if $fn throws.
            if (file_exists($tmpPath)) {
                unlink($tmpPath);
                Log::info('ScormDisk::readScormArchive — removed tmp file: ' . $tmpPath);
            }
        }
    }

    /**
     * Delete both the content folder (scorm disk) and the archive folder
     * (archive disk) for the given UUID.
     *
     * @param  string  $uuid
     * @return bool  true only when the content directory was removed.
     */
    public function deleteScorm(string $uuid): bool
    {
        $this->deleteScormArchive($uuid);
        return $this->deleteScormContent($uuid);
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Copy an S3 stream into a true OS temporary file and return its path.
     * Uses sys_get_temp_dir() — completely separate from Laravel Storage.
     *
     * @param  resource  $stream
     * @return string  Absolute path to the tmp file.
     * @throws Exception
     */
    private function streamToTempFile($stream): string
    {
        $tmpPath = tempnam(sys_get_temp_dir(), 'scorm_');
        if ($tmpPath === false) {
            throw new Exception('ScormDisk — could not create OS temp file.');
        }

        $tmpHandle = fopen($tmpPath, 'wb');
        if ($tmpHandle === false) {
            unlink($tmpPath);
            throw new Exception('ScormDisk — could not open OS temp file for writing: ' . $tmpPath);
        }

        try {
            stream_copy_to_stream($stream, $tmpHandle);
        } finally {
            fclose($tmpHandle);
            // The caller-supplied S3 stream is no longer needed.
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        return $tmpPath;
    }

    /**
     * Delete the SCORM content directory from the scorm S3 bucket.
     */
    private function deleteScormContent(string $folderHashedName): bool
    {
        try {
            return (bool) $this->getDisk()->deleteDirectory($folderHashedName);
        } catch (Exception $ex) {
            Log::error('ScormDisk::deleteScormContent — ' . $ex->getMessage());
            return false;
        }
    }

    /**
     * Delete the SCORM archive directory from the archive S3 bucket.
     */
    private function deleteScormArchive(string $uuid): bool
    {
        try {
            return (bool) $this->getArchiveDisk()->deleteDirectory($uuid);
        } catch (Exception $ex) {
            Log::error('ScormDisk::deleteScormArchive — ' . $ex->getMessage());
            return false;
        }
    }

    /**
     * Join path segments using '/' — always correct for S3 keys regardless
     * of the host OS's DIRECTORY_SEPARATOR.
     */
    private function joinS3(string ...$paths): string
    {
        return implode('/', array_filter($paths, fn($p) => $p !== ''));
    }

    /**
     * Normalise a path to use '/' (S3 convention).
     * Strips a trailing slash so the result is always a key prefix, not
     * a directory placeholder.
     */
    private function normalizeS3Key(string $path): string
    {
        // Convert OS separators → S3 separator, then strip trailing slash.
        return rtrim(str_replace(DIRECTORY_SEPARATOR, '/', $path), '/');
    }

    /**
     * Returns true when the zip entry represents a directory.
     */
    private function isDirectory(string $zipEntryName): bool
    {
        return str_ends_with($zipEntryName, '/');
    }

    /**
     * Resolve and validate the scorm content disk.
     *
     * @return FilesystemAdapter
     * @throws StorageNotFoundException
     */
    private function getDisk(): FilesystemAdapter
    {
        $diskName = config('scorm.disk');
        if (empty($diskName)) {
            throw new StorageNotFoundException('scorm_disk_not_configured');
        }
        if (!config()->has('filesystems.disks.' . $diskName)) {
            throw new StorageNotFoundException('scorm_disk_not_defined: ' . $diskName);
        }
        return Storage::disk($diskName);
    }

    /**
     * Resolve and validate the scorm archive disk.
     *
     * @return FilesystemAdapter
     * @throws StorageNotFoundException
     */
    private function getArchiveDisk(): FilesystemAdapter
    {
        $archiveDiskName = config('scorm.archive');
        if (empty($archiveDiskName)) {
            throw new StorageNotFoundException('scorm_archive_disk_not_configured');
        }
        if (!config()->has('filesystems.disks.' . $archiveDiskName)) {
            throw new StorageNotFoundException('scorm_archive_disk_not_defined: ' . $archiveDiskName);
        }
        return Storage::disk($archiveDiskName);
    }
}
