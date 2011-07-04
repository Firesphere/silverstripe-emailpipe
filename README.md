# Email Pipe Module #

*Caution: This module hasn't been actively used or maintained in a while,
and should be regarded as a starting point rather than a complete solution.*

Emulates [basecamphq.com](http://help.37signals.com/basecamp/questions/198-can-i-email-a-message-to-basecamp)
style email forwarding to store against a member database, typically in a CRM context.

Allows forwarding of emails to a specific handler address on the receiving mailserver,
which then passes the raw MIME content of the email on to the
`IncomingEmailHandler` and `ForwardedEmailHandler` controllers in this module. 

The handlers can look up members by a unique criteria (typically the email address),
and save emails as `ForwardedEmail` objects with a has_many relation to the `Member` record.

TODO Document email pipe setup for various mailservers

# Maintainer Contact
 * Ingo Schommer (Nickname: ischommer) <ingo (at) silverstripe (dot) com>

# Requirements

 * SilverStripe 2.3 (not tested with newer versions)

# Configuration #

	ForwardedEmailHandler::$member_relation_class = 'Member';
	ForwardedEmailHandler::$member_relation_search_fields = array('WorkEmail','HomeEmail');
	ForwardedEmailHandler::$email_handler_domains = array('mydomain.com');
	ForwardedEmailHandler::$email_sender_domains = array('mydomain.com');
	Object::add_extension('ForwardedEmail', 'ForwardedEmailDecorator');
	Object::add_extension('Member', 'ForwardedEmailMemberRole');