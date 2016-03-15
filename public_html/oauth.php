<?php
/**
 * This PHP file won't show any output unless there is an error.
 * If it works, it will re-direct the browser to bec.php to re-start the
 * BEC Fault Monitoring script.
 * TODO: Currently the authorisation filenames are hard-coded rather than
 * using the ones from becfm.ini
 */

// Go up one directory out of public HTML area
chdir('..');

// We're expecting a code (_GET['code'] which we need to exchange for an access token
require_once 'vendor/autoload.php';

$client = new Google_Client();
$client->setAuthConfigFile('client_secret.json');

if (isset($_GET['code'])) {
    // Exchange code for access token
    $client->authenticate($_GET['code']);
    $token = $client->getAccessToken();

    // Save the access token
    $stream = fopen("bec_fault_mon.json", "c");
    if ($stream == FALSE)
    {
        die('<HTML>Error: Failed to open file for writing</HTML>');
    }
    $written = 0;
    while ($written < sizeof($token))
    {
        $thisWrite = fwrite($stream, substr($token, $written));
        if (FALSE != $thisWrite)
        {
            $written += $thisWrite;
        }
        else
        {
            die('<HTML>Error: Failed while writing to filesystem</HTML>');
        }
    }
    fclose($stream);
}

// Re-fetch bec.php - it should find the access token thsi time if we saved one!
$redirectTo = 'bec.php';
header('Location: ' . filter_var($redirectTo, FILTER_SANITIZE_URL));
?>
