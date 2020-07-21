<?php

/**
 * Web-based access to data from the Bristol Energy Cooperative database.
 **/

chdir('..');
define('DATA_INI_FILENAME', 'data.ini');
$iniFilename = DATA_INI_FILENAME;

$weatherDatasets = array('forecast', 'filton', 'cc');
$datasets = array_merge($weatherDatasets, array('simtricity_flows'));
$tables = array('weather_forecastio', 'weather_filton', 'create_centre_meteo_raw', 'site_extra_info');
$supportsDaily = array(1, 0, 0, 0);

if (key_exists('dataset', $_GET) &&
    FALSE !== ($dataset = array_search($_GET['dataset'], $datasets)))
{
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

    $becDB = new BECDB($ini['database_type'], $ini['database_host'],
                       $ini['database_name'], $ini['database_username'],
                       $ini['database_user_password']);

    $sql = '';
    if ($_GET['dataset'] == 'simtricity_flows')
    {
        // The Simtricity flows dataset
        $sql = "SELECT webapp_shortcode, flow_token_gen, flow_token_exp, flow_token_use
                    FROM $tables[$dataset]";
        if (key_exists('site_shortcode', $_GET))
        {
            $sql .= " WHERE webapp_shortcode = '$_GET[site_shortcode]'";
        }
    }
    else
    {
        // Weather datasets
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
    }

    $result = $becDB->fetchQuery($sql);
    if (!$result)
    {
        goto html;
    }

    // Allow other sites to access the data from Javascript
    header('Access-Control-Allow-Origin: *');
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
<p>
Queries are of the following form for weather datasets:<br>
&nbsp;<code>.../data.php?dataset=(<?php print(implode('|', $weatherDatasets));?>
)[&amp;start=&lt;datetime&gt;][&amp;end=&lt;datetime&gt;][&amp;format=daily]</code><br>
where:
<ul>
<li>the datasets are forecast.io, Filton weather station, or Create Centre data</li>
<li><code>start</code> and <code>end</code> specify the start and end of the time period to request data for</li>
<li>a <code>datetime</code> is in the format <code>YYYY-MM-DD[THH:mm]</code> and specified in UTC/GMT - the time must not be specified if <code>format=daily</code> is used</li>
<li>the default format will be the finest time-grain in the dataset, but 'daily' can be specified to 'zoom out' (only available for forecast.io dataset currently)</li>
</ul>
All dates and times returned are GMT/UTC, so during British Summer Time an hour must be added.
</p>
<p>
For the Simtricity flows dataset the query is of the form:<br>
&nbsp;<code>.../data.php?dataset=simtricity_flows[&amp;site_shortcode=&lt;site_shortcode&gt;]</code><br>
where:
<ul>
<li>the <var>site_shortcode</var> is a short string identifier for the site.</li>
</ul>
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
