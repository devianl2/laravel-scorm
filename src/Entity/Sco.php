<?php


namespace Peopleaps\Scorm\Entity;


class Sco
{
    public $id;
    public $uuid;
    public $scorm;
    public $scoParent;
    public $scoChildren;
    public $entryUrl;
    public $identifier;
    public $title;
    public $visible;
    public $parameters;
    public $launchData;
    public $maxTimeAllowed;
    public $timeLimitAction;
    public $block;
    public $scoreToPassInt;
    public $scoreToPassDecimal;
    public $completionThreshold;
    public $prerequisites;

    public function getData() {
        return  [
            'id'    =>  $this->getId(),
            'scorm'    =>  $this->getScorm(),
            'scoParent'    =>  $this->getScoParent(),
            'entryUrl'    =>  $this->getEntryUrl(),
            'identifier'    =>  $this->getIdentifier(),

        ];
    }

    public function getUuid()
    {
        return $this->uuid;
    }

    public function setUuid($uuid)
    {
        $this->uuid = $uuid;
    }

    public function getId()
    {
        return $this->id;
    }

    public function setId($id)
    {
        $this->id = $id;
    }

    public function getScorm()
    {
        return $this->scorm;
    }

    public function setScorm(Scorm $scorm = null)
    {
        $this->scorm = $scorm;
    }

    public function getScoParent()
    {
        return $this->scoParent;
    }

    public function setScoParent(Sco $scoParent = null)
    {
        $this->scoParent = $scoParent;
    }

    public function getScoChildren()
    {
        return $this->scoChildren;
    }

    public function setScoChildren($scoChildren)
    {
        $this->scoChildren = $scoChildren;
    }

    public function getEntryUrl()
    {
        return $this->entryUrl;
    }

    public function setEntryUrl($entryUrl)
    {
        $this->entryUrl = $entryUrl;
    }

    public function getIdentifier()
    {
        return $this->identifier;
    }

    public function setIdentifier($identifier)
    {
        $this->identifier = $identifier;
    }

    public function getTitle()
    {
        return $this->title;
    }

    public function setTitle($title)
    {
        $this->title = $title;
    }

    public function isVisible()
    {
        return $this->visible;
    }

    public function setVisible($visible)
    {
        $this->visible = $visible;
    }

    public function getParameters()
    {
        return $this->parameters;
    }

    public function setParameters($parameters)
    {
        $this->parameters = $parameters;
    }

    public function getLaunchData()
    {
        return $this->launchData;
    }

    public function setLaunchData($launchData)
    {
        $this->launchData = $launchData;
    }

    public function getMaxTimeAllowed()
    {
        return $this->maxTimeAllowed;
    }

    public function setMaxTimeAllowed($maxTimeAllowed)
    {
        $this->maxTimeAllowed = $maxTimeAllowed;
    }

    public function getTimeLimitAction()
    {
        return $this->timeLimitAction;
    }

    public function setTimeLimitAction($timeLimitAction)
    {
        $this->timeLimitAction = $timeLimitAction;
    }

    public function isBlock()
    {
        return $this->block;
    }

    public function setBlock($block)
    {
        $this->block = $block;
    }

    public function getScoreToPass()
    {
        if (Scorm::SCORM_2004 === $this->scorm->getVersion()) {
            return $this->scoreToPassDecimal;
        } else {
            return $this->scoreToPassInt;
        }
    }

    public function setScoreToPass($scoreToPass)
    {
        if (Scorm::SCORM_2004 === $this->scorm->getVersion()) {
            $this->setScoreToPassDecimal($scoreToPass);
        } else {
            $this->setScoreToPassInt($scoreToPass);
        }
    }

    public function getScoreToPassInt()
    {
        return $this->scoreToPassInt;
    }

    public function setScoreToPassInt($scoreToPassInt)
    {
        $this->scoreToPassInt = $scoreToPassInt;
    }

    public function getScoreToPassDecimal()
    {
        return $this->scoreToPassDecimal;
    }

    public function setScoreToPassDecimal($scoreToPassDecimal)
    {
        $this->scoreToPassDecimal = $scoreToPassDecimal;
    }

    public function getCompletionThreshold()
    {
        return $this->completionThreshold;
    }

    public function setCompletionThreshold($completionThreshold)
    {
        $this->completionThreshold = $completionThreshold;
    }

    public function getPrerequisites()
    {
        return $this->prerequisites;
    }

    public function setPrerequisites($prerequisites)
    {
        $this->prerequisites = $prerequisites;
    }

    /**
     * @return array
     */
    public function serialize(Sco $sco)
    {
        $scorm = $sco->getScorm();
        $parent = $sco->getScoParent();

        return [
            'id' => $sco->getUuid(),
            'scorm' => !empty($scorm) ? ['id' => $scorm->getUuid()] : null,
            'data' => [
                'entryUrl' => $sco->getEntryUrl(),
                'identifier' => $sco->getIdentifier(),
                'title' => $sco->getTitle(),
                'visible' => $sco->isVisible(),
                'parameters' => $sco->getParameters(),
                'launchData' => $sco->getLaunchData(),
                'maxTimeAllowed' => $sco->getMaxTimeAllowed(),
                'timeLimitAction' => $sco->getTimeLimitAction(),
                'block' => $sco->isBlock(),
                'scoreToPassInt' => $sco->getScoreToPassInt(),
                'scoreToPassDecimal' => $sco->getScoreToPassDecimal(),
                'scoreToPass' => !empty($scorm) ? $sco->getScoreToPass() : null,
                'completionThreshold' => $sco->getCompletionThreshold(),
                'prerequisites' => $sco->getPrerequisites(),
            ],
            'parent' => !empty($parent) ? ['id' => $parent->getUuid()] : null,
            'children' => array_map(function (Sco $scoChild) {
                return $this->serialize($scoChild);
            }, is_array($sco->getScoChildren()) ? $sco->getScoChildren() : $sco->getScoChildren()->toArray()),
        ];
    }
}
