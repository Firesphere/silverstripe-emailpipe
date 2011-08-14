<?php
/**
 * Object extensions
 */
Object::add_extension('ForwardedEmail', 'ForwardedEmailDecorator');
Object::add_extension('Member', 'ForwardedEmailMemberRole');


/**
 * Specific handlers
 */
ForwardedEmailHandler::$member_relation_class = 'Member';
ForwardedEmailHandler::$member_relation_search_fields = array('Email');
ForwardedEmailHandler::$email_handler_domains = array('bananabattle.info');
ForwardedEmailHandler::$email_sender_domains = array('bananabattle.info');
