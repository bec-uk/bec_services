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
 *  - TODO: Compare solar radiation and generation data and record where generation is not
 *    as high as we might expect given historical generation
 *  - TODO: Create an HTML report
 *  - TODO: Email report details to BEC maintenance team so they can review whether action
 *    is required (e.g. cleaning)
 *
 * It uses a BEC database to store meteorological data from the Create Centre
 * roof and generation data from each of the BEC arrays.
 **/

// Only allow execution from a command line launch
if (php_sapi_name() != 'cli') {
	print("Error: This application must be run from a command line\n");
	exit(1);
}

/******************************************************************************
 * Defines and globals
 *****************************************************************************/

define('DEBUG', TRUE);
if (DEBUG) {
	print("Executing in debug mode\n");
}

define('BECFM_INI_FILENAME', 'becfm.ini');
$iniFilename = BECFM_INI_FILENAME;

// Stuff to the Google Gmail API
require __DIR__ . '/vendor/autoload.php';
define('GMAIL_SCOPES', implode(' ', array(//Google_Service_Gmail::GMAIL_LABELS,
											Google_Service_Gmail::GMAIL_MODIFY,
											//Google_Service_Gmail::GMAIL_READONLY,
											Google_Service_Gmail::GMAIL_COMPOSE)));

define('TEMP_DIR', '/tmp');

// BEC database stuff
define('BEC_DB_CREATE_CENTRE_RAW_TABLE', 'create_centre_meteo_raw');
define('CAN_USE_LOAD_DATA_INFILE', FALSE);

// The email address Create Centre emails come from
define('CREATE_CENTRE_EMAIL_ADDR', 'info@bristol.airqualitydata.com');

// Array to map Create Centre substance strings to database field names
$CREATE_CENTRE_SUBSTANCES = array('Relative Humidity' => 'rel_humidity',
									'Air Temperature' => 'air_temp',
									'Rainfall' => 'rain',
									'Solar Radiation' => 'sol_rad');

// Verbose output?
$verbose = FALSE;

require 'becdb.php';
require 'becgmail.php';
require 'becsimtricity.php';

/*****************************************************************************/

/**
 * Expands the home directory alias '~' to the full path.
 * @param string $path the path to expand.
 * @return string the expanded path.
 */
function expandHomeDirectory($path) {
	$homeDirectory = getenv('HOME');
	if (empty($homeDirectory)) {
		$homeDirectory = getenv("HOMEDRIVE") . getenv("HOMEPATH");
	}
	return str_replace('~', realpath($homeDirectory), $path);
}


// Command line options:
$helpString = "Usage: php $argv[0] <options>\n" .
			'where <options> are:' . "\n" .
			'  -h | --help	Display this usage message' . "\n" .
			'  -l		List arrays already in BEC database and exit' . "\n" .
			'  -u		Update array list from Simtricity and exit' . "\n" .
			'  -v		Verbose output' . "\n" .
			'  --array <array>		Run only for arrays given via one or more --array options (default is to run for all arrays)' . "\n" .
			'  --delete-create-centre-raw-table' . "\n" .
			"\t\t\t\t" . 'Delete the Create Centre meteo raw data table and delete the IMPORTED label from the gmail account so everything will be re-imported' . "\n" .
			'  --html-report-dir <path>' . "\n" .
			"\t\t\t\t" . 'Location to write the HTML report (default is /var/www/bec-gen-report)' . "\n" .
			'  --no-html-report	Supress creation of HTML report (default is to create an HTML report a web browser can read)' . "\n" .
			'  --run-read-only		Run on existing data and report only to screen; do not alter database or Gmail account (send emails) and don\'t create HTML report' . "\n" .
			'  --temp-dir <path>	Override path to the temporary directory (default is /tmp)' . "\n";


/****************************************************************************
 * Main (start of code)
 ****************************************************************************/

// Throw away name of script from $argv
unset($argv[0]);

$deleteCCRMode = FALSE;

