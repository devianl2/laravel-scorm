<?php


namespace Peopleaps\Scorm\Entity;


use Carbon\Carbon;

class ScoTracking
{
    public $userId;
    public $uuid;
    public $sco;
    public $scoreRaw;
    public $scoreMin;
    public $scoreMax;
    public $progression = 0;
    public $scoreScaled;
    public $lessonStatus = 'unknown';
    public $completionStatus = 'unknown';
    public $sessionTime;
    public $totalTimeInt;
    public $totalTimeString;
    public $entry;
    public $suspendData;
    public $credit;
    public $exitMode;
    public $lessonLocation;
    public $lessonMode;
    public $isLocked;
    public $details;
    public $latestDate;

    public function getUserId()
    {
        return $this->userId;
    }

    public function setUserId($userId)
    {
        $this->userId = $userId;
    }

    public function getUuid()
    {
        return $this->uuid;
    }

    public function setUuid($uuid)
    {
        $this->uuid = $uuid;
    }

    /**
     * @return Sco
     */
    public function getSco()
    {
        return $this->sco;
    }

    public function setSco($sco)
    {
        $this->sco = $sco;
    }

    public function getScoreRaw()
    {
        return $this->scoreRaw;
    }

    public function setScoreRaw($scoreRaw)
    {
        $this->scoreRaw = $scoreRaw;
    }

    public function getScoreMin()
    {
        return $this->scoreMin;
    }

    public function setScoreMin($scoreMin)
    {
        $this->scoreMin = $scoreMin;
    }

    public function getScoreMax()
    {
        return $this->scoreMax;
    }

    public function setScoreMax($scoreMax)
    {
        $this->scoreMax = $scoreMax;
    }

    public function getScoreScaled()
    {
        return $this->scoreScaled;
    }

    public function setScoreScaled($scoreScaled)
    {
        $this->scoreScaled = $scoreScaled;
    }

    public function getLessonStatus()
    {
        return $this->lessonStatus;
    }

    public function setLessonStatus($lessonStatus)
    {
        $this->lessonStatus = $lessonStatus;
    }

    public function getCompletionStatus()
    {
        return $this->completionStatus;
    }

    public function setCompletionStatus($completionStatus)
    {
        $this->completionStatus = $completionStatus;
    }

    public function getSessionTime()
    {
        return $this->sessionTime;
    }

    public function setSessionTime($sessionTime)
    {
        $this->sessionTime = $sessionTime;
    }

    public function getTotalTime($scormVersion)
    {
        if (Scorm::SCORM_2004 === $scormVersion) {
            return $this->totalTimeString;
        } else {
            return $this->totalTimeInt;
        }
    }

    public function setTotalTime($totalTime, $scormVersion)
    {
        if (Scorm::SCORM_2004 === $scormVersion) {
            $this->setTotalTimeString($totalTime);
        } else {
            $this->setTotalTimeInt($totalTime);
        }
    }

    public function getTotalTimeInt()
    {
        return $this->totalTimeInt;
    }

    public function setTotalTimeInt($totalTimeInt)
    {
        $this->totalTimeInt = $totalTimeInt;
    }

    public function getTotalTimeString()
    {
        return $this->totalTimeString;
    }

    public function setTotalTimeString($totalTimeString)
    {
        $this->totalTimeString = $totalTimeString;
    }

    public function getEntry()
    {
        return $this->entry;
    }

    public function setEntry($entry)
    {
        $this->entry = $entry;
    }

    public function getSuspendData()
    {
        return $this->suspendData;
    }

    public function setSuspendData($suspendData)
    {
        $this->suspendData = $suspendData;
    }

    public function getCredit()
    {
        return $this->credit;
    }

    public function setCredit($credit)
    {
        $this->credit = $credit;
    }

    public function getExitMode()
    {
        return $this->exitMode;
    }

    public function setExitMode($exitMode)
    {
        $this->exitMode = $exitMode;
    }

    public function getLessonLocation()
    {
        return $this->lessonLocation;
    }

    public function setLessonLocation($lessonLocation)
    {
        $this->lessonLocation = $lessonLocation;
    }

    public function getLessonMode()
    {
        return $this->lessonMode;
    }

    public function setLessonMode($lessonMode)
    {
        $this->lessonMode = $lessonMode;
    }

    public function getIsLocked()
    {
        return $this->isLocked;
    }

    public function setIsLocked($isLocked)
    {
        $this->isLocked = $isLocked;
    }

    public function getDetails()
    {
        return $this->details;
    }

    public function setDetails($details)
    {
        $this->details = $details;
    }

    public function getLatestDate()
    {
        return $this->latestDate;
    }

    public function setLatestDate(Carbon $latestDate = null)
    {
        $this->latestDate = $latestDate;
    }

    public function getProgression()
    {
        return $this->progression;
    }

    public function setProgression($progression)
    {
        $this->progression = $progression;
    }

    public function getFormattedTotalTime()
    {
        if (Scorm::SCORM_2004 === $this->sco->getScorm()->getVersion()) {
            return $this->getFormattedTotalTimeString();
        } else {
            return $this->getFormattedTotalTimeInt();
        }
    }

    public function getFormattedTotalTimeInt()
    {
        $remainingTime = $this->totalTimeInt;
        $hours = intval($remainingTime / 360000);
        $remainingTime %= 360000;
        $minutes = intval($remainingTime / 6000);
        $remainingTime %= 6000;
        $seconds = intval($remainingTime / 100);
        $remainingTime %= 100;

        $formattedTime = '';

        if ($hours < 10) {
            $formattedTime .= '0';
        }
        $formattedTime .= $hours.':';

        if ($minutes < 10) {
            $formattedTime .= '0';
        }
        $formattedTime .= $minutes.':';

        if ($seconds < 10) {
            $formattedTime .= '0';
        }
        $formattedTime .= $seconds.'.';

        if ($remainingTime < 10) {
            $formattedTime .= '0';
        }
        $formattedTime .= $remainingTime;

        return $formattedTime;
    }

    public function getFormattedTotalTimeString()
    {
        $pattern = '/^P([0-9]+Y)?([0-9]+M)?([0-9]+D)?T([0-9]+H)?([0-9]+M)?([0-9]+S)?$/';
        $formattedTime = '';

        if (!empty($this->totalTimeString) && 'PT' !== $this->totalTimeString && preg_match($pattern, $this->totalTimeString)) {
            $interval = new \DateInterval($this->totalTimeString);
            $time = new \DateTime();
            $time->setTimestamp(0);
            $time->add($interval);
            $timeInSecond = $time->getTimestamp();

            $hours = intval($timeInSecond / 3600);
            $timeInSecond %= 3600;
            $minutes = intval($timeInSecond / 60);
            $timeInSecond %= 60;

            if ($hours < 10) {
                $formattedTime .= '0';
            }
            $formattedTime .= $hours.':';

            if ($minutes < 10) {
                $formattedTime .= '0';
            }
            $formattedTime .= $minutes.':';

            if ($timeInSecond < 10) {
                $formattedTime .= '0';
            }
            $formattedTime .= $timeInSecond;
        } else {
            $formattedTime .= '00:00:00';
        }

        return $formattedTime;
    }
}
