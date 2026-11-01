<?php
/**
 * Submission pipeline: validation, logic, calculations, protection, storage, delivery,
 * confirmations, files, signatures and save-and-continue.
 *
 * @package Dev3\AiFormBuilder
 */

use Dev3\AiFormBuilder\Core3\Core\Settings_Store;
use Dev3\AiFormBuilder\Data\Drafts;
use Dev3\AiFormBuilder\Data\Entries;
use Dev3\AiFormBuilder\Data\Entry_Log;
use Dev3\AiFormBuilder\Forms\Form_Repository;
use Dev3\AiFormBuilder\Submission\Form_Token;
use Dev3\AiFormBuilder\Submission\Submission_Handler;
use Dev3\AiFormBuilder\Submission\Uploads;
use Dev3\AiFormBuilder\Spam\Spam_Log;

class SubmissionTest extends Dev3fb_TestCase {

	private function contact_form( array $extra = array() ) {
		return $this->make_form(
			array(
				array( 'id' => 'name', 'type' => 'name', 'label' => 'Name', 'required' => true ),
				array( 'id' => 'email', 'type' => 'email', 'label' => 'Email', 'required' => true ),
				array( 'id' => 'topic', 'type' => 'radio', 'label' => 'Topic', 'required' => true, 'options' => array( 'Sales', 'Other' ) ),
				array(
					'id'       => 'other',
					'type'     => 'text',
					'label'    => 'Other topic',
					'required' => true,
					'logic'    => array( 'rules' => array( array( 'field' => 'topic', 'op' => 'is', 'value' => 'Other' ) ) ),
				),
				array( 'id' => 'message', 'type' => 'textarea', 'label' => 'Message', 'required' => true ),
			),
			$extra
		);
	}

	private function valid( array $over = array() ) {
		return array_merge(
			array(
				'name'    => array( 'first' => 'Ann', 'last' => 'Lee' ),
				'email'   => 'ann@example.com',
				'topic'   => 'Sales',
				'message' => 'We need a new website for 3 shops.',
			),
			$over
		);
	}

	public function test_validation_errors_and_step() {
		$id  = $this->contact_form();
		$res = Submission_Handler::handle( $id, $this->params( $id, array( 'email' => 'nope', 'topic' => 'Unknown' ) ) );
		$this->assertFalse( $res['ok'] );
		$this->assertSame( 422, $res['status'] );
		$this->assertSame( array( 'name', 'email', 'topic', 'message' ), array_keys( $res['errors'] ), 'Hidden required field "other" is not validated' );
		$this->assertSame( 0, Entries::count_for_form( $id ) );
	}

	public function test_conditional_required_field_is_enforced_when_visible() {
		$id  = $this->contact_form();
		$res = Submission_Handler::handle( $id, $this->params( $id, $this->valid( array( 'topic' => 'Other' ) ) ) );
		$this->assertSame( array( 'other' ), array_keys( $res['errors'] ) );
	}

	public function test_success_stores_entry_sends_email_and_confirms() {
		$id  = $this->contact_form();
		$res = Submission_Handler::handle( $id, $this->params( $id, $this->valid( array( 'other' => 'should be dropped' ) ) ) );
		$this->assertTrue( $res['ok'], wp_json_encode( $res ) );
		$entry = Entries::get( $res['entry_id'] );
		$this->assertSame( 'unread', $entry['status'] );
		$this->assertSame( 'ann@example.com', $entry['email'] );
		$this->assertSame( '', $entry['data']['other'], 'Values of fields hidden by logic are discarded' );
		$this->assertSame( array( 'first' => 'Ann', 'last' => 'Lee' ), $entry['data']['name'] );
		$this->assertCount( 1, $this->mails );
		$this->assertSame( array( get_option( 'admin_email' ) ), (array) $this->mails[0]['to'] );
		$this->assertStringContainsString( 'New entry: Test form', $this->mails[0]['subject'] );
		$this->assertStringContainsString( 'We need a new website', $this->mails[0]['message'] );
		$this->assertSame( 'message', $res['confirmation']['type'] );
		$this->assertStringContainsString( 'Thanks!', $res['confirmation']['message'] );
		$log = Entry_Log::for_entry( $entry['id'] );
		$this->assertSame( 'email', $log[0]['type'] );
	}

