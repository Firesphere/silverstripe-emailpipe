<?php
/**
 * Scenario 1: Forwarded Client Email
 * - Store "Forwarded from" as contact (using inline "From:" text)
 * - Use "From" as CRM author
 * - Use forwarded inline or attachement, and discard the body
 * 
 * Scenario 2: BCCing in Email to Client
 * - Store "To" as contact
 * - Store "From" as CRM author
 * - Store body without any replies or inline attachements
 *
 * Its currently not possible to forward messages sent TO a client,
 * just emails FROM a client.
 * 
 * @author Ingo Schommer, SilverStripe Ltd.
 * 
 * @todo Detect duplicate messages based on Message-Id MIME header
 * @todo Save sent date
 * 
 * @package emailpipe
 */
require_once('../emailpipe/thirdparty/Mail_mimeDecode/Mail_mimeDecode.php');

class ForwardedEmailHandler extends Controller {
	
	protected $_errors = array();
	
	/**
	 * Domains which are valid email handlers for the system.
	 * These domains should have pipes, aliases or forwards
	 * to the forwarder.php script set up in your MTA.
	 * Format: '@mydomain.com' (including '@' sign)
	 * 
	 * @var string $email_handler_domain
	 */
	static $email_handler_domains = array();
	
	/**
	 * @var string $email_sender_domains
	 */
	static $email_sender_domains = array();
	
	/**
	 * @var string $email_class Subclass of ForwardedEmail.
	 */
	static $email_class = 'ForwardedEmail';
	
	/**
	 * @var string $member_relation_name
	 */
	static $member_relation_name = 'RelatedMembers';
	
	/**
	 * @var string $sender_relation_class A many-many relation class (not the relation name)
	 * present on the {@link ForwardedEmail} class. The relation name is fixed to
	 * 'Senders' (see {@link ForwardedEmail}).
	 */
	static $member_relation_class = 'Member';
	
	/**
	 * @var array $member_relation_search_fields Compares incoming email adresses to these fields
	 * in order to determine on which Members the email should be attached.
	 */
	static $member_relation_search_fields = array('Email');
	
	function index() {
		if(!isset($_REQUEST['Message'])) user_error('No Message given', E_USER_ERROR);
		
		// Process the mail, breaking down its multiple
		$decoder = new Mail_mimeDecode($_REQUEST['Message']);
		
		$email = $decoder->decode(array('include_bodies'=>true,'decode_bodies'=>true));
		
		if(!$this->isValidEmail($email)) {
			if(isset($email->headers['from'])) {
				$this->friendlyError($email->headers['from'], $email, "Can't parse email content");
			}
			user_error("ERROR: Invalid email: " . var_export($this->_errors, true) . "\n" . var_export($email, true), E_USER_ERROR);
			return false;
		}

		// CAUTION: you can't use BCC saving if the 'to' recipient is in the valid
		// email handlers as well (usually internal company communications with TO and BCC on the same domain)
		if(isset($email->headers['to']) && $this->isValidEmailHandlerDomain($email->headers['to'])) {
			// Scenario 1: Forwarded Client Email
			$this->processForwardedClientEmail($email);
		} elseif(isset($email->headers['bcc']) && $this->isValidEmailHandlerDomain($email->headers['bcc'])) {
			// Scenario 2: BCCing in Email to Client
			$this->processBccClientEmail($email);
		} elseif(isset($email->headers['cc']) && $this->isValidEmailHandlerDomain($email->headers['cc'])) {
			// Scenario 2: BCCing in Email to Client
			$this->processBccClientEmail($email);
		} else {
			if(isset($email->headers['from'])) {
				$errorMessage = "No valid email address found in 'To' or 'Bcc' headers.\n";
				if(isset($email->headers['to'])) $errorMessage .= "(To: " . $email->headers['to'] . ") \n";
				if(isset($email->headers['cc'])) $errorMessage .= "(Cc: " . $email->headers['cc'] . ") \n";
				if(isset($email->headers['bcc'])) $errorMessage .= "(Bcc: " . $email->headers['bcc'] . ") \n";
				$errorMessage .= "Valid handler domains: " . implode(',', self::$email_handler_domains) . "\n";
				$errorMessage .= "\n\n" . var_export($email->headers, true);
				$this->friendlyError(
					$email->headers['from'], 
					$email, 
					$errorMessage
				);
			}
			user_error("ERROR: No valid handler found for email: " . var_export($email, true), E_USER_ERROR);
			return false;
		}
		
		echo "OK";
	}

