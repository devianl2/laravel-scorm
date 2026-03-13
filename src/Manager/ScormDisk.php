<?php

namespace Peopleaps\Scorm\Manager;

use DateTime;
use DOMDocument;
use Exception;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Peopleaps\Scorm\Contract\UnzipperInterface;
use Peopleaps\Scorm\Entity\Scorm;
use Peopleaps\Scorm\Exception\InvalidScormArchiveException;
use Peopleaps\Scorm\Exception\StorageNotFoundException;
use Peopleaps\Scorm\Library\ScormLib;

class ScormDisk
{
    private ScormLib $scormLib;

    public function __construct(private readonly UnzipperInterface $unzipper)
    {
        $this->scormLib = new ScormLib();
    }

    /**
     * Whether extracted SCORM content for the given UUID already exists on the
     * scorm disk (detected by the presence of imsmanifest.xml).
     */
    public function contentExists(string $uuid): bool
    {
        try {
            $disk   = $this->getScormDisk();
            $prefix = $this->normalizeKey($uuid);

            if ($disk->exists($prefix . '/imsmanifest.xml')) {
                return true;
            }

            foreach ($disk->allFiles($prefix) as $path) {
                if (str_ends_with($path, 'imsmanifest.xml')) {
                    return true;
                }
            }
        } catch (\Throwable $e) {
            return false;
        }

        return false;
    }

    /**
     * Parse imsmanifest.xml from the scorm disk for the given UUID and return
     * structured metadata ready for persistence.
     *
     * @return array{
     *   identifier: string,
     *   title: string,
     *   version: string,
     *   entryUrl: string,
     *   scos: \Peopleaps\Scorm\Entity\Sco[],
     *   created_at: string|null,
     *   created_by: string|null,
     * }
     * @throws InvalidScormArchiveException
     */
    public function loadMetadata(string $uuid): array
    {
        $disk         = $this->getScormDisk();
        $prefix       = $this->normalizeKey($uuid);
        $manifestPath = $this->findManifest($disk, $prefix);

        if ($manifestPath === null) {
            throw new InvalidScormArchiveException('cannot_load_imsmanifest_message');
        }

        $xml = $disk->get($manifestPath);
        if (empty($xml)) {
            throw new InvalidScormArchiveException('cannot_load_imsmanifest_message');
        }

        $dom = $this->parseManifestXml($xml);

        $manifest = $dom->getElementsByTagName('manifest')->item(0);
        if (!$manifest || !$manifest->attributes->getNamedItem('identifier')) {
            throw new InvalidScormArchiveException('invalid_scorm_manifest_identifier');
        }

        $version = $this->resolveScormVersion($dom);
        $scos    = $this->scormLib->parseOrganizationsNode($dom);

        if (empty($scos)) {
            throw new InvalidScormArchiveException('no_sco_in_scorm_archive_message');
        }

        $entryUrl = $scos[0]->entryUrl ?? $scos[0]->scoChildren[0]->entryUrl ?? '';
        // $entryUrl = $this->prefixEntryUrl($entryUrl, $manifestPath);

        return [
            'identifier' => $manifest->attributes->getNamedItem('identifier')->nodeValue,
            'title'      => trim($dom->getElementsByTagName('title')->item(0)?->textContent ?? ''),
            'version'    => $version,
            'entryUrl'   => $entryUrl,
            'scos'       => $scos,
            'created_at' => $this->extractCreationDate($dom),
            'created_by' => $this->extractCreator($dom),
        ];
    }

    /**
     * Delegate extraction of the archive at $archiveKey into the scorm disk
     * under the $uuid prefix to the injected UnzipperInterface.
     */
    public function extractFromArchive(string $archiveKey, string $uuid): void
    {
        $this->unzipper->extract($archiveKey, $uuid);
    }

    /**
     * Store an uploaded zip on the archive disk at the given key.
     */
    public function putArchiveFile(UploadedFile $file, string $archiveKey): void
    {
        $stream = fopen($file->getRealPath(), 'r');
        if ($stream === false) {
            throw new Exception('Could not open uploaded file: ' . $file->getClientOriginalName());
        }

        try {
            $this->getArchiveDisk()->writeStream($archiveKey, $stream);
        } finally {
            fclose($stream);
        }
    }

