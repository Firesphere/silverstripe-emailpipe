<?php

require_once('../emailpipe/thirdparty/Mail_mimeDecode/Mail_mimeDecode.php');

/**
 * @package emailpipe
 */
class ForwardedMail_mimeDecode extends Mail_mimeDecode {
	
	/**
	 * Replace content before mail headers, depending on mail client
	 * this might be one of the following:
	 * - On <date>, <author> wrote:
	 * - Begin forwarded message
	 * - ------------ Forwarded Message --------------
	 * - -------- Original Message --------
	 */
	function _splitBodyHeader($input) {
		
		if (preg_match("/^(.*?)\r?\n\r?\n(.*)/s", $input, $match)) {
			return array($match[1], $match[2]);
		}
		$this->_error = 'Could not split header and body';
		return false;
	}

}
?>