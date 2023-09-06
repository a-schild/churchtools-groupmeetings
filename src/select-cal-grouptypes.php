<?php declare(strict_types=1);

require __DIR__.'/vendor/autoload.php';

use \ChurchTools\Api\Tools\CalendarTools;

if (file_exists ( 'config.php' ) )
{
    $configs = include('config.php');
}
else
{
    $configs= null;
}
//$serverURL= $configs["serverURL"];

$serverURL= filter_input(INPUT_POST, "serverURL");
$userName= filter_input(INPUT_POST, "email");
$password= filter_input(INPUT_POST, "password");

$hasError= false;
$errorMessage= null;
$visibleCalendars;
try
{
    $api = \ChurchTools\Api\RestApi::createWithUsernamePassword($serverURL,
            $userName, $password);
    $calMasterData= $api->getCalendarMasterData();
    $personMasterData= $api->getPersonMasterData();

    $visibleCalendars= $calMasterData->getCalendars();
    $visibleGroupTypes= $personMasterData->getGroupTypes();

    session_start();
    $_SESSION['userName'] = $userName;
    $_SESSION['password'] = $password;
    $_SESSION['serverURL']= $serverURL;
}
catch (Exception $e)
{
    $errorMessage= $e->getMessage();
    $hasError= true;
    session_destroy();
}
?>
<!doctype html>
<html>
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
        <title>Churchtools Gruppentreffen</title>
        <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.3.1/css/bootstrap.min.css" integrity="sha384-ggOyR0iXCbMQv3Xipma34MD+dH/1fQ784/j6cY/iJTQUOhcWr7x9JvoRxT2MZw1T" crossorigin="anonymous">
        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/4.7.0/css/font-awesome.min.css" integrity="sha256-eZrrJcwDc/3uDhsdt61sL2oOBY362qM3lon1gyExkL0=" crossorigin="anonymous" />        
        <link rel="stylesheet" href="styles.css">
        <script>
            function toggleResTypeCat(idToToggle)
            {
                    //var divTitle= document.getElementById("ID_"+idToToggle+"_TITLE");
                    var divPlus= document.getElementById("REST_"+idToToggle+"_PLUS");
                    var divContent= document.getElementById("REST_WRAPPER_"+idToToggle);
                    if (divContent.style.display === "none" || divContent.style.display === "" )
                    {
                            divPlus.classList.add("fa-minus-square");
                            divPlus.classList.remove("fa-plus-square");
                            divContent.style.display= "block";
                    }
                    else
                    {
                            divPlus.classList.remove("fa-minus-square");
                            divPlus.classList.add("fa-plus-square");
                            divContent.style.display = "none";
                    }
            }
            function toggleResType(resTypeIdToToggle)
            {
                var headerCB= document.getElementById("REST_"+resTypeIdToToggle);
                var isChecked= headerCB.checked;
                var restCBS= document.getElementsByClassName("RES_"+resTypeIdToToggle);
                Array.prototype.forEach.call(restCBS, function(el) {
                    // Do stuff here
                    el.checked= isChecked;
                });
            }
            </script>
    </head>
    <body>
        <div class="container">
            <h1>Churchtools Gruppentreffen</h1>
            <?php if ($hasError) { ?>
            <h2>Login fehlgeschlagen</h2>
            <div class="alert alert-danger" role="alert">
            Error in login: <?= $errorMessage ?>
            </div>
            <div>
                <a href="index.php" class="btn btn-primary">Zum Login</a>
            </div>
            <?php } else { ?>
            <form action="show-group-meetings.php" target="_blank" method="post">
                <div class="row">
<!--                    <div class="col-6 calendarcol">
                        <h5>Target Calendar</h5>
            <?php $calIDS= $visibleCalendars->getCalendarIDS(true);
                    foreach( $calIDS as $calID) {
                        $cal=$visibleCalendars->getCalendar($calID);
                        ?>
                 <div class="calendar form-check" style="background-color: <?= $cal->getColor()?>; color: <?= $cal->getTextColor()?>">
                        <label class="form-check-label" for="CAL_<?= $cal->getID() ?>"><input type="radio" class="form-radio-input" id="CAL_<?= $cal->getID() ?>" name="CALENDAR" value="<?= $cal->getID() ?>"><?= $cal->getName() ?> (ID: <?= $cal->getID() ?>)</label>
                    </div>
            <?php } ?>
                    </div>  -->
                    <div class="col-6 grouptypes">
                        <h5>Gruppentypen</h5>
            <?php $groupTypesIDS= $visibleGroupTypes->getGroupTypesIDS(true);
                    foreach( $groupTypesIDS as $groupTypeID) {
                        $groupType=$visibleGroupTypes->getGroupType($groupTypeID);
                    ?>
                     <div class="grouptypes form-radio" >
                            &nbsp;<label class="form-radio-label" for="GRPT_<?= $groupType->getID() ?>">
                                <input type="radio" class="form-radio-input GRPT_<?= $groupType->getID()?>" id="GRPT_<?= $groupType->getID() ?>" name="GROUPTYPE" value="<?= $groupType->getID() ?>">
                                        <?= $groupType->getTitle() ?> (ID: <?= $groupType->getID()?>)</label><br/>
                        </div>
                            <?php } ?>
                    </div>
                </div>
                <div class="row">
                    <div class="col-6">
                        <label class="form-check-label" for="SHOW_LEADER"><input type="checkbox" class="form-check-input" id="SHOW_LEADER" name="SHOW_LEADER" value="1">Gruppenleitung anzeigen</label>
                        
                    </div>
                </div>
             <div class="form-group row mt-2 ml-1">
                 <input type="submit" value="Gruppentreffen anzeigen" class="btn btn-primary mr-1">
                 <a href="index.php" class="btn btn-secondary mr-1">Abmelden</a>
             </div>
            </form>
            <?php } ?>
        </div>
        <script src="https://code.jquery.com/jquery-3.3.1.slim.min.js" integrity="sha384-q8i/X+965DzO0rT7abK41JStQIAqVgRVzpbzo5smXKp4YfRvH+8abtTE1Pi6jizo" crossorigin="anonymous"></script>
        <script src="https://cdnjs.cloudflare.com/ajax/libs/popper.js/1.14.7/umd/popper.min.js" integrity="sha384-UO2eT0CpHqdSJQ6hJty5KVphtPhzWj9WO1clHTMGa3JDZwrnQq4sF86dIHNDz0W1" crossorigin="anonymous"></script>
        <script src="https://stackpath.bootstrapcdn.com/bootstrap/4.3.1/js/bootstrap.min.js" integrity="sha384-JjSmVgyd0p3pXB1rRibZUAYoIIy6OrQ6VrjIEaFf/nJGzIxFDsf4x0xIM+B07jRM" crossorigin="anonymous"></script>
    </body>
</html>