    /**
     * Delete both the content directory (scorm disk) and the archive directory
     * (archive disk) for the given UUID.
     *
     * @return bool True when the content directory was successfully removed.
     */
    public function deleteScorm(string $uuid): bool
    {
        $this->deleteDirectory($this->getArchiveDisk(), $uuid, 'archive');
        return $this->deleteDirectory($this->getScormDisk(), $uuid, 'scorm');
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    private function normalizeKey(string $path): string
    {
        return rtrim(str_replace(DIRECTORY_SEPARATOR, '/', $path), '/');
    }

    private function findManifest(FilesystemAdapter $disk, string $prefix): ?string
    {
        try {
            $direct = $prefix . '/imsmanifest.xml';
            if ($disk->exists($direct)) {
                return $direct;
            }

            foreach ($disk->allFiles($prefix) as $path) {
                if (str_ends_with($path, 'imsmanifest.xml')) {
                    return $path;
                }
            }
        } catch (\Throwable $e) {
            return null;
        }

        return null;
    }

    private function parseManifestXml(string $xml): DOMDocument
    {
        // Escape bare ampersands that would otherwise break XML parsing.
        $xml = preg_replace('/&(?!amp;|lt;|gt;|apos;|quot;)/', '&amp;', $xml) ?? $xml;

        $dom = new DOMDocument();
        if (!$dom->loadXML($xml)) {
            throw new InvalidScormArchiveException('cannot_load_imsmanifest_message');
        }

        return $dom;
    }

    private function resolveScormVersion(DOMDocument $dom): string
    {
        $nodes = $dom->getElementsByTagName('schemaversion');
        if ($nodes->length === 0) {
            throw new InvalidScormArchiveException('invalid_scorm_version_message');
        }

        $version = trim($nodes->item(0)->textContent);

        if ($version === '1.2') {
            return Scorm::SCORM_12;
        }

        if (in_array($version, ['CAM 1.3', '2004 3rd Edition', '2004 4th Edition'], true)) {
            return Scorm::SCORM_2004;
        }

        throw new InvalidScormArchiveException('invalid_scorm_version_message');
    }

    /**
     * Prepend the manifest's directory to the entry URL when the manifest is
     * nested inside a sub-folder (e.g. "content/imsmanifest.xml").
     */
    private function prefixEntryUrl(string $entryUrl, string $manifestPath): string
    {
        $dir = dirname($manifestPath);

        if ($dir === '' || $dir === '.') {
            return $entryUrl;
        }

        return $dir . '/' . ltrim($entryUrl, '/');
    }

    private function extractCreationDate(DOMDocument $dom): ?string
    {
        $raw = trim($dom->getElementsByTagName('datetime')->item(0)?->textContent ?? '');
        if ($raw === '') {
            return null;
        }

        try {
            return (new DateTime($raw))->format('Y-m-d H:i:s');
        } catch (Exception) {
            return $raw;
        }
    }

    private function extractCreator(DOMDocument $dom): ?string
    {
        $value = trim($dom->getElementsByTagName('creator')->item(0)?->textContent ?? '');
        return $value !== '' ? $value : null;
    }

    private function deleteDirectory(FilesystemAdapter $disk, string $uuid, string $label): bool
    {
        try {
            return (bool) $disk->deleteDirectory($uuid);
        } catch (Exception $e) {
            \Illuminate\Support\Facades\Log::error("ScormDisk: failed to delete {$label} directory for {$uuid}: " . $e->getMessage());
            return false;
        }
    }

    private function getScormDisk(): FilesystemAdapter
    {
        return $this->resolveDisk(config('scorm.disk'), 'scorm_disk');
    }

    private function getArchiveDisk(): FilesystemAdapter
    {
        return $this->resolveDisk(config('scorm.archive'), 'scorm_archive_disk');
    }

    private function resolveDisk(?string $name, string $label): FilesystemAdapter
    {
        if (empty($name)) {
            throw new StorageNotFoundException("{$label}_not_configured");
        }

        if (!config()->has('filesystems.disks.' . $name)) {
            throw new StorageNotFoundException("{$label}_not_defined: {$name}");
        }

        return Storage::disk($name);
    }
}
