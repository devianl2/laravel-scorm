<?php

namespace Peopleaps\Scorm\Manager;

use Carbon\Carbon;
use DateInterval;
use DateTime;
use DOMDocument;
use DOMXPath;
use Exception;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\UploadedFile;
use Peopleaps\Scorm\Entity\Scorm;
use Peopleaps\Scorm\Entity\Sco;
use Peopleaps\Scorm\Entity\ScoTracking;
use Peopleaps\Scorm\Exception\InvalidScormArchiveException;
use Peopleaps\Scorm\Library\ScormLib;
use Peopleaps\Scorm\Model\ScormModel;
use Peopleaps\Scorm\Model\ScormScoModel;
use Peopleaps\Scorm\Model\ScormScoTrackingModel;
use Illuminate\Support\Str;
use ZipArchive;
use Illuminate\Support\Facades\Log;

class ScormManager
{
    private ScormLib  $scormLib;
    private ScormDisk $scormDisk;

    /**
     * The UUID for the current upload operation.
     * Scoped per-call via uploadScormArchive / uploadScormFromUri
     * so the class is safe to reuse across requests.
     */
    private string $uuid;

    // -------------------------------------------------------------------------
    // SCORM status priority for comparison logic
    // -------------------------------------------------------------------------

    private const STATUS_PRIORITY = [
        'unknown'       => 0,
        'not attempted' => 1,
        'browsed'       => 2,
        'incomplete'    => 3,
        'completed'     => 4,
        'failed'        => 5,
        'passed'        => 6,
    ];

    public function __construct()
    {
        $this->scormLib  = new ScormLib();
        $this->scormDisk = new ScormDisk();
    }

    // =========================================================================
    // Upload
    // =========================================================================

    /**
     * Upload a SCORM package that already lives on the archive S3 bucket.
     *
     * @param  string       $file  S3 object key inside the archive bucket.
     * @param  string|null  $uuid  Supply an existing UUID to replace that package.
     * @return ScormModel
     */
    public function uploadScormFromUri(string $file, ?string $uuid = null): ScormModel
    {
        if (empty($file)) {
            throw new InvalidScormArchiveException('file_parameter_empty');
        }

        $this->uuid = $uuid ?? Str::uuid()->toString();

        Log::info('ScormManager::uploadScormFromUri — processing: ' . $file);

        $scorm = null;
        $this->scormDisk->readScormArchive($file, function (string $tmpPath) use (&$scorm, $file, $uuid) {
            $scorm = $this->saveScorm($tmpPath, basename($file), $uuid);
        });

        return $scorm;
    }

    /**
     * Upload a SCORM package from a local/uploaded file.
     *
     * @param  UploadedFile  $file
     * @param  string|null   $uuid  Supply an existing UUID to replace that package.
     * @return ScormModel
     */
    public function uploadScormArchive(UploadedFile $file, ?string $uuid = null): ScormModel
    {
        $this->uuid = $uuid ?? Str::uuid()->toString();

        return $this->saveScorm($file, $file->getClientOriginalName(), $uuid);
    }

    // =========================================================================
    // SCO / Tracking queries
    // =========================================================================

    /**
     * @return \Illuminate\Database\Eloquent\Collection<ScormScoModel>
     */
    public function getScos(int $scormId)
    {
        return ScormScoModel::with('scorm')->where('scorm_id', $scormId)->get();
    }

    /**
     * @return ScormScoModel
     */
    public function getScoByUuid(string $scoUuid): ScormScoModel
    {
        return ScormScoModel::with('scorm')->where('uuid', $scoUuid)->firstOrFail();
    }

    public function getUserResult(int $scoId, int $userId): ?ScormScoTrackingModel
    {
        return ScormScoTrackingModel::where('sco_id', $scoId)
            ->where('user_id', $userId)
            ->first();
    }

    // =========================================================================
    // Tracking
    // =========================================================================

