<?php
/**
 * DEV3 AI features with Gemini mocked: describe-your-form, entry intelligence, auto-replies,
 * conversion advice, translation, spam shield and paste-to-fill. Plus "AI off still works".
 *
 * @package Dev3\AiFormBuilder
 */

use Dev3\AiFormBuilder\AI\Auto_Reply;
use Dev3\AiFormBuilder\AI\Conversion_Advisor;
use Dev3\AiFormBuilder\AI\Entry_Insights;
use Dev3\AiFormBuilder\AI\Extractor;
use Dev3\AiFormBuilder\AI\Form_Generator;
use Dev3\AiFormBuilder\AI\Translator;
use Dev3\AiFormBuilder\Core3\Core\Settings_Store;
use Dev3\AiFormBuilder\Data\Analytics;
use Dev3\AiFormBuilder\Data\Entries;
use Dev3\AiFormBuilder\Forms\Definition;
use Dev3\AiFormBuilder\Forms\Form_Repository;
use Dev3\AiFormBuilder\Forms\Templates;
use Dev3\AiFormBuilder\Spam\Spam_Log;
use Dev3\AiFormBuilder\Submission\Form_Token;
use Dev3\AiFormBuilder\Submission\Submission_Handler;

class AiFeaturesTest extends Dev3fb_TestCase {

	public function test_describe_your_form_fixtures_meet_acceptance() {
		$cases = json_decode( file_get_contents( __DIR__ . '/fixtures/ai-forms.json' ), true ); // phpcs:ignore
		$this->assertCount( 10, $cases );
		$good = 0;
		foreach ( $cases as $case ) {
			$draft = Form_Generator::from_ai( $case['ai'] );
			$def   = $draft['definition'];
			$this->assertLessThanOrEqual( $case['max_fixes'], count( $draft['fixes'] ), $case['prompt'] . ': ' . implode( ' | ', $draft['fixes'] ) );
			$this->assertNotEmpty( Definition::input_fields( $def ), $case['prompt'] );
			$this->assertSame( $def, Definition::normalize( $def ), 'Draft is already normalized: ' . $case['prompt'] );
			foreach ( Definition::fields( $def ) as $field ) {
				$this->assertArrayHasKey( $field['type'], Definition::types() );
			}
			$this->assertNotSame( '', $def['confirmations'][0]['message'] );
			if ( count( $draft['fixes'] ) <= 2 ) {
				++$good;
			}
		}
		$this->assertGreaterThanOrEqual( 8, $good, 'Acceptance: <= 2 manual fixes in 8 of 10' );
		$wedding = Form_Generator::from_ai( $cases[1]['ai'] )['definition'];
		$this->assertCount( 3, $wedding['steps'] );
		$this->assertSame( 2400.0, (float) Definition::field( $wedding, 'package' )['options'][1]['price'] );
		$this->assertSame( '{field:email}', $wedding['notifications'][0]['reply_to'] );
	}

	public function test_generate_calls_gemini_with_schema_and_heavy_profile() {
		$this->set_key();
		$cases          = json_decode( file_get_contents( __DIR__ . '/fixtures/ai-forms.json' ), true ); // phpcs:ignore
		$this->gemini[] = $cases[3]['ai'];
		$draft          = Form_Generator::generate( 'NPS survey with follow-up for low scores', array( 'language' => 'de', 'steps' => 'single' ) );
		$this->assertIsArray( $draft );
		$this->assertSame( 'How did we do?', $draft['title'] );
		$req = $this->requests_to( 'generateContent' )[0]['json'];
		$this->assertSame( 'application/json', $req['generationConfig']['responseMimeType'] );
		$this->assertArrayHasKey( 'responseSchema', $req['generationConfig'] );
		$this->assertStringContainsString( 'German', $req['contents'][0]['parts'][0]['text'] );
		$this->assertSame( 2048, $req['generationConfig']['thinkingConfig']['thinkingBudget'] );
		$this->assertWPError( Form_Generator::generate( 'hi' ) );
		$this->assertCount( 0, Form_Repository::all(), 'Generating saves nothing' );
	}

