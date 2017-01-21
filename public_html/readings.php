<?php

/**
 * Web-based access to meter readings from the Bristol Energy Cooperative database.
 **/

chdir('..');
define('DATA_INI_FILENAME', 'data.ini');
$iniFilename = DATA_INI_FILENAME;

if (key_exists('meter_code', $_GET))
{
    // ini contains defaults which can be overriden by the ini file
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

    $becDB = new BECDB($ini['database_type'], $ini['database_host'],
                       $ini['database_name'], $ini['database_username'],
                       $ini['database_user_password']);

    // Build SQL command
    $sql = "SELECT * FROM dailyreading_" . $_GET['meter_code'];

    $result = $becDB->fetchQuery($sql);
    if ($result === FALSE)
    {
        goto html;
    }
    else if (!$result)
    {
        print("No data recorded for this meter\n");
        exit(0);
    }

    // Output the retrieved data in CSV form to be saved in a file
    header('Content-Type:text/csv;charset=utf-8');
    header('Content-Disposition:attachment;filename=' . $_GET['meter_code'] . '.csv');
    $fh = fopen('php://output', 'w');
    $printTitles = TRUE;
    foreach ($result as $line){
        if ($printTitles)
        {
                fputcsv($fh, array_keys($line));
                $printTitles = FALSE;
        }
        fputcsv($fh, $line);
    }
    exit(0);
}
html:
?>
<html>
<head>
<meta http-equiv="Content-Type" content="text/html; charset=UTF-8">
<title>BEC meter reading page</title>
</head>
<body>
<p>
Queries are of the following form:<br>
&nbsp;<code>.../readings.php?meter_code=&lt;meter_code_name&gt;</code>
</p>
<hr>
<?php
print("Get array:<br>&nbsp;&nbsp;");
print_r($_GET);
print("<br>SQL command:<br>&nbsp;&nbsp;");
print($sql);
?>
</body>
</html>