    public function createScoTracking(string $scoUuid, $userId = null, $userName = null): ScoTracking
    {
        $sco     = ScormScoModel::where('uuid', $scoUuid)->firstOrFail();
        $version = $sco->scorm->version;

        $scoTracking = new ScoTracking();
        $scoTracking->setSco($sco->toArray());

        $cmi = null;

        switch ($version) {
            case Scorm::SCORM_12:
                $scoTracking->setLessonStatus('not attempted');
                $scoTracking->setSuspendData('');
                $scoTracking->setEntry('ab-initio');
                $scoTracking->setLessonLocation('');
                $scoTracking->setCredit('no-credit');
                $scoTracking->setTotalTimeInt(0);
                $scoTracking->setSessionTime(0);
                $scoTracking->setLessonMode('normal');
                $scoTracking->setExitMode('');
                $scoTracking->setIsLocked(!is_null($sco->prerequisites));

                $cmi = [
                    'cmi.core.entry'        => $scoTracking->getEntry(),
                    'cmi.core.student_id'   => $userId,
                    'cmi.core.student_name' => $userName,
                ];
                break;

            case Scorm::SCORM_2004:
                $scoTracking->setTotalTimeString('PT0S');
                $scoTracking->setCompletionStatus('unknown');
                $scoTracking->setLessonStatus('unknown');
                $scoTracking->setIsLocked(false);

                $cmi = [
                    'cmi.entry'               => 'ab-initio',
                    'cmi.learner_id'          => $userId,
                    'cmi.learner_name'        => $userName,
                    'cmi.scaled_passing_score' => 0.5,
                ];
                break;
        }

        $scoTracking->setUserId($userId);
        $scoTracking->setDetails($cmi);

        $stored = ScormScoTrackingModel::firstOrCreate(
            ['user_id' => $userId, 'sco_id' => $sco->id],
            [
                'uuid'               => Str::uuid()->toString(),
                'progression'        => $scoTracking->getProgression(),
                'score_raw'          => $scoTracking->getScoreRaw(),
                'score_min'          => $scoTracking->getScoreMin(),
                'score_max'          => $scoTracking->getScoreMax(),
                'score_scaled'       => $scoTracking->getScoreScaled(),
                'lesson_status'      => $scoTracking->getLessonStatus(),
                'completion_status'  => $scoTracking->getCompletionStatus(),
                'session_time'       => $scoTracking->getSessionTime(),
                'total_time_int'     => $scoTracking->getTotalTimeInt(),
                'total_time_string'  => $scoTracking->getTotalTimeString(),
                'entry'              => $scoTracking->getEntry(),
                'suspend_data'       => $scoTracking->getSuspendData(),
                'credit'             => $scoTracking->getCredit(),
                'exit_mode'          => $scoTracking->getExitMode(),
                'lesson_location'    => $scoTracking->getLessonLocation(),
                'lesson_mode'        => $scoTracking->getLessonMode(),
                'is_locked'          => $scoTracking->getIsLocked(),
                'details'            => $scoTracking->getDetails(),
                'latest_date'        => $scoTracking->getLatestDate(),
                'created_at'         => Carbon::now(),
                'updated_at'         => Carbon::now(),
            ]
        );

        $this->hydrateTrackingFromModel($scoTracking, $stored);

        return $scoTracking;
    }

    public function findScoTrackingId(string $scoUuid, string $scoTrackingUuid): ScormScoTrackingModel
    {
        return ScormScoTrackingModel::with('sco')
            ->whereHas('sco', fn(Builder $q) => $q->where('uuid', $scoUuid))
            ->where('uuid', $scoTrackingUuid)
            ->firstOrFail();
    }

    public function checkUserIsCompletedScorm(int $scormId, int $userId): bool
    {
        $scos = ScormScoModel::where('scorm_id', $scormId)->get();

        $completedCount = $scos->filter(function ($sco) use ($userId) {
            $tracking = ScormScoTrackingModel::where('sco_id', $sco->id)
                ->where('user_id', $userId)
                ->first();

            return $tracking && in_array($tracking->lesson_status, ['passed', 'completed'], true);
        })->count();

        return $completedCount === $scos->count();
    }

