<?php
/**
 * @package sscrm
 * @subpackage tests
 */
class ForwardedEmailHandlerTest extends SapphireTest {
	function testMultipartInlineForwardFromClient() {
		$multipartMessageForwardFromClient = file_get_contents(Director::baseFolder() . '/emailpipe/tests/MultipartInlineForwardFromClient.eml');
		
		$_REQUEST['Message'] = $multipartMessageForwardFromClient;
		
		$handler = new ForwardedEmailHandler();
		$handler->index();
		
		$newEmail = DataObject::get_one('ForwardedForwardedEmail', null, false, "Created DESC");
		$this->assertNotNull($newEmail);
		$this->assertEquals($newEmail->From, 'clientwork@clientcompany.com');
		$this->assertEquals($newEmail->To, 'salesguy@yourcompany.com');
		$this->assertEquals($newEmail->Subject, 'Multipart Inline Forward');
		$this->assertEquals($newEmail->Body, "Please send me a proposal!\nHere's a link: http://test.com");
		
		unset($_REQUEST['Message']);
	}
	
	function testMultipartNestedInlineForwardFromClient() {}
	
	function testMultipartBccToClient() {}
	
	function testHtmlOnlyForwardFromClient() {}
}
?>