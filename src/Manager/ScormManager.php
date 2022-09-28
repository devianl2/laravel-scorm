<?php


namespace Peopleaps\Scorm\Manager;

use Carbon\Carbon;
use DOMDocument;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Peopleaps\Scorm\Entity\Scorm;
use Peopleaps\Scorm\Entity\ScoTracking;
use Peopleaps\Scorm\Exception\InvalidScormArchiveException;
use Peopleaps\Scorm\Library\ScormLib;
use Peopleaps\Scorm\Model\ScormModel;
use Peopleaps\Scorm\Model\ScormScoModel;
use Peopleaps\Scorm\Model\ScormScoTrackingModel;
use Illuminate\Support\Str;
use Peopleaps\Scorm\Entity\Sco;

class ScormManager
{
    /** @var ScormLib */
    private $scormLib;
    /** @var ScormDisk */
    private $scormDisk;
    /** @var string $uuid */
    private $uuid;

    /**
     * Constructor.
     *
     * @param string $filesDir
     * @param string $uploadDir
     */
    public function __construct()
    {
        $this->scormLib = new ScormLib();
        $this->scormDisk = new ScormDisk();
    }

    public function uploadScormFromUri($file, $uuid = null)
    {
        // $uuid is meant for user to update scorm content. Hence, if user want to update content should parse in existing uuid
        if (!empty($uuid))
        {
            $this->uuid =   $uuid;
        }
        else
        {
            $this->uuid = Str::uuid();
        }


        $scorm = null;
        $this->scormDisk->readScormArchive($file, function ($path) use (&$scorm, $file, $uuid) {
            $filename = basename($file);
            $scorm = $this->saveScorm($path, $filename, $uuid);
        });
        return $scorm;
    }

    /**
     * @param UploadedFile $file
     * @param null|string $uuid
     * @return ScormModel
     * @throws InvalidScormArchiveException
     */
    public function uploadScormArchive(UploadedFile $file, $uuid = null)
    {
        // $uuid is meant for user to update scorm content. Hence, if user want to update content should parse in existing uuid
        if (!empty($uuid))
        {
            $this->uuid =   $uuid;
        }
        else
        {
            $this->uuid = Str::uuid();
        }

        return $this->saveScorm($file, $file->getClientOriginalName(), $uuid);
    }

    /**
     *  Checks if it is a valid scorm archive
     * 
     * @param string|UploadedFile $file zip.       
     */
    private function validatePackage($file)
    {
        $zip = new \ZipArchive();
        $openValue = $zip->open($file);
        $isScormArchive = (true === $openValue) && $zip->getStream('imsmanifest.xml');

        $zip->close();
        if (!$isScormArchive) {
            $this->onError('invalid_scorm_archive_message');
        }
    }

    /**
     *  Save scorm data
     *
     * @param string|UploadedFile $file zip.
     * @param string $filename
     * @param null|string $uuid
     * @return ScormModel
     * @throws InvalidScormArchiveException
     */
    private function saveScorm($file, $filename, $uuid = null)
    {
        $this->validatePackage($file);
        $scormData  =   $this->generateScorm($file);
        // save to db
        if (is_null($scormData) || !is_array($scormData)) {
            $this->onError('invalid_scorm_data');
        }

        // This uuid is use when the admin wants to edit existing scorm file.
        if (!empty($uuid))
        {
            $this->uuid =   $uuid; // Overwrite system generated uuid
        }

        /**
         * ScormModel::whereUuid Query Builder style equals ScormModel::where('uuid',$value)
         * 
         * From Laravel doc https://laravel.com/docs/5.0/queries#advanced-wheres.
         * Dynamic Where Clauses
         * You may even use "dynamic" where statements to fluently build where statements using magic methods:
         * 
         * Examples: 
         * 
         * $admin = DB::table('users')->whereId(1)->first();
         * From laravel framework https://github.com/laravel/framework/blob/9.x/src/Illuminate/Database/Query/Builder.php'
         *  Handle dynamic method calls into the method.
         *  return $this->dynamicWhere($method, $parameters);
         **/
        // Uuid indicator is better than filename for update content or add new content.
        $scorm = ScormModel::whereUuid($this->uuid);

        // Check if scom package already exists to drop old one.
        if (!$scorm->exists()) {
            $scorm = new ScormModel();
        } else {
            $scorm = $scorm->first();
            $this->deleteScormData($scorm);
        }

        $scorm->uuid =   $this->uuid;
        $scorm->title =   $scormData['title'];
        $scorm->version =   $scormData['version'];
        $scorm->entry_url =   $scormData['entryUrl'];
        $scorm->identifier =   $scormData['identifier'];
        $scorm->origin_file =   $filename;
        $scorm->save();

        if (!empty($scormData['scos']) && is_array($scormData['scos'])) {
            /** @var Sco $scoData */
            foreach ($scormData['scos'] as $scoData) {
                $sco = $this->saveScormScos($scorm->id, $scoData);
                if ($scoData->scoChildren) {
                    foreach ($scoData->scoChildren as $scoChild) {
                        $this->saveScormScos($scorm->id, $scoChild, $sco->id);
                    }
                }
            }
        }

        return  $scorm;
    }