	/**
	 * 	Scenario 1: Forwarded Client Email
	 * - Store "Forwarded from" as contact (using inline "From:" text)
	 * - Use "From" as CRM user
	 * - Use forwarded inline or attachement, and discard the body
	 * 
	 * @todo Authentication token checking on "to" field
	 * 
	 * @param Object $email Object based on Mail_mimeDecode
	 * @return ForwardedEmail
	 */
	protected function processForwardedClientEmail($email) {
		$forwardedEmail = null;
		$forwardedEmailHeaders = null;
		$forwardedEmailBody = null;
		
		// determine if email is forwarded as an attachement or inline in the normal message body with ">" brackets
		// Forwarded attachement emails usually have the following MIME block structure:
		// Content-Type: multipart/mixed;
		//   Content-Type: text/plain; (annotations for the forwarded message by sender, currently discarded)
		//   Content-Type: message/rfc822; (the actual attachement)
		//     Content-Type: multipart/alternative; (all parts of the attachement, including relevant headers)
		//       Content-Type: text/plain; (the *actual* body, but without headers)
		if($forwardedAttachmentPart = self::get_mimepart_by_type($email, 'message', 'rfc822')) {
			if($forwardedMultipartPart = self::get_mimepart_by_type($forwardedAttachmentPart, 'multipart', 'alternative')) {
				// if the attachement is multipart, take the body from its first text/plain inner part,
				// but the header from the multipart "parent"
				if($forwardedInnerMultipartPart = self::get_mimepart_by_type($forwardedMultipartPart, 'multipart', 'alternative')) {
					$forwardedInnerTextPart = self::get_mimepart_by_type($forwardedInnerMultipartPart, 'text', 'plain');
					$forwardedEmailBody = $forwardedInnerTextPart->body;
					// should NOT be $forwardedInnerTextPart - the header info is contained in parent part
					$forwardedEmailHeaders = $forwardedMultipartPart->headers;
				} else {
					$forwardedInnerTextPart = self::get_mimepart_by_type($forwardedMultipartPart, 'text', 'plain');
					$forwardedEmailBody = $forwardedInnerTextPart->body;
					$forwardedEmailHeaders = $forwardedMultipartPart->headers;
				}
			} else {
				$forwardedTextPart = self::get_mimepart_by_type($forwardedAttachmentPart, 'text', 'plain');
				$forwardedEmailBody = $forwardedTextPart->body;
				$forwardedEmailHeaders = $forwardedTextPart->headers;
			}
		} else {
			// fallback: get the full email body: original message including any forwarded parts
			$plaintextPart = self::get_mimepart_by_type($email, 'text', 'plain');
			$forwardedEmail = self::parseForwardedEmailBody($plaintextPart);
			$forwardedEmailBody = $forwardedEmail->body;
			$forwardedEmailHeaders = $forwardedEmail->headers;
		}

		// remove 'Fwd:' in subject
		$forwardedEmailSubject = (isset($forwardedEmailHeaders['subject'])) ? self::remove_forward_prefix_from_subject($forwardedEmailHeaders['subject']) : '';

		$emailClass = self::$email_class;
		$emailObj = new $emailClass();
		$emailObj->write();
		
		if(!isset($forwardedEmailHeaders['from'])) return false;
		
		// add members for all matched criteria
		$SQL_fromAddress = Convert::raw2sql(self::get_address_for_emailpart($forwardedEmailHeaders['from']));
		$hasFoundMember = false;
		foreach(self::$member_relation_search_fields as $fieldName) {
			$member = DataObject::get_one(self::$member_relation_class, sprintf('"%s" = \'%s\'', $fieldName, $SQL_fromAddress));
			$relationName = self::$member_relation_name;
			if($member) {
				$hasFoundMember = true;
				$emailObj->$relationName()->add($member);
			}
		}
		
		if(!$hasFoundMember) {
			user_error("Couldnt find matching member for {$SQL_fromAddress}", E_USER_ERROR);
		}
		
		// write new email
		$emailObj->From = $SQL_fromAddress;
		$emailObj->Subject = $forwardedEmailSubject;
		$emailObj->Body = utf8_encode($forwardedEmailBody);
		if(isset($forwardedEmailHeaders['message-id'])) $emailObj->MessageId = $forwardedEmailHeaders['message-id'];
		$emailObj->write();
	}
	
