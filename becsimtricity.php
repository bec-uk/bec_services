<?php

require_once 'curlwrapper.php';

// Array of serial numbers to flow tokens FIXME: Want to find these tokens using the API and put them into the meters table!
static $METER_FLOW_TOKEN = array(// Hamilton House
                                 '14230571' => 'wqajeuesl7vn3ni',
                                 // Knowle West Media Centre
                                 '14230570' => 'bnbjzj2ailar46y',
                                 // Mill Youth Centre
                                 '12156990' => 'gduk2r2s4tm5xfa', // Gen
                                 '14230572' => 'qyevukhmupgileq', // Imp/Exp (export from this flow token)
                                 // South Bristol Sports Centre
                                 '14230573' => 'utanr5zchv4vyjy',
                                 // Brentry and Henbury Children's Centre
                                 '15096966' => 'mt5crncbrnoe2va', // Imp/Exp (export from this flow token)
                                 '15096965' => 's7sxkbwnwsqf7ny', // Gen
                                 // Folk House
                                 '14240496' => 'f7fqhqcjeuhvaiq', // Imp/Exp (export from this flow token)
                                 '12156991' => '3vhoc3jskh2xp7i', // Gen
                                 // Easton Community Centre
                                 '15096967' => 'o3slsz5fazdlfia', // Gen
                                 '15096969' => 'xxgt2dsuziyfc7a',
                                 '16017854' => 'FIXME: Flow token needs to be extracted from Simtricity when it is given one (then update the $METER_FLOW_TOKEN array)', // Consumption
                                 'EML1325015602' => 'w4h5zzpru5q7wgy', // Gen
                                 'EML1325015594' => 'mqkegznis77lqby', // Gen
                                 'EML1325015592' => 'mrxbc5db2gwipzi', // Gen
                                 // Coniston Community Centre
                                 '16017855' => '3jnozfluwzlw5oq', //Gen
                                 // ACTA Community Theatre
                                 '16017852' => 'ckmmhiuyhqrev7a', // Imp/Exp (export from this flow token)
                                 '16017851' => 'c42c5zrcp4pmnja', //Gen
                                 // Wick Sports Ground
                                 '15096977' => 'sr3ntbwieuzzkpa', // Imp/Exp (export from this flow token)
                                 '15096976' => '4cq2nbgz6ljugwq' //Gen
                                 );

/**
 * Class handling access to the Simtricity platform for BEC PHP code
 */
class BECSimtricity
{

    private $token = NULL;

    /**
     * Constructor; reads communication token from file
     */
    public function __construct()
    {
        global $ini;

        $this->token = $this->getAccessToken($ini['simtricity_token_path']);
        return $this;
    }


    /**
     * Return power data (from the gviz/flow API) and return it in a prepared
     * form we can use (stripping the Google Visualisation stuff so we just
     * have DateTimes and power readings).
     *
     * @param string $url URL with full query
     * @return resource Array of DateTimes and power readings
     */
    protected function getPowerData($url)
    {
        $gvizData = curlGetData($url, array('Content-type: text/javascript', 'Accept: text/javascript, application/json'));

        // Strip up to end of string '"rows":['
        $cutOffset = strpos($gvizData, '"rows":[') + 8;
        if ($cutOffset == 8)
        {
            die('Error: Parsing gviz data failed; did not find row info' . "\n");
        }
        $gvizData = substr($gvizData, $cutOffset);

        // TODO: Check it's the data we expected


        // Parse out the date, value pairs
        if (4 === ($nextPos = strpos($gvizData, '"v":') + 4))
        {
            // No data!
            return FALSE;
        }
        $next = substr($gvizData, $nextPos);
        $thisDate = NULL;
        $thisValue = NULL;
        $count = 0;
        while ($next)
        {
            if (substr($next, 0, strlen('new Date')) === 'new Date')
            {
                $dateArgs = substr($next, 9, strpos($next, ')', 9) - 9);
                $thisDate = new DateTime();
                $dateArgs = explode(',', $dateArgs);
                // Note: JavaScript indexes months from 0; we add 1 for DateTime compatibility
                $thisDate->setDate($dateArgs[0], $dateArgs[1] + 1, $dateArgs[2]);
                $thisDate->setTime($dateArgs[3], $dateArgs[4], $dateArgs[5]);

                // Simtricity times are in local time - convert to GMT if needed
                datetimeToGMT($thisDate);

                $thisValue = NULL;
            }
            else
            {
                if ($thisValue !== NULL)
                {
                    die("Error: Parsing gviz data failed.  Current position:\n" . $next);
                }
                $thisValue = floatval(substr($next, 0, strpos($next, '}')));
                $powerData[$count++] = array($thisDate, $thisValue);
            }
            if (4 === ($nextPos = strpos($next, '"v":') + 4))
            {
                // No more data found
                break;
            }
            $next = substr($next, $nextPos);
        }

        if (DEBUG)
        {
            print("Parsed power data is:\n");
            print_r($powerData);
        }

        return $powerData;
    }