    public function updateScoTracking(string $scoUuid, int $userId, array $data): ScormScoTrackingModel
    {
        $tracking = $this->createScoTracking($scoUuid, $userId);
        $tracking->setLatestDate(Carbon::now());

        $sco   = $tracking->getSco();
        $scorm = ScormModel::where('id', $sco['scorm_id'])->firstOrFail();

        match ($scorm->version) {
            Scorm::SCORM_12   => $this->applyScorm12Data($tracking, $data),
            Scorm::SCORM_2004 => $this->applyScorm2004Data($tracking, $data),
        };

        return ScormScoTrackingModel::where('user_id', $tracking->getUserId())
            ->where('sco_id', $sco['id'])
            ->firstOrFail()
            ->tap(function (ScormScoTrackingModel $model) use ($tracking) {
                $model->progression       = $tracking->getProgression();
                $model->score_raw         = $tracking->getScoreRaw();
                $model->score_min         = $tracking->getScoreMin();
                $model->score_max         = $tracking->getScoreMax();
                $model->score_scaled      = $tracking->getScoreScaled();
                $model->lesson_status     = $tracking->getLessonStatus();
                $model->completion_status = $tracking->getCompletionStatus();
                $model->session_time      = $tracking->getSessionTime();
                $model->total_time_int    = $tracking->getTotalTimeInt();
                $model->total_time_string = $tracking->getTotalTimeString();
                $model->entry             = $tracking->getEntry();
                $model->suspend_data      = $tracking->getSuspendData();
                $model->exit_mode         = $tracking->getExitMode();
                $model->credit            = $tracking->getCredit();
                $model->lesson_location   = $tracking->getLessonLocation();
                $model->lesson_mode       = $tracking->getLessonMode();
                $model->is_locked         = $tracking->getIsLocked();
                $model->details           = $tracking->getDetails();
                $model->latest_date       = $tracking->getLatestDate();
                $model->save();
            });
    }

    public function resetUserData(int $scormId, int $userId): void
    {
        ScormScoModel::where('scorm_id', $scormId)
            ->get()
            ->each(function (ScormScoModel $sco) use ($userId) {
                ScormScoTrackingModel::where('sco_id', $sco->id)
                    ->where('user_id', $userId)
                    ->delete();
            });
    }

    // =========================================================================
    // Delete
    // =========================================================================

    public function deleteScorm(ScormModel $model): void
    {
        if (!$model) {
            return;
        }

        $this->deleteScormData($model);
        $model->delete();
    }

    // =========================================================================
    // Private — persistence
    // =========================================================================

    /**
     * Orchestrates validation → parsing → extraction → DB persistence.
     *
     * @param  string|UploadedFile  $file
     */
    private function saveScorm($file, string $filename, ?string $uuid = null): ScormModel
    {
        $this->validatePackage($file);

        $scormData = $this->generateScorm($file);

        if (empty($scormData) || !is_array($scormData)) {
            $this->onError('invalid_scorm_data');
        }

        // An explicit UUID overrides the one generated at upload-entry.
        if (!empty($uuid)) {
            $this->uuid = $uuid;
        }

        $scorm = ScormModel::where('uuid', $this->uuid)->first();

        if ($scorm === null) {
            $scorm = new ScormModel();
        } else {
            $this->deleteScormData($scorm);
        }

        $scorm->uuid         = $this->uuid;
        $scorm->title        = $scormData['title'];
        $scorm->version      = $scormData['version'];
        $scorm->entry_url    = $scormData['entryUrl'];
        $scorm->identifier   = $scormData['identifier'];
        $scorm->origin_file  = $filename;
        $scorm->metadata     = [
            'package_size' => $this->getFileSize($file),
            'created_at'   => $scormData['created_at'] ?? null,
            'created_by'   => $scormData['created_by'] ?? null,
        ];
        $scorm->save();

        if (!empty($scormData['scos'])) {
            Log::info('ScormManager::saveScorm — saving ' . count($scormData['scos']) . ' SCO(s)');
            foreach ($scormData['scos'] as $scoData) {
                $this->saveScormScosRecursively($scorm->id, $scoData);
            }
        } else {
            Log::warning('ScormManager::saveScorm — no SCOs found in parsed data');
        }

        return $scorm;
    }

    private function saveScormScosRecursively(int $scormId, Sco $scoData, ?int $parentId = null): ScormScoModel
    {
        $sco = $this->persistSco($scormId, $scoData, $parentId);

        if (!empty($scoData->scoChildren) && is_array($scoData->scoChildren)) {
            foreach ($scoData->scoChildren as $child) {
                $this->saveScormScosRecursively($scormId, $child, $sco->id);
            }
        }

        return $sco;
    }

