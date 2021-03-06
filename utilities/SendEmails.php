<?php

$sendAdminEmails = 0;
$calledFromElsewhere = 1;

//This variable will allow ParaFunc to execute.
//The point of this variable is to prevent ParaFunc from being executed directly,
//as it would be a complete waste of CPU power.
$safeToExecuteParaFunc = "1";


//Check to see if ParaFunc was already executed
if(!isset($utilitiesPath))
{
    //If this file is called from the command line, it will send the admin reports.
    //This is intended to be done via a cron job.
    //If it is called by URL or included by another file, it will serve as a library file.
    if (php_sapi_name() == "cli") $sendAdminEmails = 1;

    //We are in the utilities folder, so we have to back out one
    chdir("../");

    //ParaFunc.php MUST exist, or the page must terminate!
    if (file_exists("ParaFunc.php"))
    {
        include_once 'ParaFunc.php';
    }
    else
    {
        echo '--> <h3 class="errorMessage">ParaFunc.php not found - cannot continue!</h3> <!--';
        exit();
    }
}


set_time_limit(300);

if(!emailEnabled)
{
    displayError("Email is disabled in ParaConfig.php! You must enable email to continue.", "", "");
}

//Make sure this can be run in the first place
if(!file_exists('vendor/autoload.php'))
{
    echo ' Composer does not appear to be installed. ParaTracker expects PHPMailer to be installed via Composer. ';
    exit();
}
else
{
    //Composer appears to be installed. Load PHPMailer!
    include_once 'vendor/autoload.php';

    if(file_exists($emailPath . 'PHPMailerAutoload.php'))
    {
        require $emailPath . 'PHPMailerAutoload.php';
    }
    else
    {
        echo ' PHPMailer does not appear to be installed. ParaTracker expects this library to be installed via Composer. ';
        exit();
    }
}

//This variable sets how many displayError calls are considered acceptable per day before warnings start
//The value given here will turn the warning yellow, and 1.5 times that value will turn it red
define("callsPerDay", 25);

$emailPath = rtrim(trim($emailPath), '/') . '/';
$emailAdminReports = booleanValidator($emailAdminReports, 0);
$useSMTP = booleanValidator($useSMTP, 0);

define("emailAdminReports", $emailAdminReports);
define("emailFromAddress", $emailFromAddress);

define("useSMTP", $useSMTP);
define("smtpAddress", trim($smtpAddress));
define("smtpPort", numericValidator($smtpPort, 0, 65535, 25));
define("smtpUsername", $smtpUsername);
define("smtpPassword", $smtpPassword);


//If any other emails are to be sent, this is where to add them.

if($sendAdminEmails && emailAdminReports && count($emailAdministrators) > 0)
{
    prepareAndsendAdminReport($emailAdministrators);
}

//Below here is just functions

function getExecutionTimeArray()
{
	global $pgCon;
	$frames_fetch = pg_fetch_all(pg_query_params($pgCon, "SELECT startdate, enddate FROM analytics.runtimes WHERE startdate BETWEEN $1 AND $2", array(date('Y-m-d H:i', lastRefreshTime), date('Y-m-d H:i', currentTime))));

	//This next line prevents a boolean false from being counted as a 1
	$count = countDatabaseReturn($frames_fetch);
	for($i = 0; $i < $count; $i++)
	{
	$frames_fetch[$i]['timeDiff'] = strtotime($frames_fetch[$i]['enddate']) - strtotime($frames_fetch[$i]['startdate']);
	}
	return $frames_fetch;
}

function getDisplayErrorCalls()
{
    global $pgCon;
    $frames_fetch = pg_fetch_all(pg_query_params($pgCon, "SELECT entrydate FROM tracker.displayerror WHERE entrydate BETWEEN $1 AND $2", array(date('Y-m-d H:i', lastRefreshTime), date('Y-m-d H:i', currentTime))));

	return $frames_fetch;
}

function getCPULoadArray()
{
    global $pgCon;
    $frames_fetch = pg_fetch_all(pg_query_params($pgCon, "SELECT load FROM tracker.cpuload WHERE entrydate BETWEEN $1 AND $2", array(date('Y-m-d H:i', lastRefreshTime), date('Y-m-d H:i', currentTime))));
    return $frames_fetch;
}

function getValuesFromArray($input, $key)
{
    $max = "No data";
    $min = "No data";
    $average = "No data";
    $sum = 0;
    if(is_array($input))
    {
        $count = count($input);
        if($count > 0)
        {
            $min = $input[0];
            $max = 0;
            for($i = 0; $i < $count; $i++)
            {
                if(!empty($input[$i][$key]))
                {
                    $value = $input[$i][$key];
                    $sum = $sum + $value;
                    if($value > $max) $max = $value;
                    if($value < $min) $min = $value;
                }
            }
            $max = round($max, 2);
            $min = round($min, 2);
            $average = round($sum / $count, 2);
        }
    }
    return array($min, $max, $average);
}

