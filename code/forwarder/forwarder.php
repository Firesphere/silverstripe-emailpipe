#!/usr/bin/php5 -q
<?php
/**
 * Place this in your mailserver and point the pipe to it.
 * 
 * Example for Exim (in /etc/exim/domain_forwarder/<yourdomain>/<yourscriptname>):
 * <code>
 * pipe "/<local-absolute-path-to-script>/forwarder.php http://<yourwebsite>/ForwardedEmailHandler <admin-email>"
 * </code>
 * Change <admin-email> to an email address which should receive errors caused by this script.
 * 
 * You can test the script run with the following procedure:
 * <code>
 * cd /<your-webroot>/emailpipe/code/forwarder
 * cat tests/MultipartForwardFromClient.eml | php forwarder.php http://<yourwebsite>/ForwardedEmailHandler <admin-email>
 * </code>
 * This assumes that the email-addresses mentioned in the *.eml file are accepted handlers in your application,
 * and that you're allowed to send emails from the environment which this command is invoked on.
 * 
 * @package emailpipe
 */
$url = $_SERVER['argv'][1];
$errorEmail = $_SERVER['argv'][2];
$debug = (isset($_SERVER['argv'][3]) && $_SERVER['argv'][3]);

$errors = array();

if($url) {
	$emailData = file_get_contents("php://stdin");
	if(!sendPostRequest($url, "Message=" . urlencode($emailData))) {
		if($debug) {
			echo "Errors:\n\n" . var_export($errors, true) . "\n\nMessage:\n" . $emailData;
		} else {
			mail(
				$errorEmail, 
				sprintf("ERROR: Couldn't post to URL $url in %s", $_SERVER['PHP_SELF']),
				"Errors:\n\n" . var_export($errors, true) . "\n\nMessage:\n" . $emailData
			);
		}
	}

} else {
	mail($errorEmail, sprintf("No URL given to %s", $_SERVER['PHP_SELF']),"");
}



/**
 * Post the given data to the given URL
 * The data must already be encoded into a string
  */
function sendPostRequest($url, $data) {
	global $errors;
	global $errorEmail;
	
	$urlParts = parse_url($url);

        if(!$urlParts) {
		$errors[] = 'Cant parse URL: ' . $url;
		return false;
	}
	
	if(!isset($urlParts['port'])) $urlParts['port'] = 80;

	$fp = fsockopen($urlParts['host'], $urlParts['port'], $errno, $error );
	if(!$fp) {
		$errors[] = sprintf("Can't open socket on host '%s' with port %d: %s", $urlParts['host'], $urlParts['port'], $error);
		return false;
	}

	$length = strlen( $data );

	$send =  "POST $urlParts[path] HTTP/1.1\r\n";
	$send .= "Host: $urlParts[host]\r\n";
	$send .= "User-Agent: SilverStripe Incoming Email Handler\r\n";
	$send .= "Connection: Close\r\n";
	$send .= "Content-Type: application/x-www-form-urlencoded\r\n";
	$send .= "Content-Length: $length\r\n\r\n";

	$send .= "$data\r\n";

	$response = '';

	fwrite($fp, $send);

	while (!feof($fp)) {
		$response .= fgets($fp, 128);
	}
	fclose($fp);
	
        // check for a 2xx response and the string OK
	if(ereg("^HTTP/1.1 2", $response) && strpos($response, "OK") !== false) {
		return true;
	} else {
		$errors[] = "ERROR: Email forward failed in {$url}. {$response}";
		return false;
	}
}
?>