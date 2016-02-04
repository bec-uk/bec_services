<?php


/**
 * Class handling access to the Simtricity platform for BEC PHP code
 * @author David Cook
 */
class BECSimtricity {

	private $token = NULL;


	/**
	 * Constructor; reads communication token from file
	 */
	public function __construct() {
		$this->token = $this->getToken(SIMTRICITY_TOKEN_FILE);
		return $this;
	}


	/**
	 * Initialise a curl handle to GET JSON data
	 * @param string $url The URL to GET from
	 * @return object The object representing the decoded JSON data retreived
	 */
	protected function curlGetJSON($url, $supportPages = FALSE, $mergeArrayNames = NULL) {
		$curlHandle = curl_init($url);
		curl_setopt($curlHandle, CURLOPT_HTTPHEADER, array('Content-type: application/json', 'Accept: application/json'));
		curl_setopt($curlHandle, CURLOPT_HEADER, FALSE);
		curl_setopt($curlHandle, CURLOPT_FAILONERROR, TRUE);
		// Default method is GET - no need to set
		//curl_setopt($curlHandle, CURLOPT_HTTPGET, TRUE);
		if (DEBUG) {
			// Show progress (may need a callback - delete if it does!)
			curl_setopt($curlHandle, CURLOPT_NOPROGRESS, FALSE);
		}
		// Return the data fetched as the return value of curl_exec()
		curl_setopt($curlHandle, CURLOPT_RETURNTRANSFER, TRUE);

		print("\nTrying URL: $url\n");
		$data = curl_exec($curlHandle);
		if ($errNo = curl_errno($curlHandle)) {
			die('Error: Failed to get JSON data from Simtricity - error code ' . $errNo . "\n\t" . curl_error($curlHandle) . "\n");
		}
		if (DEBUG) {
			print("Raw data:\n");
			print_r($data);
			print("\n");
		}

		$jsonObj = json_decode($data);
		if ($jsonObj === NULL) {
			die("Error: Failed to decode data as JSON\n");
		}

		if ($supportPages && $mergeArrayNames) {
			// This API returns data in pages - there could be more pages to read!
			$pagesLeft = $jsonObj->pageCount - 1;
			while ($pagesLeft) {
				// The order we get the pages doesn't matter; we just merge the meters array in the meterData
				$pageURL = $url . '&page=' . ($pagesLeft + 1);
				curl_setopt($curlHandle, CURLOPT_URL, $pageURL);
				print("\nTrying URL: $pageURL\n");
				$data = curl_exec($curlHandle);
				if ($errNo = curl_errno($curlHandle)) {
					die('Error: Failed to get meter list page ' . ($pagesLeft + 1) . ' from Simtricity - error code ' . $errNo . "\n\t" . curl_error($curlHandle) . "\n");
				}
				$pageData = json_decode($data);
				if ($pageData === NULL) {
					die("Error: Failed to decode page " . ($pagesLeft + 1) . " data as JSON\n");
				}
				foreach ($mergeArrayNames as $mergeArrayName) {
					$jsonObj->$mergeArrayName = array_merge($jsonObj->$mergeArrayName, $pageData->$mergeArrayName);
				}
				$jsonObj->pageCount = 1;
				$pagesLeft--;
			}
		}
		curl_close($curlHandle);
		return $jsonObj;
	}

	/**
	 * Return an object containing all the site info we can get
	 */
	public function getListOfSites() {
		$url = SIMTRICITY_BASE_URI . '/a/site?authkey=' . $this->getToken();
		$siteData = $this->curlGetJSON($url, TRUE, array('sites'));
		return $siteData->sites;
	}


	/**
	 * Return an array containing all the meter info we can get
	 */
	public function getListOfMeters() {
		$url = SIMTRICITY_BASE_URI . '/a/meter?authkey=' . $this->getToken();
		$meterData = $this->curlGetJSON($url, TRUE, array('meters'));
		return $meterData->meters;
	}


	/**
	 * Retrieves the Simtricity API token for accessing the API from the given file.
	 * The file format is simply a text file containing the token (although we
	 * ignore lines that don't look like tokens too).
	 * Die on failure.
	 * @param string $filename Name of file to retrieve token from
	 * @return string Token as a string
	 */
	public function getToken($filename = NULL) {
		if ($this->token) {
			return $this->token;
		}
		if ($filename === NULL) {
			die("Error: No stored token, and no filename to read it from given\n");
		}
		$inFile = fopen($filename, 'r');
		$token = NULL;
		while ($line = fgets($inFile))
		{
			// Check the line is long enough and contains two or more '-'s
			if (strlen($line) > 30 && substr_count($line, '-') >= 2) {
				if (1 != preg_match('([0-9a-fA-F\-]+)', $line, $matches)) {
					continue;
				}
				if (strlen($matches[0]) > 30) {
					$token = $matches[0];
					if (DEBUG) {
						print("Simtricity token is: $token\n");
					}
				} else if (DEBUG) {
					print("Skipping line containing: $line");
				}
			} else if (DEBUG) {
				print("Skipping line containing: $line");
			}
		}
		fclose($inFile);
		if ($token === NULL) {
			die("Error: Simtricity token not found in '$filename'\n");
		}

		return $token;
	}


}

?>
