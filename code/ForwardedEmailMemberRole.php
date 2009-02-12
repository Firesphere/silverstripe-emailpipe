<?php
/**
 * This should match the values specified in ForwardedEmailHandler::$email_class.
 * Overload this class to specify your own relation.
 * 
 * Apply this decorator in your _config.php:
 * <code>
 * Object::add_extension('ForwardedEmailMemberRole', 'Member');
 * </code>
 * 
 * @author Ingo Schommer, SilverStripe Ltd.
 * 
 * @package emailpipe
 */
class ForwardedEmailMemberRole extends DataObjectDecorator {
	function extraStatics() {
		return array(
			'belongs_many_many' => array(
				'ForwardedEmails' => 'ForwardedEmail'
			)
		);
	}
}
?>