    private function persistSco(int $scormId, Sco $scoData, ?int $parentId = null): ScormScoModel
    {
        $sco = new ScormScoModel([
            'scorm_id'            => $scormId,
            'uuid'                => $scoData->uuid,
            'sco_parent_id'       => $parentId,
            'entry_url'           => $scoData->entryUrl,
            'identifier'          => $scoData->identifier,
            'title'               => $scoData->title,
            'visible'             => $scoData->visible,
            'sco_parameters'      => $scoData->parameters,
            'launch_data'         => $scoData->launchData,
            'max_time_allowed'    => $scoData->maxTimeAllowed,
            'time_limit_action'   => $scoData->timeLimitAction,
            'block'               => $scoData->block,
            'score_int'           => $scoData->scoreToPassInt,
            'score_decimal'       => $scoData->scoreToPassDecimal,
            'completion_threshold' => $scoData->completionThreshold,
            'prerequisites'       => $scoData->prerequisites,
        ]);

        $sco->save();

        Log::info('ScormManager::persistSco — saved SCO: ' . $sco->identifier);

        return $sco;
    }

    private function deleteScormData(ScormModel $model): void
    {
        $model->scos()->each(function (ScormScoModel $sco) {
            $sco->scoTrackings()->delete();
        });

        $model->scos()->delete();
        $this->scormDisk->deleteScorm($model->uuid);
    }

    // =========================================================================
    // Private — SCORM package parsing
    // =========================================================================

    /**
     * Open the zip and confirm imsmanifest.xml is present.
     *
     * @param  string|UploadedFile  $file
     */
    private function validatePackage($file): void
    {
        $zip            = new ZipArchive();
        $manifestResult = null;

        try {
            if ($zip->open($this->resolveFilePath($file)) !== true) {
                $this->onError('invalid_scorm_archive_message');
            }
            $manifestResult = $this->findManifestInZip($zip);
        } finally {
            $this->closeManifestStream($manifestResult);
            $zip->close();
        }

        if (!$manifestResult['found']) {
            Log::error('ScormManager::validatePackage — imsmanifest.xml not found');
            $this->onError('invalid_scorm_archive_message');
        }

        Log::info('ScormManager::validatePackage — manifest at: ' . $manifestResult['path']);
    }

    /**
     * Parse and extract SCORM archive content.
     *
     * @param  string|UploadedFile  $file
     */
    private function generateScorm($file): array
    {
        $scormData = $this->parseScormArchive($file);

        $this->scormDisk->unzipper($this->resolveFilePath($file), $this->uuid);

        // If the manifest lived inside a subdirectory, prepend that prefix to
        // all entry URLs so the browser can resolve assets correctly.
        if (isset($scormData['manifestPath']) && str_contains($scormData['manifestPath'], '/')) {
            $prefix = dirname($scormData['manifestPath']);
            $original = $scormData['entryUrl'];
            $scormData['entryUrl'] = $prefix . '/' . ltrim($scormData['entryUrl'], '/');
            Log::info("ScormManager::generateScorm — entry URL adjusted: {$original} → {$scormData['entryUrl']}");
        }

        return [
            'identifier' => $scormData['identifier'],
            'uuid'       => $this->uuid,
            'title'      => $scormData['title'],
            'version'    => $scormData['version'],
            'entryUrl'   => $scormData['entryUrl'],
            'scos'       => $scormData['scos'],
            'created_at' => $scormData['created_at'] ?? null,
            'created_by' => $scormData['created_by'] ?? null,
        ];
    }

    /**
     * Read imsmanifest.xml from the zip and parse all relevant SCORM data.
     *
     * @param  string|UploadedFile  $file
     */
    private function parseScormArchive($file): array
    {
        $zip            = new ZipArchive();
        $manifestResult = null;
        $contents       = '';

        try {
            if ($zip->open($this->resolveFilePath($file)) !== true) {
                $this->onError('cannot_load_imsmanifest_message');
            }

            $manifestResult = $this->findManifestInZip($zip);

            if (!$manifestResult['found']) {
                $this->onError('cannot_load_imsmanifest_message');
            }

            // Read the manifest stream in 8 KB chunks.
            while (!feof($manifestResult['stream'])) {
                $contents .= fread($manifestResult['stream'], 8192);
            }
        } finally {
            $this->closeManifestStream($manifestResult);
            $zip->close();
        }

        $dom = new DOMDocument();
        $contents = $this->fixXmlEntities($contents);

        if (!$dom->loadXML($contents)) {
            $this->onError('cannot_load_imsmanifest_message');
        }

        return $this->extractManifestData($dom, $manifestResult['path']);
    }

