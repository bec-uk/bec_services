#!/usr/bin/php  # -->
<?php

/**
 * Bristol Energy Cooperative PHP script to check generation during the last hour
 * where this information is available.
 *
 * This script will:
 *  - Exit with an success status if we are outside daylight hours or close to
 *    sunrise/sunset.
 *  - Access recent power data (e.g. from PVOutput) and ensure:
 *    - The date and time stamp of the latest data is within an hour of now
 *    - The power values have not been 0 for all the recent readings retrieved
 *    Otherwise, it will send an alert email noting the site has an issue.
 *
 *  - TODO: Access SolarEdge data for sites which have it available and ensure the
 *    site is generating energy.
 *  - TODO: To cope with sites not in the same timezone as the server this script
 *    runs on, timezone would need to be parameterised per site.
 *
 * PVOutput:
 *  - GET headers should include X-Pvoutput-Apikey (API key)
 *  - Use the getstatus.jsp script with params sid=<site_id>&h=1&asc=0&limit=6
 *
 **/

// Report all PHP errors
error_reporting(E_ALL);

$secondsToUTC = date('Z');

// Set the timezone for the script to GMT/UTC
date_default_timezone_set('UTC');

// Change directory to the directory the script is runnign from
chdir(dirname(__FILE__));

/******************************************************************************
 * Defines and globals
 *****************************************************************************/

define('DEBUG', FALSE);
if (DEBUG)
{
    print("Executing in debug mode\n");
}

define('BECIC_INI_FILENAME', 'becinstantcheck.ini');
$iniFilename = BECIC_INI_FILENAME;

// Stuff to use the Google Gmail and Overcast APIs
require_once __DIR__ . '/vendor/autoload.php';
define('GMAIL_SCOPES', implode(' ', array(Google_Service_Gmail::GMAIL_COMPOSE)));

define('TEMP_DIR', '/tmp');

// Forecast.io latitude and longitude to use
define('FORECAST_IO_LAT', 51.459);
define('FORECAST_IO_LONG', -2.602);

// Verbose output?
$verbose = FALSE;

require_once 'becdb.php';
require_once 'becgmail.php';
require_once 'miscfuncs.php';
require_once 'reportlog.php';

/*****************************************************************************/


// Command line options:
$helpString = "Usage: php $argv[0] <options>\n" .
              'where <options> are:' . "\n" .
              '  -h | --help       Display this usage message' . "\n" .
              '  -i <filename> | --ini-file <filename>' . "\n" .
              '                    Override location of the ini file to use' . "\n" .
              '  -R | --readonly   Run without saving a report or sending any email if one would be sent' . "\n" .
              '  -v[<l>] | --verbose[=<l>]' . "\n" .
              '                    Verbose output - an optional verbosity level <l> may be specified' . "\n" .
//TODO              '  --html-report-dir <path>' . "\n" .
//              '                    Location to write the HTML report (default is /var/www/bec-gen-report)' . "\n" .
//TODO              '  --no-html-report  Supress creation of HTML report (default is to create an HTML report a web browser can read)' . "\n" .
//TODO              '  --temp-dir <path> Override path to the temporary directory (default is /tmp)' . "\n";
'';


/****************************************************************************
 * Main (start of code)
 ****************************************************************************/

$startTime = new DateTime();

$readOnlyMode = FALSE;

