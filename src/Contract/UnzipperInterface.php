<?php

namespace Peopleaps\Scorm\Contract;

use Peopleaps\Scorm\Exception\StorageNotFoundException;

/**
 * Extracts a SCORM zip from the archive disk into the scorm (content) disk.
 *
 * Implementations may use local temp + ZipArchive (@see LocalUnzipper)
 * or delegate to a remote service such as AWS Lambda.
 */
interface UnzipperInterface
{
    /**
     * Extract the zip at $archiveKey on the archive disk into the scorm disk
     * under the $targetUuid prefix.
     *
     * @throws StorageNotFoundException When the archive is missing or extraction fails.
     */
    public function extract(string $archiveKey, string $targetUuid): void;
}
