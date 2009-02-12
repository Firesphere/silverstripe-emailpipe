<?php
/**
 * @package sscrm
 * @subpackage email
 */
class ForwardedEmail extends DataObject {
	static $db = array(
		'From' => 'Text', // contains original "From" value, please use "Members" relation to determine related members to this email
		'To' => 'Text', // contains original "To" value, please use "Members" relation to determine related members to this email
		'Subject' => 'Text',
		'Body' => 'Text',
		'DateSent' => 'SSDateTime' // a forwarded email might've been sent long before it got forwarded into the system
	);
	
	/**
	 * Assumes a belongs_many_many relationship decorated onto
	 * the object specified in ForwardedEmailHandler::$member_relation_class.
	 */
	static $many_many = array(
		'RelatedMembers' => '' // see __construct()
	);
	
	function __construct($record = null) {
		// workaround for setting the $many_many class from configuration
		self::$many_many['RelatedMembers'] = ForwardedEmailHandler::$member_relation_class;
		
		parent::__construct($record);
	}
}