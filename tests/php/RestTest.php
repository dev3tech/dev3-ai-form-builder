<?php
/**
 * REST API: permissions, forms CRUD, entries, export, files, credentials, public routes.
 *
 * @package Dev3\AiFormBuilder
 */

use Dev3\AiFormBuilder\Data\Analytics;
use Dev3\AiFormBuilder\Data\Entries;
use Dev3\AiFormBuilder\Forms\Templates;
use Dev3\AiFormBuilder\Secrets;
use Dev3\AiFormBuilder\Submission\Form_Token;
use Dev3\AiFormBuilder\Submission\Submission_Handler;

class RestTest extends Dev3fb_TestCase {

	public function set_up() {
		parent::set_up();
		do_action( 'rest_api_init' );
	}

	public function test_admin_routes_require_manage_and_nonce() {
		$routes = array( array( 'GET', 'forms' ), array( 'GET', 'entries' ), array( 'GET', 'dashboard' ), array( 'GET', 'spam' ), array( 'GET', 'credentials' ), array( 'POST', 'ai/form' ), array( 'GET', 'entries/export' ) );
		foreach ( $routes as $r ) {
			$this->assertSame( 401, $this->rest( $r[0], $r[1] )->get_status(), 'Anonymous: ' . $r[1] );
		}
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		foreach ( $routes as $r ) {
			$this->assertSame( 403, $this->rest( $r[0], $r[1] )->get_status(), 'Editor: ' . $r[1] );
		}
		$this->assertSame( 200, $this->rest( 'GET', 'forms/options' )->get_status(), 'Editors can list forms for the block' );
		$this->admin();
		$this->assertSame( 403, $this->rest( 'GET', 'forms', array(), false )->get_status(), 'Nonce is required' );
		$this->assertSame( 200, $this->rest( 'GET', 'forms' )->get_status() );
	}

	public function test_forms_crud_and_templates() {
		$this->admin();
		$this->assertGreaterThanOrEqual( 8, count( $this->rest( 'GET', 'templates' )->get_data() ) );
		$created = $this->rest( 'POST', 'forms', array( 'template' => 'quote' ) );
		$this->assertSame( 201, $created->get_status() );
		$form = $created->get_data();
		$this->assertSame( 3, $form['steps'] );
		$this->assertSame( sprintf( '[dev3fb_form id="%d"]', $form['id'] ), $form['shortcode'] );
		$def                            = $form['definition'];
		$def['steps'][0]['fields'][0]['label'] = 'What can we build?';
		$updated = $this->rest( 'PUT', 'forms/' . $form['id'], array( 'title' => 'Quote v2', 'status' => 'draft', 'definition' => $def ) )->get_data();
		$this->assertSame( 'Quote v2', $updated['title'] );
		$this->assertSame( 'draft', $updated['status'] );
		$this->assertSame( 'What can we build?', $updated['definition']['steps'][0]['fields'][0]['label'] );
		$list = $this->rest( 'GET', 'forms' )->get_data();
		$this->assertSame( 'Quote v2', $list[0]['title'] );
		$this->assertSame( 201, $this->rest( 'POST', 'forms/' . $form['id'] . '/duplicate' )->get_status() );
		$norm = $this->rest( 'POST', 'normalize', array( 'definition' => array( 'fields' => array( array( 'type' => 'stars', 'label' => 'Rate' ) ) ) ) )->get_data();
		$this->assertSame( 'rating', $norm['definition']['steps'][0]['fields'][0]['type'] );
		$this->assertTrue( $this->rest( 'DELETE', 'forms/' . $form['id'] )->get_data()['deleted'] );
		$this->assertSame( 404, $this->rest( 'GET', 'forms/' . $form['id'] )->get_status() );
	}

	private function seed_entries() {
		$id = $this->make_form( Templates::get( 'contact' )['definition'] );
		foreach ( array( 'ann', 'bo', 'cy' ) as $n => $who ) {
			Submission_Handler::handle(
				$id,
				$this->params(
					$id,
					array(
						'name'    => array( 'first' => ucfirst( $who ), 'last' => 'Test' ),
						'email'   => $who . '@example.com',
						'topic'   => 'Sales',
						'message' => "=HYPERLINK(\"evil\") message {$n}",
						'consent' => '1',
					)
				)
			);
		}
		return $id;
	}

