<?php

/**
 * Function to return a DateTime date in the format required for SQL usage
 *
 * @param DateTime $date A DateTime object
 * @return string
 */
function sqlDateString($date)
{
    return '\'' . $date->format('Y-m-d') . '\'';
}


/**
 * Function to return a DatePeriodfor the specified number of days
 * forwards or backwards from the given reference DateTime.
 *
 * @param DateTime $refDate
 * @param int $numDays
 * @param boolean $forwards
 * @return DatePeriod Date range
 */
function dateRange($refDate, $numDays, $forwards = TRUE)
{
    global $verbose;

    if (!$forwards)
    {
        $refDate->sub(new DateInterval('P' . $numDays . 'D'));
    }

    return new DatePeriod($refDate, new DateInterval('P1D'), $numDays);
}


/**
 * Return a DateTime object for yesterday.  Up until 6am we use the day before yesterday to ensure
 * Simtricity data is present.
 *
 * @return DateTime Yesterday
 */
function getYesterdayDateTime()
{
    global $verbose;

    /* In fact if it's before 6AM, do the day before yesterday as Simtricity
     * meter readings are made at 6AM.
     */
    $dateTime = new DateTime();
    $dateTime->setTime(0,0);
    $timestamp = time();
    $dayTimestamp = $dateTime->getTimestamp();
    $day = new DateInterval('P1D');
    $dateTime->sub($day);
    // Take off an extra day if before 6am
    if ($timestamp - $dayTimestamp < 6 * 60 * 60)
    {
        $dateTime->sub($day);
    }

    if ($verbose > 3)
    {
        print(__FUNCTION__ . '(): Date for yesterday = ' . $dateTime->format('d/m/Y') . "\n");
    }

    return $dateTime;
}


/**
 * Function to highlight when a generation meter has power readings of zero
 * over the last day despite decent sol_rad figures (>DECENT_SOLRAD_LIMIT W/m2).
 * It looks at each half-hourly measurement.
 *
 * @param resource $becDB
 * @return boolean TRUE if any unexpected zero generation periods seen
 */
function zeroPowerYesterday(&$becDB)
{
    global $verbose, $graphsEnabled;

    // TODO: Tune this value to remove false hits; we wouldn't expect any power
    // output when it's really dim...
    define('DECENT_SOLRAD_LIMIT', 30);

    $dateTime = getYesterdayDateTime();
    $sql = "SELECT power.*,
                   weather_filton.sol_rad AS sol_rad_filton,
                   create_centre_meteo_raw.sol_rad AS sol_rad_cc
            FROM power
                LEFT JOIN weather_filton ON power.datetime = weather_filton.datetime
                LEFT JOIN create_centre_meteo_raw ON power.datetime = create_centre_meteo_raw.datetime
            WHERE (weather_filton.sol_rad > " . DECENT_SOLRAD_LIMIT . " OR
                  create_centre_meteo_raw.sol_rad > " . DECENT_SOLRAD_LIMIT . ") AND
                  DATE(power.datetime) = " . sqlDateString($dateTime);
    $result = $becDB->fetchQuery($sql);

    // Work out when sunrise and sunset are - we'll ignore any zero power readings close to them
    $timestamp = $dateTime->getTimestamp();
    $sunriseTime = date_sunrise($timestamp, SUNFUNCS_RET_TIMESTAMP, FORECAST_IO_LAT, FORECAST_IO_LONG);
    $sunsetTime = date_sunset($timestamp, SUNFUNCS_RET_TIMESTAMP, FORECAST_IO_LAT, FORECAST_IO_LONG);
    $ignoreWithin = 60 * 60; // One hour

    $anyHits = FALSE;
    foreach ($result as $entry)
    {
        $zeroMeterCount = 0;
        foreach ($becDB->getGenMeterArray() as $genMeter)
        {
            $dateTime = new DateTime($entry['datetime']);
            $timestamp = $dateTime->getTimestamp();
            if ($entry[$genMeter] == 0 && $timestamp > $sunriseTime + $ignoreWithin && $timestamp < $sunsetTime - $ignoreWithin)
            {
                if (!$anyHits)
                {
                    $anyHits = TRUE;
                    ReportLog::append('Unexpected zero power output during ' . $dateTime->format('d/m/Y') . ":\n");
                    ReportLog::setError(TRUE);
                }
                if ($zeroMeterCount == 0)
                {
                    ReportLog::append('  Period ending [' .
                                      $dateTime->format('H:i') . " (UTC)]\tSolar radiation [CC: " . $entry['sol_rad_cc'] .
                                      ' Filton: ' . $entry['sol_rad_filton'] . "]: $genMeter");
                    $zeroMeterCount++;
                }
                else
                {
                    ReportLog::append(', ' . $genMeter);
                    $zeroMeterCount++;
                }
            }
        }
        if ($zeroMeterCount)
        {
            ReportLog::append("\n");
        }
    }
    if ($anyHits)
    {
        ReportLog::append("\n\n");
    }
    else
    {
        ReportLog::append('No unexpected zero power output readings yesterday (' . $dateTime->format('d/m/Y') . ")\n\n");
    }
    return $anyHits;
}


