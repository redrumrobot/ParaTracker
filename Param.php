<?php
echo "<!-- ";
 
//This variable will allow ParaFunc to execute.
//The point of this variable is to prevent ParaFunc from being executed directly,
//as it would be a complete waste of CPU power.
$safeToExecuteParaFunc = "1";
 
//ParaFunc.php MUST exist, or the page must terminate!
if (file_exists("ParaFunc.php"))
{
    include 'ParaFunc.php';
}
else
{
    echo '--> <h3 class="errorMessage">ParaFunc.php not found - cannot continue!</h3> <!--';
    exit();
}

if($dynamicTrackerEnabled == "1")
{
    //Terminate the script with an instruction page if no IP address was given!
    if (!isset($_GET["ip"]))
    {
        dynamicInstructionsPage($personalDynamicTrackerMessage);
    }
    $serverIPAddress = ipAddressValidator($_GET["ip"], "", $dynamicTrackerEnabled);
    $serverPort = numericValidator($_GET["port"], 1, 65535, 29070);
}

//ParaFunc already does the validation for everything, including the IP address. It should be fine to just refresh.
checkForAndDoUpdateIfNecessary($serverIPAddress, $serverPort, $dynamicIPAddressPath, $floodProtectTimeout, $connectionTimeout, $refreshTimeout, $disableFrameBorder, $fadeLevelshots, $levelshotDisplayTime, $levelshotTransitionTime, $levelshotFPS, $maximumLevelshots, $levelshotFolder, $gameName, $noPlayersOnlineMessage, $enableAutoRefresh, $autoRefreshTimer, $maximumServerInfoSize, $RConEnable, $RConMaximumMessageSize, $RConFloodProtect, $RConLogSize, $newWindowSnapToCorner, $dynamicTrackerEnabled);

echo "-->" . file_get_contents("info/" . $dynamicIPAddressPath . "param.txt");

?>