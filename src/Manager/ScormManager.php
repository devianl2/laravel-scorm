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
        $this->uuid =  $uuid ?? Str::uuid()->toString();

        // Validate that the file parameter is not empty
        if (empty($file)) {
            throw new InvalidScormArchiveException('file_parameter_empty');
        }

        // Log the file being processed for debugging
        \Log::info('Uploading SCORM from URI: ' . $file);

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
        $this->uuid =  $uuid ?? Str::uuid()->toString();

        return $this->saveScorm($file, $file->getClientOriginalName(), $uuid);
    }

    /**
     * Find imsmanifest.xml location in ZIP archive
     * 
     * @param \ZipArchive $zip
     * @return array ['found' => bool, 'path' => string, 'stream' => resource|null]
     */
    private function findManifestInZip(\ZipArchive $zip)
    {
        // Look for imsmanifest.xml in root directory first
        $manifestStream = $zip->getStream('imsmanifest.xml');

        if ($manifestStream) {
            \Log::info('Found imsmanifest.xml in root directory');
            return [
                'found' => true,
                'path' => 'imsmanifest.xml',
                'stream' => $manifestStream
            ];
        }

        // Search for imsmanifest.xml in subdirectories
        \Log::info('imsmanifest.xml not found in root, searching subdirectories...');

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $filename = $zip->getNameIndex($i);

            // Check if this file is imsmanifest.xml (case insensitive)
            if (strtolower(basename($filename)) === 'imsmanifest.xml') {
                $manifestStream = $zip->getStream($filename);
                if ($manifestStream) {
                    \Log::info('Found imsmanifest.xml at: ' . $filename);
                    return [
                        'found' => true,
                        'path' => $filename,
                        'stream' => $manifestStream
                    ];
                }
            }
        }

        return [
            'found' => false,
            'path' => '',
            'stream' => null
        ];
    }

    /**
     *  Checks if it is a valid scorm archive
     * 
     * @param string|UploadedFile $file zip.       
     */
    private function validatePackage($file)
    {
        $zip = new \ZipArchive();
        $manifestResult = null;

        try {
            $openValue = $zip->open($file);

            if ($openValue !== true) {
                \Log::error('Zip open errorCode: ' . $openValue);
                $this->onError('invalid_scorm_archive_message');
            }

            $manifestResult = $this->findManifestInZip($zip);
        } finally {
            // Always close resources
            if ($manifestResult && $manifestResult['stream'] && is_resource($manifestResult['stream'])) {
                fclose($manifestResult['stream']);
            }
            $zip->close();
        }

        if (!$manifestResult['found']) {
            \Log::error('imsmanifest.xml not found anywhere in the ZIP archive');
            $this->onError('invalid_scorm_archive_message');
        }

        \Log::info('SCORM archive validation successful. Manifest found at: ' . $manifestResult['path']);
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
        if (!empty($uuid)) {
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
        // $scorm = ScormModel::whereOriginFile($filename);
        // Uuid indicator is better than filename for update content or add new content.
        $scorm = ScormModel::where('uuid', $this->uuid);

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

        // Auto-detect and set metadata
        $scorm->metadata = [
            'package_size' => $this->getFileSize($file),
            'created_at' => $scormData['created_at'] ?? null,
            'created_by' => $scormData['created_by'] ?? null
        ];

        $scorm->save();

        if (!empty($scormData['scos']) && is_array($scormData['scos'])) {
            \Log::info('saveScorm: Saving ' . count($scormData['scos']) . ' SCOs');
            /** @var Sco $scoData */
            foreach ($scormData['scos'] as $scoData) {
                $this->saveScormScosRecursively($scorm->id, $scoData);
            }
        } else {
            \Log::warning('saveScorm: No SCOs to save');
        }

        return  $scorm;
    }

    /**
     * Save Scorm sco and it's nested children recursively
     * @param int $scorm_id scorm id.
     * @param Sco $scoData Sco data to be store.
     * @param int $sco_parent_id sco parent id for children
     */
    private function saveScormScosRecursively($scorm_id, $scoData, $sco_parent_id = null)
    {
        $sco = $this->saveScormScos($scorm_id, $scoData, $sco_parent_id);

        // Recursively save children
        if ($scoData->scoChildren && is_array($scoData->scoChildren)) {
            foreach ($scoData->scoChildren as $scoChild) {
                $this->saveScormScosRecursively($scorm_id, $scoChild, $sco->id);
            }
        }

        return $sco;
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

        \Log::info("saveScormScos: Saved SCO - " . $sco->identifier);
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
        $manifestResult = null;

        try {
            $openValue = $zip->open($file);

            if ($openValue !== true) {
                \Log::error('Failed to open ZIP file in parseScormArchive. Error code: ' . $openValue);
                $this->onError('cannot_load_imsmanifest_message');
            }

            $manifestResult = $this->findManifestInZip($zip);

            if (!$manifestResult['found']) {
                $this->onError('cannot_load_imsmanifest_message');
            }

            while (!feof($manifestResult['stream'])) {
                $contents .= fread($manifestResult['stream'], 2);
            }
        } finally {
            // Always close resources
            if ($manifestResult && $manifestResult['stream'] && is_resource($manifestResult['stream'])) {
                fclose($manifestResult['stream']);
            }
            $zip->close();
        }

        $dom = new DOMDocument();

        // Fix XML entity errors by escaping unescaped ampersands
        $contents = $this->fixXmlEntities($contents);

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
            \Log::error('parseScormArchive: No SCOs found in SCORM archive');
            $this->onError('no_sco_in_scorm_archive_message');
        }

        $data['entryUrl'] = $scos[0]->entryUrl ?? $scos[0]->scoChildren[0]->entryUrl;
        $data['scos'] = $scos;

        \Log::info('parseScormArchive: Found ' . count($scos) . ' SCOs, entryUrl: ' . $data['entryUrl']);

        // Include manifest path for later use in entry URL adjustment
        $data['manifestPath'] = $manifestResult['path'];

        // Extract creation date and creator from manifest metadata
        $data['created_at'] = $this->extractCreationDate($dom);
        $data['created_by'] = $this->extractCreator($dom);

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

        // Update main entry URL to include root path if content is in subdirectory
        // Use the manifest path we already calculated during parsing
        if (isset($scormData['manifestPath']) && strpos($scormData['manifestPath'], '/') !== false) {
            $parentPath = dirname($scormData['manifestPath']);
            $originalEntryUrl = $scormData['entryUrl'];
            $scormData['entryUrl'] = $parentPath . '/' . ltrim($scormData['entryUrl'], '/');
            \Log::info("SCORM content in subdirectory '{$parentPath}'. Entry URL: {$originalEntryUrl} -> {$scormData['entryUrl']}");
        } else {
            \Log::info("SCORM content in root directory. Entry URL unchanged: {$scormData['entryUrl']}");
        }

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
            'uuid'  =>   Str::uuid()->toString(),
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

                if (
                    empty($tracking->getCompletionStatus())
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

    public function resetUserData($scormId, $userId)
    {
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
     * Get file size in bytes
     * 
     * @param string|UploadedFile $file
     * @return int
     */
    private function getFileSize($file)
    {
        if ($file instanceof UploadedFile) {
            return $file->getSize();
        }

        if (is_string($file) && file_exists($file)) {
            return filesize($file);
        }

        return 0;
    }

    /**
     * Extract creation date from SCORM manifest metadata following SCORM standards
     */
    private function extractCreationDate(DOMDocument $dom)
    {
        try {
            $xpath = new \DOMXPath($dom);

            // Register common SCORM namespaces with error handling
            $this->registerScormNamespaces($xpath);

            // SCORM 2004: Look for lom:lom/lom:lifeCycle/lom:contribute/lom:date/lom:dateTime
            // Priority 1: Creator/Author contribution date
            $dateNodes = $this->safeXPathQuery($xpath, '//lom:dateTime[ancestor::lom:contribute[lom:role/lom:value[text()="creator" or text()="author"]]]');
            if ($dateNodes && $dateNodes->length > 0) {
                return $this->formatDate($dateNodes->item(0)->textContent);
            }

            // Priority 2: Any contribution date
            $dateNodes = $this->safeXPathQuery($xpath, '//lom:dateTime[ancestor::lom:contribute]');
            if ($dateNodes && $dateNodes->length > 0) {
                return $this->formatDate($dateNodes->item(0)->textContent);
            }

            // Priority 3: Any dateTime in metadata
            $dateNodes = $this->safeXPathQuery($xpath, '//lom:dateTime');
            if ($dateNodes && $dateNodes->length > 0) {
                return $this->formatDate($dateNodes->item(0)->textContent);
            }

            // SCORM 1.2: Look for <schema> and <schemaversion> to get creation context
            $schemaNodes = $this->safeXPathQuery($xpath, '//schema');
            if ($schemaNodes && $schemaNodes->length > 0) {
                $schema = $schemaNodes->item(0)->textContent;
                if (strpos($schema, 'ADL SCORM') !== false) {
                    // For SCORM 1.2, we might not have creation date in manifest
                    // Return null as it's not standard in SCORM 1.2
                    return null;
                }
            }
        } catch (\Exception $e) {
            \Log::warning('extractCreationDate: Error extracting creation date - ' . $e->getMessage());
        }

        return null;
    }

    /**
     * Extract creator from SCORM manifest metadata following SCORM standards
     */
    private function extractCreator(DOMDocument $dom)
    {
        try {
            $xpath = new \DOMXPath($dom);

            // Register common SCORM namespaces with error handling
            $this->registerScormNamespaces($xpath);

            // SCORM 2004: Look for lom:lom/lom:lifeCycle/lom:contribute/lom:entity
            // Priority 1: Creator role
            $entityNodes = $this->safeXPathQuery($xpath, '//lom:entity[ancestor::lom:contribute[lom:role/lom:value[text()="creator"]]]');
            if ($entityNodes && $entityNodes->length > 0) {
                return trim($entityNodes->item(0)->textContent);
            }

            // Priority 2: Author role
            $entityNodes = $this->safeXPathQuery($xpath, '//lom:entity[ancestor::lom:contribute[lom:role/lom:value[text()="author"]]]');
            if ($entityNodes && $entityNodes->length > 0) {
                return trim($entityNodes->item(0)->textContent);
            }

            // Priority 3: Publisher role
            $entityNodes = $this->safeXPathQuery($xpath, '//lom:entity[ancestor::lom:contribute[lom:role/lom:value[text()="publisher"]]]');
            if ($entityNodes && $entityNodes->length > 0) {
                return trim($entityNodes->item(0)->textContent);
            }

            // Priority 4: Any entity in contribute section
            $entityNodes = $this->safeXPathQuery($xpath, '//lom:entity[ancestor::lom:contribute]');
            if ($entityNodes && $entityNodes->length > 0) {
                return trim($entityNodes->item(0)->textContent);
            }

            // Priority 5: Any entity in metadata
            $entityNodes = $this->safeXPathQuery($xpath, '//lom:entity');
            if ($entityNodes && $entityNodes->length > 0) {
                return trim($entityNodes->item(0)->textContent);
            }

            // SCORM 1.2: Look for organization or other creator information
            // SCORM 1.2 typically doesn't have detailed creator metadata
            $orgNodes = $this->safeXPathQuery($xpath, '//organization');
            if ($orgNodes && $orgNodes->length > 0) {
                // For SCORM 1.2, we might not have creator info in manifest
                return null;
            }
        } catch (\Exception $e) {
            \Log::warning('extractCreator: Error extracting creator - ' . $e->getMessage());
        }

        return null;
    }

    /**
     * Format date string to ISO 8601 format
     */
    private function formatDate($dateString)
    {
        if (empty($dateString)) {
            return null;
        }

        // Try to parse the date and convert to ISO 8601
        try {
            $date = new \DateTime($dateString);
            return $date->format('c'); // ISO 8601 format
        } catch (\Exception $e) {
            // If parsing fails, return the original string
            return trim($dateString);
        }
    }

    /**
     * Register SCORM namespaces safely
     */
    private function registerScormNamespaces(\DOMXPath $xpath)
    {
        try {
            $xpath->registerNamespace('lom', 'http://ltsc.ieee.org/xsd/LOM');
            $xpath->registerNamespace('adlcp', 'http://www.adlnet.org/xsd/adlcp_v1p3');
            $xpath->registerNamespace('adlseq', 'http://www.adlnet.org/xsd/adlseq_v1p3');
            $xpath->registerNamespace('adlnav', 'http://www.adlnet.org/xsd/adlnav_v1p3');
        } catch (\Exception $e) {
            \Log::warning('registerScormNamespaces: Error registering namespaces - ' . $e->getMessage());
        }
    }

    /**
     * Execute XPath query safely with error handling
     */
    private function safeXPathQuery(\DOMXPath $xpath, $query)
    {
        try {
            return $xpath->query($query);
        } catch (\Exception $e) {
            \Log::warning('safeXPathQuery: Error executing XPath query "' . $query . '" - ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Fix XML entities by escaping unescaped ampersands while preserving CDATA sections
     * 
     * @param string $xml
     * @return string
     */
    private function fixXmlEntities($xml)
    {
        // Extract CDATA sections with placeholders
        $cdataSections = [];
        $xml = preg_replace_callback(
            '/<!\[CDATA\[(.*?)\]\]>/s',
            function ($matches) use (&$cdataSections) {
                $placeholder = '__CDATA_' . count($cdataSections) . '__';
                $cdataSections[$placeholder] = $matches[0];
                return $placeholder;
            },
            $xml
        );

        // Escape ampersands not part of valid XML entities
        // Valid: &name; &#123; &#xAB; &#XAB;
        $xml = preg_replace('/&(?!(?:[a-zA-Z][a-zA-Z0-9]*|#\d+|#[xX][0-9a-fA-F]+);)/', '&amp;', $xml);

        // Restore CDATA sections
        return str_replace(array_keys($cdataSections), $cdataSections, $xml);
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