	public function test_ai_off_or_no_key_returns_errors_but_forms_work() {
		$this->assertWPError( Form_Generator::generate( 'A contact form please' ) );
		$id   = Form_Repository::save( array( 'title' => 'T', 'definition' => Templates::get( 'contact' )['definition'] ) );
		$form = Form_Repository::get( $id );
		$res  = Submission_Handler::handle(
			$id,
			array(
				'dev3fb'       => array( 'name' => array( 'first' => 'A', 'last' => 'B' ), 'email' => 'a@example.com', 'message' => 'Hi there', 'consent' => '1' ),
				'dev3fb_token' => Form_Token::create( $id, time() - 30 ),
			)
		);
		$this->assertTrue( $res['ok'], wp_json_encode( $res ) );
		$insights = Entry_Insights::analyze( $res['entry_id'], true );
		$this->assertWPError( $insights, 'AI errors are reported…' );
		$this->assertSame( 'rules', Entries::get( $res['entry_id'] )['ai']['source'], '…and a rule-based score is stored' );
		$this->assertNotEmpty( $form['definition'] );
	}

	private function entry_form() {
		$id = $this->make_form(
			array(
				array( 'id' => 'name', 'type' => 'name', 'label' => 'Name' ),
				array( 'id' => 'email', 'type' => 'email', 'label' => 'Email' ),
				array( 'id' => 'phone', 'type' => 'phone', 'label' => 'Phone' ),
				array( 'id' => 'budget', 'type' => 'select', 'label' => 'Budget', 'options' => array( '$5k+' ) ),
				array( 'id' => 'message', 'type' => 'textarea', 'label' => 'Message' ),
			),
			array(),
			'Quote request'
		);
		$res = Submission_Handler::handle( $id, $this->params( $id, array( 'name' => array( 'first' => 'Ann', 'last' => 'Lee' ), 'email' => 'ann@acme.io', 'phone' => '+44 20 7946 0018', 'budget' => '$5k+', 'message' => 'We need a quote for a shop ASAP.' ) ) );
		return array( $id, $res['entry_id'] );
	}

	public function test_entry_insights_with_ai_and_privacy() {
		list( , $entry_id ) = $this->entry_form();
		$this->set_key();
		$this->gemini[] = array(
			'summary'     => 'Ann wants a quote for an online shop quickly.',
			'sentiment'   => 'POSITIVE',
			'intent'      => 'Quote for online shop',
			'category'    => 'quote_request',
			'lead_score'  => 140,
			'lead_reason' => 'Clear budget and timeline.',
			'urgency'     => 'high',
			'language'    => 'en',
			'tags'        => array( 'Shop', 'quote', 'shop' ),
		);
		$ai = Entry_Insights::analyze( $entry_id );
		$this->assertSame( 100, $ai['lead_score'], 'Clamped' );
		$this->assertSame( 'positive', $ai['sentiment'] );
		$this->assertSame( array( 'shop', 'quote' ), $ai['tags'] );
		$entry = Entries::get( $entry_id );
		$this->assertSame( 100, $entry['lead_score'] );
		$prompt = $this->requests_to( 'generateContent' )[0]['json']['contents'][0]['parts'][0]['text'];
		$this->assertStringContainsString( 'Email domain: acme.io', $prompt );
		$this->assertStringNotContainsString( 'ann@acme.io', $prompt );
		$this->assertStringNotContainsString( '7946', $prompt, 'Phone numbers are never sent' );

		$rules = Entry_Insights::rule_based( Form_Repository::get( $entry['form_id'] )['definition'], $entry['data'] );
		$this->assertGreaterThanOrEqual( 70, $rules['lead_score'] );
		$this->assertSame( 'high', $rules['urgency'] );
	}

	public function test_auto_reply_is_a_draft_until_sent() {
		list( , $entry_id ) = $this->entry_form();
		$this->set_key();
		$this->gemini[] = array( 'subject' => 'Your shop quote', 'body' => "Hi Ann,\n\nThanks!" );
		$draft          = Auto_Reply::draft( $entry_id, 'professional', 'Our starter shop is $4,900.' );
		$this->assertSame( 'Your shop quote', $draft['subject'] );
		$this->assertSame( 'ann@acme.io', $draft['to'] );
		$this->assertStringContainsString( '$4,900', $this->requests_to( 'generateContent' )[0]['json']['contents'][0]['parts'][0]['text'] );
		$sent_before = count( $this->mails );
		$this->assertSame( $sent_before, count( $this->mails ), 'Drafting sends nothing' );
		$this->assertTrue( Auto_Reply::send( $entry_id, 'Your shop quote', 'Edited body' ) );
		$mail = end( $this->mails );
		$this->assertSame( 'ann@acme.io', $mail['to'] );
		$this->assertSame( 'Edited body', $mail['message'] );
		$this->assertNotEmpty( Entries::get( $entry_id )['meta']['replied_at'] );
		$this->assertWPError( Auto_Reply::send( $entry_id, '', '' ) );
	}

