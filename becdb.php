<?php

$graphsEnabled = include "phpgraphlib/phpgraphlib.php";

/**
 * Class encapsulating actions on the BEC database
 * @author David Cook
 */
class BECDB
{
    // PDO handle to the BEC database
    private $dbHandle;
    public $graphsEnabled;


    /**
     * Function to connect to a PDO database
     * @param string $driver
     * @param string $host
     * @param string $dbName
     * @param string $user
     * @param string $password
     */
    protected function connect($driver, $host, $dbName, $user, $password)
    {
        $this->dbHandle = FALSE;
        try
        {
            $this->dbHandle = new PDO("$driver:host=$host;dbname=$dbName", $user, $password);
        }
        catch (Exception $e)
        {
                print('Exception: ' . $e->getMessage() . "\n");
        }
        if ($this->dbHandle === FALSE)
        {
            echo "Error: Failed to connect to $driver '$dbName' database";
            return NULL;
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
    public function __construct($driver, $host, $dbName, $user, $password)
    {
        global $graphsEnabled;

        $this->graphsEnabled = $graphsEnabled;
        $this->connect($driver, $host, $dbName, $user, $password);
        return $this;
    }


    /**
     * Prepare an SQL command for use on the database
     * @param string $sql SQL command to prepare
     * @return Returns the result of the PDO::prepare function
     */
    public function prepare($sql)
    {
        if (FALSE === ($result = $this->dbHandle->prepare($sql)))
        {
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
    public function exec($sql)
    {
        if (FALSE === ($result = $this->dbHandle->exec($sql)))
        {
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
    function isTablePresent($tableName)
    {
        // We only need to try and create the query to see if the table exists
        $query = $this->dbHandle->query('DESCRIBE ' . $tableName);
        if (FALSE === $query)
        {
            $err = $this->dbHandle->errorInfo();
            if (preg_match('/Table.*does ?n.t exist/i', $err[2]))
            {
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
     * @param bitmask $params Optional bitmask of parameters to pass into the
     * 			       PDO::fetchAll() function - default to associative
     * 			       array results (pass in NULL to include numbered
     * 			       indexes too)
     * @return string The result of the SQL query, or FALSE on failure
     */
    public function fetchQuery($sql, $params = PDO::FETCH_ASSOC)
    {
        global $verbose;

        if ($verbose > 4)
        {
            print(__FUNCTION__ . ': SQL query: ' . $sql . "\n");
        }

        if (FALSE === ($query = $this->dbHandle->query($sql)))
        {
            print("Error: Failed to create query '$sql'\n");
            print_r($this->dbHandle->errorInfo());
            return FALSE;
        }
        $result = $query->fetchAll($params);
        if (FALSE === $result)
        {
            print("Error: Failed to store result of query '$sql'\n");
            print_r($this->dbHandle->errorInfo());
        }
        return $result;
    }



    /**
     * Retrieve the earliest & latest DateTimes from the 'datetime' field in a named SQL table
     * @param string $table
     * @param string Optionally require the column named $popColumn not to be NULL for all dates considered
     * @return DateTime[2] An array with the earliest and latest DateTimes in
     *             the given table, or FALSE on failure/no entries
     */
    function getDateTimeExtremesFromTable($table, $popColumn = NULL)
    {
        if (!$this->isTablePresent($table))
        {
            print("Time extremes not checked as table/view '$table' not present\n");
            return FALSE;
        }
        // Find out whether the given table has separate date and time or not
        $result = $this->fetchQuery('DESCRIBE ' . $table);
        if (DEBUG)
        {
            print_r($result);
        }
        $i = 0;
        foreach ($result as $column)
        {
            $fieldName[$i++] = $column['Field'];
        }

        if ($result != FALSE && FALSE !== array_search('datetime', $fieldName))
        {
            $result = array();
            $whereClause = '';
            if ($popColumn)
            {
                $whereClause = " WHERE $popColumn IS NOT NULL";
            }
            $result[0] = $this->fetchQuery('SELECT MIN(datetime) FROM ' . $table . $whereClause, NULL);
            $result[1] = $this->fetchQuery('SELECT MAX(datetime) FROM ' . $table . $whereClause, NULL);
            if (DEBUG)
            {
                print_r($result);
            }
            if (FALSE === $result[0] || NULL === $result[0][0][0] ||
                FALSE === $result[1] || NULL === $result[1][0][0])
            {
                print("Error: Failed to retrieve earliest and latest datetimes from table '$table'$whereClause\n");
                return FALSE;
            }

            return array(new DateTime($result[0][0][0]), new DateTime($result[1][0][0]));
        }
        else
        {
            return FALSE;
        }
    }


    /**
     * Function which returns the number of rows in the table
     * @param string Name of table
     * @param string Optional name of column in which data must not be NULL (populated column)
     * @return Number of rows in the table (0 if doesn't exist)
     */
    public function rowsInTable($table, $popColumn = NULL)
    {
        $whereClause = '';
        if ($popColumn)
        {
            $whereClause = " WHERE $popColumn IS NOT NULL";
        }
        $rowCount = $this->fetchQuery("SELECT COUNT(1) FROM $table$whereClause", NULL);
        if (count($rowCount) > 0)
            return $rowCount[0][0];
        else
          return 0;
    }


    /**
     * Return TRUE if table has a column with the given name, otherwise FALSE
     *
     * @param string $table The name of the table to examine
     * @param string $columnName The name of the column to look for
     * @return TRUE if column exists in table, else FALSE (including on error)
     */
    public function tableHasColumn($table, $columnName)
    {
        $query = $this->dbHandle->query('DESCRIBE ' . $table);
        if (FALSE === $query)
        {
            return FALSE;
        }
        $desc = $query->fetchAll();
        if ($desc)
        {
            foreach ($desc as $column)
            {
                if ($column['Field'] == $columnName)
                {
                    return TRUE;
                }
            }
        }
        return FALSE;
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
    function importCreateCentreCSV($table, $filename)
    {
        // Create the table if needed
        if (FALSE === $this->exec("CREATE TABLE IF NOT EXISTS $table
                                        (datetime DATETIME NOT NULL UNIQUE,
                                         PRIMARY KEY(datetime))"))
        {
            print("Failed to create table '$table'\n");
            return FALSE;
        }

        // Table exists now!  See what we have in the CSV file...
        $csvFile = fopen($filename, 'rb');
        if (FALSE === $csvFile)
        {
            print("Error: Failed to open CSV file '$filename'\n");
            return FALSE;
        }
        $substance = FALSE;
        while (!$substance)
        {
            if (FALSE=== ($string = fgets($csvFile)))
            {
                break;
            }
            if (stristr($string, 'Substance'))
            {
                global $CREATE_CENTRE_SUBSTANCES;
                foreach ($CREATE_CENTRE_SUBSTANCES as $csvName => $dbName)
                {
                    if (stristr($string, $csvName))
                    {
                        $substance = $dbName;
                        break;
                    }
                }
            }
        }
        if (!$substance)
        {
            print("Error: Failed to identify substance CSV file reports on\n");
            return FALSE;
        }

        // Ensure the column for this substance exists in the table
        if (!$this->tableHasColumn($table, $substance))
        {
            $result = $this->exec("ALTER TABLE $table ADD COLUMN $substance DECIMAL(10,3)");
            if (FALSE === $result)
            {
                $err = $this->dbHandle->errorInfo();
                if (DEBUG)
                {
                    print_r($err);
                }
                print("Error: Failed to add column $substance to table $table\n");
                return NULL;
            }
        }

        if (CAN_USE_LOAD_DATA_INFILE)
        {
            fclose($csvFile);
            //TODO: Copy CSV file, merge date & time and stripping headers and trailing data type field
            processCreateCentreCSV($filename, $filename . '.import');
            $this->fetchQuery("");


        }
        else
        {
            // Add rows one at a time...

            // Read up to first data
            $found = FALSE;
            while (!$found)
            {
                if (FALSE === ($string = fgets($csvFile)))
                {
                    break;
                }
                if (stristr($string, '"Date","Time","Reading"'))
                {
                    $found = 1;
                }
            }
            if (!$found)
            {
                print("Error: Failed to skip through CSV file to readings\n");
                return FALSE;
            }

            // Add the data (Warning: ON DUPLICATE KEY UPDATE is MySQL-specific)
            $stmt = $this->prepare("INSERT INTO $table (datetime, $substance) VALUES(:dt, :r)
                                    ON DUPLICATE KEY UPDATE $substance=:r");
            $stmt->bindParam(':dt', $dateTimeStr);
            $stmt->bindParam(':r', $reading);
            while ($data = fgetcsv($csvFile))
            {
                $dateTime = new DateTime(str_replace('/', '-', $data[0]) . "T" . $data[1]);
                $dateTimeStr = $dateTime->format(DateTime::ISO8601);
                //$dateTimeStr = $data[0] . "T" . $data[1] . "Z";
                $reading = $data[2];
                if (DEBUG)
                {
                    print("Adding $dateTimeStr, $reading\n");
                }
                if (FALSE == $stmt->execute())
                {
                    print("Error: Failed running insertion '$stmt->queryString'\n");
                    if (DEBUG)
                    {
                        print_r($this->dbHandle->errorInfo());
                    }
                    return FALSE;
                }
            }
        }
        // Success!
        return TRUE;
    }


    function importFiltonCSV(&$filtonCSV, $table)
    {
        global $verbose;

        // Prepare SQL (Warning: ON DUPLICATE KEY UPDATE is MySQL-specific)
        $stmt = $this->prepare("INSERT INTO $table (datetime, sol_rad) VALUES(:dt, :sr)
                                ON DUPLICATE KEY UPDATE sol_rad=:sr");
        $stmt->bindParam(':dt', $dateTimeStr);
        $stmt->bindParam(':sr', $solarAverage);

        if ($verbose > 0)
        {
            print("Average solar radiation in W/m2 for preceding half hour periods:\n");
        }
        $count = 0;
        $solarAccumulator = 0;
        while ($result = $filtonCSV->getNextRow())
        {
            $count++;
            $solarAccumulator += $filtonCSV->getSolRad();
            // Read solar up to the next half-hour point and average solar
            $minute = $filtonCSV->getMinute();
            while ($minute != 0 && $minute != 30)
            {
                if (!($result = $filtonCSV->getNextRow()))
                {
                    break;
                }
                $count++;
                $solarAccumulator += $filtonCSV->getSolRad();
                $minute = $filtonCSV->getMinute();
            }
            if (FALSE === $result)
            {
                break;
            }
            // Now we're at a half-hour point; add a database entry
            $solarAverage = $solarAccumulator / $count;
            $dateTime = $filtonCSV->getDateTime();
            $dateTimeStr = $dateTime->format(DateTime::ISO8601);
            if ($verbose > 0)
            {
                print("\t$dateTimeStr: $solarAverage\n");
            }
            if (FALSE == $stmt->execute())
            {
                print("Error: Failed running insertion '$stmt->queryString'\n");
                if (DEBUG)
                {
                    print_r($this->dbHandle->errorInfo());
                }
                return FALSE;
            }
            /* We include this entry in the average for the next half hour
             * too (our average is inclusive of both lower and upper bounds).
             */
            $count = 1;
            $solarAccumulator = $filtonCSV->getSolRad();
        }
        return TRUE;
    }


    /**
     * Import the content of a CSV file from the Filton weather station.
     * This will merge with existing data if already present, or create a new
     * table if it doesn't already exist.
     * The CSV is in minutely data.  We average the solar radiation values at
     * each half hour (using the data from the preceding half hour).  From my
     * understanding, this should match what is done by the Simtricity flows
     * API (I hope!).
     * Only solar radiation in W/m2 is processed.
     * File lines are expected to be in the format:
     *   day, month, year, hour, min, Solar, UV, Daily ET, soil moist, leaf wet
     *
     * @param string $table The name of the table to import to
     * @param string $filename The name of the CSV file containing the data to import
     */
    function importFiltonWeatherCSVFile($table, $filename)
    {
        global $verbose;

        // Create the table if needed
        if (FALSE === $this->exec("CREATE TABLE IF NOT EXISTS $table
                                           (datetime DATETIME NOT NULL UNIQUE,
                                            sol_rad DECIMAL(10,3),
                                            PRIMARY KEY(datetime))"))
        {
            print("Failed to create table '$table'\n");
            return FALSE;
        }

        // Table exists now!  Let's start processing...
        if ($verbose > 0)
        {
            print("Reading weather data from $filename...\n");
        }

        $filtonCSV = new FiltonCSV('file', $filename);
        if (FALSE === $filtonCSV)
        {
            print("Error: Failed to open CSV file '$filename'\n");
            return FALSE;
        }

        if (CAN_USE_LOAD_DATA_INFILE)
        {
            fclose($csvFile);
            // TODO: Copy CSV file, merge date & time and stripping headers and trailing data fields
            // Also average solar radiation values to half-hourly data (stamped
            // with the end time of each half hour).
            processFiltonWeatherCSV($filename, $filename . '.import');
            $this->fetchQuery("");


        }
        else
        {
            // Adding rows one at a time
            $importResult = $this->importFiltonCSV($filtonCSV, $table);
        }
        // Success!
        return $importResult;
    }


    /**
     * Import Filton weather data for a given date range from the web interface.
     * This will merge with existing data if already present, or create a new
     * table if it doesn't already exist.
     * The CSV is in 10-minute intervals.  We average the solar radiation values at
     * each half hour (using the data from the preceding half hour).  From my
     * understanding, this should match what is done by the Simtricity flows
     * API (I hope!).
     * Only solar radiation in W/m2 is processed (currently).
     * File lines are expected to be in the format:
     *   DATE, TIME, TEMP C, GUST mph, DIR, AVG mph, HUM %, BARO mb, TREND mb, RAIN mm,SOLAR W/m2, UV, WEATHER
     *
     * @param string $table The name of the table to import to
     * @param array $dates An array of two DateTime objects for the start and end of the period to retrieve data for
     */
    function importFiltonWeatherWebCSV(&$becFiltonWeather, $table, &$dates)
    {
        global $verbose;

        // Create the table if needed
        if (FALSE === $this->exec("CREATE TABLE IF NOT EXISTS $table
                                           (datetime DATETIME NOT NULL UNIQUE,
                                            sol_rad DECIMAL(10,3),
                                            PRIMARY KEY(datetime))"))
        {
            print("Failed to create table '$table'\n");
            return FALSE;
        }

        // Table exists now!  Let's start processing...
        $day = clone $dates[0];
        $addDay = new DateInterval('P1D');
        while ($day->getTimestamp() < $dates[1]->getTimestamp())
        {
            $csvString = $becFiltonWeather->getCSVData($day);
            $filtonCSV = new FiltonCSV('web-string', $csvString);
            if (FALSE === $filtonCSV)
            {
                print("Error: Failed to ready web CSV data for parsing\n");
                return FALSE;
            }
            $importResult = $this->importFiltonCSV($filtonCSV, $table);
            if ($importResult == FALSE)
            {
                return FALSE;
            }

            // Iterate to next day
            $day->add($addDay);
        }
        // Success!
        return TRUE;
    }


    public function updateForecastIOHistory($forecastIO)
    {
        global $verbose;

        /* If the table doesn't exist, create it and work out date range based on
         * oldest records in any of our power tables.
         */
        $dateRange;
        if (!($tablePresent = $this->isTablePresent(BEC_DB_FORECAST_IO_TABLE)) ||
            $this->rowsInTable(BEC_DB_FORECAST_IO_TABLE) == 0)
        {
            // Create the tables if needed
            if (FALSE === $this->exec('CREATE TABLE IF NOT EXISTS ' . BEC_DB_FORECAST_IO_TABLE .
                                             ' (datetime DATETIME NOT NULL UNIQUE,
                                                cloud_cover DECIMAL(10,3),
                                                visibility DECIMAL(10,3),
                                                summary VARCHAR(255),
                                                icon VARCHAR(40),
                                                PRIMARY KEY(datetime))'))
            {
                die('Error: Failed to create table \'' . BEC_DB_FORECAST_IO_TABLE . "'\n");
            }
            if (FALSE === $this->exec('CREATE TABLE IF NOT EXISTS ' . BEC_DB_FORECAST_IO_TABLE . '_daily' .
                                             ' (date DATE NOT NULL UNIQUE,
                                                cloud_cover DECIMAL(10,3),
                                                visibility DECIMAL(10,3),
                                                summary VARCHAR(255),
                                                icon VARCHAR(40),
                                                PRIMARY KEY(date))'))
            {
                die('Error: Failed to create table \'' . BEC_DB_FORECAST_IO_TABLE . "'\n");
            }

            // Find oldest record in the power table as start of date range
            $dateRange = $this->getDateTimeExtremesFromTable('power');
        }
        else
        {
            // Start of date range is last entry
            $dateRange = $this->getDateTimeExtremesFromTable(BEC_DB_FORECAST_IO_TABLE);
            if (!$dateRange)
            {
                die('Error: Failed to retrieve earliest and latest recorded dates from table \'' . BEC_DB_FORECAST_IO_TABLE . "'\n");
            }
            // Add 1 hour to the last stored time so we don't end up fetching the same day multiple times
            $dateRange[0] = $dateRange[1]->add(new DateInterval('PT1H'));
        }
        $dateRange[1] = new DateTime();
        // Set times to midday to ensure we get the right day regardless of GMT/BST transitions
        $dateRange[0]->setTime(12, 0);
        $dateRange[1]->setTime(12, 0);

        // Add the data (Warning: ON DUPLICATE KEY UPDATE is MySQL-specific)
        $stmtHourly = $this->prepare('INSERT INTO ' . BEC_DB_FORECAST_IO_TABLE . ' (datetime, cloud_cover, visibility, summary, icon) VALUES(:dt, :cc, :vis, :sum, :icon)
                                      ON DUPLICATE KEY UPDATE cloud_cover=:cc, visibility=:vis, summary=:sum, icon=:icon');
        $stmtHourly->bindParam(':dt', $dateTimeStr);
        $stmtHourly->bindParam(':cc', $cloudCover);
        $stmtHourly->bindParam(':vis', $visibility);
        $stmtHourly->bindParam(':sum', $summaryText);
        $stmtHourly->bindParam(':icon', $iconName);

        $stmtDaily = $this->prepare('INSERT INTO ' . BEC_DB_FORECAST_IO_TABLE . '_daily (date, cloud_cover, visibility, summary, icon) VALUES(:date, :cc, :vis, :sum, :icon)
                                      ON DUPLICATE KEY UPDATE cloud_cover=:cc, visibility=:vis, summary=:sum, icon=:icon');
        $stmtDaily->bindParam(':date', $dateStr);
        $stmtDaily->bindParam(':cc', $cloudCover);
        $stmtDaily->bindParam(':vis', $visibility);
        $stmtDaily->bindParam(':sum', $summaryText);
        $stmtDaily->bindParam(':icon', $iconName);

        while ($dateRange[0]->getTimestamp() < $dateRange[1]->getTimestamp())
        {
            $weather = $forecastIO->getForecastLimitCalls(FORECAST_IO_LAT, FORECAST_IO_LONG, $dateRange[0], array());
            if (!$weather)
            {
                return FALSE;
            }
            $hourly = $weather->getHourly()->getData();
            if ($verbose > 0)
            {
                print("Forecast.io cloud cover & visibility:\n");
            }
            $aDay = new DateInterval('P1D');
            foreach ($hourly as $hour)
            {
                $cloudCover = $hour->getCloudCover();
                $visibility = $hour->getVisibility();
                $summaryText = $hour->getSummary();
                $iconName = $hour->getIcon();
                if ($summaryText !== NULL | $iconName !== NULL || $cloudCover !== NULL || $visibility !== NULL)
                {
                    $hourDateTime = $hour->getTime();
                    if ($verbose > 0)
                    {
                        print("\t" . $hourDateTime->format('d-m-Y H:i') . ": $summaryText, $iconName, $cloudCover, $visibility\n");
                    }
                    $dateTimeStr = $hourDateTime->format(DateTime::ISO8601);
                    if (FALSE == $stmtHourly->execute())
                    {
                        print("Error: Failed running insertion '$stmtHourly->queryString'\n");
                        if (DEBUG)
                        {
                            print_r($this->dbHandle->errorInfo());
                        }
                        return FALSE;
                    }
                }
            }

            $daily = $weather->getDaily()->getData();
            foreach ($daily as $day)
            {
                $cloudCover = $day->getCloudCover();
                $visibility = $day->getVisibility();
                $summaryText = $day->getSummary();
                $iconName = $day->getIcon();
                if ($summaryText !== NULL | $iconName !== NULL || $cloudCover !== NULL || $visibility !== NULL)
                {
                    if ($verbose > 0)
                    {
                        print("\t" . $dateRange[0]->format('d-m-Y') . ": $summaryText, $iconName, $cloudCover, $visibility\n");
                    }
                    $dateStr = $dateRange[0]->format('Y-m-d');
                    if (FALSE == $stmtDaily->execute())
                    {
                        print("Error: Failed running insertion '$stmtDaily->queryString'\n");
                        if (DEBUG)
                        {
                            print_r($this->dbHandle->errorInfo());
                        }
                        return FALSE;
                    }
                }
            }

            $dateRange[0]->add($aDay);
        }
        return TRUE;
    }


    /**
     * Make the half-hourly view of the Create Centre solar radiation data
     * @return boolean TRUE if the table exists or has been successfully created
     */
    public function mkCreateCentreHalfHourView()
    {
        if (!$this->isTablePresent('sol_rad_create_centre'))
        {
            // We could either do this by averaging values, or just taking the instantaneous readings
            // Averaging half hour and half hour + 15 readings:
            //$result = $this->dbHandle->exec('CREATE VIEW sol_rad_create_centre AS select datetime,avg(' . BEC_DB_CREATE_CENTRE_RAW_TABLE . '.radiation) AS radiation FROM ' . BEC_DB_CREATE_CENTRE_RAW_TABLE . ' GROUP BY (to_seconds(' . BEC_DB_CREATE_CENTRE_RAW_TABLE . 'datetime) DIV 1800)');

            // Just take instantaneous readings
            $result = $this->dbHandle->exec('CREATE VIEW sol_rad_create_centre AS select datetime,radiation
                                             FROM ' . BEC_DB_CREATE_CENTRE_RAW_TABLE .
                                            ' WHERE toseconds(datetime) MOD 1800 = 0');
            if (FALSE === $result)
            {
                print("Error: Failed to create view 'sol_rad_create_centre' - " . $this->dbHandle->errorInfo() . "\n");
                return FALSE;
            }
        }
        return TRUE;
    }


    public function recordClearPeriods(&$clearPeriods)
    {
        global $verbose;

        $table = 'clear_periods';
        // Create the table if needed
        if (FALSE === $this->exec("CREATE TABLE IF NOT EXISTS $table
                                           (date DATE NOT NULL,
                                            start TIME NOT NULL,
                                            end TIME NOT NULL,
                                            PRIMARY KEY(date, start))"))
        {
            print("Failed to create table '$table'\n");
            return FALSE;
        }

        // Add the data
        $stmt = $this->prepare("INSERT IGNORE INTO $table (date, start, end) VALUES(:date, :start, :end)");
        $stmt->bindParam(':date', $dateStr);
        $stmt->bindParam(':start', $startTimeStr);
        $stmt->bindParam(':end', $endTimeStr);
        foreach ($clearPeriods as $clear)
        {
            if ($clear['start'] != $clear['end'])
            {
                $dateStr = $clear['start']->format('Y-m-d');
                $startTimeStr = $clear['start']->format('H:i');
                $endTimeStr = $clear['end']->format('H:i');
                if (FALSE == $stmt->execute())
                {
                    print("Error: Failed running insertion '$stmt->queryString'\n");
                    if (DEBUG)
                    {
                        print_r($this->dbHandle->errorInfo());
                    }
                    return FALSE;
                }
            }
        }
        return TRUE;
    }


    /**
     * Return an array of the meter info
     * @return array Array of meter info
     */
    public function getMeterInfoArray()
    {
        $meterInfo = $this->fetchQuery('SELECT serial, type, code FROM meters');
        return $meterInfo;
    }


    /**
     * Take a meter 'code' and make it nice to use in table/column names or
     * filenames.
     * We:
     *  - Replace '-' with '_'
     *  - Ensure all characters are lower-case
     * @param string $code Meter 'code' as defined by Simtricity
     * @return string Meter name to use in tables/filenames
     */
    public function meterDBName($code)
    {
        return str_replace('-', '_', strtolower($code));
    }


    /**
     * Function to return an array containing the meter codes for all generation meters
     * FIXME: This is current hard-coded - we want to be able to extract it from the
     * database!
     *
     * @return array Array of generation meter code (strings)
     */
    public function getGenMeterArray()
    {
        return array('sbsc', 'hh1', 'myc_gen', 'fh_gen', 'pv2_gen', 'bhcc_gen', 'kwmc_gen');
    }


    /**
     * Function to generate a file containing a graph of power (kW) against
     * solar radiation (W/m2) using data from the given tables.  A date range
     * can be specified to restrict the x-axis.
     * @param string $imageFilename Name of file to write graph to
     * @param string $powerTable Name of table to get power data from
     * @param string $solRadTable Name of table to get solar radiation data from
     * @param array $dateRange An optional array of two DateTime objects to limit
     *                         the range of data used
     */
    public function createGraphImage($imageFilename, $powerTable, $powerColumn, $solRadTable, &$dateRange = NULL)
    {
        global $verbose;

        if (!$this->graphsEnabled)
        {
            return;
        }

        // We inner join the tables on the datetime field.  This is expected to be called on
        // a solar radiation data table and a instananeous power data table so the two can
        // be easily compared.  They will need different y-axes.

        if ($verbose > 0)
        {
            print("Generating power graph in file $imageFilename\n");
        }

        $sql = "SELECT $powerTable.datetime, $powerTable.$powerColumn, sol_rad
                FROM $solRadTable INNER JOIN $powerTable
                ON $powerTable.datetime = $solRadTable.datetime";

        $whereClause = '';
        $whereClausePower = '';
        if ($dateRange != NULL)
        {
            $whereClause = " WHERE DATE($solRadTable.datetime) > '" . $dateRange[0]->format('Y-m-d') . "' &&
                                   DATE($solRadTable.datetime) < '" . $dateRange[1]->format('Y-m-d') . "'";
            $whereClausePower = str_replace($solRadTable, $powerTable, $whereClause);
        }
        $sql .= $whereClause;

        $data = $this->fetchQuery($sql);

        $maxSolRad = $this->fetchQuery("SELECT MAX(sol_rad) FROM $solRadTable" . $whereClause, PDO::FETCH_NUM);
        $maxPower = $this->fetchQuery("SELECT MAX($powerColumn) FROM $powerTable" . $whereClausePower, PDO::FETCH_NUM);
        if ($maxPower[0][0] == 0)
        {
            // If there was never any power, divide by 1 rather than 0 when scaling!
            $maxPower[0][0] = 1;
        }

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
        foreach ($data as $entry)
        {
            $powerData[$entry['datetime']] = $entry[$powerColumn] / $maxPower[0][0] * 100;
            $solRadData[$entry['datetime']] = $entry['sol_rad'] / $maxSolRad[0][0] * 100;
        }
        // Free up memory (maybe!)
        $data = NULL;

        $graph->addData($powerData, $solRadData);

        $graph->createGraph();
    }

}
?>
