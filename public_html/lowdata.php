<HTML>

<HEAD>
<TITLE>Bristol Energy Cooperative - Low Data IP Log</TITLE>
</HEAD>

<?php
include "utils.php";


/******************************************************************************/

// Location to pick up ini file from to override default database access parameters
chdir('..');
define('INI_FILENAME', 'slideshow_db.ini');
$iniFilename = INI_FILENAME;

// Only one key expected (the site short-code)
if (count($_GET) != 1)
{
    $errorMessage = "Error: Invalid query - a single site short-code was expected";
    goto errorMessage;
}

// Retrieve the site short-code from the URL that was used to access this page
$sitecode = key($_GET);

// Default database access parameters which can be overriden by the ini file
$ini = array('database_type' => 'mysql',
             'database_host' => 'localhost',
             'database_name' => 'bec',
             'database_username' => 'www-data',
             'database_user_password' => '');

// Read configuration from ini file to override defaults
if (file_exists($iniFilename))
{
    $ini = array_merge($ini, parse_ini_file($iniFilename));
}

$dbHandle = FALSE;
try
{
    $dbHandle = new PDO($ini['database_type'] . ":host=" . $ini['database_host'] . ";dbname=" . $ini['database_name'],
                        $ini['database_username'], $ini['database_user_password']);
}
catch (Exception $e)
{
    print('Exception: ' . $e->getMessage());
    print("<br>");
}

if ($dbHandle === FALSE)
{
    $errorMessage = "Error: Failed to connect to " . $ini['database_type'] . " '" . $ini['database_name'] . "' database";
    goto errorMessage;
}

// First check for a valid site short-code
$sql = "SELECT COUNT(1) AS size FROM slideshow_sites WHERE sitecode='$sitecode'";

$result = runQuery($dbHandle, $sql);

if (gettype($result) != 'array')
{
    $errorMessage = $result;
    goto errorMessage;
}
if ($result[0]['size'] == 0)
{
    $errorMessage = "Error: Did not find '$sitecode' in slideshow_sites table\n";
    goto errorMessage;
}

goto success;

// If something went wrong we end up here
errorMessage:
?>
<BODY>
Failed low data IP logging.<br>
<?php
print($errorMessage);
?>
</BODY>
</HTML>

<?php
exit;

// All went well
success:
?>
<BODY>
Thanks for checking in
<?php
print(" " . $sitecode);

# Record this page fetch to the IP address log file
writeIPLog($sitecode);
?>
!
</BODY>
</HTML>
