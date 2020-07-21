<?php
/**
 * This PHP file won't show any output unless there is an error.
 * If it works, it will re-direct the browser to bec.php to re-start the
 * BEC Fault Monitoring script.
 * TODO: Currently the authorisation filenames are hard-coded rather than
 * using the ones from becfm.ini
 */

try {
    // Go up one directory out of public HTML area
    chdir('..');

    // Read configuration from ini file to override defaults
    define('BECFM_INI_FILENAME', 'becfm.ini');
    $iniFilename = BECFM_INI_FILENAME;

    // ini contains defaults which can be overridden by the ini file
    $ini = array('gmail_credentials_path' => getcwd() . '/bec_fault_mon.json',
                  'gmail_client_secret_path' => getcwd() . '/client_secret.json',
                  'gmail_username' => 'me');

    if (!is_null($_GET['inifilename']))
    {
        $iniFilename = $_GET['inifilename'];
    }

    if (file_exists($iniFilename))
    {
        $ini = array_merge($ini, parse_ini_file($iniFilename));
    }
    else if ($iniFilename != BECFM_INI_FILENAME)
    {
        die("<HTML>Error: Requested ini file '$iniFilename' not found</HMTL>");
    }

    // We're expecting a code (_GET['code'] which we need to exchange for an access token
    require_once 'vendor/autoload.php';

    $client = new Google_Client();
    $client->setAuthConfigFile($ini['gmail_client_secret_path']);
    $client->setLoginHint($ini['gmail_username']);

    if (isset($_GET['code'])) {

        // Exchange code for access token
        $client->authenticate($_GET['code']);
        $token = $client->getAccessToken();
        $filename = $ini['gmail_credentials_path'];

        if (strlen($client->getRefreshToken()) < 5)
        {
            /* We've lost the refresh token - it's better to revoke this
             * token now and re-request access from the user.
             */
            $client->revokeToken();
            unlink($filename);
        }
        else
        {
            // Save the access token
            if (strlen($token) != file_put_contents($filename, $token))
            {
                die("<HTML>Error: Failed to write access token to $filename</HTML>");
            }
        }
    }

    /* Re-fetch bec.php - it should find the access token this time if we saved one,
     * or re-request access from the user.
     */
    $redirectTo = 'bec.php';
    header('Location: ' . filter_var($redirectTo, FILTER_SANITIZE_URL));
}
catch (Exception $e)
{
    print('<HTML>Error: ' . $e->getMessage() . '</HTML>');
}
?>
