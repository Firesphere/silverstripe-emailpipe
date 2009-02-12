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
 * @package sscrm
 * @subpackage email
 */
require_once('../emailpipe/thirdparty/Mail_mimeDecode/Mail_mimeDecode.php');

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
	 * @var string $sender_relation_class A many-many relation class (not the relation name)
	 * present on the {@link ForwardedEmail} class. The relation name is fixed to
	 * 'Senders' (see {@link ForwardedEmail}).
	 */
	static $member_relation_class = 'Member';
	
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
	 */
	function processForwardedClientEmail($email) {
		/*
		// Fix subject, removing unnecessary "re's" 
		$subject = ereg_replace('^ *[Rr][Ee]:? *\[[^\]+\]','Re:', $subject);
		$subject = ereg_replace('^ *[Rr][Ee]:? *[Rr][Ee]:?','Re:', $subject);

		// Remove quoted text from the body
		$bodyLines = explode("\n", $body);
		foreach($bodyLines as $lineNum => $line) {
			if(ereg('^ *>', $line)) {
				unset($bodyLines[$lineNum]);
				if($lineNum > 0 && ereg('wrote: *$', $bodyLines[$lineNum-1])) unset($bodyLines[$lineNum-1]);
				if($lineNum > 1 && ereg('wrote: *$', $bodyLines[$lineNum-2])) unset($bodyLines[$lineNum-2]);
			}
		}
		$body = implode("\n", $bodyLines);
		$body = trim(ereg_replace("\n\n+", "\n\n", $body));
		*/
		
		// @todo Authentication token checking on "to" field
		
		$forwardedEmail = self::parseForwardedEmailBody($email);
		
		$emailClass = self::$email_class;
		$emailObj = new $emailClass();
		foreach($forwardedEmail->headers['from'] as $fromAddressWithName) {
			$SQL_fromAddress = Convert::raw2sql(self::get_address_for_emailpart($fromAddressWithName));
			$member = DataObject::get_one(self::$member_relation_class, sprintf('"Email" = \'%s\'', $SQL_fromAddress));
			if($member) $emailObj->RelatedMembers()->add($member);
		}
		$emailObj->From = $email->headers['from'];
		$emailObj->To = $forwardedEmail->headers['from'];
		$emailObj->Subject = $forwardedEmail->headers['subject'];
		$emailObj->Body = $forwardedEmail->headers['body'];
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
	 * @return string
	 */
	function parseForwardedEmailBody($emailBody) {
		return $emailBody;
	}
	
	/**
	 * Decodes an email address, returning "sam@silverstripe.com" when given "Sam Minnee <sam@silverstripe.com>".
	 * 
	 * @param string $emailStr
	 * @return string
	 */
    static function get_address_for_emailpart($emailStr) {
       if(strpos($emailA, '<') === false) return $emailStr;
       else {
           ereg('<([^>]+)>|$', $email, $parts);
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
	
}

?>