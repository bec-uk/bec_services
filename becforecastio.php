<?php

class BECForecastIO extends VertigoLabs\Overcast\Overcast
{
    public function __construct($apiKeyFilename)
    {
        parent::__construct(self::getAPIKey($apiKeyFilename));
    }

    public static function getAPIKey($filename = NULL)
    {
        if ($filename === NULL)
        {
            die("Error: No stored API key, and no filename to read it from given\n");
        }
        if (!file_exists($filename))
        {
            die("Error: File not found trying to read forecast.io API key - $filename\n");
        }
        $inFile = fopen($filename, 'r');
        $apiKey = NULL;
        while ($line = fgets($inFile))
        {
            // Check the line is long enough and contains two or more '-'s
            if (strlen($line) > 30)
            {
                if (1 != preg_match('([0-9a-fA-F]+)', $line, $matches))
                {
                    continue;
                }
                if (strlen($matches[0]) > 30)
                {
                    $apiKey = $matches[0];
                    if (DEBUG)
                    {
                        print("Forecast.io API key is: $apiKey\n");
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
        if ($apiKey === NULL)
        {
            die("Error: Forecast.io API key not found in '$filename'\n");
        }

        return $apiKey;
    }


    public function clearTimes($becDB)
    {
        $forecastTable = BEC_DB_FORECAST_IO_TABLE;
        /* Find periods with no cloud cover on days before today (don't do today
         * in case we're in the middle of a daytime clear period and so don't yet
         * know the end time of the clear period).  TODO: Could allow today if it
         * is before sunrise or after sunset...or if it's not currently clear!
         */
        $sql = "SELECT * FROM $forecastTable WHERE cloud_cover = 0 AND datetime < CURDATE()";
        $result = $becDB->fetchQuery($sql);
        if ($result === FALSE)
        {
            return FALSE;
        }
        print("Clear period:\n");
        // Recordings are hourly - find contiguous clear daytime periods
        $clearPeriod = NULL;
        $periodCounter = 0;
        $prevTime = NULL;
        foreach ($result as $row)
        {
            $thisTime = new DateTime($row['datetime']);

            if ($thisTime->getTimestamp() < date_sunrise($thisTime->getTimestamp(), SUNFUNCS_RET_TIMESTAMP, FORECAST_IO_LAT, FORECAST_IO_LONG) ||
                $thisTime->getTimestamp() > date_sunset($thisTime->getTimestamp(), SUNFUNCS_RET_TIMESTAMP, FORECAST_IO_LAT, FORECAST_IO_LONG))
            {
                continue;
            }

            print($row['datetime'] . ': ' . $row['visibility'] . "\n");
            if (is_null($clearPeriod[$periodCounter]['start']))
            {
                // Only first time we hit daytime clear skies through the loop
                $clearPeriod[$periodCounter]['start'] = $thisTime;
                $visCounter = 0;
            }
            if ($prevTime && $thisTime->getTimestamp() - $prevTime->getTimestamp() != 60 * 60)
            {
                // There's been a gap in known clearness - end this period and start new one
                $clearPeriod[$periodCounter++]['end'] = $prevTime;
                $clearPeriod[$periodCounter]['start'] = $thisTime;
                $visCounter = 0;
            }
            $clearPeriod[$periodCounter]['visibility'][$visCounter++] = $row['visibility'];

            $prevTime = $thisTime;
        }
        // Close final period
        if ($prevTime)
        {
            $clearPeriod[$periodCounter]['end'] = $prevTime;
        }

        if (DEBUG)
        {
            print("All clear periods:\n");
            foreach ($clearPeriod as $clear)
            {
                if ($clear['start'] != $clear['end'])
                {
                    print($clear['start']->format('d-m-Y H:i') . '-' . $clear['end']->format('H:i') .
                           ': ' . implode(', ', $clear['visibility']) . "\n");
                }
            }

            print("\nLunchtime clear periods:\n");
            foreach ($clearPeriod as $clear)
            {
                $eleven = clone $clear['start'];
                $eleven->setTime(11,0);
                $thirteen = clone $eleven;
                $thirteen->setTime(13,0);

                if ($clear['start']->getTimestamp() <= $eleven->getTimestamp() &&
                    $clear['end']->getTimestamp() >= $thirteen->getTimestamp())
                {
                    $visIndexStart = ($eleven->getTimestamp() - $clear['start']->getTimestamp()) / (60 * 60);
                    print($eleven->format('d-m-Y H:i') . '-' . $thirteen->format('H:i') . ': ');
                    for ($count = 0; $count < 3 ; $count++)
                    {
                        print($clear['visibility'][$visIndexStart + $count]);
                        if ($count < 2) print(', ');
                    }
                    print("\n");
                }
            }
        }

        // Record all clear periods in a table
        $becDB->recordClearPeriods($clearPeriod);



    }


    /**
     * Wrapper for getForecast which skips the call if the free API call limit
     * has been reached.
     *
     * @param float $latitude
     * @param float $longitude
     * @param DateTime $dateTime
     * @param array $parameters
     */
    public function getForecastLimitCalls($latitude, $longitude, $dateTime, $parameters)
    {
        global $verbose;

        // Default parameters
        $params = array('units' => 'si', 'exclude' => 'currently,minutely,daily,alerts');
        $params = array_merge($params, $parameters);

        if ($this->getApiCalls() > 999)
        {
            if ($verbose > 0)
            {
                print("Warning: Forecast.io not queried as have reached free API call limit\n");
            }
            return FALSE;
        }
        $forecast = $this->getForecast($latitude, $longitude, $dateTime, $params);
        if ($verbose > 0)
        {
            print('Forecast.io API calls made today: ' . $this->getApiCalls() . "\n");
        }
        return $forecast;
    }

}


?>