    /**
     * Save Scorm sco and it's nested children
     * @param int $scorm_id scorm id.
     * @param Sco $scoData Sco data to be store.
     * @param int $sco_parent_id sco parent id for children
     */
    private function saveScormScos($scorm_id, $scoData, $sco_parent_id = null)
    {
        $sco    =   new ScormScoModel();
        $sco->scorm_id  =   $scorm_id;
        $sco->uuid  =   $scoData->uuid;
        $sco->sco_parent_id  =   $sco_parent_id;
        $sco->entry_url  =   $scoData->entryUrl;
        $sco->identifier  =   $scoData->identifier;
        $sco->title  =   $scoData->title;
        $sco->visible  =   $scoData->visible;
        $sco->sco_parameters  =   $scoData->parameters;
        $sco->launch_data  =   $scoData->launchData;
        $sco->max_time_allowed  =   $scoData->maxTimeAllowed;
        $sco->time_limit_action  =   $scoData->timeLimitAction;
        $sco->block  =   $scoData->block;
        $sco->score_int  =   $scoData->scoreToPassInt;
        $sco->score_decimal  =   $scoData->scoreToPassDecimal;
        $sco->completion_threshold  =   $scoData->completionThreshold;
        $sco->prerequisites  =   $scoData->prerequisites;
        $sco->save();
        return $sco;
    }

    /**
     * @param string|UploadedFile $file zip.       
     */
    private function parseScormArchive($file)
    {
        $data = [];
        $contents = '';
        $zip = new \ZipArchive();

        $zip->open($file);
        $stream = $zip->getStream('imsmanifest.xml');

        while (!feof($stream)) {
            $contents .= fread($stream, 2);
        }


        $dom = new DOMDocument();

        if (!$dom->loadXML($contents)) {
            $this->onError('cannot_load_imsmanifest_message');
        }

        $manifest = $dom->getElementsByTagName('manifest')->item(0);
        if (!is_null($manifest->attributes->getNamedItem('identifier'))) {
            $data['identifier'] = $manifest->attributes->getNamedItem('identifier')->nodeValue;
        } else {
            $this->onError('invalid_scorm_manifest_identifier');
        }
        $titles = $dom->getElementsByTagName('title');
        if ($titles->length > 0) {
            $data['title'] = Str::of($titles->item(0)->textContent)->trim('/n')->trim();
        }

        $scormVersionElements = $dom->getElementsByTagName('schemaversion');
        if ($scormVersionElements->length > 0) {
            switch ($scormVersionElements->item(0)->textContent) {
                case '1.2':
                    $data['version'] = Scorm::SCORM_12;
                    break;
                case 'CAM 1.3':
                case '2004 3rd Edition':
                case '2004 4th Edition':
                    $data['version'] = Scorm::SCORM_2004;
                    break;
                default:
                    $this->onError('invalid_scorm_version_message');
            }
        } else {
            $this->onError('invalid_scorm_version_message');
        }
        $scos = $this->scormLib->parseOrganizationsNode($dom);

        if (0 >= count($scos)) {
            $this->onError('no_sco_in_scorm_archive_message');
        }

        $data['entryUrl'] = $scos[0]->entryUrl ?? $scos[0]->scoChildren[0]->entryUrl;
        $data['scos'] = $scos;

        return $data;
    }

    public function deleteScorm($model)
    {
        // Delete after the previous item is stored
        if ($model) {
            $this->deleteScormData($model);
            $model->delete(); // delete scorm
        }
    }

    private function deleteScormData($model)
    {
        // Delete after the previous item is stored
        $oldScos = $model->scos()->get();

        // Delete all tracking associate with sco
        foreach ($oldScos as $oldSco) {
            $oldSco->scoTrackings()->delete();
        }
        $model->scos()->delete(); // delete scos
        // Delete folder from server
        $this->deleteScormFolder($model->uuid);
    }

