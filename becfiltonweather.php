<?php

require_once 'curlwrapper.php';

/**
 * Class to abstract use of a string from the web or a local file as the source
 * of Filton CSV weather data.
 */
class FiltonCSV
{
    private $handle;
    private $curRow;
    private $dateAsFields;
    private $dateField;
    private $timeField;
    private $yearField;
    private $monthField;
    private $dayField;
    private $hourField;
    private $minuteField;
    private $solRadField;


    public function __construct($type, $csvSource)
    {
        switch ($type)
        {
            case 'web-string':
                $this->handle = fopen('data://text/plain,' . $csvSource, 'r');
                break;
            case 'file':
                $this->handle = fopen($csvSource, 'r');
                break;
            default:
                die("Error: Unrecognised CSV input type '$type'\n");
        }
        if (!$this->handle)
        {
            return FALSE;
        }

        // Read the header line to work out fields for date, time and solar
        $headerStr = fgets($this->handle);
        while ($headerStr !== FALSE && !preg_match('/da(y|te).*,.*solar/i', $headerStr))
        {
            $headerStr = fgets($this->handle);
        }
        if ($headerStr === FALSE)
        {
            print("Error: Unable to find CSV header line in $type\n");
            return FALSE;
        }
        // To ease column location, strip whitespace and chnage to lower case
        $columns = str_getcsv(str_replace(' ', '', strtolower($headerStr)));

        if ($arrayMatches = preg_grep('/solar/', $columns))
        {
            $this->solRadField = key($arrayMatches);
        }
        else
        {
            print("Error: Failed to find solar radiation field in Filton CSV data\n");
            return FALSE;
        }

        $this->dateAsFields = TRUE;
        if ($arrayMatches = preg_grep('/date/', $columns))
        {
            $this->dateField = key($arrayMatches);
            $this->dateAsFields = FALSE;
        }

        if ($this->dateAsFields)
        {
            if (FALSE === ($this->yearField = array_search('year', $columns)) ||
                FALSE === ($this->monthField = array_search('month', $columns)) ||
                FALSE === ($this->dayField = array_search('day', $columns)) ||
                FALSE === ($this->hourField = array_search('hour', $columns)) ||
                FALSE === ($this->minuteField = array_search('min', $columns)))
            {
                print("Error: Failed to find all date and time fields in Filton CSV\n");
                return FALSE;
            }
        }
        else
        {
            if ($arrayMatches = preg_grep('/time/', $columns))
            {
                $this->timeField = key($arrayMatches);
            }
            else
            {
                print("Error: Failed to find time field in Filton CSV data\n");
                return FALSE;
            }
        }
        return TRUE;
    }

    public function getNextRow()
    {
        if (FALSE === ($this->curRow = fgetcsv($this->handle)))
        {
            return FALSE;
        }
        return TRUE;
    }

    public function getDateTime()
    {
        if ($this->dateAsFields)
        {
            $dateTime = new DateTime();
            $dateTime->setDate($this->curRow[$this->yearField], $this->curRow[$this->monthField], $this->curRow[$this->dayField]);
            $dateTime->setTime($this->curRow[$this->hourField], $this->curRow[$this->minuteField]);
        }
        else
        {
            $dateTime = new DateTime($this->curRow[$this->dateField] . 'T' . $this->curRow[$this->timeField] . 'Z');
        }
        // Convert from BST to GMT if necessary
        dateTimeToGMT($dateTime);
        return $dateTime;
    }

    public function getMinute()
    {
        if ($this->dateAsFields)
        {
            $minute = $this->curRow[$this->minuteField];
        }
        else
        {
            // Assumes time is in HH:mm with no seconds!
            $minute = substr($this->curRow[$this->timeField], strpos($this->curRow[$this->timeField], ':') + 1, 2);
        }
        return $minute;
    }

    public function getSolRad()
    {
        return  $this->curRow[$this->solRadField];
    }

    public function __destruct()
    {
        fclose($this->handle);
    }
}


/**
 * Class for fetching data from the Filton weather station run by Martyn Hicks.
 * This uses the SQL interface introduced at the end of January 2016.
 */
class BECFiltonWeather
{
    const FILTON_BASE_URL = 'http://www.martynhicks.uk/weather';
    const EARLIEST_WEB_DATE = '2016-01-30';

    /**
     * The default exposed interface is at wxsqlall1.php, but Martyn has created
     * a more pared-down page for us to access it through.  The script can use
     * either, so if one stops functioning we could try the other before checking
     * whether the form usage has changed.
     * The web browser form  from which the parameters of the POST request have
     * been discovered is at:
     *   http://www.martynhicks.uk/weather/wxsql.php
     */
    //const FILTON_SQL_URL_SUFFIX = '/wxsqlall1.php';
    const FILTON_SQL_URL_SUFFIX = '/wxsqlBEC.php';


    /**
     * Get the URL to retrieve CSV data from for a given date.  The web form we
     * access generates the CSV file when we POST our query and generates a web
     * page with a link to the CSV file to be downloaded.  This function submits
     * the query and retrieves the URL of the CSV file from the resulting web
     * page.
     *
     * @param DateTime $date
     * @return string The URL the CSV data can be loaded from
     */
    public function getCSVURL(&$date)
    {
        if ($date->getTimestamp() < strtotime(self::EARLIEST_WEB_DATE))
        {
            die('Error: Specified date (' . $date->format('Y-m-d') . ') is ' .
                'earlier than the earliest data available via this interface (' .
                self::EARLIEST_WEB_DATE . ')' . "\n");
        }

        $url = self::FILTON_BASE_URL . self::FILTON_SQL_URL_SUFFIX;
        $postFields = array('formYear' => $date->format('Y'), 'formMonth' => $date->format('m'), 'formDate' => $date->format('d'), 'submit1' => 'submit');
        $putResult = curlPostData($url, $postFields, array('Content-type: application/x-www-form-urlencoded', 'Accept: text/html'));
        $startAt = 0;
        $csvURL = FALSE;
        while ($hrefOffset = strpos($putResult, '<a href=', $startAt))
        {
            $matches = array();
            if (preg_match('/[a-zA-Z0-9\-\/]+\.csv/', substr($putResult, $hrefOffset + 8), $matches))
            {
                $csvURL = self::FILTON_BASE_URL . '/' . $matches[0];
                break;
            }
        }
        return $csvURL;
    }


    /**
     * Use the web to get CSV weather data for 1 day
     *
     * @param DateTime $date
     * @return string CSV weather data
     */
    public function getCSVData(&$date)
    {
        if (FALSE == ($url = $this->getCSVURL($date)))
        {
            return FALSE;
        }
        $csvData = curlGetData($url, array('Content-type: text/html', 'Accept: text/csv'));
        return $csvData;
    }

}

?>