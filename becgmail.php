<?php

/**
 * Class wrapping accesses to Gmail for BEC PHP code
 * @author David Cook
 */
class BECGmailWrapper
{
	protected $service;

	public function __construct() {
		$client = $this->getClient();
		$this->service = new Google_Service_Gmail($client);
	}

	/**
	 * Returns an authorized API client.
	 * @return Google_Client the authorized client object
	 */
	function getClient() {
		global $ini;

		$client = new Google_Client();
		$client->setApplicationName($ini['gmail_application_name']);
		$client->setScopes(GMAIL_SCOPES);
		$client->setAuthConfigFile($ini['gmail_client_secret_path']);
		$client->setAccessType('offline');

		// Load previously authorized credentials from a file.
		$credentialsPath = expandHomeDirectory($ini['gmail_credentials_path']);
		if (file_exists($credentialsPath)) {
			$accessToken = file_get_contents($credentialsPath);
		} else {
			// Request authorization from the user.
			$authUrl = $client->createAuthUrl();
			printf("Open the following link in your browser:\n%s\n", $authUrl);
			print 'Enter verification code: ';
			$authCode = trim(fgets(STDIN));

			// Exchange authorization code for an access token.
			$accessToken = $client->authenticate($authCode);

			// Store the credentials to disk.
			if(!file_exists(dirname($credentialsPath))) {
				mkdir(dirname($credentialsPath), 0700, true);
			}
			file_put_contents($credentialsPath, $accessToken);
			printf("Credentials saved to %s\n", $credentialsPath);
		}
		$client->setAccessToken($accessToken);

		// Refresh the token if it's expired.
		if ($client->isAccessTokenExpired()) {
			$client->refreshToken($client->getRefreshToken());
			file_put_contents($credentialsPath, $client->getAccessToken());
		}
		return $client;
	}


	/**
	 * Get list of handles to messages in user's mailbox using the given filter
	 * query.  Users can call the get() method on the handle to retrieve the
	 * message content.
	 *
	 * @param  string $filter Filter query to use
	 * @return array Array of handles to Messages.
	 */
	function getMessageHandleList($filter) {
		global $ini;

		$pageToken = NULL;
		$messages = array();
		$opt_param = array('q' => $filter);
		do {
			try {
				if ($pageToken) {
					$opt_param['pageToken'] = $pageToken;
				}
				$messagesResponse = $this->service->users_messages->listUsersMessages($ini['gmail_username'], $opt_param);
				if ($messagesResponse->getMessages()) {
					$messages = array_merge($messages, $messagesResponse->getMessages());
					$pageToken = $messagesResponse->getNextPageToken();
				}
			} catch (Exception $e) {
				print 'Error: ' . $e->getMessage();
			}
		} while ($pageToken);

		if (DEBUG) {
			foreach ($messages as $message) {
				print 'Message with ID: ' . $message->getId() . '\n';
			}
		}

		return $messages;
	}


	/**
	 * List the labels in a gmail account
	 */
	function listLabels()
	{
		global $ini;

		// Print the labels in the user's account.
		$results = $this->service->users_labels->listUsersLabels($ini['gmail_username']);

		if (count($results->getLabels()) == 0) {
			print "No labels found.\n";
		} else {
			print "Labels:\n";
			foreach ($results->getLabels() as $label) {
				printf("- %s\n", $label->getName());
			}
		}
	}


	/**
	 * Get a label ID for the given label name.  Creates the label if it doesn't already exist
	 *
	 * @param  string $labelName Name of the new Label
	 * @return string Label ID
	 */
	function getOrCreateLabelID($labelName)
	{
		global $ini;

		static $label = NULL;
		if ($label === NULL) {
			$results = $this->service->users_labels->listUsersLabels($ini['gmail_username']);
			foreach ($results->getLabels() as $thisLabel) {
				if ($thisLabel->getName() === $labelName) {
					$label = $thisLabel;
					break;
				}
			}
		}
		if ($label === NULL) {
			// Label didn't exist - create it
			$label = new Google_Service_Gmail_Label();
			$label->setName($labelName);
			try {
				$label = $this->service->users_labels->create($ini['gmail_username'], $label);
				if (DEBUG) {
					print 'Label \'$labelName\' with ID: ' . $label->getId() . " created \n";
				}
			} catch (Exception $e) {
				print 'Error: Exception - ' . $e->getMessage() . "\n";
			}
		}

		return $label->getId();
	}