    /**
     * @param $folderHashedName
     * @return bool
     */
    protected function deleteScormFolder($folderHashedName)
    {
        return $this->scormDisk->deleteScorm($folderHashedName);
    }

    /**
     * @param string|UploadedFile $file zip.       
     * @return array
     * @throws InvalidScormArchiveException
     */
    private function generateScorm($file)
    {
        $scormData = $this->parseScormArchive($file);
        /**
         * Unzip a given ZIP file into the web resources directory.
         *
         * @param string $hashName name of the destination directory
         */
        $this->scormDisk->unzipper($file, $this->uuid);

        return [
            'identifier' => $scormData['identifier'],
            'uuid' => $this->uuid,
            'title' => $scormData['title'], // to follow standard file data format
            'version' => $scormData['version'],
            'entryUrl' => $scormData['entryUrl'],
            'scos' => $scormData['scos'],
        ];
    }

    /**
     * Get SCO list
     * @param $scormId
     * @return \Illuminate\Database\Eloquent\Builder[]|\Illuminate\Database\Eloquent\Collection
     */
    public function getScos($scormId)
    {
        $scos  =   ScormScoModel::with([
            'scorm'
        ])->where('scorm_id', $scormId)
            ->get();

        return $scos;
    }

    /**
     * Get sco by uuid
     * @param $scoUuid
     * @return null|\Illuminate\Database\Eloquent\Builder|Model
     */
    public function getScoByUuid($scoUuid)
    {
        $sco    =   ScormScoModel::with(['scorm'])
            ->where('uuid', $scoUuid)
            ->firstOrFail();

        return $sco;
    }

    public function getUserResult($scoId, $userId)
    {
        return ScormScoTrackingModel::where('sco_id', $scoId)->where('user_id', $userId)->first();
    }

    public function createScoTracking($scoUuid, $userId = null, $userName = null)
    {
        $sco    =   ScormScoModel::where('uuid', $scoUuid)->firstOrFail();

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

                if (is_null($sco->prerequisites)) {
                    $scoTracking->setIsLocked(false);
                } else {
                    $scoTracking->setIsLocked(true);
                }
                $cmi = [
                    'cmi.core.entry' => $scoTracking->getEntry(),
                    'cmi.core.student_id' => $userId,
                    'cmi.core.student_name' => $userName,
                ];

                break;
            case Scorm::SCORM_2004:
                $scoTracking->setTotalTimeString('PT0S');
                $scoTracking->setCompletionStatus('unknown');
                $scoTracking->setLessonStatus('unknown');
                $scoTracking->setIsLocked(false);
                $cmi = [
                    'cmi.entry' => 'ab-initio',
                    'cmi.learner_id' =>  $userId,
                    'cmi.learner_name' => $userName,
                    'cmi.scaled_passing_score' => 0.5,
                ];
                break;
        }

        $scoTracking->setUserId($userId);
        $scoTracking->setDetails($cmi);

        // Create a new tracking model
        $storeTracking  =   ScormScoTrackingModel::firstOrCreate([
            'user_id'   =>  $userId,
            'sco_id'    =>  $sco->id
        ], [
            'uuid'  =>  Str::uuid(),
            'progression'  =>  $scoTracking->getProgression(),
            'score_raw'  =>  $scoTracking->getScoreRaw(),
            'score_min'  =>  $scoTracking->getScoreMin(),
            'score_max'  =>  $scoTracking->getScoreMax(),
            'score_scaled'  =>  $scoTracking->getScoreScaled(),
            'lesson_status'  =>  $scoTracking->getLessonStatus(),
            'completion_status'  =>  $scoTracking->getCompletionStatus(),
            'session_time'  =>  $scoTracking->getSessionTime(),
            'total_time_int'  =>  $scoTracking->getTotalTimeInt(),
            'total_time_string'  =>  $scoTracking->getTotalTimeString(),
            'entry'  =>  $scoTracking->getEntry(),
            'suspend_data'  =>  $scoTracking->getSuspendData(),
            'credit'  =>  $scoTracking->getCredit(),
            'exit_mode'  =>  $scoTracking->getExitMode(),
            'lesson_location'  =>  $scoTracking->getLessonLocation(),
            'lesson_mode'  =>  $scoTracking->getLessonMode(),
            'is_locked'  =>  $scoTracking->getIsLocked(),
            'details'  =>  $scoTracking->getDetails(),
            'latest_date'  =>  $scoTracking->getLatestDate(),
            'created_at'  =>  Carbon::now(),
            'updated_at'  =>  Carbon::now(),
        ]);

