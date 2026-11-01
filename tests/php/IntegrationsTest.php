<?php
/**
 * Mailchimp, Brevo, HubSpot, Google Sheets, Zapier and webhooks with mocked HTTP.
 *
 * @package Dev3\AiFormBuilder
 */

use Dev3\AiFormBuilder\Data\Entries;
use Dev3\AiFormBuilder\Data\Entry_Log;
use Dev3\AiFormBuilder\Delivery\Integrations;
use Dev3\AiFormBuilder\Secrets;
use Dev3\AiFormBuilder\Submission\Submission_Handler;

class IntegrationsTest extends Dev3fb_TestCase {

	private function form( array $integrations, array $webhooks = array() ) {
		return $this->make_form(
			array(
				array( 'id' => 'name', 'type' => 'name', 'label' => 'Name' ),
				array( 'id' => 'email', 'type' => 'email', 'label' => 'Email', 'required' => true ),
				array( 'id' => 'company', 'type' => 'text', 'label' => 'Company' ),
				array( 'id' => 'optin', 'type' => 'consent', 'label' => 'News' ),
			),
			array(
				'integrations'  => $integrations,
				'webhooks'      => $webhooks,
				'notifications' => array(),
			)
		);
	}

	private function submit( $id, $optin = '1' ) {
		return Submission_Handler::handle( $id, $this->params( $id, array( 'name' => array( 'first' => 'Ann', 'last' => 'Lee' ), 'email' => 'Ann@Example.com', 'company' => 'Acme', 'optin' => $optin ) ) );
	}

	public function test_mailchimp_brevo_hubspot() {
		Secrets::update( array( 'mailchimp_key' => 'abc123-us21', 'brevo_key' => 'xkeysib-1', 'hubspot_token' => 'pat-na1-1' ) );
		$this->routes['us21.api.mailchimp.com'] = array( 200, array( 'id' => 'm1' ) );
		$this->routes['api.brevo.com']           = array( 201, array( 'id' => 5 ) );
		$this->routes['crm/v3/objects/contacts/ann%40example.com'] = array( 200, array( 'id' => '1' ) );
		$this->routes['api.hubapi.com']          = array( 409, array( 'message' => 'Contact already exists' ) );
		$map = array(
			'email'      => 'email',
			'first_name' => 'name',
			'last_name'  => 'name',
			'company'    => 'company',
		);
		$id  = $this->form(
			array(
				'consent_field' => 'optin',
				'mailchimp'     => array( 'enabled' => true, 'list_id' => 'aud1', 'tags' => 'web, lead', 'double' => true, 'map' => $map ),
				'brevo'         => array( 'enabled' => true, 'list_id' => '7', 'map' => $map ),
				'hubspot'       => array( 'enabled' => true, 'map' => $map ),
			)
		);
		$res = $this->submit( $id );
		$this->assertTrue( $res['ok'] );
		$mc = $this->requests_to( 'mailchimp.com/3.0/lists/aud1/members/' . md5( 'ann@example.com' ) );
		$this->assertSame( 'PUT', $mc[0]['method'] );
		$this->assertSame( 'pending', $mc[0]['json']['status_if_new'] );
		$this->assertSame( array( 'FNAME' => 'Ann', 'LNAME' => 'Lee' ), $mc[0]['json']['merge_fields'] );
		$this->assertSame( 'Basic ' . base64_encode( 'dev3:abc123-us21' ), $mc[0]['headers']['Authorization'] ); // phpcs:ignore
		$this->assertSame( array( 'web', 'lead' ), wp_list_pluck( $mc[1]['json']['tags'], 'name' ) );
		$brevo = $this->requests_to( 'api.brevo.com/v3/contacts' )[0];
		$this->assertSame( array( 7 ), $brevo['json']['listIds'] );
		$this->assertSame( 'xkeysib-1', $brevo['headers']['api-key'] );
		$hub = $this->requests_to( 'api.hubapi.com' );
		$this->assertSame( 'POST', $hub[0]['method'] );
		$this->assertSame( 'PATCH', $hub[1]['method'], 'Existing contact is updated on 409' );
		$this->assertSame( 'Acme', $hub[1]['json']['properties']['company'] );
		$types = wp_list_pluck( Entry_Log::for_entry( $res['entry_id'] ), 'status', 'type' );
		$this->assertSame( 'ok', $types['mailchimp'] );
		$this->assertSame( 'ok', $types['hubspot'] );

		// Without consent nothing is sent to the marketing tools.
		$this->http = array();
		$this->submit( $id, '' );
		$this->assertCount( 0, $this->requests_to( 'mailchimp.com' ) );
		$this->assertCount( 0, $this->requests_to( 'brevo.com' ) );
	}

