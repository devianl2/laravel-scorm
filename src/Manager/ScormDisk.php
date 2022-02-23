<?php

namespace Peopleaps\Scorm\Manager;

use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\UploadedFile;
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
                    $disk->createDir($destination);
                    continue;
                }
                $disk->putStream($destination, $unzipper->getStream($zipEntryName));
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
        if (Storage::exists($file)) {
            Storage::delete($file);
        }
        Storage::writeStream($file, $this->getArchiveDisk()->readStream($file));
        $path = Storage::path($file);
        call_user_func($fn, $path);
        unlink($path); // delete temp package
        Storage::deleteDirectory(dirname($file)); // delete temp dir
    }

    /**
     * @param string $directory
     * @return bool
     */
    public function deleteScormFolder($folderHashedName)
    {
        return $this->getDisk()->deleteDirectory($folderHashedName);
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
        if (!config()->has('filesystems.disks.' . config('scorm.disk'))) {
            throw new StorageNotFoundException('scorm_disk_not_define');
        }
        return Storage::disk(config('scorm.disk'));
    }

    /**
     * @return FilesystemAdapter $disk
     */
    private function getArchiveDisk()
    {
        if (!config()->has('filesystems.disks.' . config('scorm.archive'))) {
            throw new StorageNotFoundException('scorm_archive_disk_not_define');
        }
        return Storage::disk(config('scorm.archive'));
    }
}
