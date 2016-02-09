<?php

include "phpgraphlib/phpgraphlib.php";

/**
 * Class encapsulating actions on the BEC database
 * @author David Cook
 */
class BECDB
{
	// PDO handle to the BEC database
	private $dbHandle;


	/**
	 * Function to connect to a PDO database
	 * @param string $driver
	 * @param string $host
	 * @param string $dbName
	 * @param string $user
	 * @param string $password
	 */
	protected function connect($driver, $host, $dbName, $user, $password) {
		$this->dbHandle = new PDO("$driver: host=$host;dbname=$dbName", $user, $password);
		if ($this->dbHandle === FALSE) {
			echo "Error: Failed to connect to $driver '$dbName' database";
			return NULL;
		}
		if (DEBUG) {
			print_r($this->dbHandle);
			print_r($this->dbHandle->errorInfo());
		}
	}


	/**
	 * Constructor for the BECDB class - makes a connection to a database
	 * @param string $driver
	 * @param string $host
	 * @param string $dbName
	 * @param string $user
	 * @param string $password
	 */
	public function __construct($driver, $host, $dbName, $user, $password) {
		$this->connect($driver, $host, $dbName, $user, $password);
		return $this;
	}


	/**
	 * Prepare an SQL command for use on the database
	 * @param string $sql SQL command to prepare
	 * @return Returns the result of the PDO::prepare function
	 */
	public function prepare($sql) {
		if (FALSE === ($result = $this->dbHandle->prepare($sql))) {
			print("Error: Failed to prepare SQL command '$sql'\n");
			print_r($this->dbHandle->errorInfo());
		}
		return $result;
	}


	/**
	 * Execute an SQL command on the database
	 * @param string $sql SQL command to execute
	 * @return Returns the number of rows affected, or FALSE on failure
	 */
	public function exec($sql) {
		if (FALSE === ($result = $this->dbHandle->exec($sql))) {
			print("Error: Failed to execute SQL command '$sql'\n");
			print_r($this->dbHandle->errorInfo());
		}
		return $result;
	}


	/**
	 * Returns TRUE if a view or table is present, FALSE if it is not and NULL on any
	 * other error.
	 * @param string $tableName Name of the view or table to check for
	 */
	function isTablePresent($tableName) {
		// We only need to try and create the query to see if the table exists
		$query = $this->dbHandle->query('DESCRIBE ' . $tableName);
		if (FALSE === $query) {
			$err = $this->dbHandle->errorInfo();
			if (preg_match('/Table.*does ?n.t exist/i', $err[2])) {
				return FALSE;
			}
			// A different error
			print_r($this->dbHandle->errorInfo());
			return NULL;
		}
		return TRUE;
	}


	/**
	 * Run an SQL query and return the result (or FALSE on failure)
	 * @param string $sql The SQL query to run
	 * @return string The result of the SQL query, or FALSE on failure
	 */
	public function fetchQuery($sql) {
		if (FALSE === ($query = $this->dbHandle->query($sql))) {
			print("Error: Failed to create query '$sql'\n");
			print_r($this->dbHandle->errorInfo());
			return FALSE;
		}
		$result = $query->fetchAll();
		if (FALSE === $result) {
			print("Error: Failed to store result of query '$sql'\n");
			print_r($this->dbHandle->errorInfo());
		}
		return $result;
	}



	/**
	 * Retrieve the earliest & latest DateTimes from the 'datetime' field in a named SQL table
	 * @param string $table
	 * @return DateTime[2] An array with the earliest and latest DateTimes in
	 * 			the given table, or FALSE on failure/no entries
	 */
	function getDateTimeExtremesFromTable($table)
	{
		if (!$this->isTablePresent($table)) {
			print("Time extremes not checked as table/view '$table' not present\n");
			return FALSE;
		}
		// Find out whether the given table has separate date and time or not
		$result = $this->fetchQuery('DESCRIBE ' . $table);
		if (DEBUG) {
			print_r($result);
		}
		$i = 0;
		foreach ($result as $column) {
			$fieldName[$i++] = $column['Field'];
		}

		if ($result != FALSE && FALSE !== array_search('datetime', $fieldName)) {
			$result = array();
			$result[0] = $this->fetchQuery('SELECT MIN(datetime) FROM ' . $table);
			$result[1] = $this->fetchQuery('SELECT MAX(datetime) FROM ' . $table);
			if (DEBUG) print_r($result);
			if (FALSE === $result[0] || NULL === $result[0][0][0] || FALSE === $result[1] || NULL === $result[1][0][0]) {
				print("Error: Failed to retrieve earliest and latest datetimes from table '$table'\n");
				return FALSE;
			}

			return array(new DateTime($result[0][0][0]), new DateTime($result[1][0][0]));
		} else {
			return FALSE;
		}
	}

