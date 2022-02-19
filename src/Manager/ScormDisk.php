<?php

namespace Peopleaps\Scorm\Manager;

use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use Peopleaps\Scorm\Entity\Scorm;
use Peopleaps\Scorm\Exception\StorageNotFoundException;
use ZipArchive;
use ZipStream\ZipStream;

class ScormDisk
{
    /**
     * Extract zip file into destination directory.
     *
     * @param string $path Destination directory
     * @param string $zipFilePath The path to the zip file.
     *
     * @return bool True on success, false on failure.
     */
    public function unzip($file, $path)
    {
        $path = $this->cleanPath($path);

        $zipArchive = new ZipArchive();
        if ($zipArchive->open($file) !== true) {
            return false;
        }

        /** @var FilesystemAdapter $disk */
        $disk = $this->getDisk();

        for ($i = 0; $i < $zipArchive->numFiles; ++$i) {
            $zipEntryName = $zipArchive->getNameIndex($i);
            $destination = $path . DIRECTORY_SEPARATOR . $this->cleanPath($zipEntryName);
            if ($this->isDirectory($zipEntryName)) {
                $disk->createDir($destination);
                continue;
            }
            $disk->putStream($destination, $zipArchive->getStream($zipEntryName));
        }

        return true;
    }

    public function download(Scorm $scorm)
    {
        return response()->stream(function () use ($scorm) {
            // enable output of HTTP headers
            // $options = new ZipStream\Option\Archive();
            // $options->setSendHttpHeaders(true);
            $zip = new ZipStream($scorm->title . ".zip");
            /** @var FilesystemAdapter $disk */
            $disk = $this->getDisk();
            $zip->addFileFromStream($scorm->title, $disk->readStream($scorm->uuid));
            $zip->finish();
        });
    }

    /**
     * @param string $directory
     * @return bool
     */
    public function deleteScormFolder($folderHashedName)
    {
        return $this->getDisk()->deleteDirectory($folderHashedName);
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
}