	/**
	 *	Scenario 2: BCCing in Email to Client
	 * - Store "To" as contact
	 * - Store "From" as CRM author
	 * - Store body without any replies or inline attachements
	 * 
	 * @param Object $email Object based on Mail_mimeDecode
	 */
	protected function processBccClientEmail($email) {
		// check if the sender is allowed to forward emails in the first place
		if(!$this->isValidEmailSender(self::get_address_for_emailpart($email->headers['from']))) {
			$errorMsg = "Invalid sender address. Allowed domains: " . implode(',', self::$email_sender_domains);
			$this->friendlyError($email->headers['from'], $email, $errorMsg);
			user_error($errorMsg, E_USER_ERROR);
			return false;
		}
		
		// simpler than forwarded messages: just get the email body and store it
		$plaintextPart = self::get_mimepart_by_type($email, 'text', 'plain');
		
		$emailClass = self::$email_class;
		$emailObj = new $emailClass();
		$emailObj->write();
		
		// add members for all matched criteria
		$SQL_fromAddress = Convert::raw2sql(self::get_address_for_emailpart($email->headers['to']));
		$hasFoundMember = false;
		foreach(self::$member_relation_search_fields as $fieldName) {
			$member = DataObject::get_one(self::$member_relation_class, sprintf('"%s" = \'%s\'', $fieldName, $SQL_fromAddress));
			$relationName = self::$member_relation_name;
			if($member) {
				$hasFoundMember = true;
				$emailObj->$relationName()->add($member);
			}
		}

		if(!$hasFoundMember) {
			$errorMsg = "Couldnt find matching member for {$SQL_fromAddress}";
			if(isset($email->headers['from'])) {
				$this->friendlyError($email->headers['from'], $email, $errorMsg);
			}
			user_error($errorMsg, E_USER_ERROR);
			return false;
		}

		$emailObj->From = self::get_address_for_emailpart($email->headers['from']);
		$emailObj->Subject = (isset($email->headers['subject'])) ? $email->headers['subject'] : '';
		$emailObj->Body = utf8_encode($plaintextPart->body);
		if(isset($plaintextPart->headers['message-id'])) $emailObj->MessageId = $plaintextPart->headers['message-id'];
		$emailObj->write();
	}
	