function prepareAndsendAdminReport($emailAdministrators)
{
    //Mark the time in microseconds so we can see how long this takes.
	$parseTimer = microtime(true);

	//Get the last refresh time
    define("lastRefreshTime", numericValidator(file_get_contents(infoPath . 'emailTimer.txt'), 0, time(), 0));
    define("currentTime", time());

    $subject = 'ParaTracker - Admin Status Report';
    $message = '<table style="width: 100%; font-family: monospace; font-size: 10pt;"><tr><td style="text-align: center;"><h2 style="text-align: center;">ParaTracker - Admin Status Report</h2>';
//    $message .= '<h3 style="text-align: center;">Server: ' . $_SERVER['REQUEST_URI'] . '</h3>';

    if(mapreqEnabled)
    {
        $message .= '<h3 style="text-align: center;">Pending Levelshot Requests:</h3>';

        global $pgCon;
        $mapreqs_user = pg_fetch_all(pg_query($pgCon, 'SELECT * FROM mapreq WHERE useradded = true ORDER BY  CASE WHEN dl_link IS NULL THEN 1 ELSE 0 END, game_name ASC, bsp_name ASC'));
        $mapreqs_auto = pg_fetch_all(pg_query($pgCon, 'SELECT * FROM mapreq WHERE useradded = false ORDER BY game_name ASC, bsp_name ASC'));

		//This next line prevents a boolean false from being counted as a 1
		$count = countDatabaseReturn($mapreqs_user);
        $unit = checkPlural("request", $count);
        $message .= '<p style="font-family: monospace; text-align: center;">Manual requests: ' . colorizeDangerousValuesHigher($count, $unit, 1, 5, '') . '<br>';


		//This next line prevents a boolean false from being counted as a 1
		$count = countDatabaseReturn($mapreqs_auto);
        $unit = checkPlural("request", $count);
		$message .= 'Automatic requests: ' . colorizeDangerousValuesHigher($count, $unit, 1500, 2000, '') . '</p>';
    }

	if(enablePGSQL)
	{
		$trackedCount = pg_fetch_all(pg_query($pgCon, "SELECT COUNT(*) FROM tracker.server WHERE active = true"));
		$message .= '<h3 style="font-family: monospace; text-align: center;">Currently tracking <strong>' . $trackedCount[0]['count'] . '</strong> servers</h3>';

		$message .= '<p class="text-align: center;">' . padOutputAndImplode(getGameCountList(), '<br>') . '</p>';
	}

		$stuff = array();
		$stuff[0] = '<strong>Start Time: </strong>' . date(DATE_RFC2822, lastRefreshTime);
		$stuff[1] = '<strong>End Time: </strong>' . date(DATE_RFC2822, currentTime);
		

		$message .= '<p style="font-family: monospace; text-align: center;">' . padOutputAndImplode($stuff, '<br>') . '</p>';


    if(enablePGSQL)
    {
		$displayErrorCount = numericValidator(countDatabaseReturn(getDisplayErrorCalls()), 0, "", 622);
        $message .= '<h3 style="text-align: center;">DisplayError:</h3>';

        $problemThreshold = ((currentTime - lastRefreshTime) / 86400) * callsPerDay;

        $unit = checkPlural("time", $displayErrorCount);
        $message .= '<p style="font-family: monospace; text-align: center;">DisplayError was called ' . colorizeDangerousValuesHigher($displayErrorCount, $unit, $problemThreshold, $problemThreshold * 1.5, '');

        //86400 is the number of seconds in 24 hours
        $calls = round(((currentTime - lastRefreshTime) / 86400) * $displayErrorCount, 2);
        $unit = checkPlural("call", $calls);

        $message .= '<br><span style="font-size: 9pt;">At a rate of ' . colorizeDangerousValuesHigher($calls, $unit, $problemThreshold, $problemThreshold * 1.5, '') . ' per day</span><br>
        <span style="font-size: 8pt;">If there are excessive displayError calls, check ' . logPath . 'errorLog.php for details.</span></p>';

        $executionTimeArray = getExecutionTimeArray();
        $temp = getValuesFromArray($executionTimeArray, 'timeDiff');
        $minExecutionTime = round($temp[0]);
        $maxExecutionTime = round($temp[1]);
        $averageExecutionTime = round($temp[2]);

        $message .= '<h3 style="text-align: center;">AnalyticsBackground Execution Time:</h3>';
		$stuff = array();
		$stuff[0] = 'Highest Execution Time: ' . colorizeDangerousValuesHigher($maxExecutionTime, 'seconds', 60, 120, '');
		$stuff[1] = 'Lowest Execution Time: ' . colorizeDangerousValuesHigher($minExecutionTime, 'seconds', 60, 120, '');
		$stuff[2] = 'Average Execution Time: ' . colorizeDangerousValuesHigher($averageExecutionTime, 'seconds', 60, 120, '');
		
		$message .= '<p style="font-family: monospace; text-align: center;">' . padOutputAndImplode($stuff, '<br>') . '</p>';

        $cpuLoadArray = getCPULoadArray();
        $temp = getValuesFromArray($cpuLoadArray, 'load');
        $minCPULoad = $temp[0];
        $maxCPULoad = $temp[1];
        $averageCPULoad = $temp[2];

        $message .= '<h3 style="text-align: center;">CPU Load:</h3>';

        if($maxCPULoad == "No data")
        {
			$maxCPULoad = colorizeDangerousValuesHigher($maxCPULoad, '', 75, 90, '');
        }
        else
        {
			$maxCPULoad = colorizeDangerousValuesHigher($maxCPULoad, '%', 75, 90, '');
        }
        
        if($minCPULoad == "No data")
        {
			$minCPULoad = colorizeDangerousValuesHigher($minCPULoad, '', 75, 90, '');
        }
        else
        {
			$minCPULoad = colorizeDangerousValuesHigher($minCPULoad, '%', 75, 90, '');
        }

        if($averageCPULoad == "No data")
        {
			$averageCPULoad = colorizeDangerousValuesHigher($averageCPULoad, '', 75, 90, '');
        }
        else
        {
			$averageCPULoad = colorizeDangerousValuesHigher($averageCPULoad, '%', 75, 90, '');
        }

		$stuff = array();
		$stuff[0] = 'Highest CPU Load: ' . $maxCPULoad;
		$stuff[1] = 'Lowest CPU Load: ' . $minCPULoad;
		$stuff[2] = 'Average CPU Load: ' . $averageCPULoad;
		$message .= '<p style="font-family: monospace; text-align: center;">' . padOutputAndImplode($stuff, '<br>') . '</p>';
    }
    else
    {
        $message .= "<h3>Postgres is disabled!</h3>";
    }

	$message .= '<h3 style="text-align: center;">Free Space:</h3>';
	$stuff = array();
	$stuff[0] = 'Free space in info folder: ' . getFreeSpace(infoPath);
	$stuff[1] = 'Free space in logs folder: ' . getFreeSpace(logPath);
	$message .= '<p style="font-family: monospace; text-align: center;">' . padOutputAndImplode($stuff, '<br>') . '</p>';

	$parseTimer = number_format(((microtime(true) - $parseTimer) * 1000), 3);
	$message .= '<p style="font-family: monospace; text-align: center;">Email prepared in ' . $parseTimer . ' milliseconds.</p>';

    $message .= "</td></tr></table>";


    if(sendEmail($emailAdministrators, $subject, $message))
    {
        file_put_contents(infoPath . 'emailTimer.txt', currentTime);
    }
}