        $scoTracking->setUuid($storeTracking->uuid);
        $scoTracking->setProgression($storeTracking->progression);
        $scoTracking->setScoreRaw($storeTracking->score_raw);
        $scoTracking->setScoreMin($storeTracking->score_min);
        $scoTracking->setScoreMax($storeTracking->score_max);
        $scoTracking->setScoreScaled($storeTracking->score_scaled);
        $scoTracking->setLessonStatus($storeTracking->lesson_status);
        $scoTracking->setCompletionStatus($storeTracking->completion_status);
        $scoTracking->setSessionTime($storeTracking->session_time);
        $scoTracking->setTotalTimeInt($storeTracking->total_time_int);
        $scoTracking->setTotalTimeString($storeTracking->total_time_string);
        $scoTracking->setEntry($storeTracking->entry);
        $scoTracking->setSuspendData($storeTracking->suspend_data);
        $scoTracking->setCredit($storeTracking->credit);
        $scoTracking->setExitMode($storeTracking->exit_mode);
        $scoTracking->setLessonLocation($storeTracking->lesson_location);
        $scoTracking->setLessonMode($storeTracking->lesson_mode);
        $scoTracking->setIsLocked($storeTracking->is_locked);
        $scoTracking->setDetails($storeTracking->details);
        $scoTracking->setLatestDate(Carbon::parse($storeTracking->latest_date));