	/**
	 * Parses the forwarded original email out of a
	 * plaintext MIME part email body.
	 * 
	 * @param string $emailBody
	 * @return Object
	 */
	protected function parseForwardedEmailBody($email) {
		$emailBody = $email->body;
		
		// if the original body contains quoted text, remove all unquoted text *after* the last quoted text.
		// this limitation is necessary to avoid removing valid MIME header parts *before any quoted text
		if(preg_match_all('/^\>.*\n/m', $emailBody, $matches, PREG_OFFSET_CAPTURE)) {
			// find the last occurrence of '>' and remove all text after it (e.g. automatically added email footers)
			$lastQuotedLineMatch = $matches[0][count($matches[0])-1];
			$lastQuotedLineEndPos = $lastQuotedLineMatch[1] + strlen($lastQuotedLineMatch[0]);
			$emailBody = substr($emailBody, 0, $lastQuotedLineEndPos);
		}
		
		// reduce quote level (leading '>' character) to make header parsing work in Mail_mimeDecoder
		$emailBody = trim(self::reduce_quote_level($emailBody));
		
		// Split original content from forwarded ones
		// @todo Fragile detection, as it leaves out other headers
		$headers = array('From:', 'To:', 'Subject:');
		$posFirstHeader = null;
		foreach($headers as $header) {
			$posHeader = strpos($emailBody, $header);
			if($posHeader !== FALSE && ($posHeader < $posFirstHeader || !$posFirstHeader)) {
				$posFirstHeader = $posHeader;
			}
		}
		$originalEmailBody = substr($emailBody, 0, $posFirstHeader-1);
		$forwardedEmailBody = substr($emailBody, $posFirstHeader, strlen($emailBody));

		// Make sure there's a carriage return after the header - otherwise the email is not valid MIME format
		// Ignore pseudo-headers from content onwards ($headerFinished), we had problems with footers like
		// "Skype: chillu23, Address: ..."
		$validForwardedEmailBody = '';
		$headerFinished = false;
		$insertedNewlines = false;
		foreach(preg_split('/\n/', $forwardedEmailBody) as $line) {
			// match header rows like "From: ingo@silverstripe.com"
			if(!preg_match('/^\s*[a-zA-Z\-]*\:/', $line)) $headerFinished = true;
			
			if($headerFinished && !$insertedNewlines) {
				// add encoding from parent container - otherwise 'quoted-printable' will be interpreted as '7bit'
				if(isset($email->headers['content-type'])) {
					$validForwardedEmailBody .= "Content-Type: " . $email->headers['content-type'] . "\n";
				}
				if(isset($email->headers['content-transfer-encoding'])) {
					$validForwardedEmailBody .= "Content-Transfer-Encoding: " . $email->headers['content-transfer-encoding'] . "\n";
				}
				
				// insert newlines *before* the content (previous match was last header row)
				$validForwardedEmailBody .= "\r\n";
				$insertedNewlines = true;
			}
			
			$validForwardedEmailBody .= $line . "\n";
		}
		
		$forwardedEmailBody = $validForwardedEmailBody;

		$decoder = new Mail_mimeDecode($forwardedEmailBody);
		$forwardedEmail = $decoder->decode(array('include_bodies'=>true,'decode_bodies'=>true));

		return $forwardedEmail;
	}
	
	/**
	 * Send a friendly error to the original sender of a faulty email,
	 * including the original email and an error message.
	 * 
	 * @param string $to
	 * @param Object $email
	 * @param string $message
	 */
	protected function friendlyError($to, $email, $message) {
		$toAddress = self::get_address_for_emailpart($to);
		$plaintextPart = self::get_mimepart_by_type($email, 'text', 'plain');
		$subject = "Can't process email '" . $email->headers['subject'] . "'";
		$body = $message . "\n";
		$body .= "\n-------------------- Original Email ---------------------\n";
		$body .= ($plaintextPart) ? $plaintextPart->body : var_export($email, true);
		$emailObj = new Email(Email::getAdminEmail(), $toAddress, $subject, $body);
		$emailObj->sendPlain();
	}
	
	/**
	 * Decodes an email address, returning "sam@silverstripe.com" when given "Sam Minnee <sam@silverstripe.com>".
	 * 
	 * @param string $emailStr
	 * @return string
	 */
    static function get_address_for_emailpart($emailAdress) {
		$emailAdress = trim($emailAdress);
		
		if(strpos($emailAdress, '<') === false) {
			return $emailAdress;
		} else {
			ereg('<([^>]+)>|$', $emailAdress, $parts);
			return $parts[1];
		}
	}