    /**
     * Pull all required fields from the parsed DOMDocument.
     */
    private function extractManifestData(DOMDocument $dom, string $manifestPath): array
    {
        $data       = [];
        $manifest   = $dom->getElementsByTagName('manifest')->item(0);
        $identifier = $manifest?->attributes->getNamedItem('identifier');

        if ($identifier === null) {
            $this->onError('invalid_scorm_manifest_identifier');
        }

        $data['identifier'] = $identifier->nodeValue;

        $titles = $dom->getElementsByTagName('title');
        $data['title'] = $titles->length > 0
            ? Str::of($titles->item(0)->textContent)->trim('/n')->trim()->toString()
            : '';

        $schemaVersion = $dom->getElementsByTagName('schemaversion');

        if ($schemaVersion->length === 0) {
            $this->onError('invalid_scorm_version_message');
        }

        $data['version'] = match ($schemaVersion->item(0)->textContent) {
            '1.2'             => Scorm::SCORM_12,
            'CAM 1.3',
            '2004 3rd Edition',
            '2004 4th Edition' => Scorm::SCORM_2004,
            default           => $this->onError('invalid_scorm_version_message'),
        };

        $scos = $this->scormLib->parseOrganizationsNode($dom);

        if (empty($scos)) {
            Log::error('ScormManager::extractManifestData — no SCOs found');
            $this->onError('no_sco_in_scorm_archive_message');
        }

        $data['entryUrl']     = $scos[0]->entryUrl ?? $scos[0]->scoChildren[0]->entryUrl;
        $data['scos']         = $scos;
        $data['manifestPath'] = $manifestPath;
        $data['created_at']   = $this->extractCreationDate($dom);
        $data['created_by']   = $this->extractCreator($dom);

        Log::info('ScormManager::extractManifestData — ' . count($scos) . ' SCO(s), entryUrl: ' . $data['entryUrl']);

        return $data;
    }

    // =========================================================================
    // Private — tracking data application
    // =========================================================================

    private function applyScorm12Data(ScoTracking $tracking, array $data): void
    {
        $tracking->setDetails($data);

        if (!empty($data['cmi.suspend_data'])) {
            $tracking->setSuspendData($data['cmi.suspend_data']);
        }

        $scoreRaw    = isset($data['cmi.core.score.raw'])  ? (int) $data['cmi.core.score.raw']  : null;
        $scoreMin    = isset($data['cmi.core.score.min'])  ? (int) $data['cmi.core.score.min']  : null;
        $scoreMax    = isset($data['cmi.core.score.max'])  ? (int) $data['cmi.core.score.max']  : null;
        $lessonStatus  = $data['cmi.core.lesson_status']  ?? 'unknown';
        $sessionTime   = $data['cmi.core.session_time']   ?? null;
        $entry         = $data['cmi.core.entry']          ?? null;
        $exit          = $data['cmi.core.exit']           ?? null;
        $lessonLocation = $data['cmi.core.lesson_location'] ?? null;
        $totalTime     = $data['cmi.core.total_time']     ?? 0;

        $tracking->setEntry($entry);
        $tracking->setExitMode($exit);
        $tracking->setLessonLocation($lessonLocation);
        $tracking->setSessionTime($this->convertTimeInHundredth($sessionTime));
        $tracking->setTotalTime($this->convertTimeInHundredth($totalTime), Scorm::SCORM_12);
        $tracking->setLessonStatus($lessonStatus);

        // Keep best score
        $bestScore = $tracking->getScoreRaw();
        if (empty($bestScore) || (!is_null($scoreRaw) && $scoreRaw > (int) $bestScore)) {
            $tracking->setScoreRaw($scoreRaw);
            $tracking->setScoreMin($scoreMin);
            $tracking->setScoreMax($scoreMax);
        }

        $progression = !empty($scoreRaw) ? (float) $scoreRaw : 0.0;
        if ($progression === 0.0 && in_array($lessonStatus, ['completed', 'passed'], true)) {
            $progression = 100.0;
        }
        if ($progression > $tracking->getProgression()) {
            $tracking->setProgression($progression);
        }
    }

