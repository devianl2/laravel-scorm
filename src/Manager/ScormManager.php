<?php

namespace Peopleaps\Scorm\Manager;

use Carbon\Carbon;
use DateInterval;
use DateTime;
use Exception;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Peopleaps\Scorm\Contract\UnzipperInterface;
use Peopleaps\Scorm\Entity\Scorm;
use Peopleaps\Scorm\Entity\Sco;
use Peopleaps\Scorm\Entity\ScoTracking;
use Peopleaps\Scorm\Exception\InvalidScormArchiveException;
use Peopleaps\Scorm\Model\ScormModel;
use Peopleaps\Scorm\Model\ScormScoModel;
use Peopleaps\Scorm\Model\ScormScoTrackingModel;

class ScormManager
{
    private readonly ScormDisk $scormDisk;

    public function __construct(?UnzipperInterface $unzipper = null)
    {
        $this->scormDisk = new ScormDisk(
            $unzipper ?? new LocalUnzipper(
                Storage::disk(config('scorm.archive')),
                Storage::disk(config('scorm.disk')),
            )
        );
    }

    // -------------------------------------------------------------------------
    // Upload / ingest
    // -------------------------------------------------------------------------

    /**
     * Ingest a SCORM package already present on the archive disk (e.g. uploaded
     * directly to S3). Pass the object key as $archiveKey.
     *
     * @throws InvalidScormArchiveException
     */
    public function uploadScormFromUri(string $archiveKey, ?string $uuid = null): ScormModel
    {
        $uuid = $uuid ?? Str::uuid()->toString();

        if (!$this->scormDisk->contentExists($uuid)) {
            $this->scormDisk->extractFromArchive($archiveKey, $uuid);
        }

        return $this->persistScorm(
            uuid: $uuid,
            filename: basename($archiveKey),
            packageSize: 0,
        );
    }

    /**
     * Accept a freshly-uploaded zip file, store it on the archive disk, extract
     * it and persist the resulting SCORM record.
     *
     * @throws InvalidScormArchiveException
     */
    public function uploadScormArchive(UploadedFile $file, ?string $uuid = null): ScormModel
    {
        $uuid       = $uuid ?? Str::uuid()->toString();
        $archiveKey = $uuid . '/' . $file->getClientOriginalName();

        $this->scormDisk->putArchiveFile($file, $archiveKey);
        $this->scormDisk->extractFromArchive($archiveKey, $uuid);

        return $this->persistScorm(
            uuid: $uuid,
            filename: $file->getClientOriginalName(),
            packageSize: $file->getSize(),
        );
    }

    // -------------------------------------------------------------------------
    // Delete
    // -------------------------------------------------------------------------

    public function deleteScorm(ScormModel $model): void
    {
        $this->deleteScormData($model);
        $model->delete();
    }

    // -------------------------------------------------------------------------
    // Query helpers
    // -------------------------------------------------------------------------

    public function getScos(int $scormId)
    {
        return ScormScoModel::with('scorm')->where('scorm_id', $scormId)->get();
    }

    public function getScoByUuid(string $scoUuid): ScormScoModel
    {
        return ScormScoModel::with('scorm')->where('uuid', $scoUuid)->firstOrFail();
    }

    public function getUserResult(int $scoId, int $userId): ?ScormScoTrackingModel
    {
        return ScormScoTrackingModel::where('sco_id', $scoId)->where('user_id', $userId)->first();
    }

    // -------------------------------------------------------------------------
    // Tracking
    // -------------------------------------------------------------------------

