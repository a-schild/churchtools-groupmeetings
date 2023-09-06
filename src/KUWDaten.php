<?php

/*
 * To change this license header, choose License Headers in Project Properties.
 * To change this template file, choose Tools | Templates
 * and open the template in the editor.
 */

/**
 * Description of KUWDaten
 *
 * @author andre
 */
class KUWDaten {
    private $startDate;
    private $endTime;
    private $classTitle;
    private $title;
    private $location;
    private $remarks;
    private $uid;
    private $isCanceled= false;
    private $isEntryFromParents= false;
    
    public function setStartDate(\DateTime $startDate)
    {
        $this->startDate= $startDate;
    }
    
    public function getStartDate() : \DateTime
    {
        return $this->startDate;
    }

    public function setEndTime(string $endTime)
    {
        $this->endTime= $endTime;
    }
    
    public function getEndTime(): ?string
    {
        return $this->endTime;
    }
    
    public function getEndDateTime(): ?\DateTime
    {
        if ($this->endTime != null && $this->endTime != "")
        {
            $retVal= clone $this->getStartDate();
            $etValue= $this->endTime;
            if (strlen($etValue) == 5)
            {
                $retVal->setTime(substr($etValue, 0, 2), substr($etValue, 3,2));
            }
            
            return $retVal;
        }
        else
        {
            return null;
        }
    }
    
    public function setTitle(string $title)
    {
        $this->title= $title;
    }
    
    public function getTitle(): ?string
    {
        return $this->title;
    }
    
    public function setClassTitle(string $classTitle)
    {
        $this->classTitle= $classTitle;
    }
    
    public function getClassTitle(): ?string
    {
        return $this->classTitle;
    }
    
    public function setLocation(string $location)
    {
        $this->location= $location;
    }
    
    public function getLocation(): ?string
    {
        return $this->location;
    }
    
    public function setRemarks(string $remarks)
    {
        $this->remarks= $remarks;
    }
    
    public function getRemarks(): ?string
    {
        return $this->remarks;
    }
    
    public function setIsCanceled(bool $isCanceled)
    {
        $this->isCanceled= $isCanceled;
    }
    
    public function isCanceled() :bool
    {
        return $this->isCanceled;
    }
    
    public function isEntryFromParents() :bool
    {
        return $this->isEntryFromParents;
    }
    
    public function setIsEntryFromParents(bool $isEntryFromParents)
    {
        $this->isEntryFromParents= $isEntryFromParents;
    }
    
    public function setUID(string $uid)
    {
        $this->uid= $uid;
    }
    
    public function getUID() : string
    {
        return $this->uid;
    }
}
