<?php
/**
 * Front-end markup: every field type renders, escaping, logic-hidden fields, translations,
 * shortcode/block and closed forms.
 *
 * @package Dev3\AiFormBuilder
 */

use Dev3\AiFormBuilder\Forms\Definition;
use Dev3\AiFormBuilder\Forms\Form_Repository;
use Dev3\AiFormBuilder\Frontend\Frontend;
use Dev3\AiFormBuilder\Frontend\Renderer;

class RendererTest extends Dev3fb_TestCase {

	public function test_every_field_type_renders() {
		$fields = array();
		foreach ( array_keys( Definition::types() ) as $type ) {
			$fields[] = array(
				'id'      => 'f_' . $type,
				'type'    => $type,
				'label'   => 'Label <script>' . $type,
				'options' => array( 'One', 'Two' ),
			);
		}
		$id   = $this->make_form( $fields, array( 'payments' => array( 'enabled' => true, 'currency' => 'EUR' ) ) );
		$html = Renderer::render( Form_Repository::get( $id ) );
		$this->assertStringNotContainsString( '<script>', $html );
		foreach ( array_keys( Definition::types() ) as $type ) {
			$this->assertStringContainsString( 'data-field="f_' . $type . '"', $html, $type );
		}
		$this->assertStringContainsString( 'name="dev3fb[f_name][first]"', $html );
		$this->assertStringContainsString( 'name="dev3fb[f_checkbox][]"', $html );
		$this->assertStringContainsString( 'name="dev3fb_files_f_file[]"', $html );
		$this->assertStringContainsString( '<canvas class="dev3fb-signature-pad"', $html );
		$this->assertStringContainsString( 'role="switch"', $html );
		$this->assertStringContainsString( '<option value="DE"', $html );
		$this->assertStringContainsString( 'name="dev3fb_hp"', $html );
		$this->assertStringContainsString( 'data-dev3fb="', $html );
		$this->assertMatchesRegularExpression( '/name="dev3fb_token" value="\d+\.[a-f0-9]{32}"/', $html );
		$this->assertStringNotContainsString( 'DEV3', $html, 'Visitor-facing markup is unbranded' );
	}

	public function test_logic_hidden_steps_and_fields_and_translation() {
		$id = $this->make_form(
			array(
				'steps'        => array(
					array(
						'title'  => 'Start',
						'fields' => array(
							array( 'id' => 'kind', 'type' => 'radio', 'label' => 'Kind', 'options' => array( 'A', 'B' ), 'default' => 'A' ),
							array( 'id' => 'b_only', 'type' => 'text', 'label' => 'Only B', 'logic' => array( 'rules' => array( array( 'field' => 'kind', 'op' => 'is', 'value' => 'B' ) ) ) ),
						),
					),
					array(
						'title'  => 'Second',
						'fields' => array( array( 'id' => 'email', 'type' => 'email', 'label' => 'Email' ) ),
					),
				),
				'settings'     => array( 'progress' => 'steps' ),
				'translations' => array( 'fr' => array( 'field.email.label' => 'Courriel', 'step.step_1.title' => 'Début' ) ),
			)
		);
		$form = Form_Repository::get( $id );
		$html = Renderer::render( $form );
		$this->assertMatchesRegularExpression( '/data-field="b_only" data-type="text" hidden/', $html );
		$this->assertStringContainsString( 'dev3fb-step--later', $html );
		$this->assertStringContainsString( 'dev3fb-progress-steps', $html );
		$this->assertStringContainsString( 'type="button" class="dev3fb-btn dev3fb-next"', $html );
		$this->assertSame( 'fr', Frontend::language( $form['definition'], 'fr' ) );
		$fr = do_shortcode( '[dev3fb_form id="' . $id . '" lang="fr"]' );
		$this->assertStringContainsString( 'Courriel', $fr );
		$this->assertStringContainsString( 'Début', $fr );
		$this->assertStringContainsString( 'name="dev3fb_lang" value="fr"', $fr );
	}

	public function test_shortcode_block_draft_and_closed() {
		$id = $this->make_form( array( array( 'id' => 'email', 'type' => 'email', 'label' => 'Email' ) ), array( 'settings' => array( 'close_at' => '2000-01-01 00:00', 'closed_message' => 'Sorry, closed.' ) ) );
		$this->assertStringContainsString( 'Sorry, closed.', do_shortcode( '[dev3fb_form id="' . $id . '"]' ) );
		$this->assertSame( '', do_shortcode( '[dev3fb_form id="999999"]' ) );
		$open = $this->make_form( array( array( 'id' => 'email', 'type' => 'email', 'label' => 'Email' ) ) );
		$this->assertStringContainsString( 'wp-block-dev3fb-form', Frontend::render_block( array( 'formId' => $open ) ) );
		$this->assertTrue( WP_Block_Type_Registry::get_instance()->is_registered( 'dev3fb/form' ) );
		Form_Repository::save( array( 'id' => $open, 'status' => 'draft' ) );
		$this->assertSame( '', do_shortcode( '[dev3fb_form id="' . $open . '"]' ), 'Drafts are hidden from visitors' );
		$this->admin();
		$this->assertStringContainsString( 'dev3fb-form', do_shortcode( '[dev3fb_form id="' . $open . '"]' ), 'Editors can preview drafts' );
	}
}