    public function createScoTracking(string $scoUuid, mixed $userId = null, ?string $userName = null): ScoTracking
    {
        $sco     = ScormScoModel::where('uuid', $scoUuid)->firstOrFail();
        $version = $sco->scorm->version;

        $tracking = new ScoTracking();
        $tracking->setSco($sco->toArray());

        $cmi = match ($version) {
            Scorm::SCORM_12 => $this->initScorm12Tracking($tracking, $sco, $userId, $userName),
            Scorm::SCORM_2004 => $this->initScorm2004Tracking($tracking, $userId, $userName),
            default => [],
        };

        $tracking->setUserId($userId);
        $tracking->setDetails($cmi);

        $stored = ScormScoTrackingModel::firstOrCreate(
            ['user_id' => $userId, 'sco_id' => $sco->id],
            [
                'uuid'               => Str::uuid()->toString(),
                'progression'        => $tracking->getProgression(),
                'score_raw'          => $tracking->getScoreRaw(),
                'score_min'          => $tracking->getScoreMin(),
                'score_max'          => $tracking->getScoreMax(),
                'score_scaled'       => $tracking->getScoreScaled(),
                'lesson_status'      => $tracking->getLessonStatus(),
                'completion_status'  => $tracking->getCompletionStatus(),
                'session_time'       => $tracking->getSessionTime(),
                'total_time_int'     => $tracking->getTotalTimeInt(),
                'total_time_string'  => $tracking->getTotalTimeString(),
                'entry'              => $tracking->getEntry(),
                'suspend_data'       => $tracking->getSuspendData(),
                'credit'             => $tracking->getCredit(),
                'exit_mode'          => $tracking->getExitMode(),
                'lesson_location'    => $tracking->getLessonLocation(),
                'lesson_mode'        => $tracking->getLessonMode(),
                'is_locked'          => $tracking->getIsLocked(),
                'details'            => $tracking->getDetails(),
                'latest_date'        => $tracking->getLatestDate(),
                'created_at'         => Carbon::now(),
                'updated_at'         => Carbon::now(),
            ],
        );

        $this->hydrateTrackingFromModel($tracking, $stored);

        return $tracking;
    }

    public function updateScoTracking(string $scoUuid, mixed $userId, array $data): ScormScoTrackingModel
    {
        $tracking = $this->createScoTracking($scoUuid, $userId);
        $tracking->setLatestDate(Carbon::now());

        $sco   = $tracking->getSco();
        $scorm = ScormModel::where('id', $sco['scorm_id'])->firstOrFail();

        match ($scorm->version) {
            Scorm::SCORM_12   => $this->applyScorm12Update($tracking, $data),
            Scorm::SCORM_2004 => $this->applyScorm2004Update($tracking, $data),
        };

        $row = ScormScoTrackingModel::where('user_id', $tracking->getUserId())
            ->where('sco_id', $sco['id'])
            ->firstOrFail();

        $row->fill([
            'progression'       => $tracking->getProgression(),
            'score_raw'         => $tracking->getScoreRaw(),
            'score_min'         => $tracking->getScoreMin(),
            'score_max'         => $tracking->getScoreMax(),
            'score_scaled'      => $tracking->getScoreScaled(),
            'lesson_status'     => $tracking->getLessonStatus(),
            'completion_status' => $tracking->getCompletionStatus(),
            'session_time'      => $tracking->getSessionTime(),
            'total_time_int'    => $tracking->getTotalTimeInt(),
            'total_time_string' => $tracking->getTotalTimeString(),
            'entry'             => $tracking->getEntry(),
            'suspend_data'      => $tracking->getSuspendData(),
            'exit_mode'         => $tracking->getExitMode(),
            'credit'            => $tracking->getCredit(),
            'lesson_location'   => $tracking->getLessonLocation(),
            'lesson_mode'       => $tracking->getLessonMode(),
            'is_locked'         => $tracking->getIsLocked(),
            'details'           => $tracking->getDetails(),
            'latest_date'       => $tracking->getLatestDate(),
        ])->save();

        return $row;
    }

    public function findScoTrackingId(string $scoUuid, string $trackingUuid): ScormScoTrackingModel
    {
        return ScormScoTrackingModel::with('sco')
            ->whereHas('sco', fn(Builder $q) => $q->where('uuid', $scoUuid))
            ->where('uuid', $trackingUuid)
            ->firstOrFail();
    }