	public function test_notifications_logic_reply_to_and_conditional_confirmation() {
		$id = $this->contact_form(
			array(
				'notifications' => array(
					array( 'name' => 'Sales', 'to' => 'sales@example.com', 'reply_to' => '{field:email}', 'subject' => 'Lead from {field:name}', 'logic' => array( 'rules' => array( array( 'field' => 'topic', 'op' => 'is', 'value' => 'Sales' ) ) ) ),
					array( 'name' => 'Other', 'to' => 'other@example.com', 'logic' => array( 'rules' => array( array( 'field' => 'topic', 'op' => 'is', 'value' => 'Other' ) ) ) ),
					array( 'name' => 'Visitor', 'to' => '{field:email}', 'subject' => 'Copy' ),
				),
				'confirmations' => array(
					array( 'type' => 'redirect', 'url' => 'https://example.com/thanks?e={field:email}', 'logic' => array( 'rules' => array( array( 'field' => 'topic', 'op' => 'is', 'value' => 'Other' ) ) ) ),
					array( 'type' => 'message', 'message' => 'Thanks {field:name}!' ),
				),
			)
		);
		$res = Submission_Handler::handle( $id, $this->params( $id, $this->valid() ) );
		$this->assertCount( 2, $this->mails );
		$this->assertSame( array( 'sales@example.com' ), (array) $this->mails[0]['to'] );
		$this->assertSame( 'Lead from Ann Lee', $this->mails[0]['subject'] );
		$this->assertContains( 'Reply-To: ann@example.com', $this->mails[0]['headers'] );
		$this->assertSame( array( 'ann@example.com' ), (array) $this->mails[1]['to'] );
		$this->assertStringContainsString( 'Thanks Ann Lee!', $res['confirmation']['message'] );

		$res = Submission_Handler::handle( $id, $this->params( $id, $this->valid( array( 'topic' => 'Other', 'other' => 'Press', 'email' => 'bo@example.com' ) ) ) );
		$this->assertSame( 'redirect', $res['confirmation']['type'] );
		$this->assertSame( 'https://example.com/thanks?e=bo%40example.com', $res['confirmation']['url'] );
	}

	public function test_honeypot_and_time_trap_and_rate_limit() {
		$id = $this->contact_form( array( 'protection' => array( 'min_seconds' => 5, 'rate_limit' => 2 ) ) );
		// Honeypot: fake success, nothing stored or sent.
		$res = Submission_Handler::handle( $id, $this->params( $id, $this->valid(), array( 'dev3fb_hp' => 'http://spam' ) ) );
		$this->assertTrue( $res['ok'] );
		$this->assertSame( 0, Entries::count_for_form( $id ) );
		$this->assertCount( 0, $this->mails );
		// Too fast.
		$fast = $this->params( $id, $this->valid(), array( 'dev3fb_token' => Form_Token::create( $id, time() - 1 ) ) );
		$this->assertTrue( Submission_Handler::handle( $id, $fast )['ok'] );
		$this->assertSame( 0, Entries::count_for_form( $id ) );
		$this->assertSame( array( 'honeypot', 'too_fast' ), array_reverse( wp_list_pluck( Spam_Log::query( array() )['items'], 'trigger' ) ) );
		// Forged token.
		$bad = Submission_Handler::handle( $id, $this->params( $id, $this->valid(), array( 'dev3fb_token' => '123.abc' ) ) );
		$this->assertSame( 403, $bad['status'] );
		// Rate limit (2/min per IP).
		$_SERVER['REMOTE_ADDR'] = '198.51.100.9';
		Submission_Handler::handle( $id, $this->params( $id, $this->valid() ) );
		Submission_Handler::handle( $id, $this->params( $id, $this->valid() ) );
		$third = Submission_Handler::handle( $id, $this->params( $id, $this->valid() ) );
		$this->assertSame( 429, $third['status'] );
		$this->assertSame( 2, Entries::count_for_form( $id ) );
	}

	public function test_availability_rules() {
		$id   = $this->contact_form( array( 'settings' => array( 'limit_entries' => 1, 'closed_message' => 'Full!' ) ) );
		$this->assertTrue( Submission_Handler::handle( $id, $this->params( $id, $this->valid() ) )['ok'] );
		$res = Submission_Handler::handle( $id, $this->params( $id, $this->valid() ) );
		$this->assertSame( 'Full!', $res['message'] );

		$later = $this->contact_form( array( 'settings' => array( 'open_at' => gmdate( 'Y-m-d H:i', time() + 2 * DAY_IN_SECONDS ) ) ) );
		$this->assertSame( 403, Submission_Handler::handle( $later, $this->params( $later, $this->valid() ) )['status'] );

		$login = $this->contact_form( array( 'settings' => array( 'require_login' => true ) ) );
		$this->assertSame( 'dev3fb_login', Submission_Handler::handle( $login, $this->params( $login, $this->valid() ) )['code'] );
		wp_set_current_user( self::factory()->user->create() );
		$this->assertTrue( Submission_Handler::handle( $login, $this->params( $login, $this->valid() ) )['ok'] );
	}

