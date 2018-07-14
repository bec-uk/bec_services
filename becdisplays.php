#!/usr/bin/php  # -->
<?php

/**
 * Bristol Energy Cooperative Display log checker
 *
 * In its default operating mode, this script will:
 *  - Check the latest fetches in the display log files
 *  - Email if a display has stopped fetching slideshow pages
 **/

// Report all PHP errors
error_reporting(E_ALL);

// Set the timezone for the script to GMT/UTC
date_default_timezone_set('UTC');

// Change directory to the directory the script is running from
chdir(dirname(__FILE__));

/******************************************************************************
 * Defines and globals
 *****************************************************************************/

define('DEBUG', FALSE);
if (DEBUG)
{
    print("Executing in debug mode\n");
}

define('BECDISPLAYS_INI_FILENAME', 'becdisplays.ini');
$iniFilename = BECDISPLAYS_INI_FILENAME;

// Stuff to use the Google Gmail API
require_once __DIR__ . '/vendor/autoload.php';
define('GMAIL_SCOPES', implode(' ', array(Google_Service_Gmail::GMAIL_COMPOSE)));

define('TEMP_DIR', '/tmp');

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
              '  -R | --readonly   Run without sending emails' . "\n" .
              '  -v[<l>] | --verbose[=<l>]' . "\n" .
              '                    Verbose output - an optional verbosity level <l> may be specified' . "\n" .
//TODO              '  --html-report-dir <path>' . "\n" .
//              '                    Location to write the HTML report (default is /var/www/bec-gen-report)' . "\n" .
//TODO              '  --no-html-report  Suppress creation of HTML report (default is to create an HTML report a web browser can read)' . "\n" .
//TODO              '  --temp-dir <path> Override path to the temporary directory (default is /tmp)' . "\n";
'';


// Read through the specified log file to the last line of text and report the IP address and date/time
function reportLatestFetch($becDB, $displayLogFile, $warnAfterMins)
{
    // Extract site code from filename
    //  slideshow_fetch_*.log
    $siteCode = str_replace('slideshow_fetch_', '', $displayLogFile);
    $siteCode = str_replace('.log', '', $siteCode);

    // TODO: Only report errors if the site has a BEC display and it is always on; query database to find out
    // Check the database to see if the site has an active display we should be checking the status of
    $monitorSite = $becDB->fetchQuery('SELECT monitor_fetches FROM slideshow_sites WHERE sitecode = "' . $siteCode . '"');
    if (DEBUG)
    {
        print($siteCode . ' display monitoring: ' . $monitorSite[0]['monitor_fetches'] . "\n");
    }
    $monitorSite = $monitorSite[0]['monitor_fetches'];

    if ($monitorSite == 1 || DEBUG)
    {
        $logData = file_get_contents($displayLogFile);
        $endLoc = strrpos($logData, "\n");
        $startLoc = strrpos($logData, "\n", -1 * (strlen($logData) - $endLoc + 1));
        if (!$startLoc)
        {
            $startLoc = -1;
        }
        if ($endLoc === FALSE)
        {
            // No log entry lines found!
            return;
        }

        $logEntry = substr($logData, $startLoc + 1, $endLoc);
        if (DEBUG)
        {
            print('Last ' . $siteCode . ' log entry was: ' . $logEntry . "\n");
        }

        // Check age and record as an eroor if last fetch was more given time ago
        $datetime = new DateTime(substr($logEntry, 0, 25));
        $datetime2 = clone $datetime;
        $now = new DateTime();
        $oldStr = "\t";
        if ($datetime2->add(new DateInterval('PT' . $warnAfterMins . 'M'))->getTimestamp() < $now->getTimestamp())
        {
            // The last fetch from this file was more than the given number of minutes old - record it as an error
            $oldStr = '***';
              ReportLog::setError(TRUE);
        }

        $ipAddr = substr($logEntry, 27);
        // ReportLog::append("Site\tLast fetch\t\tOld\tLast IP address\n");
        ReportLog::append($siteCode . "\t" . $datetime->format('d/m/Y H:m') . "\t" . $oldStr . "\t" . $ipAddr . "\n");
    }
    return;
}


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
                        'run-read-only',
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


// ini contains defaults which can be overriden by the ini file
$ini = array( // Database
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
              'gmail_from' => 'becmonitoring@gmail.com'
              );

// Read configuration from ini file to override defaults
if (file_exists($iniFilename))
{
    $ini = array_merge($ini, parse_ini_file($iniFilename));
}
else if ($iniFilename != BECDISPLAYS_INI_FILENAME)
{
    die("Error: Requested ini file '$iniFilename' not found\n");
}

// Connect to the BEC database
$becDB = new BECDB($ini['database_type'], $ini['database_host'],
$ini['database_name'], $ini['database_username'],
$ini['database_user_password']);

// Get the Gmail API client and construct the service object.
$gmail = new BECGmailWrapper();

// Reporting
ReportLog::prepend("<pre>\n");
ReportLog::prepend("BEC Display Monitoring Report Log\n" .
                   "=================================\n" .
                   "Start time: " . $startTime->format('d/M/Y H:i') . " (UTC)\n\n");

ReportLog::append("Site\tLast fetch\t\tOld\tLast IP address\n");
ReportLog::append("====\t==========\t\t===\t===============\n");
foreach (glob("slideshow_fetch_*.log") as $logFile)
{
    reportLatestFetch($becDB, $logFile, 240);
}

ReportLog::append("</pre>\n");
$report = ReportLog::get();
print($report . "\n\n");

// Write it to a local file so we can see it ran
file_put_contents('lastdisplaycheck.log', $report);

// Send email report containing report log if there was an error
if (ReportLog::hasError() && !$readOnlyMode)
{
    $msgBody = array($report);
    $gmail->sendEmail($ini['email_reports_to'], '', '', 'BEC site display issue report', $msgBody);
}

// TODO: HTML report



exit(0);
?>