	public function test_sheets_zapier_and_signed_webhook() {
		$this->routes['script.google.com'] = array( 200, array( 'result' => 'ok' ) );
		$this->routes['hooks.zapier.com']  = array( 200, array( 'status' => 'success' ) );
		$this->routes['hooks.example.com'] = array( 500, 'oops' );
		$id  = $this->form(
			array(
				'sheets' => array( 'enabled' => true, 'url' => 'https://script.google.com/macros/s/abc/exec' ),
				'zapier' => array( 'enabled' => true, 'url' => 'https://hooks.zapier.com/hooks/catch/1/2/' ),
			),
			array(
				array( 'name' => 'CRM', 'enabled' => true, 'url' => 'https://hooks.example.com/in', 'format' => 'json', 'secret' => 's3cret' ),
				array( 'name' => 'Off', 'enabled' => false, 'url' => 'https://off.example.com' ),
			)
		);
		$res   = $this->submit( $id );
		$sheet = $this->requests_to( 'script.google.com' )[0]['json'];
		$this->assertSame( 'Test form', $sheet['sheet'] );
		$this->assertSame( 'Ann Lee', $sheet['values']['Name'] );
		$zap = $this->requests_to( 'hooks.zapier.com' )[0]['json'];
		$this->assertSame( 'entry.created', $zap['event'] );
		$this->assertSame( 'Acme', $zap['fields']['company']['value'] );
		$hook = $this->requests_to( 'hooks.example.com' )[0];
		$this->assertSame( 'sha256=' . hash_hmac( 'sha256', $hook['body'], 's3cret' ), $hook['headers']['X-DEV3FB-Signature'] );
		$this->assertCount( 0, $this->requests_to( 'off.example.com' ) );
		$log = Entry_Log::for_entry( $res['entry_id'] );
		$this->assertContains( 'error', wp_list_pluck( wp_list_filter( $log, array( 'type' => 'webhook' ) ), 'status' ), 'HTTP 500 is logged as an error' );
	}

	public function test_connection_test_lists_audiences() {
		Secrets::update( array( 'mailchimp_key' => 'abc-us5' ) );
		$this->routes['us5.api.mailchimp.com/3.0/lists'] = array( 200, array( 'lists' => array( array( 'id' => 'a1', 'name' => 'Customers' ) ) ) );
		$out = Integrations::test( 'mailchimp' );
		$this->assertSame( array( array( 'value' => 'a1', 'label' => 'Customers' ) ), $out['lists'] );
		Secrets::update( array( 'mailchimp_key' => 'nodc' ) );
		$this->assertWPError( Integrations::test( 'mailchimp' ) );
		$this->assertWPError( Integrations::test( 'brevo' ) );
	}

	public function test_secrets_are_encrypted_and_masked() {
		Secrets::update( array( 'stripe_secret' => 'sk_live_supersecret1234' ) );
		$raw = get_option( 'dev3fb_secrets' );
		$this->assertStringNotContainsString( 'supersecret', wp_json_encode( $raw ) );
		$this->assertSame( 'sk_live_supersecret1234', Secrets::get( 'stripe_secret' ) );
		$status = Secrets::status();
		$this->assertTrue( $status['stripe_secret']['set'] );
		$this->assertSame( '••••••••1234', $status['stripe_secret']['hint'] );
		Secrets::update( array( 'stripe_secret' => '••••••••1234' ) );
		$this->assertSame( 'sk_live_supersecret1234', Secrets::get( 'stripe_secret' ), 'Masked value is ignored' );
		Secrets::update( array( 'stripe_secret' => '' ) );
		$this->assertSame( '', Secrets::get( 'stripe_secret' ) );
	}
}