function sendEmail($recipients, $subject, $messageBody)
{
	//Create a new PHPMailer instance
    $mail = new PHPMailer;

//$mail->SMTPDebug = 3;                               // Enable verbose debug output

    if(useSMTP)
    {
        $mail->isSMTP();                                      // Set mailer to use SMTP
        $mail->Host = smtpAddress;
        $mail->SMTPAuth = false;                               // Enable SMTP authentication
        $mail->Username = smtpUsername;                 // SMTP username
        $mail->Password = smtpPassword;                           // SMTP password
		$mail->SMTPSecure = false;
		$mail->SMTPAutoTLS = false;
        $mail->Port = smtpPort;                                    // TCP port to connect to
    }
    else
    {
        // Set PHPMailer to use the sendmail transport
        $mail->isSendmail();
    }

    $mail->setFrom(emailFromAddress, 'ParaTracker');

    if(is_array($recipients))
    {
        foreach($recipients as $address)
        {
            if(empty($address)) continue;
            $mail->addAddress($address);
        }
    }
    else
    {
        $mail->addAddress($recipients);
    }

    //Set the subject line
    $mail->Subject = $subject;
    //Read an HTML message body from an external file, convert referenced images to embedded,
    //convert HTML into a basic plain-text alternative body
    $mail->msgHTML($messageBody);

    //send the message, check for errors

    if (!$mail->send())
    {
        echo "Mailer Error: " . $mail->ErrorInfo;
        if(useSMTP)
        {
            echo "\n<br>Attempting without SMTP...\n<br>";
            $mail->isSendmail();
            if (!$mail->send())
            {
                echo "Mailer Error: " . $mail->ErrorInfo;
                displayError("Failed to send email with SMTP! Attemped without SMTP, but also failed. " . $mail->ErrorInfo, "", "");
                return 0;
            }
            else
            {
                sentConfirmation();
                displayError("Failed to send email with SMTP, but succeeded without it! " . $mail->ErrorInfo, "", "");
                return 0;
            }
        }
        else
        {
            displayError("Failed to send email! Was not using SMTP. " . $mail->ErrorInfo, "", "");
            return 0;
        }
    } else {
        sentConfirmation();
        return 1;
    }

}