    /**
     * HTTP GET JSON data.  This is specific to Simtricity as the data may be
     * 'paged' and it will fetch and merge all pages.
     *
     * @param string $url The URL to GET from
     * @return object The object representing the decoded JSON data retreived
     */
    protected function getJSON($url, $supportPages = FALSE, $mergeArrayNames = NULL)
    {
        global $verbose;

        $curlHandle = NULL;
        $data = curlGetData($url, array('Content-type: application/json', 'Accept: application/json'), $curlHandle);

        if (DEBUG)
        {
            if (!$curlHandle)
            {
                die("Error: curlGetData() failed - curl handle was not set\n");
            }
        }

        $jsonObj = json_decode($data);
        if ($jsonObj === NULL)
        {
            die("Error: Failed to decode data as JSON\n");
        }

        if ($supportPages && $mergeArrayNames)
        {
            // This API returns data in pages - there could be more pages to read!
            $pagesLeft = $jsonObj->pageCount - 1;
            while ($pagesLeft)
            {
                // The order we get the pages doesn't matter; we just merge the meters array in the meterData
                $pageURL = $url . '&page=' . ($pagesLeft + 1);
                curl_setopt($curlHandle, CURLOPT_URL, $pageURL);
                if ($verbose > 0)
                {
                    print("\nTrying URL: $pageURL\n");
                }
                $data = curl_exec($curlHandle);
                if ($errNo = curl_errno($curlHandle))
                {
                    die('Error: Failed to get meter list page ' . ($pagesLeft + 1) .
                        ' from Simtricity - error code ' . $errNo . "\n\t" . curl_error($curlHandle) . "\n");
                }
                $pageData = json_decode($data);
                if ($pageData === NULL)
                {
                    die("Error: Failed to decode page " . ($pagesLeft + 1) . " data as JSON\n");
                }
                foreach ($mergeArrayNames as $mergeArrayName)
                {
                    $jsonObj->$mergeArrayName = array_merge($jsonObj->$mergeArrayName, $pageData->$mergeArrayName);
                }
                $jsonObj->pageCount = 1;
                $pagesLeft--;
            }
        }
        curl_close($curlHandle);
        return $jsonObj;
    }

    /**
     * Return an object containing all the site info we can get
     */
    public function getListOfSites()
    {
        global $ini;

        $url = $ini['simtricity_base_uri'] . '/a/site?authkey=' . $this->getAccessToken();
        $siteData = $this->getJSON($url, TRUE, array('sites'));

        if (DEBUG)
        {
            print("Site list:\n");
            print_r($siteData->sites);
        }

        return $siteData->sites;
    }


    /**
     * Return an array containing all the meter info we can get
     */
    public function getListOfMeters()
    {
        global $ini;

        $url = $ini['simtricity_base_uri'] . '/a/meter?authkey=' . $this->getAccessToken();
        $meterData = $this->getJSON($url, TRUE, array('meters'));

        if (DEBUG)
        {
            print("Meter list:\n");
            print_r($meterData->meters);
        }

        return $meterData->meters;
    }


    /**
     * Retrieves the Simtricity API token for accessing the API from the given file.
     * The file format is simply a text file containing the token (although we
     * ignore lines that don't look like tokens too).
     * Die on failure.
     *
     * @param string $filename Name of file to retrieve token from (optional if it has already been read from the file)
     * @return string Token as a string
     */
    public function getAccessToken($filename = NULL)
    {
        if ($this->token)
        {
            return $this->token;
        }
        if ($filename === NULL)
        {
            die("Error: No stored token, and no filename to read it from given\n");
        }
        if (!file_exists($filename))
        {
            die("Error: File not found trying to read Simtricity access token - $filename\n");
        }
        $inFile = fopen($filename, 'r');
        $token = NULL;
        while ($line = fgets($inFile))
        {
            // Check the line is long enough and contains two or more '-'s
            if (strlen($line) > 30 && substr_count($line, '-') >= 2)
            {
                if (1 != preg_match('([0-9a-fA-F\-]+)', $line, $matches))
                {
                    continue;
                }
                if (strlen($matches[0]) > 30)
                {
                    $token = $matches[0];
                    if (DEBUG)
                    {
                        print("Simtricity token is: $token\n");
                    }
                }
                else if (DEBUG)
                {
                    print("Skipping line containing: $line");
                }
            }
            else if (DEBUG)
            {
                print("Skipping line containing: $line");
            }
        }
        fclose($inFile);
        if ($token === NULL)
        {
            die("Error: Simtricity token not found in '$filename'\n");
        }

        return $token;
    }


