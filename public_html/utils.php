<?php
/* Function to perform an SQL query */
function runQuery($dbHandle, $sql)
{
	// Create & perform the query
	if (FALSE === ($query = $dbHandle->query($sql)))
	{
		$result = "Error: Failed to create query '$sql'\n";
		$result .= $dbHandle->errorInfo();
		return $result;
	}
	$result = $query->fetchAll(PDO::FETCH_ASSOC);
	if (FALSE === $result)
	{
		$result = "Error: Failed to store result of query '$sql'\n";
		$result .= $dbHandle->errorInfo();
	}
	return $result;
}

/*
 * Function that logs the time and IP address of each fetch of the page to a file
 * specific to the site-code used.  The logs are to help us determine whether a
 * site display is running as they will refetch the page every hour.
 */
function writeIPLog($sitecode)
{
    $logFilename = "slideshow_fetch_$sitecode.log";
    $remoteIP = $_SERVER['REMOTE_ADDR'];
    $fetchInfo = date(DATE_ATOM) . ': ' . $remoteIP . ' (' . gethostbyaddr($remoteIP) . ")\n";

    // If the log file is over 32kB, rename it to have a '.1' on the end of its
    // name (deleteing any old '.1' file) and we'll start a fresh log file.
    if (file_exists($logFilename) && filesize($logFilename) > 32 * 1024)
    {
        if (file_exists($logFilename . '.1'))
        {
            unlink($logFilename . '.1');
        }
        rename($logFilename, $logFilename . '.1');
    }

    file_put_contents($logFilename, $fetchInfo, FILE_APPEND);
}

?>
