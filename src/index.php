<?php
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use CTApi\CTConfig;
use CTApi\CTLog;
use CTApi\Models\GroupRole;
use CTApi\Models\GroupSettings;
use CTApi\Models\Groups\Group;
use CTApi\Models\Groups\Group\GroupRequest;
use CTApi\Models\GroupMeeting;
use CTApi\Models\GroupMeetingMember;
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

$cacheLocation = sys_get_temp_dir() . '/kuwdaten/';

$filesystemAdapter = new Local($cacheLocation);
$filesystem = new Filesystem($filesystemAdapter);

if (!file_exists($cacheLocation)) {
    mkdir($cacheLocation);
}
$cachePool = new FilesystemCachePool($filesystem);
$cacheKeyCalEntries = "kuwDaten";
$cacheKeyGroups = "kuwgroups";
$calendareEntries = array();
$visibleGroups = null;
$hasError = false;

$configs = include('config.php');
if ($configs["loggingToFileEnabled"]) {
    CTLog::enableFileLog(); // enable logfile
}
if ($configs["loggingToConsoleEnabled"]) {
    CTLog::enableConsoleLog();
    if ($configs["loggingLevelDebug"]) {
        CTLog::setConsoleLogLevelDebug();
    }
}

if ($cachePool->hasItem($cacheKeyCalEntries) && $cachePool->hasItem($cacheKeyGroups)) {
    CTLog::getLog()->info("Loading data from existing cache at ". $cacheLocation);
    $calendareEntries = $cachePool->get($cacheKeyCalEntries);
    $selectedGroups = $cachePool->get($cacheKeyGroups);
} else {
    CTLog::getLog()->info("Loading data from church tool server, not found cached data at ".$cacheLocation);
    set_time_limit(60); // Allow more time to retrieve entries
    session_start();
    $serverURL = $configs["serverURL"];
    if (array_key_exists("loginToken", $configs)) {
        $loginToken = $configs["loginToken"];
    } else {
        $loginUser = $configs["loginUser"];
        $loginPassword = $configs["loginPassword"];
    }

    $groupType = $configs["groupType"]; // 5 = KUW
    $showLeader = 0;

    $errorMessage = null;
    try {
        CTConfig::setApiUrl($serverURL);
        if (isset($loginToken)) {
            // CTConfig::setApiKey($loginToken);
            if (!CTConfig::authWithLoginToken($loginToken)) {
                echo "Token login failed";
                CTLog::getLog()->error("Token login failed!");
                die;
            } else {
                CTLog::getLog()->debug("Token login ok");
            }
        } else {
            CTConfig::authWithCredentials(
                $loginUser,
                $loginPassword);
        }
        //$api = \ChurchTools\Api\RestApi::createWithLoginIdToken($serverURL, $loginID, $loginToken);
        //$personMasterData= $api->getPersonMasterData();
        $selectedGroups = GroupRequest::where("group_type_ids", [$groupType])->get();
        $_SESSION["selectedGroups"] = $selectedGroups;
        $sDate = new \DateTime('now');
        $sDate->sub(new \DateInterval('P1Y'));
        $fromDate= $sDate->format('Y-m-d');
        $eDate = new \DateTime('now');
        $eDate->add(new \DateInterval('P1Y'));
        $endDate= $eDate->format('Y-m-d');
        
        foreach ($selectedGroups as $group) {
            $childGroups = $group->requestGroupChildren()->get();
            if ($childGroups != null && sizeof($childGroups) > 0) {
                // Has children, ignore it
                CTLog::getLog()->debug("Ignoring parent group [" . $group->getName() . "] id [" . $group->getId() . "]");
            } else {
                CTLog::getLog()->debug("Get meetings for group [" . $group->getName() . "] id [" . $group->getId() . "]");
                try {
                    $meetings = $group->requestGroupMeetings()?->where("start_date", $fromDate)
                        ->where("end_date", $endDate)->get();
                    CTLog::getLog()->debug("Got ".sizeof($meetings)." meetings for group [" . $group->getName() . "] id [" . $group->getId() . "]");
                } catch (Exception $e) {
                    $meetings = null;
                    CTLog::getLog()->warning("Get meetings for group [" . $group->getName() . "] id [" . $group->getId() . "] failed");
                }
                $parentGroups = $group->requestGroupParents()->get();
                if ($parentGroups != null && sizeof($parentGroups) > 0) {
                    CTLog::getLog()->debug("Got parent groups for [" . $group->getName() . "] id [" . $group->getId() . "]");
                    // Has parents, look if there are groupmeetings too
                    foreach ($parentGroups as $gid) {
                        CTLog::getLog()->debug("Get meetings for group [" . $gid->getName() . "] id [" . $gid->getId() . "]");
                        $meetings2 = $gid->requestGroupMeetings()?->where("start_date", $fromDate)
                        ->where("end_date", $endDate)->get();
                        if ($meetings2 != null && sizeof($meetings2) > 0) {
                            if ($meetings != null && sizeof($meetings) > 0) {
                                $meetings = array_merge($meetings, $meetings2);
                            } else {
                                $meetings = $meetings2;
                            }
                        } else {
                            CTLog::getLog()->debug("Got no groups meetings for [" . $gid->getName() . "] id [" . $gid->getId() . "]");
                        }
                    }
                } else {
                    CTLog::getLog()->debug("No parent groups for [" . $group->getName() . "] id [" . $group->getId() . "]");
                }
                //var_dump($meetings);
                if ($meetings != null && sizeof($meetings) > 0) {
                    usort($meetings,
                        function ($a, $b) {
                            return strtotime($a->getDateFrom()) - strtotime($b->getDateFrom());
                        });
                    $myCalendar = array();
                    $calendarTitle = $group->getName();
                    foreach ($meetings as $meeting) {
                        $pollResult = $meeting->getPollResult();
                        $remainingResults = [];
                        $untilTime = null;
                        $ort = null;
                        if ($pollResult != null) {
                            foreach ($pollResult as $result) {
                                $v = $result["value"];
                                if ($v != null && $v != "") {
                                    if ($result["label"] == "Bis" || $result["label"] == "Dauer") {
                                        $untilTime = $v;
                                    } elseif ($result["label"] == "Ort?" || $result["label"] == "Ort") {
                                        $ort = $v;
                                    } elseif ($result["label"] == "Anderer Ort") {
                                        $ort = $v;
                                    } else {
                                        $remainingResults[$result["label"]] = $v;
                                    }
                                }
                            }
                        }
                        $kuwEntry = new KUWDaten();
                        $kuwEntry->setClassTitle($calendarTitle);
                        $kuwEntry->setUID($meeting->getID() . "." . $meeting->getGroupID());
                        $kuwEntry->setIsCanceled($meeting->getIsCanceled());
                        $kuwEntry->setStartDate(new DateTime($meeting->getDateFrom(), new DateTimeZone("UTC")));
                        if ($untilTime != null) {
                            $kuwEntry->setEndTime(new DateTime($untilTime, new DateTimeZone("UTC")));
                        }
                        $isFirst = true;
                        if ($pollResult != null) {

                            $addRemarks = "";
                            foreach ($remainingResults as $key => $result) {
                                if ($result != null && $result != "") {
                                    if ($isFirst) {
                                        $isFirst = false;
                                    } else {
                                        $addRemarks .= "<br>";
                                    }
                                    if ($key == "Kommentar") {
                                        $addRemarks .= $result;
                                    } else {
                                        $addRemarks .= $key . ": " . $result;
                                    }
                                }
                            }
                            if ($addRemarks != "") {
                                $kuwEntry->setRemarks($addRemarks);
                            }
                        }
                        if ($ort != null) {
                            $kuwEntry->setLocation($ort);
                        }
                        array_push($myCalendar, $kuwEntry);
                    }
                    $calendareEntries[$group->getName()] = $myCalendar;
                } else {
                    CTLog::getLog()->warning("Got NO meetings for group [" . $group->getName() . "] id [" . $group->getId() . "]");
                }
            }
        }
        $cachePool->set($cacheKeyCalEntries, $calendareEntries, $configs["cacheLifeTime"]);
        $cachePool->set($cacheKeyGroups, $visibleGroups, $configs["cacheLifeTime"]);
    } catch (Exception $e) {
        $errorMessage = $e->getMessage();
        $hasError = true;
        session_destroy();
    }
}
if (!$hasError && isset($_REQUEST["format"]) && $_REQUEST["format"] == "ics") {
    //setup calendar
    $calendar = new Calendar();
    $tzDate = new DateTimeZone('Europe/Zurich');
    $calendar->setTimezone($tzDate);
    $calendar->setProdId('-//Ref-Nidau//EN');
    $i = 0;
    $uidEntries = array();
    foreach ($calendareEntries as $calName => $calEntries) {
        $isFirst = true;
        foreach ($calEntries as $meeting) {
            $uuid = $meeting->getUID();
            if (in_array($uuid, $uidEntries)) {
                // Already in calendar, skip (Parent stuff)
            } else {
                array_push($uidEntries, $uuid);
//                $calSummary= $calName;
                $calSummary = $meeting->getClassTitle();
//                preg_match("/(\d*)\.(\d*)/", $uuid, $matches, PREG_OFFSET_CAPTURE);
//                $groupID= $matches[2][0];
//                if ($selectedGroups != null) {
//                    $group= $selectedGroups->getGroupByID($groupID);
//                    $calSummary= $group->getName();
//                } else {
//                    $calSummary= "KUW";
//                }
                $eventobj = new CalendarEvent();
                $eventobj->setUid($meeting->getUID() . "@ref-nidau.ch");
				$meeting->getStartDate()->setTimezone($tzDate);

                $eventobj->setStart($meeting->getStartDate());

                $endTime = $meeting->getEndDateTime();
                if ($endTime != null) {
					$endTime->setTimezone($tzDate);
                    $eventobj->setSummary($calSummary);
                    $eventobj->setEnd($endTime);
                } else {
                    $eventobj->setSummary($calSummary . " : " . $meeting->getEndTime());
                }

                if ($meeting->getLocation() != null && $meeting->getLocation() != "") {
                    $evLocation = new Location();
                    $evLocation->setName($meeting->getLocation());
                    $eventobj->addLocation($evLocation);
                    // Add description
                    $eventobj->setDescription($meeting->getRemarks() . "\nOrt: " . $meeting->getLocation());
                } else {
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
} else {
    $now = new DateTime();
    $tzDate = new DateTimeZone('Europe/Zurich');

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
                    Array.prototype.forEach.call(pastElements, function (el) {
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
    <?php
    } else {
        foreach ($calendareEntries as $calName => $calEntries) {
            $isFirst = true;

            ?>
                        <h2 class="group-title"><?= $calName ?></h2>
            <?php foreach ($calEntries as $meeting) {
                $isPast = $now > $meeting->getStartDate();
				$meeting->getStartDate()->setTimezone($tzDate);
                ?>
                            <div class="row <?= $isPast ? "ispast" : "" ?>">
                <?php if ($meeting->isCanceled()) { ?><strike><?php } ?>
                                    <div class="col-4 <?= $meeting->isCanceled() ? "bg-danger" : "" ?>"><?= $meeting->getStartDate()->format("d.m.Y H:i e") ?> <?= ($meeting->getEndTime() != null ? "- " . $meeting->getEndTime() . " " : "") ?>
                <?php if ($meeting->isCanceled()) { ?></strike><br />Treffen wurde abgesagt</span><?php } ?>
                            </div>
                            <div class="col-8">
                <?php
                if ($meeting->getRemarks() != "") {
                    echo $meeting->getRemarks();
                    $isFirst = false;
                }
                if ($meeting->getLocation() != null) {
                    if ($isFirst) {
                        $isFirst = false;
                    } else {
                        echo "<br>";
                    };

                    ?>Ort: <?= $meeting->getLocation() ?><?php } ?>
                <?php if ($meeting->isCanceled()) { ?></strike><?php } ?>
                            </div>
                        </div>
                        <?php
                        }
                    }
                }

                ?>
            <h2 class="footer"><a href="https://ref-nidau.ch">Reformierte Kirchgemeinde Nidau</a></h2>
        </div>
        <script src="https://code.jquery.com/jquery-3.3.1.slim.min.js" integrity="sha384-q8i/X+965DzO0rT7abK41JStQIAqVgRVzpbzo5smXKp4YfRvH+8abtTE1Pi6jizo" crossorigin="anonymous"></script>
        <script src="https://cdnjs.cloudflare.com/ajax/libs/popper.js/1.14.7/umd/popper.min.js" integrity="sha384-UO2eT0CpHqdSJQ6hJty5KVphtPhzWj9WO1clHTMGa3JDZwrnQq4sF86dIHNDz0W1" crossorigin="anonymous"></script>
        <script src="https://stackpath.bootstrapcdn.com/bootstrap/4.3.1/js/bootstrap.min.js" integrity="sha384-JjSmVgyd0p3pXB1rRibZUAYoIIy6OrQ6VrjIEaFf/nJGzIxFDsf4x0xIM+B07jRM" crossorigin="anonymous"></script>
    </body>
    </html>
            <?php } ?>