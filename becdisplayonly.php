#!/usr/bin/php  # -->
<?php

/**
 * Bristol Energy Cooperative Stand-Alone Display DB Update PHP script
 *
 * In its default operating mode, this script will:
 *  - Pull in any new generation data from the Simtricity platform
 *
 * It uses a local BEC database to store generation data for the
 * generation meter at the site.
 **/

// Report all PHP errors
error_reporting(E_ALL);

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

define('BECFM_INI_FILENAME', 'becfm.ini');
$iniFilename = BECFM_INI_FILENAME;

define('TEMP_DIR', '/tmp');

// BEC database stuff
define('CAN_USE_LOAD_DATA_INFILE', FALSE);

// Verbose output?
$verbose = FALSE;

require_once 'becdb.php';
require_once 'becsimtricity.php';
require_once 'miscfuncs.php';
require_once 'reportlog.php';

/*****************************************************************************/


// Command line options:
$helpString = "Usage: php $argv[0] <options>\n" .
              'where <options> are:' . "\n" .
              '  -h | --help       Display this usage message' . "\n" .
              '  -i <filename> | --ini-file <filename>' . "\n" .
              '                    Override location of the ini file to use' . "\n" .
              '  -R | --readonly   Run without importing any new data' . "\n" .
              '  -u                Update site and meter lists from Simtricity' . "\n" .
              '  -v[<l>] | --verbose[=<l>]' . "\n" .
              '                    Verbose output - an optional verbosity level <l> may be specified' . "\n" .
              '  --delete-simtricity' . "\n" .
              '                    Delete Simtricity data from the database and exit' . "\n";


/****************************************************************************
 * Main (start of code)
 ****************************************************************************/

$startTime = new DateTime();

$deleteSimtricityMode = FALSE;
$readOnlyMode = FALSE;
$updateSitesAndMeters = FALSE;

if ($argc > 1)
{
    // There were some options/arguments.  Process them...

    $parameters = array('l' => '',
                         'h' => 'help',
                         'i:' => 'ini-file:',
                         'R' => 'readonly',
                         'u' => '',
                         'v::' => 'verbose::',
                         'delete-simtricity',
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

    if (optionUsed('u', $options, $parameters))
    {
        $updateSitesAndMeters = TRUE;
    }

    if (optionUsed('delete-simtricity', $options, $parameters))
    {
        $deleteSimtricityMode = TRUE;
    }

}


// ini contains defaults which can be overridden by the ini file
$ini = array(// Database
              'database_type' => 'mysql',
              'database_host' => 'localhost',
              'database_name' => 'bec',
              'database_username' => 'www-data',
              'database_user_password' => '',
              // Simtricity
              'simtricity_base_uri' => 'https://trial.simtricity.com',
              'simtricity_token_path' => __DIR__ . '/simtricity_token.txt',
              );

// Read configuration from ini file to override defaults
if (file_exists($iniFilename))
{
    $ini = array_merge($ini, parse_ini_file($iniFilename));
}
else if ($iniFilename != BECFM_INI_FILENAME)
{
    die("Error: Requested ini file '$iniFilename' not found\n");
}

// Connect to the BEC database
$becDB = new BECDB($ini['database_type'], $ini['database_host'],
                   $ini['database_name'], $ini['database_username'],
                   $ini['database_user_password']);

if ($deleteSimtricityMode)
{
    // Drop Simtricity reading tables
    $meterInfo = $becDB->getMeterInfoArray();
    $result = TRUE;
    foreach ($meterInfo as $meter)
    {
        $tableName = 'dailyreading_' . $becDB->meterDBName($meter['code']);
        $res = $becDB->exec('DROP TABLE ' . $tableName);
        if ($res === FALSE)
        {
            print("Error: Failed when trying to remove table $tableName\n");
            $result = $res;
        }
    }

    // Drop power, sites and meters tables
    $tables = array('power', 'sites', 'meters');
    foreach ($tables as $table)
    {
        $res = $becDB->exec('DROP TABLE ' . $table);
        if ($res === FALSE)
        {
            print("Error: Failed when trying to remove table '$table'\n");
            $result = $res;
        }
    }
    exit($result !== FALSE ? 0 : 1);
}

// Normal processing mode

if (!$readOnlyMode)
{
    $simtricity = new BECSimtricity();
    if ($updateSitesAndMeters)
    {
        // Update list of sites and meters from Simtricity
        $simtricity->updateSiteDataFromSimtricty($becDB, 'sites');
        $simtricity->updateMeterDataFromSimtricty($becDB, 'meters');
    }

    // Pull reading & power data for the generation meter
    // First get the info on the meter we want to fetch for
    $shortcode = file_get_contents('/home/pi/becshortcode');
    $shortcode = trim($shortcode);
    $meter = $becDB->getGenMeterInfoForSite($shortcode);
    if ($meter)
    {
        $simtricity->updateMeterReadings($becDB, $meter);
        $simtricity->updateMeterPowerData($becDB, $meter);
    }
}

// Reporting
ReportLog::prepend("BEC Stand-Alone Display Update Log\n" .
                   "==================================\n\n" .
                   "Start time: " . $startTime->format('d/m/Y H:i') . " (UTC)\n\n");
$report = ReportLog::get();
print($report . "\n\n");

// Write it to a local file so we can see it ran
file_put_contents('last.log', $report);

exit(0);
?>