function sentConfirmation()
{
	echo "Message sent! ";
}

function getGameCountList()
{
	$output = array();
	$databaseInfo = getServerListFromDatabase();

	$count = countDatabaseReturn($databaseInfo);
	$unrecognizedGames = array();
	
	for($i = 0; $i < $count; $i++)
	{
		if(!isset($gameCountList[$databaseInfo[$i]["name"]]) || empty($gameCountList[$databaseInfo[$i]["name"]])) $gameCountList[$databaseInfo[$i]["name"]] = 1;
		else if($databaseInfo[$i]["name"] == 'Unrecognized Game')
		{
			array_push($unrecognizedGames, colorRed($databaseInfo[$i]["location"] . ':' . $databaseInfo[$i]["port"]));
		}
		else $gameCountList[$databaseInfo[$i]["name"]]++;
	}

	$gameList = detectGameName("")[0];
	$count = count($gameList);
	for($i = 0; $i < $count; $i++)
	{
		if(isset($gameCountList[$gameList[$i]]))
		{
			$unit = checkPlural("server", $gameCountList[$gameList[$i]]);
			array_push($output, $gameList[$i] . ': ' . colorizeDangerousValuesLower($gameCountList[$gameList[$i]], $unit, 5, 0, ''));
		}
		else
		{
			array_push($output, $gameList[$i] . ': ' . colorizeDangerousValuesLower(0, 'servers', 0, -1, ''));
		}
	}

	$count = count($unrecognizedGames);
	$unit = checkPlural("server", $count);
	array_push($output, 'Unrecognized Games: ' . colorizeDangerousValuesHigher($count, $unit, 1, 1, ''));

	for($i = 0; $i < $count; $i++)
	{
		array_push($output, $unrecognizedGames[$i]);
	}

	return $output;
}

function getFreeSpace($path)
{
    $redThreshold = 314572800;
    $yellowThreshold = 1073741824;

    $val = disk_free_space($path);
    if ($val < 2048)
    {
        $reducedValue = $val;
        $units = 'Bytes';
        $output = colorizeDangerousValuesLower($val, $units, $yellowThreshold, $redThreshold, $reducedValue);
    }
    else if ($val < 2097152)
    {
        $reducedValue = round($val / 1024, 2);
        $units = 'KiB';
        $output = colorizeDangerousValuesLower($val, $units, $yellowThreshold, $redThreshold, $reducedValue);
    }
    else if ($val < 1073741824)
    {
        $reducedValue = round($val / 1048576, 2);
        $units = 'MiB';
        $output = colorizeDangerousValuesLower($val, $units, $yellowThreshold, $redThreshold, $reducedValue);
    }
    else
    {
        $reducedValue = round($val / 1073741824, 2);
        $units = 'GiB';
        $output = colorizeDangerousValuesLower($val, $units, $yellowThreshold, $redThreshold, $reducedValue);
    }

    return $output;
}

function colorizeDangerousValuesLower($input, $units, $yellowThreshold, $redThreshold, $input2)
{
	if($units != '') $units = ' ' . $units;

	if(!isset($input2) || $input2 == "") $input2 = $input;
    if($input <= $redThreshold) $output = colorRed('&nbsp;' . $input2 . $units . '&nbsp;');
    else if($input <= $yellowThreshold) $output = colorYellow('&nbsp;' . $input2 . $units . '&nbsp;');
    else $output = colorGreen('&nbsp;' . $input2 . $units . '&nbsp;');

    return $output;
}

function colorizeDangerousValuesHigher($input, $units, $yellowThreshold, $redThreshold, $input2)
{
	if($units != '') $units = ' ' . $units;

    if(!isset($input2) || $input2 == "") $input2 = $input;
    if($input >= $redThreshold) $output = colorRed('&nbsp;' . $input2 . $units . '&nbsp;');
    else if($input >= $yellowThreshold) $output = colorYellow('&nbsp;' . $input2 . $units . '&nbsp;');
    else $output = colorGreen('&nbsp;' . $input2 . $units . '&nbsp;');

    return $output;
}

function colorGreen($input)
{
    return '<span style="background-color: #5F5; font-weight: bold;">' . $input . '</span>';
}

function colorYellow($input)
{
    return '<span style="background-color: #FF5; font-weight: bold;">' . $input . '</span>';
}

function colorRed($input)
{
    return '<span style="background-color: #F55; font-weight: bold;">' . $input . '</span>';
}

?>
