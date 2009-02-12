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
 * 
 * @package emailpipe
 */
require_once('../emailpipe/thirdparty/Mail_mimeDecode/Mail_mimeDecode.php');
require_once('../emailpipe/code/ForwardedMail_mimeDecode.php');

class ForwardedEmailHandler extends Controller {
	
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
		// Process the mail, breaking down its multiple
		$decoder = new Mail_mimeDecode($_REQUEST['Message']);
		
		$email = $decoder->decode(array('include_bodies'=>true,'decode_bodies'=>true));
		
		if(!self::is_valid_email($email)) return false;
		
		if(self::is_valid_email_handler_domain($email->headers['to'])) {
			// Scenario 1: Forwarded Client Email
			$this->processForwardedClientEmail($email);
		} elseif(self::is_valid_email_handler_domain($email->headers['bcc'])) {
			// Scenario 2: BCCing in Email to Client
			$this->processBccClientEmail($email);
		} else {
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
	 * @param Object $email Object based on Mail_mimeDecode
	 * @return ForwardedEmail
	 */
	function processForwardedClientEmail($email) {
		/*
		// Fix subject, removing unnecessary "re's" 
		$subject = ereg_replace('^ *[Rr][Ee]:? *\[[^\]+\]','Re:', $subject);
		$subject = ereg_replace('^ *[Rr][Ee]:? *[Rr][Ee]:?','Re:', $subject);
		*/
		
		// @todo Authentication token checking on "to" field
		
		$plaintextPart = self::get_mimepart_by_type($email);
		$forwardedEmail = self::parseForwardedEmailBody($plaintextPart->body);
		$forwardedEmailBody = trim(self::reduce_quote_level($forwardedEmail->body));
		$forwardedEmailSubject = self::remove_forward_prefix_from_subject($forwardedEmail->headers['subject']);

		$emailClass = self::$email_class;
		$emailObj = new $emailClass();
		$emailObj->write();
		
		// add members for all matched criteria
		$SQL_fromAddress = Convert::raw2sql(self::get_address_for_emailpart($forwardedEmail->headers['from']));
		foreach(self::$member_relation_search_fields as $fieldName) {
			$member = DataObject::get_one(self::$member_relation_class, sprintf('"%s" = \'%s\'', $fieldName, $SQL_fromAddress));
			$relationName = self::$member_relation_name;
			$emailObj->$relationName()->add($member);
		}
		
		$emailObj->From = $SQL_fromAddress;
		$emailObj->Subject = $forwardedEmailSubject;
		$emailObj->Body = $forwardedEmailBody;
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
	function processBccClientEmail($email) {
		
	}
	
	/**
	 * Parses the forwarded original email out of a
	 * plaintext MIME part email body.
	 * 
	 * @param string $emailBody
	 * @return Object
	 */
	function parseForwardedEmailBody($emailBody) {
		// Split original content from forwarded ones
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

		$decoder = new Mail_mimeDecode($forwardedEmailBody);
		return $decoder->decode(array('include_bodies'=>true,'decode_bodies'=>true));
	}
	
	/**
	 * Decodes an email address, returning "sam@silverstripe.com" when given "Sam Minnee <sam@silverstripe.com>".
	 * 
	 * @param string $emailStr
	 * @return string
	 */
    static function get_address_for_emailpart($emailAdress) {
       if(strpos($emailAdress, '<') === false) return $emailAdress;
       else {
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
	static function is_valid_email_handler_domain($emailAddress) {
		foreach(self::$email_handler_domains as $domain) {
			if(strpos($emailAddress, $emailAddress) !== FALSE) return true;
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
	static function is_valid_email($email) {
		// Ignore delivery failure notifications
		if(strpos(strtolower($email->headers['subject']), "mail delivery failed") !== false) return false;
		
		$textPart = self::get_mimepart_by_type($email, 'text', 'plain');
		
		return ($textPart);
	}
	
	/**
	 * @param Object $email Object based on Mail_mimeDecode
	 * @param string $primaryType
	 * @param string $secondaryType
	 * @return Object
	 */
	static function get_mimepart_by_type($email, $primaryType = 'text', $secondaryType = 'plain') {
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
		$replace = '>' * $levels;
		return preg_replace('/^' . $replace . '/', '', $str);
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