	/**
	 * Apply a label to a message
	 * @param unknown_type $messageID
	 * @param string $labelName The label name to add
	 * @return boolean Returns TRUE on success, else FALSE
	 */
	function applyLabel($messageID, $labelName)
	{
		global $ini;

		$labelID = $this->getOrCreateLabelID($labelName);

		$modReq = new Google_Service_Gmail_ModifyMessageRequest();
		$modReq->setAddLabelIds(array($labelID));
		try {
			$retVal = $this->service->users_messages->modify($ini['gmail_username'], $messageID, $modReq);
			if ($retVal->getId() != $messageID) {
				print("Error: Failed to apply label '$labelName' (ID '$labelID') to message\n");
				return FALSE;
			}
		} catch (Exception $e) {
			print('Error: Exception - ' . $e->getMessage() . "\n");
			return FALSE;
		}
		return TRUE;
	}


	/**
	 * Delete a label from the account (i.e. all occurrences)
	 * @param string $labelName The label name to delete
	 * @return boolean Returns TRUE on success, else FALSE
	 */
	function deleteLabel($labelName)
	{
		global $ini;

		$labelID = $this->getOrCreateLabelID($labelName);
		try {
			$this->service->users_labels->delete($ini['gmail_username'], $labelID);
			if (DEBUG) {
				print 'Label with id: ' . $labelID . ' successfully deleted.';
			}
		} catch (Exception $e) {
			print 'Error: Exception - ' . $e->getMessage();
			return FALSE;
		}
		return TRUE;
	}

	/**
	 * Function to fix up the timezone in an email date header if necessary.
	 * If we see "UT" at the end, add a "C".
	 * Note: I'm not sure why this is wrong in the Create Centre emails!
	 * @param string $dateString The date string to fix
	 * @return string Fixed date string
	 */
	function fixEmailDateHeaderTZ($dateString) {
		$dateString = rtrim($dateString);
		if (strripos($dateString, 'UT') == strlen($dateString) - 2) {
			$dateString .= "C";
		}
		return $dateString;
	}


	/**
	 * Function to import any new meteorlogical data from the Create Centre
	 * roof which has come in to the associated Gmail account to the given
	 * database.
	 * @param unknown_type $becDB
	 */
	function importNewMeteoData($becDB)
	{
		global $ini;

		// Get a list of email IDs from the Create Centre, subject
		// "Air Quality Update", and not already marked as IMPORTED.
		$createCentreUpdateEmailList = $this->getMessageHandleList(//'from:' . CREATE_CENTRE_EMAIL_ADDR . ', ' .
																	'subject:Air Quality ,' .
																	'!label:IMPORTED');

		if (DEBUG) {
			print('Importing CSV files from ' . count($createCentreUpdateEmailList) . " emails\n");
		}

		// List the matching emails
		foreach ($createCentreUpdateEmailList as $emailHandle)
		{
			$email = $this->service->users_messages->get($ini['gmail_username'], $emailHandle->getId());
			$payload = $email->getPayload();
			$headers = $payload->getHeaders();
			$dateTime;
			foreach ($headers as $header)
			{
				if (0 === strcasecmp('date', $header->getName()))
				{
					$dateTime = new DateTime($this->fixEmailDateHeaderTZ($header->getValue()));
				}
			}
			if (DEBUG) {
				print("  Date: " . $dateTime->format(DateTime::ISO8601) . '\n');
			}
			$msgParts = $payload->getParts();
			if (DEBUG) {
				print("  Parts: " . sizeof($msgParts) . "\n");
			}
			foreach ($msgParts as $msgPart) {
				// We're looking for CSV file attachments
				$outFilename = TEMP_DIR . '/' . $msgPart->getFilename();
				if ($msgPart->getMimeType() === 'application/octet-stream' &&
					FALSE != strripos($outFilename, 'csv')) {
					if (DEBUG) {
						print(    "Filename: $outFilename\n");
						print(    "Data:\n");
					}
					$attachmentID = $msgPart->getBody()->getAttachmentId();
					$data = $this->service->users_messages_attachments->get($ini['gmail_username'], $emailHandle->getId(), $attachmentID)->getData();
					$data = base64_decode($data, TRUE);
					if (DEBUG) {
						print($data . "\n");
					}

					// Add the CSV to the database; save it first
					$outFile = fopen($outFilename, 'w');
					if (FALSE == fwrite($outFile, $data)) {
						print("Error: Failed to write CSV from email to $outFilename - skipping\n");
						fclose($outFile);
						unlink($outFilename);
						continue;
					}
					fclose($outFile);
					// Now import it
					if (FALSE == $becDB->importCreateCentreCSV(BEC_DB_CREATE_CENTRE_RAW_TABLE, $outFilename)) {
						print("Error: Failed to import data from CSV file '$outFilename' - skipping\n");
					}
				}
			}
			// Label the email as imported
			$this->applyLabel($emailHandle->getId(), 'IMPORTED');
		}
	}

}
?>