if ($argc > 1)
{
    // There were some options/arguments.  Process them...

    $parameters = array('h' => 'help',
                        'i:' => 'ini-file:',
                        'R' => 'readonly',
                        'v::' => 'verbose::',
                        'html-report-dir:',
                        'no-html-report',
                        'temp-dir');

    // Grab command line options into $options
    $options = getopt(implode('', array_keys($parameters)), $parameters);

    // Prune the $argv array of options we'll be processing
    $pruneargv = array();
    foreach ($options as $option => $value)
    {
        foreach ($argv as $key => $chunk)
        {
            $regex = '/^'. (isset($option[1]) ? '--' : '-') . $option . '/';
            if ($chunk == $value && $argv[$key-1][0] == '-' || preg_match($regex, $chunk))
            {
                array_push($pruneargv, $key);
            }
        }
    }
    while ($key = array_pop($pruneargv))
    {
        unset($argv[$key]);
    }

    // Throw away name of script from $argv
    unset($argv[0]);
    // Error if anything left in $argv
    if (count($argv) > 0)
    {
        print('Error: Unrecognised command line options: ' . join(' ', $argv) . "\n");
        exit(1);
    }

    // Process $options

    /**
     * Function which returns FALSE if an option was not used.  If it was used
     * it will either return TRUE, or the option parameter if there was one.
     *
     * @param string $option
     * @param array $options The result of getopt()
     * @param array $parameters An array of supported parameters
     * @return mixed FALSE if not used, otherwise TRUE or the option parameter if there was one
     */
    function optionUsed($option, &$options, &$parameters)
    {
        static $paramsNoColons = NULL;
        if ($paramsNoColons == NULL)
        {
            // Strip all colons from keys and values
            foreach ($parameters as $key => $value)
            {
                $key = str_replace(':', '', $key);
                $value = str_replace(':', '', $value);
                $paramsNoColons[$key] = $value;
            }
        }

        $shortOptUsed = key_exists($option, $options);
        $longOptUsed = FALSE;
        if (key_exists($option, $paramsNoColons))
        {
            $longOptUsed = key_exists($paramsNoColons[$option], $options);
        }
        if ($shortOptUsed)
        {
            if ($options[$option] != FALSE)
            {
                return $options[$option];
            }
            else
            {
                return TRUE;
            }
        }
        else if ($longOptUsed)
        {
            if ($options[$paramsNoColons[$option]] != FALSE)
            {
                return $options[$paramsNoColons[$option]];
            }
            else
            {
                return TRUE;
            }
        }
        return FALSE;
    }

    /**
     * array_reduce callback function to return the highest number from an array
     *
     * @param int $carry Return value from previous call for this array
     * @param int $item Item under consideration
     * @return int Higher of $carry and $item
     */
    function higherNum($carry, $item)
    {
        return ($carry > $item ? $carry : $item);
    }

    if ($verbosity = optionUsed('v', $options, $parameters))
    {
        // Enable verbose output...we cope with multiple calls, but long
        // options will be ignored if short options were used too.
        global $verbose;
        if (is_array($verbosity))
        {
            $verbosity = array_reduce($verbosity, 'higherNum');
        }
        if ($verbosity > 1)
        {
            $verbose = $verbosity;
        }
        else
        {
            $verbose = 1;
        }
    }

    if ($iniFile = optionUsed('i', $options, $parameters))
    {
        // Set the name of the ini file to use
        $iniFilename = $iniFile;
    }

    if (optionUsed('h', $options, $parameters))
    {
        // Help!
        print($helpString);
        exit(0);
    }

    if (optionUsed('R', $options, $parameters))
    {
        $readOnlyMode = TRUE;
    }

}


/**
 * Check recent generation for a given PVOutput site
 *
 * @param string $displayName Display name for the site
 * @param string $siteID PVOutput.org site ID
 * @param string $apiKey PVOutput.org API key to use
 *
 * @return boolean FALSE for no error, else TRUE
 */