    private function applyScorm2004Data(ScoTracking $tracking, array $data): void
    {
        $tracking->setDetails($data);

        if (!empty($data['cmi.suspend_data'])) {
            $tracking->setSuspendData($data['cmi.suspend_data']);
        }

        $completionStatus = $data['cmi.completion_status'] ?? 'unknown';
        $successStatus    = $data['cmi.success_status']    ?? 'unknown';
        $scoreRaw         = isset($data['cmi.score.raw'])    ? (int)   $data['cmi.score.raw']    : null;
        $scoreMin         = isset($data['cmi.score.min'])    ? (int)   $data['cmi.score.min']    : null;
        $scoreMax         = isset($data['cmi.score.max'])    ? (int)   $data['cmi.score.max']    : null;
        $scoreScaled      = isset($data['cmi.score.scaled']) ? (float) $data['cmi.score.scaled'] : null;
        $progression      = isset($data['cmi.progress_measure']) ? (float) $data['cmi.progress_measure'] : 0.0;

        // Compute cumulative total time
        $sessionTimeStr = isset($data['cmi.session_time'])
            ? $this->formatSessionTime($data['cmi.session_time'])
            : 'PT0S';

        try {
            $totalTime   = new DateInterval($tracking->getTotalTimeString() ?: 'PT0S');
            $sessionTime = new DateInterval($sessionTimeStr);
        } catch (Exception) {
            $totalTime   = new DateInterval('PT0S');
            $sessionTime = new DateInterval('PT0S');
        }

        $base = new DateTime('@0');
        $base->add($totalTime)->add($sessionTime);
        $totalTimeInterval = $this->retrieveIntervalFromSeconds($base->getTimestamp());

        $data['cmi.total_time'] = $totalTimeInterval;
        $tracking->setTotalTimeString($totalTimeInterval);

        // Keep best score
        $bestScore = $tracking->getScoreRaw();
        if (empty($bestScore) || (!is_null($scoreRaw) && $scoreRaw > (int) $bestScore)) {
            $tracking->setScoreRaw($scoreRaw);
            $tracking->setScoreMin($scoreMin);
            $tracking->setScoreMax($scoreMax);
            $tracking->setScoreScaled($scoreScaled);
        }

        // Merge completion + success into a single lesson status
        $lessonStatus = in_array($successStatus, ['passed', 'failed'], true)
            ? $successStatus
            : $completionStatus;

        $tracking->setLessonStatus($lessonStatus);

        $currentPriority  = self::STATUS_PRIORITY[$completionStatus]            ?? 0;
        $existingPriority = self::STATUS_PRIORITY[$tracking->getCompletionStatus()] ?? 0;

        if (empty($tracking->getCompletionStatus()) || $currentPriority > $existingPriority) {
            $tracking->setCompletionStatus($completionStatus);
        }

        if ($progression === 0.0 && in_array($lessonStatus, ['completed', 'passed'], true)) {
            $progression = 100.0;
        }
        if ($progression > $tracking->getProgression()) {
            $tracking->setProgression($progression);
        }
    }

    // =========================================================================
    // Private — ZIP helpers
    // =========================================================================

    /**
     * Find imsmanifest.xml inside a ZipArchive, checking the root first,
     * then subdirectories.
     *
     * @return array{found: bool, path: string, stream: resource|null}
     */
    private function findManifestInZip(ZipArchive $zip): array
    {
        // Root check
        $stream = $zip->getStream('imsmanifest.xml');
        if ($stream !== false) {
            Log::info('ScormManager::findManifestInZip — found at root');
            return ['found' => true, 'path' => 'imsmanifest.xml', 'stream' => $stream];
        }

        // Subdirectory search
        for ($i = 0; $i < $zip->numFiles; ++$i) {
            $name = $zip->getNameIndex($i);
            if (strtolower(basename($name)) === 'imsmanifest.xml') {
                $stream = $zip->getStream($name);
                if ($stream !== false) {
                    Log::info('ScormManager::findManifestInZip — found at: ' . $name);
                    return ['found' => true, 'path' => $name, 'stream' => $stream];
                }
            }
        }

        return ['found' => false, 'path' => '', 'stream' => null];
    }

