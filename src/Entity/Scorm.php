<?php


namespace Peopleaps\Scorm\Entity;


use Doctrine\Common\Collections\ArrayCollection;

class Scorm
{
    const SCORM_12 = 'scorm_12';
    const SCORM_2004 = 'scorm_2004';

    public $uuid;
    public $id;
    public $version;
    public $hashName;
    public $ratio = 56.25;
    public $scos;
    public $scoSerializer;

    /**
     * @return string
     */
    public function getUuid()
    {
        return $this->uuid;
    }

    /**
     * @param int $id
     */
    public function setUuid($uuid)
    {
        $this->uuid = $uuid;
    }

    /**
     * @return int
     */
    public function getId()
    {
        return $this->id;
    }

    /**
     * @param int $id
     */
    public function setId($id)
    {
        $this->id = $id;
    }

    /**
     * @return string
     */
    public function getVersion()
    {
        return $this->version;
    }

    /**
     * @param string $version
     */
    public function setVersion($version)
    {
        $this->version = $version;
    }

    /**
     * @return string
     */
    public function getHashName()
    {
        return $this->hashName;
    }

    /**
     * @param string $hashName
     */
    public function setHashName($hashName)
    {
        $this->hashName = $hashName;
    }

    /**
     * @return float
     */
    public function getRatio()
    {
        return $this->ratio;
    }

    /**
     * @param float $ratio
     */
    public function setRatio($ratio)
    {
        $this->ratio = $ratio;
    }

    /**
     * @return Sco[]
     */
    public function getScos()
    {
        return $this->scos;
    }

    /**
     * @return Sco[]
     */
    public function getRootScos()
    {
        $roots = [];

        if (!empty($this->scos)) {
            foreach ($this->scos as $sco) {
                if (is_null($sco->getScoParent())) {
                    // Root sco found
                    $roots[] = $sco;
                }
            }
        }

        return $roots;
    }

    public function serialize(Scorm $scorm)
    {
        return [
            'id' => $scorm->getUuid(),
            'version' => $scorm->getVersion(),
            'hashName' => $scorm->getHashName(),
            'ratio' => $scorm->getRatio(),
            'scos' => $this->serializeScos($scorm),
        ];
    }

    private function serializeScos(Scorm $scorm)
    {
        return array_map(function (Sco $sco) {
            return $sco->serialize($sco);
        }, $scorm->getRootScos());
    }
}
