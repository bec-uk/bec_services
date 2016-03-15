<?php

/**
 * Return date string for yesterday to use in SQL queries
 * @return string Date yesterday
 */
function getYesterdayForSQL()
{
    global $verbose;

    /* In fact if it's before 6AM, do the day before yesterday as Simtricity
     * meter readings are made at 6AM.
     */
    $dateTime = new DateTime();
    $dateTime->setTime(0,0);
    $timestamp = time();
    $dayTimestamp = $dateTime->getTimestamp();
    if ($timestamp - $dayTimestamp < 6 * 60 * 60)
    {
        $yesterday = '\'' . date('Y-m-d', strtotime('-2 days', $timestamp)) . '\'';
    }
    else
    {
        $yesterday = '\'' . date('Y-m-d', strtotime('-1 day', $timestamp)) . '\'';
    }

    if ($verbose > 3)
    {
        print(__FUNCTION__ . "(): Date for yesterday = $yesterday\n");
    }

    return $yesterday;
}


/**
 * Function to highlight when a generation meter has power readings of zero
 * over the last day despite decent sol_rad figures (>DECENT_SOLRAD_LIMIT W/m2).
 * It looks at each half-hourly measurement.
 *
 * @param unknown_type $becDB
 * @return boolean TRUE if any unexpected zero generation periods seen
 */
function zeroPowerYesterday(&$becDB)
{
    global $verbose, $graphsEnabled;

    // TODO: Tune this value to remove false hits; we wouldn't expect any power
    // output when it's really dim...
    define('DECENT_SOLRAD_LIMIT', 10);

    $sql = "SELECT power.*,
                   weather_filton.sol_rad AS sol_rad_filton,
                   create_centre_meteo_raw.sol_rad AS sol_rad_cc
            FROM power
                LEFT JOIN weather_filton ON power.datetime = weather_filton.datetime
                LEFT JOIN create_centre_meteo_raw ON power.datetime = create_centre_meteo_raw.datetime
            WHERE (weather_filton.sol_rad > " . DECENT_SOLRAD_LIMIT . " OR
                  create_centre_meteo_raw.sol_rad > " . DECENT_SOLRAD_LIMIT . ") AND
                  DATE(power.datetime) = " . getYesterdayForSQL();
    $result = $becDB->fetchQuery($sql);

    $anyHits = FALSE;
    foreach ($result as $entry)
    {
        foreach ($becDB->getGenMeterArray() as $genMeter)
        {
            if ($entry[$genMeter] === 0)
            {
                if (!$anyHits)
                {
                    $anyHits = TRUE;
                    ReportLog::append('Unexpected zero power output:' . "\n");
                    ReportLog::setError(TRUE);
                }
                ReportLog::append("  Meter $genMeter recorded no power output for period ending " .
                                  $entry['datetime']->format('H:i') . ", but solar radiation readings were CC = " . $entry['sol_rad_cc'] .
                                  ", Filton = " . $entry['sol_rad_filton'] . "\n");
            }
        }
    }
    if ($anyHits)
    {
        ReportLog::append("\n\n");
    }
    else if ($verbose)
    {
        print('No unexpected zero power output readings yesterday (' . getYesterdayForSQL() . ")\n\n");
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

    $sql = 'SELECT * FROM power
            WHERE DATE(power.datetime) = ' . getYesterdayForSQL();
    $result = $becDB->fetchQuery($sql);

    if (sizeof($result) == 0)
    {
        ReportLog::append('Simtricity: No power data found for yesterday (' . getYesterdayForSQL() . ")\n\n");
        ReportLog::setError(TRUE);
        return TRUE;
    }

    $anyHits = FALSE;
    $period = array();
    foreach ($result as $entry)
    {
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
                    ReportLog::append('Simtricity: Missing power data:' . "\n");
                    ReportLog::setError(TRUE);
                }
                ReportLog::append("  Meter $genMeter has missing power data for period ending " .
                                   $entry['datetime']->format('H:i') . "\n");
            }
        }
    }

    for ($i = 0; $i < 48 ; $i++)
    {
        if (!key_exists($i, $period))
        {
            if (!$anyHits)
            {
                $anyHits = TRUE;
                ReportLog::append('Simtricity: Missing power data:' . "\n");
                ReportLog::setError(TRUE);
            }
            $hour = $i / 2;
            $min = ($i % 2 == 1 ? 30 : 0);
            ReportLog::append(sprintf('  No power data recorded for any meter for period ending %02d:%02d', $hour, $min) . "\n");
        }
    }

    if ($anyHits)
    {
        ReportLog::append("\n\n");
    }
    else if ($verbose)
    {
        print('Simtricity: No missing power data for yesterday (' . getYesterdayForSQL() . ")\n\n");
    }
    return $anyHits;
}

?>