        return $scoTracking;
    }

    public function findScoTrackingId($scoUuid, $scoTrackingUuid)
    {
        return ScormScoTrackingModel::with([
            'sco'
        ])->whereHas('sco', function (Builder $query) use ($scoUuid) {
            $query->where('uuid', $scoUuid);
        })->where('uuid', $scoTrackingUuid)
            ->firstOrFail();
    }

    public function checkUserIsCompletedScorm($scormId, $userId)
    {

        $completedSco    =   [];
        $scos   =   ScormScoModel::where('scorm_id', $scormId)->get();

        foreach ($scos as $sco) {
            $scoTracking    =   ScormScoTrackingModel::where('sco_id', $sco->id)->where('user_id', $userId)->first();

            if ($scoTracking && ($scoTracking->lesson_status == 'passed' || $scoTracking->lesson_status == 'completed')) {
                $completedSco[] =   true;
            }
        }

        if (count($completedSco) == $scos->count()) {
            return true;
        } else {
            return false;
        }
    }

    public function updateScoTracking($scoUuid, $userId, $data)
    {
        $tracking = $this->createScoTracking($scoUuid, $userId);
        $tracking->setLatestDate(Carbon::now());
        $sco    =   $tracking->getSco();
        $scorm  =   ScormModel::where('id', $sco['scorm_id'])->firstOrFail();

        $statusPriority = [
            'unknown' => 0,
            'not attempted' => 1,
            'browsed' => 2,
            'incomplete' => 3,
            'completed' => 4,
            'failed' => 5,
            'passed' => 6,
        ];

        switch ($scorm->version) {
            case Scorm::SCORM_12:
                if (isset($data['cmi.suspend_data']) && !empty($data['cmi.suspend_data'])) {
                    $tracking->setSuspendData($data['cmi.suspend_data']);
                }

                $scoreRaw = isset($data['cmi.core.score.raw']) ? intval($data['cmi.core.score.raw']) : null;
                $scoreMin = isset($data['cmi.core.score.min']) ? intval($data['cmi.core.score.min']) : null;
                $scoreMax = isset($data['cmi.core.score.max']) ? intval($data['cmi.core.score.max']) : null;
                $lessonStatus = isset($data['cmi.core.lesson_status']) ? $data['cmi.core.lesson_status'] : 'unknown';
                $sessionTime = isset($data['cmi.core.session_time']) ? $data['cmi.core.session_time'] : null;
                $sessionTimeInHundredth = $this->convertTimeInHundredth($sessionTime);
                $progression = !empty($scoreRaw) ? floatval($scoreRaw) : 0;
                $entry  =   isset($data['cmi.core.entry']) ? $data['cmi.core.entry'] : null;
                $exit  =   isset($data['cmi.core.exit']) ? $data['cmi.core.exit'] : null;
                $lessonLocation =   isset($data['cmi.core.lesson_location']) ? $data['cmi.core.lesson_location'] : null;
                $totalTime  =   isset($data['cmi.core.total_time']) ? $data['cmi.core.total_time'] : 0;

                $tracking->setDetails($data);
                $tracking->setEntry($entry);
                $tracking->setExitMode($exit);
                $tracking->setLessonLocation($lessonLocation);
                $tracking->setSessionTime($sessionTimeInHundredth);

                // Compute total time
                $totalTimeInHundredth = $this->convertTimeInHundredth($totalTime);
                $tracking->setTotalTime($totalTimeInHundredth, Scorm::SCORM_12);

                $bestScore = $tracking->getScoreRaw();

                // Update best score if the current score is better than the previous best score

                if (empty($bestScore) || (!is_null($scoreRaw) && (int)$scoreRaw > (int)$bestScore)) {
                    $tracking->setScoreRaw($scoreRaw);
                    $tracking->setScoreMin($scoreMin);
                    $tracking->setScoreMax($scoreMax);
                }

                $tracking->setLessonStatus($lessonStatus);
                $bestStatus = $lessonStatus;

                if (empty($progression) && ('completed' === $bestStatus || 'passed' === $bestStatus)) {
                    $progression = 100;
                }

                if ($progression > $tracking->getProgression()) {
                    $tracking->setProgression($progression);
                }

                break;

            case Scorm::SCORM_2004:
                $tracking->setDetails($data);

                if (isset($data['cmi.suspend_data']) && !empty($data['cmi.suspend_data'])) {
                    $tracking->setSuspendData($data['cmi.suspend_data']);
                }

                $dataSessionTime = isset($data['cmi.session_time']) ?
                    $this->formatSessionTime($data['cmi.session_time']) :
                    'PT0S';
                $completionStatus = isset($data['cmi.completion_status']) ? $data['cmi.completion_status'] : 'unknown';
                $successStatus = isset($data['cmi.success_status']) ? $data['cmi.success_status'] : 'unknown';
                $scoreRaw = isset($data['cmi.score.raw']) ? intval($data['cmi.score.raw']) : null;
                $scoreMin = isset($data['cmi.score.min']) ? intval($data['cmi.score.min']) : null;
                $scoreMax = isset($data['cmi.score.max']) ? intval($data['cmi.score.max']) : null;
                $scoreScaled = isset($data['cmi.score.scaled']) ? floatval($data['cmi.score.scaled']) : null;
                $progression = isset($data['cmi.progress_measure']) ? floatval($data['cmi.progress_measure']) : 0;
                $bestScore = $tracking->getScoreRaw();

                // Computes total time
                $totalTime = new \DateInterval($tracking->getTotalTimeString());

                try {
                    $sessionTime = new \DateInterval($dataSessionTime);
                } catch (\Exception $e) {
                    $sessionTime = new \DateInterval('PT0S');
                }
                $computedTime = new \DateTime();
                $computedTime->setTimestamp(0);
                $computedTime->add($totalTime);
                $computedTime->add($sessionTime);
                $computedTimeInSecond = $computedTime->getTimestamp();
                $totalTimeInterval = $this->retrieveIntervalFromSeconds($computedTimeInSecond);
                $data['cmi.total_time'] = $totalTimeInterval;
                $tracking->setTotalTimeString($totalTimeInterval);

                // Update best score if the current score is better than the previous best score
                if (empty($bestScore) || (!is_null($scoreRaw) && (int)$scoreRaw > (int)$bestScore)) {
                    $tracking->setScoreRaw($scoreRaw);
                    $tracking->setScoreMin($scoreMin);
                    $tracking->setScoreMax($scoreMax);
                    $tracking->setScoreScaled($scoreScaled);
                }

                // Update best success status and completion status
                $lessonStatus = $completionStatus;
                if (in_array($successStatus, ['passed', 'failed'])) {
                    $lessonStatus = $successStatus;
                }

                $tracking->setLessonStatus($lessonStatus);
                $bestStatus = $lessonStatus;

                if (empty($tracking->getCompletionStatus())
                    || ($completionStatus !== $tracking->getCompletionStatus() && $statusPriority[$completionStatus] > $statusPriority[$tracking->getCompletionStatus()])
                ) {
                    // This is no longer needed as completionStatus and successStatus are merged together
                    // I keep it for now for possible retro compatibility
                    $tracking->setCompletionStatus($completionStatus);
                }

                if (empty($progression) && ('completed' === $bestStatus || 'passed' === $bestStatus)) {
                    $progression = 100;
                }

                if ($progression > $tracking->getProgression()) {
                    $tracking->setProgression($progression);
                }

                break;
        }

        $updateResult   =   ScormScoTrackingModel::where('user_id', $tracking->getUserId())
            ->where('sco_id', $sco['id'])
            ->firstOrFail();

        $updateResult->progression  =   $tracking->getProgression();
        $updateResult->score_raw    =   $tracking->getScoreRaw();
        $updateResult->score_min    =   $tracking->getScoreMin();
        $updateResult->score_max    =   $tracking->getScoreMax();
        $updateResult->score_scaled    =   $tracking->getScoreScaled();
        $updateResult->lesson_status    =   $tracking->getLessonStatus();
        $updateResult->completion_status    =   $tracking->getCompletionStatus();
        $updateResult->session_time    =   $tracking->getSessionTime();
        $updateResult->total_time_int    =   $tracking->getTotalTimeInt();
        $updateResult->total_time_string    =   $tracking->getTotalTimeString();
        $updateResult->entry    =   $tracking->getEntry();
        $updateResult->suspend_data    =   $tracking->getSuspendData();
        $updateResult->exit_mode    =   $tracking->getExitMode();
        $updateResult->credit    =   $tracking->getCredit();
        $updateResult->lesson_location    =   $tracking->getLessonLocation();
        $updateResult->lesson_mode    =   $tracking->getLessonMode();
        $updateResult->is_locked    =   $tracking->getIsLocked();
        $updateResult->details    =   $tracking->getDetails();
        $updateResult->latest_date    =   $tracking->getLatestDate();

        $updateResult->save();

        return $updateResult;
    }

    public function resetUserData($scormId, $userId) {
        $scos   =   ScormScoModel::where('scorm_id', $scormId)->get();

        foreach ($scos as $sco) {
            $scoTracking    =   ScormScoTrackingModel::where('sco_id', $sco->id)->where('user_id', $userId)->delete();
        }
    }

    private function convertTimeInHundredth($time)
    {
        if ($time != null) {
            $timeInArray = explode(':', $time);
            $timeInArraySec = explode('.', $timeInArray[2]);
            $timeInHundredth = 0;

            if (isset($timeInArraySec[1])) {
                if (1 === strlen($timeInArraySec[1])) {
                    $timeInArraySec[1] .= '0';
                }
                $timeInHundredth = intval($timeInArraySec[1]);
            }
            $timeInHundredth += intval($timeInArraySec[0]) * 100;
            $timeInHundredth += intval($timeInArray[1]) * 6000;
            $timeInHundredth += intval($timeInArray[0]) * 360000;

            return $timeInHundredth;
        } else {
            return 0;
        }
    }

    /**
     * Converts a time in seconds to a DateInterval string.
     *
     * @param int $seconds
     *
     * @return string
     */
    private function retrieveIntervalFromSeconds($seconds)
    {
        $result = '';
        $remainingTime = (int) $seconds;

        if (empty($remainingTime)) {
            $result .= 'PT0S';
        } else {
            $nbDays = (int) ($remainingTime / 86400);
            $remainingTime %= 86400;
            $nbHours = (int) ($remainingTime / 3600);
            $remainingTime %= 3600;
            $nbMinutes = (int) ($remainingTime / 60);
            $nbSeconds = $remainingTime % 60;
            $result .= 'P' . $nbDays . 'DT' . $nbHours . 'H' . $nbMinutes . 'M' . $nbSeconds . 'S';
        }

        return $result;
    }

    private function formatSessionTime($sessionTime)
    {
        $formattedValue = 'PT0S';
        $generalPattern = '/^P([0-9]+Y)?([0-9]+M)?([0-9]+D)?T([0-9]+H)?([0-9]+M)?([0-9]+S)?$/';
        $decimalPattern = '/^P([0-9]+Y)?([0-9]+M)?([0-9]+D)?T([0-9]+H)?([0-9]+M)?[0-9]+\.[0-9]{1,2}S$/';

        if ('PT' !== $sessionTime) {
            if (preg_match($generalPattern, $sessionTime)) {
                $formattedValue = $sessionTime;
            } elseif (preg_match($decimalPattern, $sessionTime)) {
                $formattedValue = preg_replace(['/\.[0-9]+S$/'], ['S'], $sessionTime);
            }
        }

        return $formattedValue;
    }

    /**
     * Clean resources and throw exception.
     */
    private function onError($msg)
    {
        $this->scormDisk->deleteScorm($this->uuid);
        throw new InvalidScormArchiveException($msg);
    }
}