	/**
	 * Checks if the email address is a valid "handler",
	 * e.g. should be discarded as an unauthenticated email 
	 * by an unrelated party to avoid spamming.
	 * Also used to determine the "scenario" in which an email
	 * was sent (forward from client, of bcc to client).
	 * 
	 * @param string
	 * @return boolean
	 */
	protected function isValidEmailHandlerDomain($emailAddress) {
		foreach(self::$email_handler_domains as $domain) {
			if(stripos($emailAddress, $domain) !== FALSE) return true;
		}
		return false;
	}
	
	/**
	 * Determines if a sender is allowed to forward or CC an email.
	 * Should be usually restricted to the users of your application - currently
	 * via domain matching only.
	 * 
	 * @param string
	 * @return boolean
	 */
	protected function isValidEmailSender($emailAddress) {
		foreach(self::$email_sender_domains as $domain) {
			if(stripos($emailAddress, $domain) !== FALSE) return true;
		}
		return false;
	}
	
	/**
	 * We require a plaintext body in the email.
	 * HTML-only emails can't be parsed.
	 * 
	 * @todo Accept HTML-only emails (use Convert::html2raw())
	 * 
	 * @param Object $email Object based on Mail_mimeDecode
	 * @return boolean
	 */
	protected function isValidEmail($email) {
		foreach(array('from','to','subject') as $header) {
			if(!isset($email->headers[$header])) {
				$this->_errors[] = 'Insufficient headers: "' . $header . '" missing';
				//user_error('Insufficient headers: "' . $header . '" missing', E_USER_WARNING);
				return false;
			}
		}
			
		// Ignore delivery failure notifications
		if(stripos($email->headers['subject'], "mail delivery failed") !== false) {
			return false;
		}
		
		$textPart = self::get_mimepart_by_type($email, 'text', 'plain');
		if(!$textPart || !$textPart->body) {
			$this->_errors[] = 'No Plaintext MIME part found';
			return false;
		}
		
		return ($textPart);
	}
	
	/**
	 * @param Object $email Object based on Mail_mimeDecode
	 * @param string $primaryType E.g. "text"
	 * @param string $secondaryType E.g. "plain"
	 * @return Object
	 */
	protected function get_mimepart_by_type($email, $primaryType, $secondaryType) {
		if(!isset($email->parts)) return false;
		foreach($email->parts as $part) {
			if($part->ctype_primary == $primaryType && $part->ctype_secondary == $secondaryType) {
				return $part;
			}
		}
		return false;
	}

	/**
	 * Reduce quoting through ">" character in a body of plaintext email content.
	 * 
	 * @return string
	 */
	static function reduce_quote_level($str, $levels = 1) {
		$replace = str_repeat('>', $levels);
		return preg_replace('/^' . $replace . '\s*/m', '', $str);
	}
	
	/**
	 * Remove all "unquoted" text from an email body, identified
	 * by a lack of ">" characters at the beginning of the line.
	 * 
	 * @param string $str
	 * @return string
	 */
	static function remove_unquoted_body($str) {
		return preg_replace('/^[^>]\s*/m', '', $str);
	}
	
	/**
	 * Removes "Fwd:" and "[Fwd:]" prefixes from email subjects
	 * 
	 * @param string $subject
	 * @return string
	 */
	static function remove_forward_prefix_from_subject($subject) {
		// Case '[Fwd: Subject]
		if(preg_match('/^\s*\[Fwd\:\s*(.*)\]/', $subject, $matches)) {
			return $matches[1];
		}
		
		// Case 'Fwd:
		if(preg_match('/^\s*Fwd\:\s*(.*)/', $subject, $matches)) {
			return $matches[1];
		}
		
		return $subject;
	}
}

?>