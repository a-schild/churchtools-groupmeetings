<?php declare(strict_types=1);

require __DIR__.'/vendor/autoload.php';

use \ChurchTools\Api\Tools\CalendarTools;
use \PhpOffice\PhpSpreadsheet\Spreadsheet;
use \PhpOffice\PhpSpreadsheet\Writer\Xlsx;


session_start();
$userName= $_SESSION["userName"];
$password= $_SESSION["password"];
$serverURL= $_SESSION["serverURL"];
$selectedGroups= $_SESSION["selectedGroups"];

$hasError= false;
$errorMessage= null;
try
{
    $api = \ChurchTools\Api\RestApi::createWithUsernamePassword($serverURL,
            $userName, $password);
    $personMasterData= $api->getPersonMasterData();

    $visibleGroupTypes= $personMasterData->getGroupTypes();
    $visibleGroups= $personMasterData->getGroups();
}
catch (Exception $e)
{
    $errorMessage= $e->getMessage();
    $hasError= true;
    session_destroy();
}

$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();
$sheet->setCellValue('A1', 'Gruppentreffen');
$rowPos= 2;
foreach( $selectedGroups as $group) {
    $meetings= $api->getGroupMeetings($group->getId());
    if ($meetings != null && sizeof($meetings) > 0) {
        foreach ($meetings as $meeting) { 
            $sheet->setCellValue('A'.$rowPos, $group->getTitle());
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
            $sheet->setCellValue('B'.$rowPos, $meeting->getStartDate()->format("d.m.Y"));
            $sheet->setCellValue('C'.$rowPos, $meeting->getStartDate()->format("H:i"));
            if ($untilTime != null)
            {
                $sheet->getCell('D'.$rowPos)->setValueExplicit(
                    $untilTime,
                    \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING
                );
            }
            if ($meeting->isMeetingCanceled()) {
                $sheet->setCellValue('E'.$rowPos, "Treffen wurde abgesagt");
                $sheet->getStyle("A".$rowPos.":G".$rowPos)->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setARGB('ff0000');
            }
            if ($ort != null) {
                $sheet->setCellValue('F'.$rowPos, "Ort: ".$ort);
            }
            if ($pollResult != null) {
                $outLine= "";
                foreach ($remainingResults as $key => $result) {
                    if ($result != null && $result != "") {
                        if (strlen($outLine) > 0 ) {
                            $outLine .= "\n";
                        }
                        $outLine.= $key.": ".$result;
                    }
                }
                if ($outLine != "")
                {
                    $sheet->setCellValue('G'.$rowPos, $outLine);
                    // $sheet->getStyle('G'.$rowPos)->getAlignment()->setWrapText(true);
                }
            }
            $rowPos++;
        }
    }
}
$writer = new Xlsx($spreadsheet);
header('Content-Type:vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition:attachment;filename="Gruppentreffen.xlsx"');
header('Cache-Control:max-age=0');
$writer->save('php://output');

        