	/**
	 * Import the content of a CSV file from the Create Centre roof weather
	 * station.  This will merge with existing data if already present, or
	 * create a new table if it doesn't already exist.
	 * The separate date and time fields will be merged into a single DateTime
	 * field.
	 * The final field on each line (the data type, e.g. °C, % or W/m2) will be
	 * ignored.
	 * Note: This only supports the file format where there is only 1 substance
	 * recorded in each CSV file.
	 *
	 * @param string $table The name of the table to import to
	 * @param string $filename The name of the CSV file containing the data to import
	 */
	function importCreateCentreCSV($table, $filename) {
		// Does $table exist?
		if (!$this->isTablePresent($table)) {
			// Create the table
			if (FALSE === $this->exec("CREATE TABLE $table (datetime DATETIME NOT NULL UNIQUE)")) {
				print("Failed to create table '$table'\n");
				return FALSE;
			}
		}

		// Table exists now!  See what we have in the CSV file...
		$csvFile = fopen($filename, 'rb');
		if (FALSE === $csvFile) {
			print("Error: Failed to open CSV file '$filename'\n");
			return FALSE;
		}
		$substance = FALSE;
		while (!$substance) {
			if (FALSE=== ($string = fgets($csvFile))) {
				break;
			}
			if (stristr($string, 'Substance')) {
				global $CREATE_CENTRE_SUBSTANCES;
				foreach ($CREATE_CENTRE_SUBSTANCES as $csvName => $dbName) {
					if (stristr($string, $csvName)) {
						$substance = $dbName;
						break;
					}
				}
			}
		}
		if (!$substance) {
			print("Error: Failed to identify substance CSV file reports on\n");
			return FALSE;
		}

		// Ensure the column for this substance exists in the table
		$result = $this->exec("ALTER TABLE $table ADD COLUMN $substance DECIMAL(10,3)");
		$columnExisted = FALSE;
		if (FALSE === $result) {
			$err = $this->dbHandle->errorInfo();
			if (DEBUG) {
				print_r($err);
			}
			if (preg_match('/Duplicate column name/', $err[2])) {
				$columnExisted = TRUE;
			} else {
				print("Error: Database communication error");
				return NULL;
			}
		}

		if (CAN_USE_LOAD_DATA_INFILE) {
			fclose($csvFile);
			// Copy CSV file, merge date & time and stripping headers and trailing data type field
			processCreateCentreCSV($filename, $filename . '.import');
			$this->fetchQuery("");


		} else {
			// Add rows one at a time...

			// Read up to first data
			$found = FALSE;
			while (!$found) {
				if (FALSE === ($string = fgets($csvFile))) {
					break;
				}
				if (stristr($string, '"Date","Time","Reading"')) {
					$found = 1;
				}
			}
			if (!$found) {
				print("Error: Failed to skip through CSV file to readings\n");
				return FALSE;
			}

			// Add the data (Warning: ON DUPLICATE KEY UPDATE is MySQL-specific)
			$stmt = $this->prepare("INSERT INTO $table (datetime, $substance) VALUES(:dt, :r)
									ON DUPLICATE KEY UPDATE $substance=:r");
			$stmt->bindParam(':dt', $dateTimeStr);
			$stmt->bindParam(':r', $reading);
			while ($data = fgetcsv($csvFile)) {
				$dateTime = new DateTime(preg_replace('@([0-9]+)/([0-9]+)/([0-9]+)@', '$3-$2-$1', $data[0]) . "T" . $data[1]);
				$dateTimeStr = $dateTime->format(DateTime::ISO8601);
				//$dateTimeStr = $data[0] . "T" . $data[1] . "Z";
				$reading = $data[2];
				if (DEBUG) {
					print("Adding $dateTimeStr, $reading\n");
				}
				if (FALSE == $stmt->execute()) {
					print("Error: Failed running insertion '$stmt->queryString'\n");
					if (DEBUG) {
						print_r($this->dbHandle->errorInfo());
					}
					return FALSE;
				}
				if (DEBUG) print_r($stmt);
			}
		}
		// Success!
		return TRUE;
	}


	/**
	 * Make the half-hourly view of the Create Centre solar radiation data
	 * @return boolean TRUE if the table exists or has been successfully created
	 */
	public function mkCreateCentreHalfHourView() {
		if (!$this->isTablePresent('sol_rad_create_centre')) {
			// We could either do this by averaging values, or just taking the instantaneous readings
			// Averaging half hour and half hour + 15 readings:
			//$result = $this->dbHandle->exec('CREATE VIEW sol_rad_create_centre AS select datetime,avg(' . BEC_DB_CREATE_CENTRE_RAW_TABLE . '.radiation) AS radiation FROM ' . BEC_DB_CREATE_CENTRE_RAW_TABLE . ' GROUP BY (to_seconds(' . BEC_DB_CREATE_CENTRE_RAW_TABLE . 'datetime) DIV 1800)');

			// Just take instantaneous readings
			$result = $this->dbHandle->exec('CREATE VIEW sol_rad_create_centre AS select datetime,radiation FROM ' . BEC_DB_CREATE_CENTRE_RAW_TABLE . ' WHERE toseconds(datetime) MOD 1800 = 0');
			if (FALSE === $result) {
				print("Error: Failed to create view 'sol_rad_create_centre' - " . $this->dbHandle->errorInfo() . "\n");
				return FALSE;
			}
		}
		return TRUE;
	}


	public function getMeterInfoArray() {
		$meterInfo = $this->fetchQuery('SELECT serial, type, code FROM meters');
		return $meterInfo;
	}


	public function meterTableName($code) {
		return str_replace('-', '_', strtolower($code));
	}


	public function createGraphImage($imageFilename, $powerTable, $solRadTable, $dateRange = NULL) {
		global $verbose;

		// We inner join the tables on the datetime field.  This is expected to be called on
		// a solar radiation data table and a instananeous power data table so the two can
		// be easily compared.  They will need different y-axes.

		if ($verbose > 0) {
			print("Generating power graph in file $imageFilename\n");
		}

		$sql = "SELECT $powerTable.datetime, power, sol_rad
				FROM $solRadTable INNER JOIN $powerTable
				ON $powerTable.datetime = $solRadTable.datetime";

		$whereClause = '';
		$whereClausePower = '';
		if ($dateRange != NULL) {
			$whereClause = " WHERE DATE($solRadTable.datetime) > '" . $dateRange[0]->format('Y-m-d') . "' &&
								DATE($solRadTable.datetime) < '" . $dateRange[1]->format('Y-m-d') . "'";
			$whereClausePower = str_replace($solRadTable, $powerTable, $whereClause);
		}
		$sql .= $whereClause;

		$data = $this->fetchQuery($sql);

		$maxPower = $this->fetchQuery("SELECT MAX(power) FROM $powerTable" . $whereClausePower);
		$maxSolRad = $this->fetchQuery("SELECT MAX(sol_rad) FROM $solRadTable" . $whereClause);

		$graph = new PHPGraphLib(10000, 1000, $imageFilename);
		$graph->setTitle($powerTable . ' against solar radiation (both scaled to % of maximum recorded value)');
		$graph->setBars(FALSE);
		$graph->setLine(TRUE);
		$graph->setLineColor('red', 'yellow');
		$graph->setLegend(TRUE);
		$graph->setLegendTitle('Power', 'Solar radiation');

		// Reassmble the data into the form needed for PHPGraphLib and scale values so they can be plotted on the same
		// y-axis (a limitation of PHPGraphLib...if we can go GPLv3, we can use PCharts2 which can do multiple y-axes,
		// or there's SVGGraph which is LGPL).
		foreach ($data as $entry) {
			$powerData[$entry['datetime']] = $entry['power'] / $maxPower[0][0] * 100;
			$solRadData[$entry['datetime']] = $entry['sol_rad'] / $maxSolRad[0][0] * 100;
		}
		// Free up memory (maybe!)
		$data = NULL;

		$graph->addData($powerData, $solRadData);

		$graph->createGraph();
	}

}
?>
