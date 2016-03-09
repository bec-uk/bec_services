<?php

/**
 * Web-based access to data from the Bristol Energy Cooperative database.
 **/

define('DATA_INI_FILENAME', 'data.ini');
$iniFilename = DATA_INI_FILENAME;

$datasets = array('forecast', 'filton', 'cc');
$tables = array('weather_forecastio', 'weather_filton', 'create_centre_meteo_raw');
$supportsDaily = array(1, 0, 0);

if (key_exists('dataset', $_GET) &&
    FALSE !== ($dataset = array_search($_GET['dataset'], $datasets)))
{
    // ini contains defaults which can be overriden by the ini file
    $ini = array('database_type' => 'mysql',
                 'database_host' => 'localhost',
                 'database_name' => 'bec',
                 'database_username' => 'www-data',
                 'database_user_password' => '',

                 'bec_fault_mon_path' => '/mnt/1tb1/devel/bec_src/bec_fault_mon');

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
    require 'becdb.php';

    $becDB = new BECDB($ini['database_type'], $ini['database_host'],
                       $ini['database_name'], $ini['database_username'],
                       $ini['database_user_password']);

    $sql = 'SELECT * FROM ' . $tables[$dataset];

    $dateOnly = FALSE;
    $needAnd = FALSE;
    $needWhere = TRUE;
    if (key_exists('format', $_GET))
    {
        switch ($_GET['format'])
        {
            case 'daily':
                if ($supportsDaily[$dataset])
                {
                    $sql .= '_daily';
                    $dateOnly = TRUE;
                }
                else
                {
                    goto html;
                }
                break;
            default:
                goto html;
        }
    }

    if (key_exists('start', $_GET))
    {
        $start = new DateTime($_GET['start'] . ($dateOnly ? 'T00:00' : '') . 'Z');
        if (!$start)
        {
            goto html;
        }
        $sql .= ($needWhere ? ' WHERE' : '') .
        	' date' . ($dateOnly ? '' : 'time') . ' >= \'' . $start->format('Y-m-d' . ($dateOnly ? '' : '\TH:i')) . '\'';
        $needWhere = FALSE;
        $needAnd = TRUE;
    }

    if (key_exists('end', $_GET))
    {
        $end = new DateTime($_GET['end'] . ($dateOnly ? 'T00:00' : '') . 'Z');
        if (!$end)
        {
            goto html;
        }
        $sql .= ($needWhere ? ' WHERE' : '') .
                ($needAnd ? ' AND' : '') .
        	' date' . ($dateOnly ? '' : 'time') . ' <= \'' . $end->format('Y-m-d' . ($dateOnly ? '' : '\TH:i')) . '\'';
        $needWhere = FALSE;
        $needAnd = TRUE;
    }

    $result = $becDB->fetchQuery($sql);
    if (!$result)
    {
        goto html;
    }

    // Output the retrieved data in JSON form
    header('Content-Type:application/json;charset=utf-8');
    if (!($json = json_encode($result)))
    {
        goto html;
    }
    print($json);
    exit(0);
}
html:
?>
<html>
<head>
<meta http-equiv="Content-Type" content="text/html; charset=UTF-8">
<title>BEC database access help</title>
</head>
<body>
Queries are of the following form:<br>
&nbsp;<code>.../weather.php?dataset=(<?php print(implode('|', $datasets));?>
)[&amp;start=&lt;datetime&gt;][&amp;end=&lt;datetime&gt;][&amp;format=daily]</code><br>
where:
<ul>
<li>the datasets are forecast.io, Filton weather station, or Create Centre data</li>
<li><code>start</code> and <code>end</code> specify the start and end of the time period to request data for</li>
<li>a <code>datetime</code> is in the format YYYY-MM-DD[THH:mm] and specified in UTC/GMT - the time must not be specified if <code>format=daily</code> is used</li>
<li>the default format will be the finest time-grain in the dataset, but 'daily' can be specified to 'zoom out'</li>
</ul>
<hr>
<?php
print_r($_GET);
print($sql);
?>
</body>
</html>