    public function checkUserIsCompletedScorm(int $scormId, mixed $userId): bool
    {
        $scos = ScormScoModel::where('scorm_id', $scormId)->get();

        $completed = $scos->filter(function (ScormScoModel $sco) use ($userId) {
            $tracking = ScormScoTrackingModel::where('sco_id', $sco->id)
                ->where('user_id', $userId)
                ->first();
            return $tracking && in_array($tracking->lesson_status, ['passed', 'completed'], true);
        });

        return $completed->count() === $scos->count();
    }

    public function resetUserData(int $scormId, mixed $userId): void
    {
        ScormScoModel::where('scorm_id', $scormId)
            ->get()
            ->each(function (ScormScoModel $sco) use ($userId) {
                ScormScoTrackingModel::where('sco_id', $sco->id)->where('user_id', $userId)->delete();
            });
    }

    // -------------------------------------------------------------------------
    // Private — persistence
    // -------------------------------------------------------------------------

    private function persistScorm(string $uuid, string $filename, int $packageSize): ScormModel
    {
        $scormData = $this->scormDisk->loadMetadata($uuid);

        if (empty($scormData['identifier']) || empty($scormData['scos'])) {
            $this->rollbackAndFail($uuid, 'invalid_scorm_data');
        }

        $scorm = ScormModel::where('uuid', $uuid)->first();

        if ($scorm) {
            $this->deleteScormData($scorm);
        } else {
            $scorm = new ScormModel();
        }

        $scorm->fill([
            'uuid'       => $uuid,
            'title'      => $scormData['title'],
            'version'    => $scormData['version'],
            'entry_url'  => $scormData['entryUrl'],
            'identifier' => $scormData['identifier'],
            'origin_file' => $filename,
            'metadata'   => [
                'package_size' => $packageSize,
                'created_at'   => $scormData['created_at'] ?? null,
                'created_by'   => $scormData['created_by'] ?? null,
            ],
        ])->save();

        foreach ($scormData['scos'] as $scoData) {
            $this->saveScoRecursive($scorm->id, $scoData);
        }

        return $scorm;
    }

    private function saveScoRecursive(int $scormId, Sco $scoData, ?int $parentId = null): ScormScoModel
    {
        $sco = $this->saveSco($scormId, $scoData, $parentId);

        foreach ($scoData->scoChildren ?? [] as $child) {
            $this->saveScoRecursive($scormId, $child, $sco->id);
        }

        return $sco;
    }

    private function saveSco(int $scormId, Sco $scoData, ?int $parentId): ScormScoModel
    {
        $sco = new ScormScoModel();
        $sco->fill([
            'scorm_id'           => $scormId,
            'uuid'               => $scoData->uuid,
            'sco_parent_id'      => $parentId,
            'entry_url'          => $scoData->entryUrl,
            'identifier'         => $scoData->identifier,
            'title'              => $scoData->title,
            'visible'            => $scoData->visible,
            'sco_parameters'     => $scoData->parameters,
            'launch_data'        => $scoData->launchData,
            'max_time_allowed'   => $scoData->maxTimeAllowed,
            'time_limit_action'  => $scoData->timeLimitAction,
            'block'              => $scoData->block,
            'score_int'          => $scoData->scoreToPassInt,
            'score_decimal'      => $scoData->scoreToPassDecimal,
            'completion_threshold' => $scoData->completionThreshold,
            'prerequisites'      => $scoData->prerequisites,
        ])->save();

        return $sco;
    }

    private function deleteScormData(ScormModel $model): void
    {
        foreach ($model->scos()->get() as $sco) {
            $sco->scoTrackings()->delete();
        }
        $model->scos()->delete();
        $this->scormDisk->deleteScorm($model->uuid);
    }

    private function rollbackAndFail(string $uuid, string $message): never
    {
        $this->scormDisk->deleteScorm($uuid);
        throw new InvalidScormArchiveException($message);
    }

    // -------------------------------------------------------------------------
    // Private — tracking initialisation
    // -------------------------------------------------------------------------

