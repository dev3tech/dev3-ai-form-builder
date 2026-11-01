<?php
/**
 * Definition normalizer: the single gate for builder saves, templates, imports and AI drafts.
 *
 * @package Dev3\AiFormBuilder
 */

use Dev3\AiFormBuilder\Forms\Definition;
use Dev3\AiFormBuilder\Forms\Form_Repository;
use Dev3\AiFormBuilder\Forms\Templates;

class DefinitionTest extends WP_UnitTestCase {

	public function test_registry_has_35_types() {
		$this->assertGreaterThanOrEqual( 30, count( Definition::types() ) );
		foreach ( array( 'text', 'email', 'file', 'signature', 'calculation', 'product', 'total', 'nps', 'address', 'consent' ) as $type ) {
			$this->assertArrayHasKey( $type, Definition::types() );
		}
	}

	public function test_normalize_repairs_and_reports() {
		$fixes = array();
		$def   = Definition::normalize(
			array(
				'steps' => array(
					array(
						'fields' => array(
							array( 'key' => 'Email Address', 'type' => 'e-mail', 'label' => 'Email' ),
							array( 'key' => 'email address', 'type' => 'dropdown', 'label' => 'Topic' ),
							array( 'key' => 'other', 'type' => 'text', 'label' => 'Other', 'logic' => array( 'rules' => array( array( 'field' => 'later', 'op' => 'is', 'value' => 'x' ), array( 'field' => 'email_address_2', 'operator' => 'equals', 'value' => 'Sales' ) ) ) ),
							array( 'key' => 'later', 'type' => 'radio', 'label' => 'Later', 'options' => array( 'A', 'B', 'A' ) ),
							array( 'key' => 'calc', 'type' => 'calculation', 'label' => 'Calc', 'formula' => '{nope} * 2' ),
							array( 'type' => 'heading', 'label' => '<b>Title</b>', 'required' => true ),
						),
					),
					array( 'fields' => array() ),
				),
			),
			$fixes
		);
		$fields = Definition::fields( $def );
		$this->assertSame( array( 'email_address', 'email_address_2', 'other', 'later', 'calc', 'title' ), wp_list_pluck( $fields, 'id' ) );
		$this->assertSame( 'text', $fields[0]['type'], 'Unknown type falls back to text' );
		$this->assertSame( 'select', $fields[1]['type'], 'Alias dropdown → select' );
		$this->assertCount( 2, $fields[1]['options'], 'Placeholder options added to an empty choice field' );
		$this->assertSame( array( array( 'field' => 'email_address_2', 'op' => 'is', 'value' => 'Sales' ) ), $fields[2]['logic']['rules'], 'Rules pointing to later fields are removed' );
		$this->assertCount( 2, $fields[3]['options'], 'Duplicate options removed' );
		$this->assertSame( '', $fields[4]['formula'], 'Formula with unknown reference cleared' );
		$this->assertFalse( $fields[5]['required'], 'Layout fields are never required' );
		$this->assertCount( 1, $def['steps'], 'Empty steps dropped' );
		$this->assertGreaterThanOrEqual( 4, count( $fixes ) );
		$this->assertCount( 1, $def['confirmations'] );
		$this->assertSame( '{admin_email}', $def['notifications'][0]['to'] );
	}

	public function test_sanitizes_dangerous_input() {
		$def = Definition::normalize(
			array(
				'steps'    => array(
					array(
						'fields' => array(
							array( 'type' => 'paragraph', 'content' => '<p>Hi</p><script>alert(1)</script>' ),
							array( 'type' => 'file', 'label' => 'CV', 'accept' => 'pdf, PHP ,exe', 'max_size' => 9999 ),
						),
					),
				),
				'settings' => array( 'accent' => 'red;}', 'css_class' => 'a"><x b' ),
				'webhooks' => array( array( 'url' => 'javascript:alert(1)', 'enabled' => true ) ),
			)
		);
		$fields = Definition::fields( $def );
		$this->assertStringNotContainsString( '<script', $fields[0]['content'] );
		$this->assertSame( 'pdf,php,exe', $fields[1]['accept'] );
		$this->assertSame( 512.0, (float) $fields[1]['max_size'] );
		$this->assertSame( '', $def['settings']['accent'] );
		$this->assertStringNotContainsString( '"', $def['settings']['css_class'] );
		$this->assertFalse( $def['webhooks'][0]['enabled'] );
	}

	public function test_translations_strings_and_apply() {
		$def     = Templates::get( 'contact' )['definition'];
		$strings = Definition::strings( $def );
		$this->assertArrayHasKey( 'field.email.label', $strings );
		$this->assertArrayHasKey( 'settings.submit_label', $strings );
		$this->assertArrayHasKey( 'field.topic.option_0', $strings );
		$def['translations'] = array(
			'de' => array(
				'field.email.label'     => 'E-Mail',
				'field.topic.option_0'  => 'Allgemeine Frage',
				'settings.submit_label' => 'Senden',
				'bad key!'              => 'x',
			),
		);
		$def = Definition::normalize( $def );
		$this->assertArrayNotHasKey( 'bad key!', $def['translations']['de'] );
		$de  = Definition::translate( $def, 'de_DE' );
		$this->assertSame( 'de', $de['lang'] );
		$this->assertSame( 'E-Mail', Definition::field( $de, 'email' )['label'] );
		$this->assertSame( 'Senden', $de['settings']['submit_label'] );
		$topic = Definition::field( $de, 'topic' );
		$this->assertSame( 'Allgemeine Frage', $topic['options'][0]['label'] );
		$this->assertSame( Definition::field( $def, 'topic' )['options'][0]['value'], $topic['options'][0]['value'], 'Values stay the same' );
	}

	public function test_templates_and_repository() {
		$this->assertGreaterThanOrEqual( 8, count( Templates::summaries() ) );
		foreach ( Templates::summaries() as $summary ) {
			$this->assertGreaterThan( 0, $summary['fields'], $summary['id'] );
		}
		$id   = Form_Repository::save(
			array(
				'title'      => 'Quote',
				'definition' => Templates::get( 'quote' )['definition'],
			)
		);
		$form = Form_Repository::get( $id );
		$this->assertSame( 'Quote', $form['title'] );
		$this->assertCount( 3, $form['definition']['steps'] );
		$this->assertSame( 'show', Definition::field( $form['definition'], 'service_other' )['logic']['action'] );
		$copy = Form_Repository::duplicate( $id );
		$this->assertSame( 'draft', Form_Repository::get( $copy )['status'] );
		$this->assertTrue( Form_Repository::delete( $copy ) );
		$this->assertNull( Form_Repository::get( $copy ) );
	}
}