	public function test_entries_list_detail_bulk_and_export() {
		$form = $this->seed_entries();
		$this->admin();
		$list = $this->rest( 'GET', 'entries', array( 'form' => $form ) )->get_data();
		$this->assertSame( 3, $list['total'] );
		$this->assertSame( 3, $list['counts']['unread'] );
		$this->assertSame( 'Cy Test', $list['rows'][0]['name'] );
		$search = $this->rest( 'GET', 'entries', array( 'search' => 'bo@example' ) )->get_data();
		$this->assertSame( 1, $search['total'] );

		$id     = $list['rows'][0]['id'];
		$detail = $this->rest( 'GET', 'entries/' . $id )->get_data();
		$this->assertSame( 'read', $detail['entry']['status'], 'Opening marks as read' );
		$this->assertSame( 'Cy Test', $detail['name'] );
		$this->assertArrayNotHasKey( 'user_agent', $detail['entry']['meta'] );

		$patched = $this->rest( 'PATCH', 'entries/' . $id, array( 'starred' => true, 'note' => 'Call back Monday' ) )->get_data();
		$this->assertTrue( $patched['entry']['starred'] );
		$this->assertSame( 'note', $patched['log'][0]['type'] );

		$ids = wp_list_pluck( $list['rows'], 'id' );
		$this->assertSame( 2, $this->rest( 'POST', 'entries/bulk', array( 'ids' => array_slice( $ids, 1 ), 'action' => 'trash' ) )->get_data()['updated'] );
		$this->assertSame( 2, Entries::counts( $form )['trash'] );
		$this->assertSame( 400, $this->rest( 'POST', 'entries/bulk', array( 'ids' => $ids, 'action' => 'explode' ) )->get_status() );

		$csv = $this->rest( 'GET', 'entries/export', array( 'form' => $form, 'status' => 'all' ) )->get_data();
		$this->assertSame( 3, $csv['count'] );
		$this->assertStringStartsWith( "\xEF\xBB\xBF", $csv['csv'] );
		$this->assertStringContainsString( '"\'=HYPERLINK(""evil"") message 0"', $csv['csv'], 'Formula injection is neutralised' );
		$this->assertStringContainsString( '"Your name"', $csv['csv'] );

		$this->assertTrue( $this->rest( 'DELETE', 'entries/' . $id )->get_data()['deleted'] );
	}

	public function test_dashboard_and_insights() {
		$form = $this->seed_entries();
		Analytics::record( $form, str_repeat( 'a', 20 ), 'view' );
		$this->admin();
		$dash = $this->rest( 'GET', 'dashboard' )->get_data();
		$this->assertSame( 3, $dash['entries_30'] );
		$this->assertCount( 30, $dash['daily'] );
		$this->assertSame( 3, $dash['top'][0]['entries'] );
		$ins = $this->rest( 'GET', 'forms/' . $form . '/insights' )->get_data();
		$this->assertSame( 1, $ins['funnel']['views'] );
	}

	public function test_private_file_download_requires_manage() {
		add_filter( 'dev3fb_allow_sideload', '__return_true' );
		add_filter( 'dev3fb_stream_file', '__return_false' );
		$form = $this->make_form( array( array( 'id' => 'doc', 'type' => 'file', 'label' => 'Doc', 'accept' => 'txt' ) ) );
		$tmp  = wp_tempnam( 'a.txt' );
		file_put_contents( $tmp, 'secret' ); // phpcs:ignore
		$res = Submission_Handler::handle( $form, $this->params( $form, array() ), array( 'dev3fb_files_doc' => array( 'name' => 'a.txt', 'type' => 'text/plain', 'tmp_name' => $tmp, 'error' => 0, 'size' => 6 ) ) );
		$this->assertTrue( $res['ok'], wp_json_encode( $res ) );
		$this->assertSame( 401, $this->rest( 'GET', 'entries/' . $res['entry_id'] . '/file', array( 'field' => 'doc', 'index' => 0 ) )->get_status() );
		$this->admin();
		$out = $this->rest( 'GET', 'entries/' . $res['entry_id'] . '/file', array( 'field' => 'doc', 'index' => 0 ) )->get_data();
		$this->assertSame( 'a.txt', $out['name'] );
		$this->assertSame( 'secret', file_get_contents( $out['path'] ) ); // phpcs:ignore
		$this->assertSame( 404, $this->rest( 'GET', 'entries/' . $res['entry_id'] . '/file', array( 'field' => 'doc', 'index' => 5 ) )->get_status() );
		remove_filter( 'dev3fb_allow_sideload', '__return_true' );
		remove_filter( 'dev3fb_stream_file', '__return_false' );
	}

