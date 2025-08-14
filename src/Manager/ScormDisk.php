<?php

namespace Peopleaps\Scorm\Manager;

use Exception;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Peopleaps\Scorm\Exception\StorageNotFoundException;

class ScormDisk
{
    /**
     * Extract zip file into destination directory.
     *
     * @param UploadedFile|string $file zip source
     * @param string $path The path to the destination.
     *
     * @return bool true on success, false on failure.
     */
    function unzipper($file, $target_dir)
    {
        $target_dir = $this->cleanPath($target_dir);
        $unzipper = resolve(\ZipArchive::class);
        if ($unzipper->open($file)) {
            /** @var FilesystemAdapter $disk */
            $disk = $this->getDisk();
            for ($i = 0; $i < $unzipper->numFiles; ++$i) {
                $zipEntryName = $unzipper->getNameIndex($i);
                $destination = $this->join($target_dir, $this->cleanPath($zipEntryName));
                if ($this->isDirectory($zipEntryName)) {
                    $disk->createDirectory($destination);
                    continue;
                }
                $disk->writeStream($destination, $unzipper->getStream($zipEntryName));
            }
            return true;
        }
        return false;
    }

    /**
     * @param string $file SCORM archive uri on storage.
     * @param callable $fn function run user stuff before unlink
     */
    public function readScormArchive($file, callable $fn)
    {
        try {
            $archiveDisk = $this->getArchiveDisk();
            
            // Log the file path being processed for debugging
            Log::info('Processing SCORM archive file: ' . $file);
            
            // Check if file exists on archive disk
            if (!$archiveDisk->exists($file)) {
                Log::error('File not found on archive disk: ' . $file);
                throw new StorageNotFoundException('scorm_archive_not_found_on_archive_disk: ' . $file);
            }
            
            // Get the stream from archive disk
            $stream = $archiveDisk->readStream($file);
            if (!is_resource($stream)) {
                Log::error('Failed to read stream from archive disk for file: ' . $file . '. Stream type: ' . gettype($stream));
                throw new StorageNotFoundException('failed_to_read_scorm_archive_stream: ' . $file);
            }
            
            if (Storage::exists($file)) {
                Storage::delete($file);
            }
            
            Storage::writeStream($file, $stream);
            $path = Storage::path($file);
            call_user_func($fn, $path);
            // Clean local resources
            $this->clean($file);
        } catch (Exception $ex) {
            Log::error('Error in readScormArchive: ' . $ex->getMessage() . ' for file: ' . $file);
            throw new StorageNotFoundException('scorm_archive_not_found');
        }
    }

    private function clean($file)
    {
        try {
            Storage::delete($file);
            Storage::deleteDirectory(dirname($file)); // delete temp dir
        } catch (Exception $ex) {
            Log::error($ex->getMessage());
        }
    }

    /**
     * @param string $directory
     * @return bool
     */
    public function deleteScorm($uuid)
    {
        $this->deleteScormArchive($uuid); // try to delete archive if exists.
        return $this->deleteScormContent($uuid);
    }

    /**
     * @param string $directory
     * @return bool
     */
    private function deleteScormContent($folderHashedName)
    {
        try {
            return $this->getDisk()->deleteDirectory($folderHashedName);
        } catch (Exception $ex) {
            Log::error($ex->getMessage());
        }
    }

    /**
     * @param string $directory
     * @return bool
     */
    private function deleteScormArchive($uuid)
    {
        try {
            return $this->getArchiveDisk()->deleteDirectory($uuid);
        } catch (Exception $ex) {
            Log::error($ex->getMessage());
        }
    }

    /**
     * 
     * @param array $paths
     * @return string joined path
     */
    private function join(...$paths)
    {
        return  implode(DIRECTORY_SEPARATOR, $paths);
    }

    private function isDirectory($zipEntryName)
    {
        return substr($zipEntryName, -1) ===  '/';
    }

    private function cleanPath($path)
    {
        return str_replace('/', DIRECTORY_SEPARATOR, $path);
    }

    /**
     * @return FilesystemAdapter $disk
     */
    private function getDisk()
    {
        $diskName = config('scorm.disk');
        if (empty($diskName)) {
            throw new StorageNotFoundException('scorm_disk_not_configured');
        }
        
        if (!config()->has('filesystems.disks.' . $diskName)) {
            throw new StorageNotFoundException('scorm_disk_not_define: ' . $diskName);
        }
        
        $disk = Storage::disk($diskName);
        
        // Test if the disk is accessible
        try {
            $disk->exists('test');
        } catch (Exception $ex) {
            Log::error('SCORM disk not accessible: ' . $ex->getMessage());
            throw new StorageNotFoundException('scorm_disk_not_accessible: ' . $diskName);
        }
        
        return $disk;
    }

    /**
     * @return FilesystemAdapter $disk
     */
    private function getArchiveDisk()
    {
        $archiveDiskName = config('scorm.archive');
        if (empty($archiveDiskName)) {
            throw new StorageNotFoundException('scorm_archive_disk_not_configured');
        }
        
        if (!config()->has('filesystems.disks.' . $archiveDiskName)) {
            throw new StorageNotFoundException('scorm_archive_disk_not_define: ' . $archiveDiskName);
        }
        
        $disk = Storage::disk($archiveDiskName);
        
        // Test if the disk is accessible
        try {
            $disk->exists('test');
        } catch (Exception $ex) {
            Log::error('Archive disk not accessible: ' . $ex->getMessage());
            throw new StorageNotFoundException('scorm_archive_disk_not_accessible: ' . $archiveDiskName);
        }
        
        return $disk;
    }
}