function checkPVOutputSite($displayName, $siteID, $apiKey)
{
    global $verbose, $secondsToUTC;
    $result = FALSE;

    // Fetch latest PVOutput data
    ReportLog::append("Fetching generation data for $displayName\n");

    $url = "https://pvoutput.org/service/r2/getstatus.jsp?sid=$siteID&h=1&asc=0&limit=6";
    if (DEBUG)
    {
        print("\n\nFetching URL: $url\n");
    }
    $curlHandle = curl_init($url);
    curl_setopt($curlHandle, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($curlHandle, CURLOPT_HTTPHEADER, array("X-Pvoutput-Apikey: $apiKey"));
    $data = curl_exec($curlHandle);
    $errno = curl_errno($curlHandle);
    curl_close($curlHandle);

    if (DEBUG)
    {
        print("Data: " . $data . "\n\n");
    }
    if ($errno != 0)
    {
        ReportLog::append("  Error: Failed to fetch any generation data (errno $errno)\n");
        ReportLog::setError(TRUE);
        $result = TRUE;
    }
    else
    {
        # Check the data looks right.  It should be of the form:
        #  YYYYMMDD,HH:mm,<gen_watt_hours>,<gen_watts>,<con_watt_hours>,<con_watts>,<normalised_power_kw/kw>,<temp>,<volts>,...;
        # The latest reading will be the first read.
        # The times are in local time and will need the timezone difference to UTC subtracting.
        $lines = explode(";", $data);
        $counter = 0;
        $recordTime = [];
        $sumPower = 0;
        foreach ($lines as $line)
        {
            $lineParts = explode(",", $line);
            $dateStr = $lineParts[0];
            $timeStr = $lineParts[1];
            $powerStr = $lineParts[3];

            # In local time
            $recordTime[$counter] = new DateTime($dateStr . 'T' . $timeStr);

            # Subtract time difference to UTC time if there is one
            if ($secondsToUTC)
            {
                # FIXME: Note that this is for the timezone on the server - if this is
                # different to the timezone of the installation recorded in PVOutput
                # then it will do the wrong thing...
                $recordTime[$counter]->modify("-$secondsToUTC seconds");
            }

            $sumPower += $powerStr;
            if ($verbose > 1)
            {
                print($recordTime[$counter]->format('d/m/Y H:i') . ': ' . $powerStr . "\n");
            }
            $counter++;
        }

        # If the latest time was over an hour ago we warn
        if ($recordTime[0]->getTimestamp() < time() - 60 * 60)
        {
            ReportLog::append('  Warning: No generation data since ' . $recordTime[0]->format('d/m/Y H:i') . " (expected every 5 to 10 minutes during daylight) (UTC time)\n");
            ReportLog::setError(TRUE);
            $result = TRUE;
        }
        else
        {
            ReportLog::append('  Last recorded generation data was at ' . $recordTime[0]->format('d/m/Y H:i') . " (UTC time)\n");
        }

        # If sum of the last few powers recorded is less than .5, send a warning
        if ($sumPower < 0.5)
        {
            ReportLog::append('  Warning: Very low power generation detected; sum of last $counter records is $sumPower during the period from ' . $recordTime[sizeof($lines) - 1]->format('d/m/Y H:i') . " to " . $recordTime[0]->format('d/m/Y H:i') . " (UTC times)\n");
            ReportLog::setError(TRUE);
            $result = TRUE;
        }
        else
        {
            ReportLog::append("  Sum of last $counter recorded power readings: $sumPower\n");
        }
    }
    return $result;
}

ReportLog::prepend("BEC Instant Power Output Checker\n" .
    "================================\n\n" .
    "Start time: " . $startTime->format('d/m/Y H:i') . " (UTC)\n\n");

// Quit without error if not within daylight hours

// Work out when sunrise and sunset are - we'll skip checking if close to them
$timestamp = time();
$sunriseTime = date_sunrise($timestamp, SUNFUNCS_RET_TIMESTAMP, FORECAST_IO_LAT, FORECAST_IO_LONG);
$sunsetTime = date_sunset($timestamp, SUNFUNCS_RET_TIMESTAMP, FORECAST_IO_LAT, FORECAST_IO_LONG);

# Site status array to build up; compare with last run when deciding whether to email
$siteStatus = [];

if ($timestamp < $sunriseTime + 60 * 60)
{
    print("Info: Before sunrise or the sun has been up for less than 1 hour - skipping check\n");
    print("      Sunrise time is " . gmdate('d/m/Y H:i', $sunriseTime) . " (UTC)\n\n");
    exit(0);
}
else if ($timestamp > $sunsetTime - 30 * 60)
{
    print("Info: After sunset or sunset within 30 minutes - skipping check\n");
    print("      Sunset time is " . gmdate('d/m/Y H:i', $sunsetTime) . " (UTC)\n\n");
    exit(0);
}
else
{
    // ini contains defaults which can be overridden by the ini file
    $ini = array(// Database
                  'database_type' => 'mysql',
                  'database_host' => 'localhost',
                  'database_name' => 'bec',
                  'database_username' => 'www-data',
                  'database_user_password' => '',
                  // Gmail
                  'gmail_application_name' => 'BEC Fault Monitoring',
                  'gmail_credentials_path' => __DIR__ . '/bec_fault_mon.json',
                  'gmail_client_secret_path' => __DIR__ . '/client_secret.json',
                  'gmail_username' => 'me',
                  'gmail_from' => 'becmonitoring@gmail.com',
                  // PVOutput.org
                  'pvoutput_site_displayname_csv' => 'Hamilton House - Bristol',
                  'pvoutput_site_id_csv' => '26297',
                  'pvoutput_api_key_csv' => 'bristolenergycoop',
    );

    // Read configuration from ini file to override defaults
    if (file_exists($iniFilename))
    {
        $ini = array_merge($ini, parse_ini_file($iniFilename));
    }
    else if ($iniFilename != BECIC_INI_FILENAME)
    {
        die("Error: Requested ini file '$iniFilename' not found\n");
    }

    // Email support: get the Gmail API client and construct the service object.
    $gmail = new BECGmailWrapper();

    $pvOutputSiteDisplayNames = str_getcsv($ini['pvoutput_site_displayname_csv']);
    $pvOutputSiteIDs = str_getcsv($ini['pvoutput_site_id_csv']);
    $pvOutputAPIKeys = str_getcsv($ini['pvoutput_api_key_csv']);

    if (count($pvOutputSiteDisplayNames) != count($pvOutputSiteIDs) || count($pvOutputSiteIDs) != count($pvOutputAPIKeys))
    {
        die("Error: PVOutput.org info incorrect:\n" .
            "  Site Display Names: " . count($pvOutputSiteDisplayNames) . "\n" .
            "  Site IDs: " . count($pvOutputSiteIDs) . "\n" .
            "  API Keys: " . count($pvOutputAPIKeys) . "\n");
    }

    for ($i = 0; $i < count($pvOutputSiteDisplayNames); $i++)
    {
        $res = checkPVOutputSite($pvOutputSiteDisplayNames[$i], $pvOutputSiteIDs[$i], $pvOutputAPIKeys[$i]);
        $siteStatus[$pvOutputSiteDisplayNames[$i]] = $res === FALSE ? "NO ERROR" : "ERROR";
        ReportLog::append('  Check result: ' . $siteStatus[$pvOutputSiteDisplayNames[$i]] . "\n\n");
    }
}

// Reporting

// We only want to email the report on the first error in a new day or if site status has changed
$statusStr = file_get_contents('becinstantcheck.status');

if ($statusStr == FALSE)
{
    $newDay = TRUE;
    $siteStatusChanged = FALSE;
}
else
{
    $lineBreakPos = strpos($statusStr, "\n");
    $lastRecordDate = substr($statusStr, 0, $lineBreakPos);
    $newDay = (strcmp($lastRecordDate, $startTime->format('d/m/Y')) != 0);

    $oldSiteStatus = json_decode(substr($statusStr, $lineBreakPos + 1), TRUE);
    $siteStatusChanged = $siteStatus != $oldSiteStatus;
}

if (!ReportLog::hasError())
{
    ReportLog::append("No errors detected for any sites\n");
    if ($siteStatusChanged)
    {
        ReportLog::append(" - Previous error(s) recorded at $lastRecordDate have cleared.\n");
    }
}

$report = ReportLog::get();
print($report . "\n\n");

// Write it to a local file so we can see it ran
file_put_contents('becinstantcheck.log', $report);

// Send email report containing report log if site status has changed or there is an error and it's a new day
if ($siteStatusChanged ||
    ReportLog::hasError() && $newDay)
{
    $msgBody = array($report);
    $gmail->sendEmail($ini['email_reports_to'], '', '', 'BEC instant power generation report', $msgBody);
}
else
{
    print("Email not sent\n\n");
}

$statusStr = $startTime->format('d/m/Y') . "\n" . json_encode($siteStatus);
file_put_contents('becinstantcheck.status', $statusStr);

// TODO: HTML report

exit(0);
?>