    /** Close the manifest stream if it is still open. */
    private function closeManifestStream(?array $manifestResult): void
    {
        if (
            $manifestResult !== null
            && isset($manifestResult['stream'])
            && is_resource($manifestResult['stream'])
        ) {
            fclose($manifestResult['stream']);
        }
    }

    // =========================================================================
    // Private — manifest metadata extraction
    // =========================================================================

    /**
     * Extract creation date from SCORM manifest LOM metadata.
     * Returns an ISO-8601 string or null.
     */
    private function extractCreationDate(DOMDocument $dom): ?string
    {
        try {
            $xpath = $this->buildXPath($dom);

            foreach (
                [
                    '//lom:dateTime[ancestor::lom:contribute[lom:role/lom:value[text()="creator" or text()="author"]]]',
                    '//lom:dateTime[ancestor::lom:contribute]',
                    '//lom:dateTime',
                ] as $query
            ) {
                $nodes = $this->safeXPathQuery($xpath, $query);
                if ($nodes && $nodes->length > 0) {
                    return $this->formatDate($nodes->item(0)->textContent);
                }
            }
        } catch (Exception $e) {
            Log::warning('ScormManager::extractCreationDate — ' . $e->getMessage());
        }

        return null;
    }

    /**
     * Extract the creator/author from SCORM manifest LOM metadata.
     */
    private function extractCreator(DOMDocument $dom): ?string
    {
        try {
            $xpath = $this->buildXPath($dom);

            foreach (
                [
                    '//lom:entity[ancestor::lom:contribute[lom:role/lom:value[text()="creator"]]]',
                    '//lom:entity[ancestor::lom:contribute[lom:role/lom:value[text()="author"]]]',
                    '//lom:entity[ancestor::lom:contribute[lom:role/lom:value[text()="publisher"]]]',
                    '//lom:entity[ancestor::lom:contribute]',
                    '//lom:entity',
                ] as $query
            ) {
                $nodes = $this->safeXPathQuery($xpath, $query);
                if ($nodes && $nodes->length > 0) {
                    return trim($nodes->item(0)->textContent);
                }
            }
        } catch (Exception $e) {
            Log::warning('ScormManager::extractCreator — ' . $e->getMessage());
        }

        return null;
    }

    private function buildXPath(DOMDocument $dom): DOMXPath
    {
        $xpath = new DOMXPath($dom);
        $xpath->registerNamespace('lom',    'http://ltsc.ieee.org/xsd/LOM');
        $xpath->registerNamespace('adlcp',  'http://www.adlnet.org/xsd/adlcp_v1p3');
        $xpath->registerNamespace('adlseq', 'http://www.adlnet.org/xsd/adlseq_v1p3');
        $xpath->registerNamespace('adlnav', 'http://www.adlnet.org/xsd/adlnav_v1p3');
        return $xpath;
    }

    private function safeXPathQuery(DOMXPath $xpath, string $query): \DOMNodeList|false
    {
        try {
            return $xpath->query($query);
        } catch (Exception $e) {
            Log::warning('ScormManager::safeXPathQuery — "' . $query . '": ' . $e->getMessage());
            return false;
        }
    }

    private function formatDate(string $dateString): ?string
    {
        if (empty($dateString)) {
            return null;
        }
        try {
            return (new DateTime($dateString))->format('c');
        } catch (Exception) {
            return trim($dateString);
        }
    }

    // =========================================================================
    // Private — XML / time utilities
    // =========================================================================

    /**
     * Escape unescaped ampersands in XML while preserving CDATA sections.
     */
    private function fixXmlEntities(string $xml): string
    {
        $cdata = [];

        $xml = preg_replace_callback(
            '/<!\[CDATA\[(.*?)\]\]>/s',
            function (array $m) use (&$cdata): string {
                $key         = '__CDATA_' . count($cdata) . '__';
                $cdata[$key] = $m[0];
                return $key;
            },
            $xml
        );

        // Escape bare & that are not already part of a valid entity reference.
        $xml = preg_replace('/&(?!(?:[a-zA-Z][a-zA-Z0-9]*|#\d+|#[xX][0-9a-fA-F]+);)/', '&amp;', $xml);

        return str_replace(array_keys($cdata), $cdata, $xml);
    }