/**
 * Function to highlight when power data is missing for the previous day.
 *
 * @param resource $becDB
 * @return boolean TRUE if there was any missing data for previous day
 */
function missingPowerDataYesterday(&$becDB)
{
    global $verbose;

    $dateTime = getYesterdayDateTime();
    $sql = 'SELECT * FROM power
            WHERE DATE(power.datetime) = ' . sqlDateString($dateTime);
    $result = $becDB->fetchQuery($sql);

    if (sizeof($result) == 0)
    {
        ReportLog::append('No power data found for yesterday (' . sqlDateString($dateTime) . ")\n");
        ReportLog::setError(TRUE);

        // Also report the latest power reading we have got
        $dateRange = $becDB->getDateTimeExtremesFromTable('power');
        ReportLog::append('Most recent power data is from ' . $dateRange[1]->format('Y-m-d') . "\n\n");
        return TRUE;
    }

    $anyHits = FALSE;
    $period = array();

    // First check through the data returned from the database (if any)
    foreach ($result as $entry)
    {
        $missingCount = 0;
        $dateTime = new DateTime($entry['datetime']);
        $timestamp = $dateTime->getTimestamp();
        $dateTime->setTime(0, 0);
        $dayTimestamp = $dateTime->getTimestamp();
        $secondsIntoDay = $timestamp - $dayTimestamp;
        $halfHourIndex = $secondsIntoDay / (60 * 30);
        $period[$halfHourIndex] = TRUE;
        foreach ($becDB->getGenMeterArray() as $genMeter)
        {
            if ($entry[$genMeter] === NULL)
            {
                if (!$anyHits)
                {
                    $anyHits = TRUE;
                    ReportLog::append('Missing power data during ' . $dateTime->format('d/m/Y') . ":\n");
                    ReportLog::setError(TRUE);
                }
                $dateTime->setTimestamp($timestamp);
                if ($missingCount == 0)
                {
                    ReportLog::append('  Period ending [' .
                                      $dateTime->format('H:i') . " (UTC)]: $genMeter");
                    $missingCount++;
                }
                else
                {
                    ReportLog::append(', ' . $genMeter);
                    $missingCount++;
                }
            }
        }
        if ($missingCount)
        {
            ReportLog::append("\n");
        }
    }

    // Now check whether every half-hour period had an entry in the database at all
    for ($i = 0; $i < 48 ; $i++)
    {
        if (!key_exists($i, $period))
        {
            if (!$anyHits)
            {
                $anyHits = TRUE;
                ReportLog::append('Missing power data during ' . $dateTime->format('d/m/Y') . ":\n");
                ReportLog::setError(TRUE);
            }
            $hour = $i / 2;
            $min = ($i % 2 == 1 ? 30 : 0);
            ReportLog::append(sprintf('  No power data recorded for any meter for period ending %02d:%02d', $hour, $min) . " (UTC)\n");
        }
    }

    if ($anyHits)
    {
        ReportLog::append("\n\n");
    }
    else
    {
        ReportLog::append('No missing power data for yesterday (' . $dateTime->format('d/m/Y') . ")\n\n");
    }
    return $anyHits;
}

?>