    private function initScorm12Tracking(ScoTracking $tracking, ScormScoModel $sco, mixed $userId, ?string $userName): array
    {
        $tracking->setLessonStatus('not attempted');
        $tracking->setSuspendData('');
        $tracking->setEntry('ab-initio');
        $tracking->setLessonLocation('');
        $tracking->setCredit('no-credit');
        $tracking->setTotalTimeInt(0);
        $tracking->setSessionTime(0);
        $tracking->setLessonMode('normal');
        $tracking->setExitMode('');
        $tracking->setIsLocked($sco->prerequisites !== null);

        return [
            'cmi.core.entry'        => $tracking->getEntry(),
            'cmi.core.student_id'   => $userId,
            'cmi.core.student_name' => $userName,
        ];
    }

    private function initScorm2004Tracking(ScoTracking $tracking, mixed $userId, ?string $userName): array
    {
        $tracking->setTotalTimeString('PT0S');
        $tracking->setCompletionStatus('unknown');
        $tracking->setLessonStatus('unknown');
        $tracking->setIsLocked(false);

        return [
            'cmi.entry'               => 'ab-initio',
            'cmi.learner_id'          => $userId,
            'cmi.learner_name'        => $userName,
            'cmi.scaled_passing_score' => 0.5,
        ];
    }

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

    // -------------------------------------------------------------------------
    // Private — tracking updates
    // -------------------------------------------------------------------------

    private function applyScorm12Update(ScoTracking $tracking, array $data): void
    {
        $tracking->setDetails($data);

        if (!empty($data['cmi.suspend_data'])) {
            $tracking->setSuspendData($data['cmi.suspend_data']);
        }

        $scoreRaw   = isset($data['cmi.core.score.raw']) ? (int) $data['cmi.core.score.raw'] : null;
        $scoreMin   = isset($data['cmi.core.score.min']) ? (int) $data['cmi.core.score.min'] : null;
        $scoreMax   = isset($data['cmi.core.score.max']) ? (int) $data['cmi.core.score.max'] : null;
        $status     = $data['cmi.core.lesson_status'] ?? 'unknown';
        $sessionTime = $this->convertTimeToHundredths($data['cmi.core.session_time'] ?? null);
        $totalTime   = $this->convertTimeToHundredths($data['cmi.core.total_time'] ?? '0:0:0');

        $tracking->setEntry($data['cmi.core.entry'] ?? null);
        $tracking->setExitMode($data['cmi.core.exit'] ?? null);
        $tracking->setLessonLocation($data['cmi.core.lesson_location'] ?? null);
        $tracking->setSessionTime($sessionTime);
        $tracking->setTotalTime($totalTime, Scorm::SCORM_12);
        $tracking->setLessonStatus($status);

        if (empty($tracking->getScoreRaw()) || (!is_null($scoreRaw) && $scoreRaw > (int) $tracking->getScoreRaw())) {
            $tracking->setScoreRaw($scoreRaw);
            $tracking->setScoreMin($scoreMin);
            $tracking->setScoreMax($scoreMax);
        }

        $progression = !empty($scoreRaw) ? (float) $scoreRaw : 0;
        if ($progression === 0.0 && in_array($status, ['completed', 'passed'], true)) {
            $progression = 100;
        }
        if ($progression > $tracking->getProgression()) {
            $tracking->setProgression($progression);
        }
    }