	public function test_server_recomputes_calculations_and_totals() {
		$id  = $this->make_form(
			array(
				array( 'id' => 'ticket', 'type' => 'product', 'label' => 'Ticket', 'required' => true, 'options' => array( array( 'label' => 'Std', 'price' => 49 ), array( 'label' => 'VIP', 'price' => 129 ) ) ),
				array( 'id' => 'qty', 'type' => 'quantity', 'label' => 'Qty', 'product' => 'ticket', 'min' => 1, 'max' => 10 ),
				array( 'id' => 'fee', 'type' => 'calculation', 'label' => 'Fee', 'formula' => 'round({_subtotal} * 0.05, 2)', 'add_to_total' => true ),
				array( 'id' => 'total', 'type' => 'total', 'label' => 'Total' ),
			)
		);
		$res = Submission_Handler::handle( $id, $this->params( $id, array( 'ticket' => 'VIP', 'qty' => '2', 'fee' => '0', 'total' => '0.01' ) ) );
		$this->assertTrue( $res['ok'], wp_json_encode( $res ) );
		$entry = Entries::get( $res['entry_id'] );
		$this->assertSame( '12.9', $entry['data']['fee'] );
		$this->assertSame( '270.90', $entry['data']['total'], 'Browser-sent totals are ignored' );
		$this->assertSame( 270.9, $entry['payment_total'] );
		$this->assertSame( 422, Submission_Handler::handle( $id, $this->params( $id, array( 'ticket' => 'VIP', 'qty' => '11' ) ) )['status'] );
	}

	public function test_field_type_validation() {
		$id     = $this->make_form(
			array(
				array( 'id' => 'site', 'type' => 'url', 'label' => 'Site' ),
				array( 'id' => 'phone', 'type' => 'phone', 'label' => 'Phone' ),
				array( 'id' => 'when', 'type' => 'date', 'label' => 'When', 'min_date' => '2030-01-01' ),
				array( 'id' => 'at', 'type' => 'time', 'label' => 'At' ),
				array( 'id' => 'stars', 'type' => 'rating', 'label' => 'Stars' ),
				array( 'id' => 'nps', 'type' => 'nps', 'label' => 'NPS' ),
				array( 'id' => 'tags', 'type' => 'checkbox', 'label' => 'Tags', 'options' => array( 'A', 'B' ) ),
				array( 'id' => 'country', 'type' => 'country', 'label' => 'Country' ),
				array( 'id' => 'agree', 'type' => 'consent', 'label' => 'Agree', 'required' => true ),
				array( 'id' => 'pw', 'type' => 'password', 'label' => 'Password' ),
				array( 'id' => 'short', 'type' => 'text', 'label' => 'Short', 'maxlength' => 3 ),
			)
		);
		$bad    = Submission_Handler::handle( $id, $this->params( $id, array( 'site' => 'not a url', 'phone' => '12', 'when' => '2029-12-31', 'at' => '25:00', 'stars' => '6', 'nps' => '11', 'tags' => array( 'A', 'Z' ), 'country' => 'XX', 'short' => 'abcd' ) ) );
		$this->assertEqualsCanonicalizing( array( 'site', 'phone', 'when', 'at', 'stars', 'nps', 'tags', 'country', 'agree', 'short' ), array_keys( $bad['errors'] ) );
		$good   = Submission_Handler::handle( $id, $this->params( $id, array( 'site' => 'example.com', 'phone' => '+1 555 0100 22', 'when' => '2030-02-01', 'at' => '09:30', 'stars' => '5', 'nps' => '0', 'tags' => array( 'B' ), 'country' => 'DE', 'agree' => '1', 'pw' => 'hunter2', 'short' => 'abc' ) ) );
		$this->assertTrue( $good['ok'], wp_json_encode( $good ) );
		$data = Entries::get( $good['entry_id'] )['data'];
		$this->assertSame( 'https://example.com', $data['site'] );
		$this->assertTrue( wp_check_password( 'hunter2', $data['pw'] ), 'Passwords are stored hashed' );
	}

