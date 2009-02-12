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
		$multipartMessageForwardFromClient = file_get_contents(Director::baseFolder() . '/emailpipe/tests/MultipartInlineForwardFromClient.eml');
		
		$_REQUEST['Message'] = $multipartMessageForwardFromClient;
		
		$handler = new ForwardedEmailHandler();
		$handler->index();
		
		$newEmail = DataObject::get_one('ForwardedEmail', null, false, "Created DESC");
		$this->assertNotNull($newEmail);
		$this->assertEquals($newEmail->From, 'work@clientcompany.com');
		$this->assertEquals($newEmail->Subject, 'Multipart Inline Forward');
		$this->assertEquals($newEmail->Body, "Please send me a proposal!\nHere's a link: http://test.com");
		
		$member = $this->objFromFixture('Member', 'johnclient');
		$this->assertEquals($newEmail->TestRelatedMembers()->Count(), 1);
		$relatedMember = $newEmail->TestRelatedMembers()->First();
		$this->assertEquals($relatedMember->ID, $member->ID);
		
		unset($_REQUEST['Message']);
	}
	
	function testMultipartNestedInlineForwardFromClient() {}
	
	function testMultipartBccToClient() {}
	
	function testHtmlOnlyForwardFromClient() {}
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