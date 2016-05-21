<?php

/**
 * Class for collecting report info
 */
class ReportLog
{
    static $reportLog = '';
    static $errorInReport = FALSE;


    /**
     * Prepend to the report log
     *
     * @param string $str
     */
    static function prepend($str)
    {
         self::$reportLog = $str . self::$reportLog;
    }


   /**
     * Append to the report log
     *
     * @param string $str
     */
    static function append($str)
    {
         self::$reportLog .= $str;
    }


    /**
     * Get the report log
     */
    static function get()
    {
        return self::$reportLog;
    }


    /**
     * Record that there is an error in what we will report
     *
     * @param boolean $bool
     */
    static function setError($bool)
    {
        self::$errorInReport = $bool;
    }


    /**
     * Return whether there is an error to report as a boolean
     */
    static function hasError()
    {
        return self::$errorInReport;
    }
}

?>
