<?php
/**
 * This should match the values specified in ForwardedEmailHandler::$member_relation_name and ForwardedEmailHandler::$member_relation_class.
 * Overload this class to specify your own relation.
 *
 * Apply this decorator in your _config.php:
 * <code>
 * Object::add_extension('ForwardedEmail', 'ForwardedEmailDecorator');
 * </code>
 * 
 * @author Ingo Schommer, SilverStripe Ltd.
 * 
 * @package emailpipe
 */
class ForwardedEmailDecorator extends DataObjectDecorator {
	function extraStatics() {
		return array(
			'many_many' => array(
				'RelatedMembers' => 'Member'
			)
		);
	}
}
?>