    private function applyScorm2004Update(ScoTracking $tracking, array $data): void
    {
        $tracking->setDetails($data);

        if (!empty($data['cmi.suspend_data'])) {
            $tracking->setSuspendData($data['cmi.suspend_data']);
        }

        $sessionTimeStr  = $this->normalizeIso8601Duration($data['cmi.session_time'] ?? 'PT0S');
        $completionStatus = $data['cmi.completion_status'] ?? 'unknown';
        $successStatus    = $data['cmi.success_status'] ?? 'unknown';
        $scoreRaw         = isset($data['cmi.score.raw'])    ? (int)   $data['cmi.score.raw']    : null;
        $scoreMin         = isset($data['cmi.score.min'])    ? (int)   $data['cmi.score.min']    : null;
        $scoreMax         = isset($data['cmi.score.max'])    ? (int)   $data['cmi.score.max']    : null;
        $scoreScaled      = isset($data['cmi.score.scaled']) ? (float) $data['cmi.score.scaled'] : null;
        $progression      = isset($data['cmi.progress_measure']) ? (float) $data['cmi.progress_measure'] : 0;

        // Accumulate total time
        $totalTime   = new DateInterval($tracking->getTotalTimeString());
        try {
            $sessionTime = new DateInterval($sessionTimeStr);
        } catch (Exception) {
            $sessionTime = new DateInterval('PT0S');
        }
        $base = new DateTime('@0');
        $base->add($totalTime)->add($sessionTime);
        $totalTimeInterval = $this->secondsToIsoDuration($base->getTimestamp());
        $data['cmi.total_time'] = $totalTimeInterval;
        $tracking->setTotalTimeString($totalTimeInterval);

        if (empty($tracking->getScoreRaw()) || (!is_null($scoreRaw) && $scoreRaw > (int) $tracking->getScoreRaw())) {
            $tracking->setScoreRaw($scoreRaw);
            $tracking->setScoreMin($scoreMin);
            $tracking->setScoreMax($scoreMax);
            $tracking->setScoreScaled($scoreScaled);
        }

        $lessonStatus = in_array($successStatus, ['passed', 'failed'], true) ? $successStatus : $completionStatus;
        $tracking->setLessonStatus($lessonStatus);
        $tracking->setCompletionStatus($completionStatus);

        if ($progression === 0.0 && in_array($lessonStatus, ['completed', 'passed'], true)) {
            $progression = 100;
        }
        if ($progression > $tracking->getProgression()) {
            $tracking->setProgression($progression);
        }
    }

    // -------------------------------------------------------------------------
    // Private — time utilities
    // -------------------------------------------------------------------------

    /**
     * Convert a SCORM 1.2 HH:MM:SS.ss time string to hundredths of a second.
     */
    private function convertTimeToHundredths(?string $time): int
    {
        if ($time === null || $time === '') {
            return 0;
        }

        [$h, $m, $rest] = explode(':', $time);
        [$s, $cs]       = array_pad(explode('.', $rest), 2, '0');

        if (strlen($cs) === 1) {
            $cs .= '0';
        }

        return (int) $h * 360000
            + (int) $m * 6000
            + (int) $s * 100
            + (int) $cs;
    }

    /**
     * Convert a total number of seconds to a SCORM 2004 ISO 8601 duration string.
     */
    private function secondsToIsoDuration(int $seconds): string
    {
        if ($seconds === 0) {
            return 'PT0S';
        }

        $d  = intdiv($seconds, 86400);
        $seconds %= 86400;
        $h  = intdiv($seconds, 3600);
        $seconds %= 3600;
        $m  = intdiv($seconds, 60);
        $s  = $seconds % 60;

        return "P{$d}DT{$h}H{$m}M{$s}S";
    }

    /**
     * Normalise a SCORM 2004 session time string to a valid ISO 8601 duration,
     * stripping decimal seconds that PHP's DateInterval does not support.
     */
    private function normalizeIso8601Duration(string $value): string
    {
        if ($value === 'PT') {
            return 'PT0S';
        }

        // Full integer duration
        if (preg_match('/^P([0-9]+Y)?([0-9]+M)?([0-9]+D)?T([0-9]+H)?([0-9]+M)?([0-9]+S)?$/', $value)) {
            return $value;
        }

        // Duration with decimal seconds — strip the fractional part
        if (preg_match('/^P([0-9]+Y)?([0-9]+M)?([0-9]+D)?T([0-9]+H)?([0-9]+M)?[0-9]+\.[0-9]{1,2}S$/', $value)) {
            return preg_replace('/\.[0-9]+S$/', 'S', $value);
        }

        return 'PT0S';
    }
}
