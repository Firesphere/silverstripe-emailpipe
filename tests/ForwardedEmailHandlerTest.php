<?php
/**
 * @package emailpipe
 */
class ForwardedEmailHandlerTest extends SapphireTest {
	
	static $fixture_file = 'emailpipe/tests/ForwardedEmailHandlerTest.yml';

	function setUp() {
		ForwardedEmailHandler::$member_relation_class = 'Member';
		ForwardedEmailHandler::$member_relation_name = 'TestRelatedMembers';
		ForwardedEmailHandler::$member_relation_search_fields = array('Email');
		ForwardedEmailHandler::$email_handler_domains = array('silverstripe.com');
		
		self::kill_temp_db();
		$dbname = self::create_temp_db();
		DB::set_alternative_database_name($dbname);
		
		parent::setUp();
	}
	
	function tearDown() {		
		self::kill_temp_db();
		self::create_temp_db();
		
		parent::tearDown();
	}

	function testMultipartInlineForwardFromClient() {
		$message = file_get_contents(Director::baseFolder() . '/emailpipe/tests/MultipartInlineForwardFromClient.eml');
		
		$_REQUEST['Message'] = $message;
		
		$handler = new ForwardedEmailHandler();
		$handler->index();
		
		$newEmail = DataObject::get_one('ForwardedEmail', null, false, "Created DESC");
		$this->assertNotNull($newEmail);
		$this->assertEquals($newEmail->From, 'work@clientcompany.com');
		$this->assertEquals($newEmail->Subject, 'Multipart Inline Forward', "Subject doesn't contain 'Fwd:'");
		$this->assertContains("Please send me a proposal!", $newEmail->Body, "Quoted text is retained in frowarded emails");
		$this->assertContains("Here's a link: http://test.com", $newEmail->Body, "Header-like characters with colons are not removed");
		$this->assertContains("> Should I send you a proposal?", $newEmail->Body, "Inline second level quotes are retained");
		$this->assertNotContains("Skype:", $newEmail->Body, "Unquoted text is stripped from forwarded emails");
		
		$member = $this->objFromFixture('Member', 'johnclient');
		$this->assertEquals($newEmail->TestRelatedMembers()->Count(), 1);
		$relatedMember = $newEmail->TestRelatedMembers()->First();
		$this->assertEquals($relatedMember->ID, $member->ID);
		
		unset($_REQUEST['Message']);
	}
	
	function testMultipartBccToClient() {
		$message = file_get_contents(Director::baseFolder() . '/emailpipe/tests/MultipartBccToClient.eml');
		
		$_REQUEST['Message'] = $message;
		
		$handler = new ForwardedEmailHandler();
		$handler->index();
		
		$newEmail = DataObject::get_one('ForwardedEmail', null, false, "Created DESC");
		$this->assertNotNull($newEmail);
		$this->assertEquals($newEmail->From, 'salesguy@yourcompany.com');
		$this->assertEquals($newEmail->Subject, 'Multipart Bcc');
		$this->assertEquals($newEmail->Body, "Hello Client!\n");
		
		$member = $this->objFromFixture('Member', 'johnclient');
		$this->assertEquals($newEmail->TestRelatedMembers()->Count(), 1);
		$relatedMember = $newEmail->TestRelatedMembers()->First();
		$this->assertEquals($relatedMember->ID, $member->ID);
		
		unset($_REQUEST['Message']);
	}
}

Object::add_extension('ForwardedEmail', 'ForwardedEmailHandlerTest_ForwardedEmailDecorator');
Object::add_extension('Member', 'ForwardedEmailMemberRole');

class ForwardedEmailHandlerTest_ForwardedEmailDecorator extends DataObjectDecorator implements TestOnly {
	function extraStatics() {
		return array(
			'many_many' => array(
				'TestRelatedMembers' => 'Member'
			)
		);
	}
}
?>