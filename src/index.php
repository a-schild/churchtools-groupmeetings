<?php declare(strict_types=1);

require __DIR__.'/../vendor/autoload.php';

use ChurchTools\Api\Tools\CalendarTools;
use League\Flysystem\Adapter\Local;
use League\Flysystem\Filesystem;
use Cache\Adapter\Filesystem\FilesystemCachePool;

use Jsvrcek\ICS\Model\Calendar;
use Jsvrcek\ICS\Model\CalendarEvent;
use Jsvrcek\ICS\Model\Description\Location;

use Jsvrcek\ICS\Utility\Formatter;
use Jsvrcek\ICS\CalendarStream;
use Jsvrcek\ICS\CalendarExport;

require_once 'KUWDaten.php';
include_once("config.php");
$filesystemAdapter = new Local(__DIR__.'/');
$filesystem        = new Filesystem($filesystemAdapter);


$cachePool = new FilesystemCachePool($filesystem);
$cacheKeyCalEntries= "kuwDaten";
$cacheKeyGroups= "kuwgroups";
$calendareEntries= array();
$visibleGroups= null;
$hasError= false;

if ($cachePool->hasItem($cacheKeyCalEntries) && $cachePool->hasItem($cacheKeyGroups))
{
    $calendareEntries= $cachePool->get($cacheKeyCalEntries);
    $selectedGroups=  $cachePool->get($cacheKeyGroups);
}
else
{
    $configs = include('config.php');
    session_start();
    $serverURL= $configs["serverURL"];
    $loginID= $configs["loginID"];
    $loginToken= $configs["loginToken"];

    $groupType= 5;
    $showLeader= 0;

    $errorMessage= null;
    try
    {
        $api = \ChurchTools\Api\RestApi::createWithLoginIdToken($serverURL, $loginID, $loginToken);
        $personMasterData= $api->getPersonMasterData();

        $visibleGroupTypes= $personMasterData->getGroupTypes();
        $visibleGroups= $personMasterData->getGroups();
        $selectedGroups= $visibleGroups->getGroupsOfType($groupType);
        $_SESSION["selectedGroups"]= $selectedGroups;

        foreach( $selectedGroups as $group) 
        {
            if ($group->getChildIDS() != null && sizeof($group->getChildIDS()) > 0)
            {
                // Has children, ignore it
            }
            else
            {
                $meetings= $api->getGroupMeetings($group->getId());
                if ($group->getParentIDS() != null && sizeof($group->getParentIDS()) > 0)
                {
                    // Has parents, look if there are groupmeetings too
                    foreach($group->getParentIDS() as $gid)
                    {
                        $meetings2= $api->getGroupMeetings($gid);
                        if ($meetings2 != null && sizeof($meetings2) > 0) {
                            if ($meetings != null && sizeof($meetings) > 0) {
                                $meetings= array_merge($meetings, $meetings2);
                            }
                            else
                            {
                                $meetings= $meetings2;
                            }
                        }
                    }
                }
                if ($meetings != null && sizeof($meetings) > 0) {
                    usort($meetings,
                        function ($a, $b)  {
                            return $a->getStartDate()->getTimestamp() > $b->getStartDate()->getTimestamp();
                    });
                    $myCalendar= array();
                    $calendarTitle= $group->getTitle();
                    foreach ($meetings as $meeting) 
                    { 
                        $pollResult= $meeting->getPollResult();
                        $remainingResults= [];
                        $untilTime= null;
                        $ort= null;
                        if ($pollResult != null) {
                            foreach ($pollResult as $result) {
                                $v= $result["value"];
                                if ($v != null && $v != "") {
                                    if ($result["label"] == "Bis" || $result["label"] == "Dauer")
                                    {
                                        $untilTime= $v;
                                    }
                                    elseif ($result["label"] == "Ort?" || $result["label"] == "Ort")
                                    {
                                        $ort= $v;
                                    }
                                    elseif ($result["label"] == "Anderer Ort")
                                    {
                                        $ort= $v;
                                    }
                                    else
                                    {
                                        $remainingResults[$result["label"]]= $v;
                                    }
                                }
                            }
                        }
                        $kuwEntry= new KUWDaten();
                        $kuwEntry->setUID($meeting->getID().".".$meeting->getGroupID());
                        $kuwEntry->setIsCanceled($meeting->isMeetingCanceled());
                        $kuwEntry->setStartDate($meeting->getStartDate());
                        if ($untilTime != null)
                        {
                            $kuwEntry->setEndTime($untilTime);
                        }
                        $isFirst= true;
                        if ($pollResult != null) {
                            
                            $addRemarks= "";
                            foreach ($remainingResults as $key => $result) {
                                if ($result != null && $result != "") {
                                    if ($isFirst) { $isFirst= false; } else { $addRemarks.= "<br>"; };
                                    if ($key == "Kommentar") {
                                            $addRemarks.= $result;
                                    }
                                    else{
                                            $addRemarks.= $key.": ".$result;
                                    }
                                }
                            }
                            if ($addRemarks != "")
                            {
                                $kuwEntry->setRemarks($addRemarks);
                            }
                        }
                        if ($ort != null) {
                            $kuwEntry->setLocation($ort);
                        }
                        array_push($myCalendar, $kuwEntry);
                    } 
                    $calendareEntries[$group->getTitle()]= $myCalendar;
                }
            }
        }
        $cachePool->set($cacheKeyCalEntries, $calendareEntries, 3600);
        $cachePool->set($cacheKeyGroups, $visibleGroups, 3600);
    }
    catch (Exception $e)
    {
        $errorMessage= $e->getMessage();
        $hasError= true;
        session_destroy();
    }
} 
if (!$hasError && isset($_REQUEST["format"]) && $_REQUEST["format"] == "ics")
{
    //setup calendar
    $calendar = new Calendar();
    $tzDate = new DateTimeZone('Europe/Zurich');
    $calendar->setTimezone($tzDate);
    $calendar->setProdId('-//Ref-Nidau//EN');
    $i= 0;
    $uidEntries= array();
    foreach ($calendareEntries as $calName => $calEntries)
    {   $isFirst= true;
        foreach ($calEntries as $meeting) {
            $uuid= $meeting->getUID();
            if (in_array($uuid, $uidEntries))
            {
                // Already in calendar, skip (Parent stuff)
            }
            else 
            {
                array_push($uidEntries, $uuid);
                $calSummary= $calName;
                
                preg_match("/(\d*)\.(\d*)/", $uuid, $matches, PREG_OFFSET_CAPTURE);
                $groupID= $matches[2][0];
                
                $group= $selectedGroups->getGroupByID($groupID);
                $calSummary= $group->getTitle();
                $eventobj = new CalendarEvent();
                $eventobj->setUid($meeting->getUID()."@ref-nidau.ch");
                $eventobj->setStart($meeting->getStartDate());

                $endTime= $meeting->getEndDateTime();
                if ($endTime != null)
                {
                    $eventobj->setSummary($calSummary);
                    $eventobj->setEnd($endTime);
                }
                else
                {
                    $eventobj->setSummary($calSummary . " : " .$meeting->getEndTime());
                }

                if ($meeting->getLocation() != null && $meeting->getLocation() != "")
                {
                    $evLocation= new Location();
                    $evLocation->setName($meeting->getLocation());
                    $eventobj->addLocation($evLocation);
                    // Add description
                    $eventobj->setDescription($meeting->getRemarks()."\nOrt: ".$meeting->getLocation());
                }
                else
                {
                    // Add description
                    $eventobj->setDescription($meeting->getRemarks());
                }
                $calendar->addEvent($eventobj);
            }
        }
    }
    header("content-type:text/calendar");
    header('Content-Disposition: attachment; filename="kuwdaten-ref-nidau.ics"');
    //setup exporter
    $calendarExport = new CalendarExport(new CalendarStream, new Formatter());
    $calendarExport->addCalendar($calendar);

    //output .ics formatted text
    echo $calendarExport->getStream();
}
else
{
    $now= new DateTime();
?>
    <!doctype html>
    <html>
        <head>
            <meta charset="utf-8">
            <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
            <title>KUW Daten Reformierte Kirchgemeinde Nidau</title>
            <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.3.1/css/bootstrap.min.css" integrity="sha384-ggOyR0iXCbMQv3Xipma34MD+dH/1fQ784/j6cY/iJTQUOhcWr7x9JvoRxT2MZw1T" crossorigin="anonymous">
            <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/4.7.0/css/font-awesome.min.css" integrity="sha256-eZrrJcwDc/3uDhsdt61sL2oOBY362qM3lon1gyExkL0=" crossorigin="anonymous" />        
            <link rel="stylesheet" href="styles.css">
            <script>
            function togglePast() {
                var pastElements = document.getElementsByClassName("ispast");
                Array.prototype.forEach.call(pastElements, function(el) {
                    if (el.style.display === "flex") {
                      el.style.display = "none";
                    } else {
                      el.style.display = "flex";
                    }
                });
              } 
              </script>
        </head>
        <body>
            <div class="container">
                <div class="row toprow  align-items-center">
                    <h1 class="col-8"><a href="https://ref-nidau.ch"><img src="RKN_Logo_D_weiss-150px.png"></a> KUW Daten</h1>
                    <div class="col-4  text-right"><a class="pastbutton" onclick="togglePast(); return false;">Vergangene Termine Anzeigen</a></div>
                </div>
                <?php if ($hasError) { ?>
                <h2>Login fehlgeschlagen</h2>
                <div class="alert alert-danger" role="alert">
                Error in login: <?= $errorMessage ?>
                </div>
                <div>
                    <a href="index.php" class="btn btn-primary">Zum Login</a>
                </div>
                <?php } else { 
                    foreach ($calendareEntries as $calName => $calEntries)
                    {   $isFirst= true;
                        ?>
                        <h2 class="group-title"><?= $calName ?></h2>
                            <?php foreach ($calEntries as $meeting) { 
                                $isPast= $now > $meeting->getStartDate(); ?>
                            <div class="row <?= $isPast ? "ispast" : "" ?>">
                            <?php if ($meeting->isCanceled()) { ?><strike><?php } ?>
                            <div class="col-4 <?= $meeting->isCanceled() ? "bg-danger" : "" ?>"><?= $meeting->getStartDate()->format("d.m.Y H:i") ?> <?= ($meeting->getEndTime() != null ? "- ".$meeting->getEndTime()." " : "")?>
                                <?php if ($meeting->isCanceled()) { ?></strike><br />Treffen wurde abgesagt</span><?php } ?>
                            </div>
                                <div class="col-8">
                            <?php if ($meeting->getRemarks() != "") {
                                echo $meeting->getRemarks();
                                    $isFirst= false;
                            
                                }
                                if ($meeting->getLocation() != null) {
                                    if ($isFirst) { $isFirst= false; } else { echo "<br>"; };
                                    ?>Ort: <?= $meeting->getLocation() ?><?php } ?>
                            <?php if ($meeting->isCanceled()) { ?></strike><?php } ?>
                            </div>
                            </div>
                            <?php } 
                    } 
                } ?>
                <h2 class="footer"><a href="https://ref-nidau.ch">Reformierte Kirchgemeinde Nidau</a></h2>
            </div>
            <script src="https://code.jquery.com/jquery-3.3.1.slim.min.js" integrity="sha384-q8i/X+965DzO0rT7abK41JStQIAqVgRVzpbzo5smXKp4YfRvH+8abtTE1Pi6jizo" crossorigin="anonymous"></script>
            <script src="https://cdnjs.cloudflare.com/ajax/libs/popper.js/1.14.7/umd/popper.min.js" integrity="sha384-UO2eT0CpHqdSJQ6hJty5KVphtPhzWj9WO1clHTMGa3JDZwrnQq4sF86dIHNDz0W1" crossorigin="anonymous"></script>
            <script src="https://stackpath.bootstrapcdn.com/bootstrap/4.3.1/js/bootstrap.min.js" integrity="sha384-JjSmVgyd0p3pXB1rRibZUAYoIIy6OrQ6VrjIEaFf/nJGzIxFDsf4x0xIM+B07jRM" crossorigin="anonymous"></script>
        </body>
    </html>
<?php } ?>