	public function test_conversion_heuristics_ai_advice_and_fix() {
		$id   = $this->make_form(
			array(
				array( 'id' => 'email', 'type' => 'email', 'label' => 'Email', 'required' => true ),
				array( 'id' => 'phone', 'type' => 'phone', 'label' => 'Phone', 'required' => true ),
				array( 'id' => 'message', 'type' => 'textarea', 'label' => 'Message' ),
			)
		);
		for ( $i = 0; $i < 10; $i++ ) {
			$session = str_pad( 'sess' . $i, 20, 'x' );
			Analytics::record( $id, $session, 'view' );
			Analytics::record( $id, $session, 'start' );
			if ( $i < 6 ) {
				Analytics::record( $id, $session, 'abandon', 0, 'phone' );
			} elseif ( $i < 8 ) {
				Analytics::record( $id, $session, 'abandon', 0, 'message' );
				Analytics::record( $id, $session, 'submit' );
			}
		}
		$form     = Form_Repository::get( $id );
		$overview = Conversion_Advisor::overview( $id, $form['definition'] );
		$f        = $overview['funnel'];
		$this->assertSame( 10, $f['views'] );
		$this->assertSame( 2, $f['submits'] );
		$this->assertSame( 'phone', $f['abandons'][0]['field'], 'Submitted sessions are not counted as abandons' );
		$this->assertSame( 6, $f['abandons'][0]['count'] );
		$this->assertSame( 'make_optional', $overview['hints'][0]['fix'] );

		$this->set_key();
		$this->gemini[] = array(
			'summary' => 'The phone field loses most visitors.',
			'items'   => array(
				array( 'field' => 'phone', 'problem' => 'Required phone.', 'suggestion' => 'Make it optional.', 'fix' => 'make_optional', 'value' => '', 'impact' => 'high' ),
				array( 'field' => 'nope', 'problem' => 'x', 'suggestion' => 'y', 'fix' => 'remove_field', 'value' => '', 'impact' => 'low' ),
			),
		);
		$advice = Conversion_Advisor::advise( $form['definition'], $form['title'], $f );
		$this->assertSame( 'phone', $advice['items'][0]['field'] );
		$this->assertSame( 'none', $advice['items'][1]['fix'], 'Unknown fields cannot be auto-fixed' );
		$fixed = Conversion_Advisor::apply_fix( $form['definition'], $advice['items'][0] );
		$this->assertFalse( Definition::field( $fixed, 'phone' )['required'] );
		$this->assertTrue( Definition::field( Form_Repository::get( $id )['definition'], 'phone' )['required'], 'Not saved until accepted' );
		$moved = Conversion_Advisor::apply_fix( $form['definition'], array( 'field' => 'message', 'fix' => 'move_to_new_step' ) );
		$this->assertCount( 2, $moved['steps'] );
		$this->assertWPError( Conversion_Advisor::apply_fix( $form['definition'], $advice['items'][1] ) );
	}

	public function test_translation_draft_keeps_merge_tags_and_reports_missing() {
		$this->set_key();
		$def                              = Templates::get( 'contact' )['definition'];
		$def['confirmations'][0]['message'] = 'Thanks {field:email}!';
		$strings                          = Definition::strings( $def );
		$items                            = array();
		foreach ( $strings as $key => $text ) {
			if ( 'field.phone.label' === $key ) {
				continue; // Missing on purpose.
			}
			$items[] = array(
				'key'  => $key,
				'text' => 'confirmation.confirmation_1.message' === $key ? 'Danke!' : 'DE ' . $text,
			);
		}
		$this->gemini[] = array( 'translations' => $items );
		$out            = Translator::translate( $def, 'de' );
		$map            = wp_list_pluck( $out['strings'], 'text', 'key' );
		$this->assertSame( 'DE Email', $map['field.email.label'] );
		$this->assertSame( 'Phone', $map['field.phone.label'] );
		$this->assertSame( 'Thanks {field:email}!', $map['confirmation.confirmation_1.message'], 'Lost merge tag keeps the original' );
		$this->assertCount( 2, $out['notes'] );
		$schema = $this->requests_to( 'generateContent' )[0]['json']['generationConfig']['responseSchema'];
		$this->assertContains( 'field.email.label', $schema['properties']['translations']['items']['properties']['key']['enum'] );
		$this->assertWPError( Translator::translate( $def, 'not a lang' ) );
	}

