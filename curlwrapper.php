<?php


/**
 * Use a curl handle to GET CSV data
 *
 * @param string $url The URL to GET from
 * @return string The raw CSV data or FALSE if none/on failure
 */
function curlGetCSV($url)
{
    return curlGetData($url, array('Content-type: text/csv', 'Accept: text/csv, application/json'));
}


/**
 * Function to read data back from a given URL.  The expected and accepted
 * content types should be specified.  Can optionally return the curl handle
 * still open for further use by the caller.  If not requested the curl
 * handle will be closed.
 *
 * @param string $url URL to read from
 * @param array $contentAndAcceptTypes An array of strings specifying expected
 *               and accepted content types
 * @param resource $returnCurlHandle Optional.  If not NULL, return the open curl handle
 */
function curlGetData($url, $contentAndAcceptTypes, &$returnCurlHandle = 'not used')
{
    global $verbose;

    $curlHandle = curl_init($url);
    curl_setopt($curlHandle, CURLOPT_HTTPHEADER, $contentAndAcceptTypes);
    curl_setopt($curlHandle, CURLOPT_HEADER, FALSE);
    curl_setopt($curlHandle, CURLOPT_FAILONERROR, TRUE);
    // Default method is GET - no need to set
    //curl_setopt($curlHandle, CURLOPT_HTTPGET, TRUE);
    if (DEBUG)
    {
        // Show progress (may need a callback - delete if it does!)
        curl_setopt($curlHandle, CURLOPT_NOPROGRESS, FALSE);
    }
    // Return the data fetched as the return value of curl_exec()
    curl_setopt($curlHandle, CURLOPT_RETURNTRANSFER, TRUE);

    if ($verbose > 0)
    {
        print("\nTrying URL: $url\n");
    }
    $data = curl_exec($curlHandle);
    if ($errNo = curl_errno($curlHandle))
    {
        if ($verbose > 0)
        {
            print('Info: No data returned from URL - error code ' . $errNo . "\n\t" . curl_error($curlHandle) . "\n");
        }
        $data = FALSE;
    }
    if (DEBUG)
    {
        print("Raw data:\n");
        print_r($data);
        print("\n");
    }
    if ($returnCurlHandle == 'not used')
    {
        curl_close($curlHandle);
    }
    else
    {
        $returnCurlHandle = $curlHandle;
    }
    return $data;
}


/**
 * Function to POST data using a given URL.  The expected and accepted
 * content types should be specified.  Can optionally return the curl handle
 * still open for further use by the caller.  If not requested the curl
 * handle will be closed.
 *
 * @param string $url URL to POST
 * @param array POST fields
 * @param array $contentAndAcceptTypes An array of strings specifying expected
 *               and accepted content types
 * @param resource $returnCurlHandle Optional.  If not NULL, return the open curl handle
 */
function curlPostData($url, $postFields, $contentAndAcceptTypes, &$returnCurlHandle = 'not used')
{
    global $verbose;

    $curlHandle = curl_init($url);
    curl_setopt($curlHandle, CURLOPT_HTTPHEADER, $contentAndAcceptTypes);
    curl_setopt($curlHandle, CURLOPT_HEADER, FALSE);
    curl_setopt($curlHandle, CURLOPT_FAILONERROR, TRUE);
    curl_setopt($curlHandle, CURLOPT_POST, TRUE);
    curl_setopt($curlHandle, CURLOPT_HTTPGET, FALSE);
    curl_setopt($curlHandle, CURLOPT_FRESH_CONNECT, TRUE);
    curl_setopt($curlHandle, CURLOPT_FORBID_REUSE, TRUE);
    curl_setopt($curlHandle, CURLOPT_POSTFIELDS, http_build_query($postFields));
    if (DEBUG)
    {
        // Show progress (may need a callback - delete if it does!)
        curl_setopt($curlHandle, CURLOPT_NOPROGRESS, FALSE);
    }
    // Return the data fetched as the return value of curl_exec()
    curl_setopt($curlHandle, CURLOPT_RETURNTRANSFER, TRUE);

    if ($verbose > 0)
    {
        print("\nTrying URL: $url\n\tPOST:\n");
        print_r($postFields);
        print("\n");
    }
    $data = curl_exec($curlHandle);
    if ($errNo = curl_errno($curlHandle))
    {
        if ($verbose > 0)
        {
            print('Info: No data returned from URL - error code ' . $errNo . "\n\t" . curl_error($curlHandle) . "\n");
        }
        $data = FALSE;
    }
    if (DEBUG)
    {
        print("Raw data:\n");
        print_r($data);
        print("\n");
    }
    if ($returnCurlHandle == 'not used')
    {
        curl_close($curlHandle);
    }
    else
    {
        $returnCurlHandle = $curlHandle;
    }
    return $data;
}



?>