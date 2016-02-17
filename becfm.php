#!/usr/bin/php
<?php

/**
 * Bristol Energy Cooperative Fault Monitoring PHP script
 *
 * In its default operating mode, this script will:
 *  - TODO: Pull in any new meteorological readings from the Create Centre roof from the
 *    associated gmail account
 *  - TODO: Update the list of BEC arrays from the master list on the Simtricity platform
 *  - TODO: Pull in any new generation data from the Simtricity platform
 *  - Pull in any new cloudiness data from forecast.io (TODO: or a better source?)
 *  - TODO: Compare solar radiation and generation data and record where generation is not
 *    as high as we might expect given historical generation
 *  - TODO: Create an HTML report
 *  - TODO: Email report details to BEC maintenance team so they can review whether action
 *    is required (e.g. cleaning)
 *
 * It uses a BEC database to store meteorological data from the Create Centre
 * roof and generation data from each of the BEC arrays.
 **/

// Report all PHP errors
error_reporting(E_ALL);

// Only allow execution from a command line launch
if (php_sapi_name() != 'cli')
{
    print("Error: This application must be run from a command line\n");
    exit(1);
}

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

// Stuff to the Google Gmail and Overcast APIs
require __DIR__ . '/vendor/autoload.php';
define('GMAIL_SCOPES', implode(' ', array(//Google_Service_Gmail::GMAIL_LABELS,
                                           Google_Service_Gmail::GMAIL_MODIFY,
                                           //Google_Service_Gmail::GMAIL_READONLY,
                                           Google_Service_Gmail::GMAIL_COMPOSE)));

define('TEMP_DIR', '/tmp');

// BEC database stuff
define('BEC_DB_CREATE_CENTRE_RAW_TABLE', 'create_centre_meteo_raw');
define('BEC_DB_FORECAST_IO_TABLE', 'weather_forecastio');
define('CAN_USE_LOAD_DATA_INFILE', FALSE);

// The email address Create Centre emails come from
define('CREATE_CENTRE_EMAIL_ADDR', 'info@bristol.airqualitydata.com');

// Array to map Create Centre substance strings to database field names
$CREATE_CENTRE_SUBSTANCES = array('Relative Humidity' => 'rel_humidity',
                                   'Air Temperature' => 'air_temp',
                                   'Rainfall' => 'rain',
                                   'Solar Radiation' => 'sol_rad');

// Forecast.io latitude and longitude to use
define('FORECAST_IO_LAT', 51.459);
define('FORECAST_IO_LONG', -2.602);

// Verbose output?
$verbose = FALSE;

require 'becdb.php';
require 'becforecastio.php';
require 'becgmail.php';
require 'becsimtricity.php';

/*****************************************************************************/

/**
 * Expands the home directory alias '~' to the full path.
 * @param string $path the path to expand.
 * @return string the expanded path.
 */
function expandHomeDirectory($path)
{
    $homeDirectory = getenv('HOME');
    if (empty($homeDirectory))
    {
        $homeDirectory = getenv("HOMEDRIVE") . getenv("HOMEPATH");
    }
    return str_replace('~', realpath($homeDirectory), $path);
}


// Command line options:
$helpString = "Usage: php $argv[0] <options>\n" .
              'where <options> are:' . "\n" .
              '  -h | --help       Display this usage message' . "\n" .
              '  -l                List arrays already in BEC database and exit' . "\n" .
              '  -u                Update array list from Simtricity and exit' . "\n" .
              '  -v                Verbose output' . "\n" .
              '  --array <array>   Run only for arrays given via one or more --array options (default is to run for all arrays)' . "\n" .
              '  --delete-create-centre-raw-table' . "\n" .
              '                    Delete the Create Centre meteo raw data table and delete the IMPORTED label from the gmail account so everything will be re-imported, then exit' . "\n" .
              '  --delete-all-simtricity-raw-tables' . "\n" .
              '                    Delete all of the Simtricity meter reading raw data tables and exit' . "\n" .
              '  --html-report-dir <path>' . "\n" .
              '                    Location to write the HTML report (default is /var/www/bec-gen-report)' . "\n" .
              '  --no-html-report  Supress creation of HTML report (default is to create an HTML report a web browser can read)' . "\n" .
              '  --run-read-only   Run on existing data and report only to screen; do not alter database or Gmail account (send emails) and don\'t create HTML report' . "\n" .
              '  --temp-dir <path> Override path to the temporary directory (default is /tmp)' . "\n";


