<?php

/**
 * Generic incoming email handler.
 *
 * Sub-class and define processEmail() to set up different email handlers for your sites.
 * 
 * Then point incomingEmailHandler.php at this file.  This is a script stored in P:\kristovScripts, and is designed to be
 * fed by an exim pipe command.  It takes 1 argument, which is the URL of the handler class, for example,
 * http://test.silverstripe.com/kcommunity/ForumEmailHandler
 * 
 * The mailserver-side script will take the raw content of the email and post it to the handler as $_POST['Message'].
 * IncomingEmailHandler then breaks the message down, finds the plain text body, and posts it to processEmail.
 *
 * @todo Currently $attachments and $headers aren't working.
 */
 
abstract class IncomingEmailHandler extends Controller {
    abstract function processEmail($from, $to, $subject, $body, $attachments, $headers);

	function index() {
	    // Process the mail, breaking down its multiple
	    $message = $_REQUEST['Message'];
	    $parts = array();
	    $this->decodeMultipart($message, $parts);
	    
	    if($parts[0]) {
	        $mainPart = $parts[0];
                $attachment = array();
	        foreach($parts as $part) {
                    /**
                     * Handling for just 1 attachment! Multiple attachments require some testing and extensive coding.
                     */
                    if((isset($part['content-disposition'])) && (strpos($part['content-disposition'], 'attachment') !== false) && (!count($attachment)) ) {
                        $attachment = $this->AttachmentHandler($part);
                    }
                    /**
                     * Don't break anymore, since we could have other things, like attachments.
                     * Just check if the textPart isn't set yet.
                     */
	            if(strpos($part['content-type'], 'text/plain') !== false && !isset($textPart)) {
	                $textPart = $part;
//	                break;
	            }
	        }
	    
	    } else {
	        $mainPart = $textPart = $parts;
	    }
	    
	    $to = $mainPart['to'];
	    $from = $mainPart['from'];
	    $subject = $mainPart['subject'];
	    $body = $textPart['body'];

            $this->processEmail($from, $to, $subject, $body, $attachment, $mainPart);
	    
	    echo "OK";
	}
	
	/*
     * Breaks a nested multipart e-mail down into each component
     * Some components will be sub-components of other ones
     * It will ignore the effects of rfc822 email files
     */
    function decodeMultipart($emailData, &$emailParts, $limitToContentType = null, $indent = "") {
    	// Get the outermost e-mail
    	$email = $this->readEmail($emailData);

    	// If we have a .eml attachment just get the contents of the attachment
    	if(isset($email['content-type']) && substr($email['content-type'],0,14) == "message/rfc822") {
    		$email = $this->readEmail($email[body]);
    	}


    	if(!$limitToContentType || !$email['content-type'] || (substr(strtolower($email['content-type']),0,strlen($limitToContentType)) == $limitToContentType)) {
    		$emailParts[] = $email;
    	}



    	// Do we have multiparts?
    	if(substr($email['content-type'],0,10) == "multipart/" && (ereg('boundary="([^"]+)"', $email['content-type'], $parts) || ereg('boundary=([^ ]+)', $email['content-type'], $parts))) {
    		$boundary = $parts[1];

    		$multiparts = explode($boundary, $email['body']);
    		// Remove the first part - it's junk anyway
    		array_shift($multiparts);

    		// Recursively add to $emailParts
    		foreach($multiparts as $multipart) {
    			// -- signals the end of the parts
    			if(trim($multipart) == "--") break;

    			// $multipart is another valid email; they can be nested indefinitely
    			$this->decodeMultipart($multipart, $emailParts, $limitToContentType, "$indent  ");

    		}
    	}
    }
    
    /*
     * Splits an email into headers and body.  Returns a map, keyed by lowercase header names
     *  - if there are more than one of the same header, each value is given in an array
     *  - multiline headers a handled okay, output header is a single line however
     *  - the body is given as the 'body' element of the map
     *  - content-transfer-encoding is recognised, and content is decoded
     */
    function readEmail($emailData) {
    	// Remove leading whitespace
    	$emailData = ereg_replace("^[\t\r\n ]+", "", $emailData);
        $email = array();
    	list($headers, $email['body']) = split("(\n\r?){2}", $emailData, 2);
    	$headers = split("(\n\r?)", trim($headers));

    	foreach($headers as $header) {
    		// Normal header
    		if(ereg('^([^ ]+ *):(.*)', $header, $parts)) {
    			$headerName = strtolower(trim($parts[1]));
    			$headerValue = trim($parts[2]);

    			// There are more than one of these headers already; append to the array
    			if(isset($email[$headerName]) && is_array($email[$headerName])) {
    				$email[$headerName][] = $headerValue;
    				// Keep a track of the most recent header so multiline headers can be appended
    				$mostRecentHeader = &$email[$headerName][sizeof($email[$headerName])-1];

    			// Second header added with this name; we must turn a string header into an array
    			} elseif(isset($email[$headerName])) {
    				$email[$headerName] = array(
    					$email[$headerName], 
    					$headerValue
    				);
    				$mostRecentHeader = &$email[$headerName][1];

    			// Normal Case
    			} else {
    				$email[$headerName] = $headerValue;
    				$mostRecentHeader = &$email[$headerName];
    			}


    		// Second line of a multiline header
    		} else if(isset($mostRecentHeader) && $header[0] =="\t" || $header[0] == " ") {
    			$mostRecentHeader .= ' ' . trim($header);
    		}
    	}

    	// Handle content transfer encoding
        if(isset($email['content-transfer-encoding'])) {
            switch(strtolower($email['content-transfer-encoding'])) {
                    case 'quoted-printable':
                            $email['body'] = preg_replace('/=([A-Za-z0-9]{2})/e', 'chr(hexdec("$1"))', $email['body']);
                            break;
            }
        }

    	return $email;
    }
    
    /**
     * Decodes an email address, returning "sam@silverstripe.com" when given "Sam Minnee <sam@silverstripe.com>"
     */
    function decodeEmail($email) {
       if(strpos($email, '<') === false) return $email;
       else {
           ereg('<([^>]+)>|$', $email, $parts);
           return $parts[1];
        }
    }
    
    /**
     * Handling of attachments. Files will be written to /tmp and can be fetched in the user's sub-class.
     * @param type $part Part of the e-mail that has a content-disposition
     * @return type array
     */
    function AttachmentHandler($part){
        // Get the filename. Shouldn't this be a regex?
        $filepart = $part['content-disposition'];
        $filename = substr($filepart, (strpos($filepart, '=') + 1));
        $filename = str_replace('"', '', $filename);

        // Open the file (hopefully), as writable.
        if($fp = fopen('/tmp/mailattachments/' . $filename, 'w')){
            // write body, decode it according to MIME-to-File base64
            fwrite($fp, base64_decode($part['body']));
            // close file and feel good about yourself
            fclose($fp);
            
            // Write the data to the return-array.
            $type = explode($part['content-type'], ';');
            $attachment['status'] = true;
            $attachment['location'] = '/tmp/mailattachments' . $filename;
            $attachment['type'] = $type[0];
            $attachment['name'] = $filename;
        }
        else{
            //Ouch, something went terribly wrong. Status is false!
            $attachment['status'] = false;
        }
        return $attachment;
    }
    
}