    /**
     * Convert HH:MM:SS.ss time string into hundredths of a second.
     */
    private function convertTimeInHundredth(?string $time): int
    {
        if (empty($time)) {
            return 0;
        }

        [$h, $m, $secFull] = explode(':', $time);
        [$sec, $frac]      = array_pad(explode('.', $secFull), 2, '0');

        // Normalise fraction to two digits
        $frac = str_pad(substr($frac, 0, 2), 2, '0');

        return (int) $frac
            + (int) $sec   * 100
            + (int) $m     * 6_000
            + (int) $h     * 360_000;
    }

    /** Convert a total number of seconds into a P…DT…H…M…S DateInterval string. */
    private function retrieveIntervalFromSeconds(int $seconds): string
    {
        if ($seconds === 0) {
            return 'PT0S';
        }

        $d = intdiv($seconds, 86_400);
        $seconds %= 86_400;
        $h = intdiv($seconds,  3_600);
        $seconds %=  3_600;
        $m = intdiv($seconds,     60);
        $s = $seconds % 60;

        return "P{$d}DT{$h}H{$m}M{$s}S";
    }

    /**
     * Validate and normalise a SCORM 2004 session-time ISO-8601 string.
     */
    private function formatSessionTime(string $sessionTime): string
    {
        if ($sessionTime === 'PT') {
            return 'PT0S';
        }

        $general = '/^P([0-9]+Y)?([0-9]+M)?([0-9]+D)?T([0-9]+H)?([0-9]+M)?([0-9]+S)?$/';
        $decimal = '/^P([0-9]+Y)?([0-9]+M)?([0-9]+D)?T([0-9]+H)?([0-9]+M)?[0-9]+\.[0-9]{1,2}S$/';

        if (preg_match($general, $sessionTime)) {
            return $sessionTime;
        }

        if (preg_match($decimal, $sessionTime)) {
            return (string) preg_replace('/\.[0-9]+S$/', 'S', $sessionTime);
        }

        return 'PT0S';
    }

    // =========================================================================
    // Private — misc helpers
    // =========================================================================

    /**
     * Return the real filesystem path whether $file is an UploadedFile or a
     * plain string path (the OS tmp path from ScormDisk::readScormArchive).
     *
     * @param  string|UploadedFile  $file
     */
    private function resolveFilePath($file): string
    {
        return $file instanceof UploadedFile ? $file->getRealPath() : $file;
    }

    /**
     * Copy all persisted fields from a ScormScoTrackingModel back into the
     * ScoTracking entity so callers always work with a fully hydrated object.
     */
    private function hydrateTrackingFromModel(ScoTracking $tracking, ScormScoTrackingModel $model): void
    {
        $tracking->setUuid($model->uuid);
        $tracking->setProgression($model->progression);
        $tracking->setScoreRaw($model->score_raw);
        $tracking->setScoreMin($model->score_min);
        $tracking->setScoreMax($model->score_max);
        $tracking->setScoreScaled($model->score_scaled);
        $tracking->setLessonStatus($model->lesson_status);
        $tracking->setCompletionStatus($model->completion_status);
        $tracking->setSessionTime($model->session_time);
        $tracking->setTotalTimeInt($model->total_time_int);
        $tracking->setTotalTimeString($model->total_time_string);
        $tracking->setEntry($model->entry);
        $tracking->setSuspendData($model->suspend_data);
        $tracking->setCredit($model->credit);
        $tracking->setExitMode($model->exit_mode);
        $tracking->setLessonLocation($model->lesson_location);
        $tracking->setLessonMode($model->lesson_mode);
        $tracking->setIsLocked($model->is_locked);
        $tracking->setDetails($model->details);
        $tracking->setLatestDate(Carbon::parse($model->latest_date));
    }

    /**
     * Get file size in bytes.
     *
     * @param  string|UploadedFile  $file
     */
    private function getFileSize($file): int
    {
        if ($file instanceof UploadedFile) {
            return $file->getSize();
        }

        return is_string($file) && file_exists($file) ? (int) filesize($file) : 0;
    }

    /**
     * Clean up any partially-written files and throw.
     *
     * @return never
     */
    private function onError(string $msg): never
    {
        $this->scormDisk->deleteScorm($this->uuid);
        throw new InvalidScormArchiveException($msg);
    }
}