if ($argc > 1) {
	// There were some options/arguments.  Process them...

	$parameters = array(
		'l' => '',
		'h' => 'help',
		'i:' => 'ini-file:',
		'u' => '',
		'v' => 'verbose',
		'array:',
		'delete-create-centre-raw-table',
		'html-report-dir:',
		'no-html-report',
		'run-read-only',
		'temp-dir'
	);

	// Grab command line options into $options
	$options = getopt(implode('', array_keys($parameters)), $parameters);

	// Prune the $argv array of options we'll be processing
	$pruneargv = array();
	foreach ($options as $option => $value) {
		foreach ($argv as $key => $chunk) {
			$regex = '/^'. (isset($option[1]) ? '--' : '-') . $option . '/';
			if ($chunk == $value && $argv[$key-1][0] == '-' || preg_match($regex, $chunk)) {
				array_push($pruneargv, $key);
			}
		}
	}
	while ($key = array_pop($pruneargv)) unset($argv[$key]);

	// Error if anything left in $argv
	if (count($argv) > 0) {
		print('Error: Unrecogised command line options: ' . join(' ', $argv) . "\n");
		exit(1);
	}

	// Process $options

	function optionUsed($option, $options, $parameters) {
		$shortOptUsed = key_exists($option, $options);
		$longOptUsed = FALSE;
		if (key_exists($option, $parameters)) {
			$longOptUsed = key_exists($parameters[$option], $options);
		}
		if ($shortOptUsed) {
			if ($options[$option] != FALSE) {
				return $options[$option];
			} else {
				return TRUE;
			}
		} else if ($longOptUsed) {
			if ($options[$parameters[$option]] != FALSE) {
				return $options[$parameters[$option]];
			} else {
				return TRUE;
			}
		}
		return FALSE;
	}

	if (optionUsed('v', $options, $parameters)) {
		// Enable verbose output
		$verbose = TRUE;
	}

	if ($t = optionUsed('i', $options, $parameters)) {
		// Enable verbose output
		$iniFilename = $t;
	}

	if (optionUsed('h', $options, $parameters)) {
		// Help!
		print($helpString);
		exit(0);
	}

	if (optionUsed('l', &$options, &$parameters)) {
		// Just list the known arrays in the database and exit
		listArrays();
		exit(0);
	}

	if (optionUsed('u', $options, &$parameters)) {
		// Just update the arrays in the database from Simtricity and exit
		print("Checking for new arrays from Simtricity platform...\n");

		// TODO: Check for new arrays


		listArrays();
		exit(0);
	}

	if (optionUsed('delete-create-centre-raw-table', $options, $parameters)) {
		$deleteCCRMode = TRUE;
	}

}




// ini contains defaults which can be overriden by the ini file
$ini = array(	// Database
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
				//Simtricity
				'simtricity_base_uri' => 'https://trial.simtricity.com',
				'simtricity_token_path' => __DIR__ . '/simtricity_token.txt'
				);

// Read configuration from ini file to override defaults
if (file_exists($iniFilename)) {
	$iniValues = parse_ini_file($iniFilename);
}

// Connect to the BEC database
$becDB = new BECDB($iniValues['database_type'], $iniValues['database_host'],
					$iniValues['database_name'], $iniValues['database_username'],
					$iniValues['database_user_password']);
if (DEBUG) {
	$dateTimes = $becDB->getDateTimeExtremesFromTable(BEC_DB_CREATE_CENTRE_RAW_TABLE);
	print_r($dateTimes);
}

// Get the Gmail API client and construct the service object.

$gmail = new BECGmailWrapper();

if ($deleteCCRMode) {
	// Drop the Create Centre table from the database
	$result = $becDB->exec('DROP TABLE ' . BEC_DB_CREATE_CENTRE_RAW_TABLE);
	if ($result !== FALSE) {
		$result = $gmail->deleteLabel('IMPORTED');
	}
	exit($result !== FALSE ? 0 : 1);
}

// Normal processing mode

// Import any new Create Centre data
if (FALSE === $gmail->importNewMeteoData($becDB)) {
	die('Error: Failed while importing Create Centre meteorlogical data' . "\n");
}

// Ensure the half-hourly view of the Create Centre data exists
if (FALSE === $becDB->mkCreateCentreHalfHourView()) {
	die('Error: Failed to create half-hourly view or Create Centre solar radiation data' . "\n");
}

// Update list of sites and meters from Simtricity
$simtricity = new BECSimtricity();
$meters = $simtricity->getListOfMeters();
print("\nMeter list:\n");
print_r($meters);
$sites = $simtricity->getListOfSites();
print("\nSite list:\n");
print_r($sites);


$simtricity->updateSiteDataFromSimtricty($becDB, 'sites');
$simtricity->updateMeterDataFromSimtricty($becDB, 'meters');


// Pull reading & power data for all Simtricity meters
// TODO: Move functions into becdb.php and pass in $simtricity so it can be used to retrieve data
$simtricity->updateAllMeterReadings($becDB);
$simtricity->updateAllMeterPowerTables($becDB);

// Compare solar radiation and generation readings


// HTML report

// Generate graphs
foreach ($becDB->getMeterInfoArray() as $meter) {
	$niceMeterName = $becDB->meterTableName($meter['code']);
	$powerTable = 'power_' . $niceMeterName;

	$fullDateRange = $becDB->getDateTimeExtremesFromTable($powerTable);

	if ($fullDateRange) {
		$interval = $fullDateRange[0]->diff($fullDateRange[1]);
		if ($interval->y > 1 || $interval->m > 1 || $interval->d > 28) {
			// Show 28 days at a time
			$lowerDate = $fullDateRange[0];
			$upperDate = clone $lowerDate;
			$fourWeeks = new DateInterval('P4W');
			$upperDate->add($fourWeeks);
			while ($lowerDate->getTimestamp() < $fullDateRange[1]->getTimestamp()) {
				$becDB->createGraphImage($niceMeterName . '_' . $lowerDate->format('Ymd') . '.png',
											$powerTable,
											BEC_DB_CREATE_CENTRE_RAW_TABLE,
											array($lowerDate, $upperDate));
				$lowerDate->add($fourWeeks);
				$upperDate->add($fourWeeks);
			}
		} else {
			// Show all
			$becDB->createGraphImage($niceMeterName . '.png',
										$powerTable,
										BEC_DB_CREATE_CENTRE_RAW_TABLE,
										$fullDateRange);
		}

	}
}

// Alert emails


exit(0);
?>
