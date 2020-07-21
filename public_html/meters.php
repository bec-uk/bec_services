<?php

/**
 * Web-based access to meter details from the Bristol Energy Cooperative database.
 **/

chdir('..');
define('DATA_INI_FILENAME', 'data.ini');
$iniFilename = DATA_INI_FILENAME;

// ini contains defaults which can be overridden by the ini file
$ini = array('database_type' => 'mysql',
             'database_host' => 'localhost',
             'database_name' => 'bec',
             'database_username' => 'www-data',
             'database_user_password' => '',

             'bec_fault_mon_path' => '..');

// Read configuration from ini file to override defaults
if (file_exists($iniFilename))
{
    $ini = array_merge($ini, parse_ini_file($iniFilename));
}
else if ($iniFilename != DATA_INI_FILENAME)
{
    die("Error: Requested ini file '$iniFilename' not found\n");
}

// Pull in the becdb.php file from the BEC fault monitoring software
set_include_path(get_include_path() . PATH_SEPARATOR . $ini['bec_fault_mon_path']);
require_once 'becdb.php';

?>
<html>
<head>
<meta http-equiv="Content-Type" content="text/html; charset=UTF-8">
<title>BEC meter details page</title>
</head>
<body>
<h1>Bristol Energy Cooperative Meter Readings</h1>

<?php
$becDB = new BECDB($ini['database_type'], $ini['database_host'],
                   $ini['database_name'], $ini['database_username'],
                   $ini['database_user_password']);

// Build SQL command
$sql = 'SELECT sites.name, meters.serial, meters.code FROM meters LEFT JOIN sites ON meters.siteToken = sites.token ORDER BY sites.name';

$result = $becDB->fetchQuery($sql);
if (!$result)
{
?>
<p>
Failed to retrieve list of meters.
</p>
</body>
</html>
<?php
    // Exit here on failure
    exit(1);
}

// On success, list the meters with links to download CSV data
?>
<table>
<?php

$printTitles = TRUE;
foreach ($result as $line)
{
    print("<tr>\n");
    if ($printTitles)
    {
            foreach (array('Site', 'Serial number', 'Meter name') as $title)
            {
                print("<td>$title</td>");
            }
            print("</tr>\n<tr>\n");
            $printTitles = FALSE;
    }
    foreach (array_keys($line) as $key)
    {
        if ($key === 'code')
        {
            $meterName = $line[$key];
            $url = 'readings.php?meter_code=' . $becDB->meterDBName($meterName);
            print("<td><a href='$url'>$meterName</a></td>");
        }
        else
        {
            print("<td>$line[$key]</td>");
        }
    }
    print("</tr>\n");
}

?>
</table>
</body>
</html>