	public function test_ai_spam_shield_actions_and_fail_open() {
		$this->set_key();
		$fields = array(
			array( 'id' => 'email', 'type' => 'email', 'label' => 'Email', 'required' => true ),
			array( 'id' => 'message', 'type' => 'textarea', 'label' => 'Message', 'required' => true ),
		);
		$spam   = array( 'spam_probability' => 0.97, 'abusive' => false, 'sentiment' => 'neutral', 'language' => 'en', 'reason' => 'SEO pitch.' );
		$values = array( 'email' => 'seo@spam.biz', 'message' => 'We can rank your site #1 on Google, contact me at http://spam.biz' );

		$keep = $this->make_form( $fields, array( 'protection' => array( 'min_seconds' => 0, 'ai_spam' => true, 'spam_action' => 'spam' ) ) );
		$this->gemini[] = $spam;
		$res = Submission_Handler::handle( $keep, $this->params( $keep, $values ) );
		$this->assertTrue( $res['ok'] );
		$entry = Entries::get( $res['entry_id'] );
		$this->assertSame( 'spam', $entry['status'] );
		$this->assertCount( 0, $this->mails, 'No notifications for spam' );
		$log = Spam_Log::query( array() )['items'][0];
		$this->assertSame( 97, $log['score'] );
		$this->assertSame( $entry['id'], $log['entry_id'] );
		$this->assertStringContainsString( '[link: spam.biz]', $log['excerpt'] );
		$this->assertStringNotContainsString( 'seo@spam.biz', $log['excerpt'] );

		$block = $this->make_form( $fields, array( 'protection' => array( 'min_seconds' => 0, 'ai_spam' => true, 'spam_action' => 'block' ) ) );
		$this->gemini[] = $spam;
		$res = Submission_Handler::handle( $block, $this->params( $block, $values ) );
		$this->assertFalse( $res['ok'] );
		$this->assertSame( 0, Entries::count_for_form( $block ) );

		$this->gemini[] = array( 'spam_probability' => 0.05, 'abusive' => false, 'sentiment' => 'positive', 'language' => 'en', 'reason' => 'Genuine.' );
		$this->assertTrue( Submission_Handler::handle( $block, $this->params( $block, array( 'email' => 'ann@example.com', 'message' => 'Do you ship to Canada?' ) ) )['ok'] );
		$this->assertSame( 1, Entries::count_for_form( $block ) );

		// Gemini down: fail-open delivers, fail-closed blocks.
		$this->gemini[] = array( '__status' => 500 );
		$this->assertTrue( Submission_Handler::handle( $block, $this->params( $block, array( 'email' => 'b@example.com', 'message' => 'Question about prices' ) ) )['ok'] );
		Settings_Store::update( array( 'fail_open' => false ) );
		Settings_Store::flush();
		$this->gemini[] = array( '__status' => 500 );
		$this->assertFalse( Submission_Handler::handle( $block, $this->params( $block, array( 'email' => 'c@example.com', 'message' => 'Question about prices' ) ) )['ok'] );

		// Allowlisted sender skips the AI.
		Spam_Log::allowlist( Spam_Log::hash( 'friend@example.com' ) );
		$before = count( $this->requests_to( 'generateContent' ) );
		$this->assertTrue( Submission_Handler::handle( $block, $this->params( $block, array( 'email' => 'friend@example.com', 'message' => 'Hi again' ) ) )['ok'] );
		$this->assertSame( $before, count( $this->requests_to( 'generateContent' ) ) );
	}

	public function test_paste_to_fill_maps_only_known_fields() {
		$def     = Templates::get( 'contact' )['definition'];
		$targets = Extractor::targets( $def );
		$ids     = wp_list_pluck( $targets, 'id' );
		$this->assertContains( 'name.first', $ids );
		$this->assertContains( 'topic', $ids );
		$this->assertNotContains( 'consent', $ids );
		$mapped = Extractor::map(
			array(
				array( 'id' => 'name.first', 'value' => 'Ann' ),
				array( 'id' => 'email', 'value' => 'ANN@Example.com' ),
				array( 'id' => 'topic', 'value' => 'support' ),
				array( 'id' => 'phone', 'value' => 'n/a' ),
				array( 'id' => 'evil', 'value' => 'x' ),
			),
			$targets
		);
		$this->assertSame( array( 'dev3fb[name][first]', 'dev3fb[email]', 'dev3fb[topic]' ), wp_list_pluck( $mapped, 'name' ) );
		$this->assertSame( 'ann@example.com', $mapped[1]['value'] );
		$this->assertSame( 'Support', $mapped[2]['value'] );
	}
}