    /**
     * Function to get the latest Simtricity site data and sync it into the BEC
     * database.
     *
     * @param resource $becDB The BEC database handle
     * @param string Name of table for site data
     */
    public function updateSiteDataFromSimtricty(&$becDB, $siteTable)
    {
        $siteData = $this->getListOfSites();
        // Create the table if needed
        if (FALSE === $becDB->exec("CREATE TABLE IF NOT EXISTS $siteTable
                                            (name CHAR(64) NOT NULL UNIQUE,
                                             code CHAR(16),
                                             activity CHAR(16),
                                             token CHAR(32) NOT NULL UNIQUE,
                                             PRIMARY KEY(token))"))
        {
            print("Failed to create table '$siteTable'\n");
            return FALSE;
        }

        // Add/update the data (Warning: ON DUPLICATE KEY UPDATE is MySQL-specific)
        $stmt = $becDB->prepare("INSERT INTO $siteTable (name, code, activity, token)
                                            VALUES(:name, :code, :activity, :token)
                                            ON DUPLICATE KEY UPDATE name=:name, code=:code, activity=:activity");
        $stmt->bindParam(':name', $name);
        $stmt->bindParam(':code', $code);
        $stmt->bindParam(':activity', $activity);
        $stmt->bindParam(':token', $token);
        foreach ($siteData as $site)
        {
            $name = $site->name;
            $code = $site->code;
            $activity = $site->activity;
            $token = $site-> token;
            if (FALSE == $stmt->execute())
            {
                print("Error: Failed running insertion '$stmt->queryString'\n");
                if (DEBUG)
                {
                    print_r($this->dbHandle->errorInfo());
                }
                return FALSE;
            }
            if (DEBUG)
            {
                print_r($stmt);
            }
        }
    }


    /**
     * Function to get the latest Simtricity meter meta-data and sync it into the BEC
     * database.
     *
     * @param resource $becDB The BEC database handle
     */
    public function updateMeterDataFromSimtricty(&$becDB, $meterTable)
    {
        global $verbose;

        $meterData = $this->getListOfMeters();
        // Ensure the table exists
        // Create the table if needed
        if (FALSE === $becDB->exec("CREATE TABLE IF NOT EXISTS $meterTable
                                            (serial CHAR(32) NOT NULL UNIQUE,
                                             token CHAR(32) NOT NULL UNIQUE,
                                             siteToken CHAR(32) NOT NULL,
                                             code CHAR(16),
                                             model CHAR(32),
                                             spec CHAR(32),
                                             type CHAR(32) NOT NULL,
                                             startDate DATETIME,
                                             PRIMARY KEY(serial),
                                             CONSTRAINT fk_site FOREIGN KEY(siteToken) REFERENCES sites(token))"))
        {
            print("Failed to create table '$meterTable'\n");
            return FALSE;
        }

        // First get the site name to site token mappings
        $siteTokensRaw = $becDB->fetchQuery('SELECT name, token FROM sites');
        foreach ($siteTokensRaw as $raw)
        {
            $siteTokenArray[$raw['name']] = $raw['token'];
        }

        // Add the data, ignore if already present (Warning: INSERT IGNORE is MySQL-specific)
        // TODO: Do we want to support changes to the meter 'code'?
        $stmt = $becDB->prepare("INSERT IGNORE INTO $meterTable (serial, token, siteToken, code, model, spec, type, startDate)
                                            VALUES(:serial, :token, :siteToken, :code, :model, :spec, :type, :startDate)");
        $stmt->bindParam(':serial', $serial);
        $stmt->bindParam(':token', $token);
        $stmt->bindParam(':siteToken', $siteToken);
        $stmt->bindParam(':code', $code);
        $stmt->bindParam(':model', $model);
        $stmt->bindParam(':spec', $spec);
        $stmt->bindParam(':type', $type);
        $stmt->bindParam(':startDate', $dateTimeStr);
        if ($verbose > 0)
        {
            print("Updating database table $meterTable.");
        }
        foreach ($meterData as $meter)
        {
            $serial = $meter->serial;
            $token = $meter->meterToken;
            $siteName = $meter->site;
            // FIXME: Shoudln't need this, but some Simtricity meter data is missing the site name
            if (!$siteName)
            {
                if (substr($meter->code, 0, 3) == 'ECC')
                {
                    $siteName = 'Easton Community Centre';
                }
            }
            $siteToken = $siteTokenArray[$siteName];
            if (strlen($siteToken) < 2)
            {
                die("Error: Failed to match meter site name to a site token\n");
            }
            $code = $meter->code;
            $model = $meter->model;
            $spec = $meter->spec;
            $type = $meter->type;
            // The start field is a timestamp in ms, but PHP uses timestamps based in seconds
            $startDate = new DateTime();
            $startDate->setTimestamp($meter->start / 1000);
            // MySQL doesn't like the trailing timezone
            $dateTimeStr = $startDate->format(DateTime::ISO8601);
            $dateTimeStr = substr($dateTimeStr, 0, strrpos($dateTimeStr, '+'));
            if (FALSE == $stmt->execute())
            {
                print("Error: Failed running insertion '$stmt->queryString'\n");
                print_r($this->dbHandle->errorInfo());
                return FALSE;
            }
            if ($verbose > 0)
            {
                print('.');
            }
        }
        if ($verbose > 0)
        {
            print("done\n");
        }
    }


    /**
     * Function to retrieve power data from Simtricity and store it in the
     * specified table.
     *
     * @param resource $becDB BEC database handle
     * @param string $table Name of table to store the power data in
     * @param array $meterInfo Array containing the info for the meter to retrieve data for
     * @param DateTime $startDate Don't retrieve data before this date
     * @return FALSE on failure
     */
    public function updatePowerDataFromSimtricity(&$becDB, $table, &$meterInfo, $startDate)
    {
        global $verbose, $ini, $METER_FLOW_TOKEN;

        // Create the table if needed
        if (FALSE === $becDB->exec("CREATE TABLE IF NOT EXISTS $table
                                            (datetime DATETIME NOT NULL UNIQUE,
                                             PRIMARY KEY(datetime))"))
        {
            print("Failed to create table '$table'\n");
            return FALSE;
        }

        // Add a column for this meter if needed
        $column = $becDB->meterDBName($meterInfo['code']);
        if (!$becDB->tableHasColumn($table, $column))
        {
            $result = $becDB->exec("ALTER TABLE $table ADD COLUMN $column DECIMAL(10,3)");
            if (FALSE === $result)
            {
                $err = $becDB->dbHandle->errorInfo();
                if (DEBUG)
                {
                    print_r($err);
                }
                print("Error: Failed to add column $column to table $table\n");
                return NULL;
            }
        }

        // Lookup the flow token for this meter (FIXME: Not yet in the database as don't know how to retrieve from the API!)
        $flowToken = $METER_FLOW_TOKEN[$meterInfo['serial']];
        if (strlen($flowToken) < 2)
        {
            print('Error: Failed to find flow API token for meter ' . $meterInfo{'code'} . ' - ' . $meterInfo{'serial'} . " - skipping\n");
            return FALSE;
        }
        else if (strstr($flowToken, ' ') != FALSE)
        {
            // This is not a flow token - print it as an error message
            print("Error: $flowToken - skipping\n");
            return FALSE;
        }

        $url = $ini['simtricity_base_uri'] . '/gviz/flow?authkey=' . $this->getAccessToken();
        $url .= '&resolution=PT30M';
        $url .= '&start=' . $startDate->format('Y-m-d\TH:i');
        // Now as the end time
        $endDate = new DateTime();
        $url .= '&end=' . $endDate->format('Y-m-d\TH:i');
        $url .= '&tq=' . urlencode('select `timestamp`, `' . $flowToken . '` label `timestamp` "Time", `' . $flowToken . '` "Generation "') . '&tqx=reqId:3';

        if ($verbose > 0)
        {
            print('Retrieving power data for meter ' . $meterInfo['code'] . ' with serial number ' . $meterInfo['serial'] . "\n");
        }
        $data = $this->getPowerData($url);
        if ($data === FALSE)
        {
            if ($verbose > 0)
            {
                print('No data retrieved for meter ' . $meterInfo['code'] . ' with serial number ' . $meterInfo['serial'] . "\n");
            }
            return;
        }

        // Add/update the data (Warning: ON DUPLICATE KEY UPDATE is MySQL-specific)
        $stmt = $becDB->prepare("INSERT INTO $table (datetime, $column)
                                            VALUES(:datetime, :power)
                                            ON DUPLICATE KEY UPDATE $column=:power");
        $stmt->bindParam(':datetime', $dateTimeStr);
        $stmt->bindParam(':power', $power);

        if ($verbose > 0)
        {
            print("Updating table $table for meter " . $meterInfo['code'] . '.');
        }

        foreach ($data as $entry)
        {
            $dateTimeStr = $entry[0]->format(DateTime::ISO8601);
            // MySQL doesn't like the trailing timezone
            $dateTimeStr = substr($dateTimeStr, 0, strrpos($dateTimeStr, '+'));
            $power = $entry[1];

            if (FALSE == $stmt->execute())
            {
                print("Error: Failed running insertion '$stmt->queryString'\n");
                if (DEBUG)
                {
                    print_r($this->dbHandle->errorInfo());
                }
                return FALSE;
            }
            if ($verbose > 0)
            {
                print('.');
            }
        }
        if ($verbose > 0)
        {
            print("done\n");
        }
    }


    /**
     * Function to fetch meter readings from Simtricity.  They come in CSV form.
     *
     * @param resource $becDB The BEC database handle
     * @param string $table Name of table in database
     * @param string $meter Array containing info for this meter
     */
    public function fetchReadingDataFromSimtricity(&$becDB, $table, $meter, &$startDate)
    {
        global $verbose, $ini;

        /* Use the Simtricity export API to request half-hourly meter readings in
         * CSV format.
         * These are the 'billing quality' meter readings and although we request
         * half-hourly data, as we only want actual readings (rather than
         * interpolated values which just average over the time-period without
         * accounting for light levels), most meters can only provide us daily
         * readings (sometimes less often, although CEPro say they will go back
         * and retrieve missing data if it's not present due to communication
         * failure).
         */
        $url = $ini['simtricity_base_uri'] . '/a/export/meter/' . $meter['type'] . '/' .
               $meter['serial'] . '?authkey=' . $this->getAccessToken();
        $url .= '&start=' . $startDate->format('Y-m-d\TH:i:s\Z');
        $now = new DateTime();
        $url .= '&end=' . $now->format('Y-m-d\TH:i:s\Z');
        $url .= '&resolution=PT30M';
        $url .= '&reading-type=ACTUAL';

        if ($verbose > 0)
        {
            print('Retrieving reading data for meter ' . $meter['code'] . ' with serial number ' . $meter['serial'] . '.');
        }
        $csvData = curlGetCSV($url);
        if ($csvData === FALSE)
        {
            if ($verbose > 0)
            {
                print('No data retrieved for meter ' . $meter['code'] . ' with serial number ' . $meter['serial'] . "\n");
            }
            return FALSE;
        }
        if ($verbose > 0)
        {
            print("done\n");
        }
        return $csvData;
    }


    /**
     * Function to retrieve meter data recordings to a table in the BEC database
     *
     * @param resource $becDB The BEC database handle
     * @param string $table Name of table in database
     * @param string $meter Array containing info for this meter
     * @param DateTime $startDate Don't retrieve data before this date
     */
    public function updateReadingDataFromSimtricity(&$becDB, $table, $meter, $startDate)
    {
        global $verbose, $ini;

        // Create the table if needed
        if (FALSE === $becDB->exec("CREATE TABLE IF NOT EXISTS $table
                                            (datetime DATETIME NOT NULL UNIQUE,
                                             readingImport FLOAT,
                                             readingExport FLOAT,
                                             PRIMARY KEY(datetime))"))
        {
            print("Failed to create table '$table'\n");
            return FALSE;
        }

        $csvData = $this->fetchReadingDataFromSimtricity($becDB, $table, $meter, $startDate);
        if ($csvData === FALSE)
        {
            /* No data returned */
            return;
        }

        // Add/update the data (Warning: ON DUPLICATE KEY UPDATE is MySQL-specific)
        $stmt = $becDB->prepare("INSERT IGNORE INTO $table (datetime, readingImport, readingExport)
                                            VALUES(:datetime, :readingImport, :readingExport)");
        $stmt->bindParam(':datetime', $dateTimeStr);
        $stmt->bindParam(':readingImport', $readingImport);
        $stmt->bindParam(':readingExport', $readingExport);

        // Headings
        $row = strtok($csvData,"\n");
        // First row of data
        $row = strtok("\n");
        if ($verbose > 0)
        {
            print("Updating table $table.");
        }
        while ($row !== FALSE)
        {
            $rowCSV = str_getcsv($row);

            $dateTime = new DateTime($rowCSV[1]);
            // Simtricity times are in local time - convert to GMT if needed
            datetimeToGMT($dateTime);

            $dateTimeStr = $dateTime->format(DateTime::ISO8601);
            // MySQL doesn't like the trailing timezone
            $dateTimeStr = substr($dateTimeStr, 0, strrpos($dateTimeStr, '+'));
            $readingImport = $rowCSV[2];
            $readingExport = $rowCSV[3];

            if (FALSE == $stmt->execute())
            {
                print("Error: Failed running insertion '$stmt->queryString'\n");
                if (DEBUG)
                {
                    print_r($this->dbHandle->errorInfo());
                }
                return FALSE;
            }
            if ($verbose > 0)
            {
                print('.');
            }

            $row = strtok("\n");
        }
        if ($verbose > 0)
        {
            print("done\n");
        }
    }


    /**
     * For a given meter, retrieve all it's data following the latest reading
     * already present in the database.
     *
     * @param resource $becDB The BEC database handle
     * @param resource $meter Array of info for the meter to fetch for
     */
    public function updateMeterReadings(&$becDB, &$meter)
    {
        $tableName = 'dailyreading_' . $becDB->meterDBName($meter['code']);

        if ($becDB->isTablePresent($tableName) && $becDB->rowsInTable($tableName) > 0 && ($date = $becDB->getDateTimeExtremesFromTable($tableName)))
        {
            $startDate = $date[1];
        }
        else
        {
            // Default to the turn of the millenium
            $startDate = new DateTime('2000-01-01T00:00:00Z');
        }

        # We only update from Simtricity if it has been more than 24 hours since the last record
        # was saved.
        $now = new DateTime();
        $diff = $now->getTimestamp() - $startDate->getTimestamp();
        if ($diff > 24 * 60 * 60)
        {
            $this->updateReadingDataFromSimtricity($becDB, $tableName, $meter, $startDate);
        }
    }


    /**
     * For each meter, retrieve all it's data following the latest reading
     * already present in the database.
     *
     * @param resource $becDB The BEC database handle
     */
    public function updateAllMeterReadings(&$becDB)
    {
        $meterInfo = $becDB->getMeterInfoArray();

        foreach ($meterInfo as $meter)
        {
            $this->updateMeterReadings($becDB, $meter);
        }
    }


    /**
     * For a meter, retrieve all it's power measurements following the last already in the database
     *
     * @param resource $becDB The BEC database handle
     * @param array $meter Array of info on the meter to fetch for
     */
    public function updateMeterPowerData(&$becDB, &$meter)
    {
        global $verbose;

        // All power values put into same table with different columns for different meters
        $tableName = 'power';
        $column = $becDB->meterDBName($meter['code']);

        if ($becDB->isTablePresent($tableName) &&
            $becDB->tableHasColumn($tableName, $column) &&
            $becDB->rowsInTable($tableName, $column) > 0 &&
            ($date = $becDB->getDateTimeExtremesFromTable($tableName, $column)))
        {
            $startDate = $date[1];
            $now = new DateTime();
            // Skip if latest time is already 24 hours ago or less
            $secs = $now->getTimestamp() - $startDate->getTimestamp();
            if ($secs / 60 / 60 <= 24)
            {
                if ($verbose > 0)
                {
                    print("Table $tableName already up to date for meter " . $meter['code'] . "\n");
                }
                return;
            }
        }
        else
        {
            // Default to the turn of the millenium
            $startDate = new DateTime('2000-01-01T00:00:00Z');
        }

        $this->updatePowerDataFromSimtricity($becDB, $tableName, $meter, $startDate);
    }


    /**
     * For each meter, retrieve all it's power measurements following the last already in the database
     *
     * @param resource $becDB The BEC database handle
     */
    public function updateAllMeterPowerData(&$becDB)
    {
        global $verbose;

        $meterInfo = $becDB->getMeterInfoArray();

        foreach ($meterInfo as $meter)
        {
            $this->updateMeterPowerData($becDB, $meter);
        }
    }
}

?>