	public function test_credentials_never_returned() {
		$this->admin();
		$this->rest( 'POST', 'credentials/save', array( 'stripe_secret' => 'sk_test_abcdef9999', 'nonsense' => 'x' ) );
		$out = $this->rest( 'GET', 'credentials' )->get_data();
		$this->assertTrue( $out['secrets']['stripe_secret']['set'] );
		$this->assertTrue( $out['stripe']['test_mode'] );
		$this->assertStringNotContainsString( 'abcdef', wp_json_encode( $out ) );
		$settings = $this->rest( 'GET', 'settings' )->get_data();
		$this->assertStringNotContainsString( 'sk_test', wp_json_encode( $settings ) );
		$this->assertSame( 'sk_test_abcdef9999', Secrets::get( 'stripe_secret' ) );
	}

	public function test_public_submit_route_and_same_origin() {
		$form = $this->make_form( Templates::get( 'contact' )['definition'] );
		$body = array(
			'form'         => $form,
			'dev3fb_token' => Form_Token::create( $form, time() - 30 ),
			'dev3fb'       => array( 'name' => array( 'first' => 'Ann', 'last' => 'Lee' ), 'email' => 'ann@example.com', 'message' => 'Hello there', 'consent' => '1' ),
		);
		$res  = $this->rest( 'POST', 'submit', $body, false );
		$this->assertSame( 200, $res->get_status(), wp_json_encode( $res->get_data() ) );
		$this->assertTrue( $res->get_data()['ok'] );
		$bad = $this->rest( 'POST', 'submit', array_merge( $body, array( 'dev3fb' => array() ) ), false );
		$this->assertSame( 422, $bad->get_status() );
		$this->assertArrayHasKey( 'email', (array) $bad->get_data()['errors'] );
		$cross = $this->rest( 'POST', 'submit', $body, false, array( 'Origin' => 'https://evil.example' ) );
		$this->assertSame( 403, $cross->get_status() );
		$token = $this->rest( 'POST', 'token', array( 'form' => $form ), false )->get_data()['token'];
		$this->assertIsInt( Form_Token::verify( $token, $form ) );
	}

	public function test_public_save_track_and_parse() {
		$def                               = Templates::get( 'quote' )['definition'];
		$form                              = $this->make_form( $def );
		$token                             = Form_Token::create( $form, time() - 30 );
		$save                              = $this->rest( 'POST', 'save', array( 'form' => $form, 'dev3fb_token' => $token, 'dev3fb_step' => 1, 'dev3fb' => array( 'service' => 'Redesign' ), 'dev3fb_page' => home_url( '/quote/' ) ), false )->get_data();
		$this->assertStringContainsString( 'dev3fb_resume=' . $save['token'], $save['link'] );
		$tracked = $this->rest( 'POST', 'track', array( 'form' => $form, 'session' => str_repeat( 'b', 24 ), 'events' => array( array( 'event' => 'view' ), array( 'event' => 'start' ), array( 'event' => 'abandon', 'field' => 'budget' ), array( 'event' => 'submit' ), array( 'event' => 'abandon', 'field' => 'not_a_field' ) ) ), false )->get_data();
		$this->assertSame( 3, $tracked['recorded'], 'submit cannot be faked; unknown field ids are dropped' );

		$parse = $this->rest( 'POST', 'parse', array( 'form' => $form, 'token' => $token, 'text' => 'Hi, I am Ann Lee from Acme, ann@acme.io' ), false );
		$this->assertSame( 403, $parse->get_status(), 'Paste-to-fill is off for this form' );
		$contact = $this->make_form( Templates::get( 'contact' )['definition'] );
		$this->assertSame( 403, $this->rest( 'POST', 'parse', array( 'form' => $contact, 'token' => $token, 'text' => 'Hi, I am Ann Lee from Acme' ), false )->get_status(), 'Token is bound to its form' );
		$this->set_key();
		$this->gemini[] = array( 'fields' => array( array( 'id' => 'email', 'value' => 'ann@acme.io' ), array( 'id' => 'name.first', 'value' => 'Ann' ) ) );
		$ok = $this->rest( 'POST', 'parse', array( 'form' => $contact, 'token' => Form_Token::create( $contact ), 'text' => 'Hi, I am Ann Lee from Acme, ann@acme.io' ), false );
		$this->assertSame( 200, $ok->get_status(), wp_json_encode( $ok->get_data() ) );
		$this->assertSame( 2, $ok->get_data()['count'] );
	}
}