/****************************************************************************
 * Main (start of code)
 ****************************************************************************/

// Throw away name of script from $argv
unset($argv[0]);

$deleteCCRMode = FALSE;
$deleteSimtricityMode = FALSE;

if ($argc > 1)
{
    // There were some options/arguments.  Process them...

    $parameters = array('l' => '',
                         'h' => 'help',
                         'i:' => 'ini-file:',
                         'u' => '',
                         'v' => 'verbose',
                         'array:',
                         'delete-create-centre-raw-table',
                         'delete-all-simtricity-raw-tables',
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

    // Error if anything left in $argv
    if (count($argv) > 0)
    {
        print('Error: Unrecogised command line options: ' . join(' ', $argv) . "\n");
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
    function optionUsed($option, $options, $parameters)
    {
        $shortOptUsed = key_exists($option, $options);
        $longOptUsed = FALSE;
        if (key_exists($option, $parameters))
        {
            $longOptUsed = key_exists($parameters[$option], $options);
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
            if ($options[$parameters[$option]] != FALSE)
            {
                return $options[$parameters[$option]];
            }
            else
            {
                return TRUE;
            }
        }
        return FALSE;
    }

    if (optionUsed('v', $options, $parameters))
    {
        // Enable verbose output
        $verbose = TRUE;
    }

    if ($t = optionUsed('i', $options, $parameters))
    {
        // Enable verbose output
        $iniFilename = $t;
    }

    if (optionUsed('h', $options, $parameters))
    {
        // Help!
        print($helpString);
        exit(0);
    }

    if (optionUsed('l', &$options, &$parameters))
    {
        // Just list the known arrays in the database and exit
        listArrays();
        exit(0);
    }

    if (optionUsed('u', $options, &$parameters))
    {
        // Just update the arrays in the database from Simtricity and exit
        print("Checking for new arrays from Simtricity platform...\n");

        // TODO: Check for new arrays


        listArrays();
        exit(0);
    }

    if (optionUsed('delete-create-centre-raw-table', $options, $parameters))
    {
        $deleteCCRMode = TRUE;
    }

    if (optionUsed('delete-all-simtricity-raw-tables', $options, $parameters))
    {
        if ($deleteCCRMode)
        {
            print("Warning: The --delete-all-simtricity-raw-tables option will be ignored as --delete-create-centre-raw-table was specified too\n");
        }
        $deleteSimtricityMode = TRUE;
    }

}


// ini contains defaults which can be overriden by the ini file
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
              // Simtricity
              'simtricity_base_uri' => 'https://trial.simtricity.com',
              'simtricity_token_path' => __DIR__ . '/simtricity_token.txt',
              // Forecast.io
              'forecast_io_api_key_path' => __DIR__ . '/forecast_io_api_key.txt'
              );

// Read configuration from ini file to override defaults
if (file_exists($iniFilename))
{
    $ini = array_merge($ini, parse_ini_file($iniFilename));
}

// Connect to the BEC database
$becDB = new BECDB($ini['database_type'], $ini['database_host'],
                   $ini['database_name'], $ini['database_username'],
                   $ini['database_user_password']);
if (DEBUG)
{
    $dateTimes = $becDB->getDateTimeExtremesFromTable(BEC_DB_CREATE_CENTRE_RAW_TABLE);
    print_r($dateTimes);
}

// Create Centre data: get the Gmail API client and construct the service object.
$gmail = new BECGmailWrapper();

if ($deleteCCRMode)
{
    // Drop the Create Centre table from the database
    $result = $becDB->exec('DROP TABLE ' . BEC_DB_CREATE_CENTRE_RAW_TABLE);
    if ($result !== FALSE)
    {
        $result = $gmail->deleteLabel('IMPORTED');
    }
    exit($result !== FALSE ? 0 : 1);
}

if ($deleteSimtricityMode)
{
    // Drop all the Simtricity reading and power tables
    $meterInfo = $becDB->getMeterInfoArray();
    $result = TRUE;
    foreach ($meterInfo as $meter)
    {
        foreach (array('dailyreading_', 'power_') as $prefix)
        {
            $tableName = $prefix . $becDB->meterTableName($meter['code']);
            $res = $becDB->exec('DROP TABLE ' . $tableName);
            if ($res === FALSE)
            {
                print("Error: Failed when trying to remove table $tableName\n");
                $result = $res;
            }
        }
    }
    exit($result !== FALSE ? 0 : 1);
}

// Normal processing mode

// Import any new Create Centre data from Gmail account
if (FALSE === $gmail->importNewMeteoData($becDB))
{
    die('Error: Failed while importing Create Centre meteorlogical data' . "\n");
}

// Update list of sites and meters from Simtricity
$simtricity = new BECSimtricity();
if (DEBUG)
{
    $meters = $simtricity->getListOfMeters();
    print("\nMeter list:\n");
    print_r($meters);
    $sites = $simtricity->getListOfSites();
    print("\nSite list:\n");
    print_r($sites);
}
$simtricity->updateSiteDataFromSimtricty($becDB, 'sites');
$simtricity->updateMeterDataFromSimtricty($becDB, 'meters');


// Pull reading & power data for all Simtricity meters
// TODO: Move functions into becdb.php and pass in $simtricity so it can be used to retrieve data
$simtricity->updateAllMeterReadings($becDB);
$simtricity->updateAllMeterPowerTables($becDB);

// Pull weather data from forecast.io
$forecastIO = new BECForecastIO($ini['forecast_io_api_key_path']);

if (DEBUG)
{
    $forecastIO->clearTimes($becDB);
    $data = $becDB->getClearGenAndSolRadData();
    exit(1); // FIXME: REMOVE
}

$becDB->updateForecastIOHistory($forecastIO);

{
    {
        {
        }
    }
}

// Compare solar radiation and generation readings


// HTML report

// Generate graphs
if ($becDB->graphsEnabled)
{
    if (!file_exists('graphs'))
    {
        mkdir('graphs');
    }
    foreach ($becDB->getMeterInfoArray() as $meter)
    {
        $niceMeterName = $becDB->meterTableName($meter['code']);
        $powerTable = 'power_' . $niceMeterName;

        // Skip if table has no data
        if (!$becDB->rowsInTable($powerTable))
        {
            if ($verbose > 0)
            {
                print("Skipping graph for table $powerTable as it has no data\n");
            }
            continue;
        }

        $fullDateRange = $becDB->getDateTimeExtremesFromTable($powerTable);

        if ($fullDateRange)
        {
            $interval = $fullDateRange[0]->diff($fullDateRange[1]);
            if ($interval->y > 1 || $interval->m > 1 || $interval->d > 28)
            {
                // Show 28 days at a time (going backwards)
                $upperDate = clone $fullDateRange[1];
                $lowerDate = clone $upperDate;
                $fourWeeks = new DateInterval('P4W');
                $lowerDate->sub($fourWeeks);
                while ($lowerDate->getTimestamp() >= $fullDateRange[0]->getTimestamp())
                {
                    $dateRange = array($lowerDate, $upperDate);
                    $becDB->createGraphImage("graphs/${niceMeterName}_" . $lowerDate->format('Ymd') . '.png',
                                             $powerTable,
                                             BEC_DB_CREATE_CENTRE_RAW_TABLE,
                                             $dateRange);
                    $lowerDate->sub($fourWeeks);
                    $upperDate->sub($fourWeeks);
                }
            }
            else
            {
                // Show all
                $becDB->createGraphImage("graphs/$niceMeterName" . '.png',
                                         $powerTable,
                                         BEC_DB_CREATE_CENTRE_RAW_TABLE,
                                         $fullDateRange);
            }

        }
    }
}
else if ($verbose > 0)
{
    print("Graph generation can be enabled by downloading PHPGraphLib, putting " .
           "the phpgraphlib directory on the PHP include path (e.g. in the same " .
           "directory as these PHP scripts).\n" .
           "It can be git cloned using:\n" .
           "\t git clone https://github.com/elliottb/phpgraphlib.git\n" .
           "Website:\n" .
           "\thttp://www.ebrueggeman.com/phpgraphlib\n");
}

// Alert emails


exit(0);
?>