	public function test_file_upload_and_signature() {
		add_filter( 'dev3fb_allow_sideload', '__return_true' );
		$id  = $this->make_form(
			array(
				array( 'id' => 'cv', 'type' => 'file', 'label' => 'CV', 'accept' => 'pdf,txt', 'max_size' => 1, 'required' => true ),
				array( 'id' => 'sig', 'type' => 'signature', 'label' => 'Sign', 'required' => true ),
			)
		);
		$tmp = wp_tempnam( 'cv.txt' );
		file_put_contents( $tmp, 'Hello CV' ); // phpcs:ignore
		$files = array(
			'dev3fb_files_cv' => array(
				'name'     => array( 'my cv.txt' ),
				'type'     => array( 'text/plain' ),
				'tmp_name' => array( $tmp ),
				'error'    => array( UPLOAD_ERR_OK ),
				'size'     => array( 8 ),
			),
		);
		// A tiny valid PNG (1x1) padded above the 100-byte minimum by a text chunk.
		$png  = base64_encode( base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==' ) . str_repeat( "\0", 64 ) ); // phpcs:ignore
		$res  = Submission_Handler::handle( $id, $this->params( $id, array( 'sig' => 'data:image/png;base64,' . $png ) ), $files );
		$this->assertTrue( $res['ok'], wp_json_encode( $res ) );
		$entry = Entries::get( $res['entry_id'] );
		$this->assertSame( 'my-cv.txt', $entry['data']['cv'][0]['name'] );
		$path = Uploads::absolute( $entry['data']['cv'][0]['path'] );
		$this->assertFileExists( $path );
		$this->assertFileExists( Uploads::base_dir() . '/.htaccess' );
		$this->assertSame( 'image/png', $entry['data']['sig']['type'] );
		$this->assertCount( 2, Uploads::files_of( $entry ) );

		// Blocked extension.
		$evil  = wp_tempnam( 'x.php' );
		file_put_contents( $evil, '<?php echo 1;' ); // phpcs:ignore
		$files['dev3fb_files_cv'] = array( 'name' => array( 'x.php' ), 'type' => array( 'text/plain' ), 'tmp_name' => array( $evil ), 'error' => array( 0 ), 'size' => array( 12 ) );
		$bad = Submission_Handler::handle( $id, $this->params( $id, array( 'sig' => 'data:image/png;base64,' . $png ) ), $files );
		$this->assertArrayHasKey( 'cv', $bad['errors'] );

		Entries::delete( $entry['id'] );
		$this->assertFileDoesNotExist( $path );
		remove_filter( 'dev3fb_allow_sideload', '__return_true' );
	}

	public function test_store_entries_off_still_sends_email() {
		$id  = $this->contact_form( array( 'settings' => array( 'store_entries' => false ) ) );
		$res = Submission_Handler::handle( $id, $this->params( $id, $this->valid() ) );
		$this->assertTrue( $res['ok'] );
		$this->assertSame( 0, Entries::count_for_form( $id ) );
		$this->assertCount( 1, $this->mails );
	}

	public function test_drafts_save_resume_and_cleanup() {
		$id    = $this->contact_form( array( 'settings' => array( 'save_continue' => true ) ) );
		$token = Drafts::save( $id, array( 'email' => 'ann@example.com' ), 1, 'ann@example.com' );
		$this->assertMatchesRegularExpression( '/^[A-Za-z0-9]{32}$/', $token );
		$draft = Drafts::get( $token, $id );
		$this->assertSame( 1, $draft['step'] );
		$this->assertNull( Drafts::get( $token, $id + 1 ), 'Bound to the form' );
		$this->assertSame( $token, Drafts::save( $id, array( 'email' => 'x@example.com' ), 2, '', $token ) );
		$this->assertSame( 'x@example.com', Drafts::get( $token, $id )['data']['email'] );
		$res = Submission_Handler::handle( $id, $this->params( $id, $this->valid(), array( 'dev3fb_resume' => $token ) ) );
		$this->assertTrue( $res['ok'] );
		$this->assertNull( Drafts::get( $token, $id ), 'Draft removed after submit' );
	}

	public function test_translated_submission_uses_translated_messages() {
		$id   = $this->contact_form();
		$form = Form_Repository::get( $id );
		$def  = $form['definition'];
		$def['translations'] = array( 'de' => array( 'message.required' => 'Pflichtfeld.', 'confirmation.confirmation_1.message' => 'Danke!' ) );
		Form_Repository::save( array( 'id' => $id, 'definition' => $def ) );
		$res = Submission_Handler::handle( $id, $this->params( $id, array(), array( 'dev3fb_lang' => 'de' ) ) );
		$this->assertSame( 'Pflichtfeld.', $res['errors']['email'] );
		$ok = Submission_Handler::handle( $id, $this->params( $id, $this->valid(), array( 'dev3fb_lang' => 'de' ) ) );
		$this->assertStringContainsString( 'Danke!', $ok['confirmation']['message'] );
		$this->assertSame( 'de', Entries::get( $ok['entry_id'] )['lang'] );
	}
}
