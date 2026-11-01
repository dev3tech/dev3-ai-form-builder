<?php
/**
 * Logic, formulas and totals: the same JSON fixtures as tests/js/engine.test.js.
 *
 * @package Dev3\AiFormBuilder
 */

use Dev3\AiFormBuilder\Engine\Calculator;
use Dev3\AiFormBuilder\Engine\Formula;
use Dev3\AiFormBuilder\Engine\Logic;
use Dev3\AiFormBuilder\Engine\Merge_Tags;
use Dev3\AiFormBuilder\Forms\Definition;

class EngineTest extends WP_UnitTestCase {

	private static function fixture( $name ) {
		return json_decode( file_get_contents( dirname( __DIR__ ) . '/fixtures/' . $name ), true ); // phpcs:ignore
	}

	public function test_logic_compare_fixtures() {
		foreach ( self::fixture( 'logic-cases.json' )['compare'] as $case ) {
			$this->assertSame( $case['expected'], Logic::compare( $case['op'], $case['actual'], $case['value'] ), wp_json_encode( $case ) );
		}
	}

	public function test_logic_visible_and_evaluate_fixtures() {
		$fx = self::fixture( 'logic-cases.json' );
		foreach ( $fx['visible'] as $case ) {
			$this->assertSame( $case['expected'], Logic::visible( $case['logic'], $case['values'] ), wp_json_encode( $case ) );
		}
		foreach ( $fx['evaluate'] as $case ) {
			$out = Logic::evaluate( $case['definition'], $case['values'] );
			$this->assertSame( $case['fields'], $out['fields'] );
			$this->assertSame( $case['steps'], $out['steps'] );
			foreach ( $case['fields'] as $id => $visible ) {
				if ( ! $visible ) {
					$this->assertSame( '', $out['values'][ $id ] );
				}
			}
		}
	}

	public function test_formula_fixtures() {
		foreach ( self::fixture( 'formula-cases.json' ) as $case ) {
			$result = Formula::evaluate( $case['formula'], $case['vars'] );
			if ( $case['error'] ) {
				$this->assertWPError( $result, $case['formula'] );
				continue;
			}
			$this->assertEqualsWithDelta( $case['expected'], $result, 0.000001, $case['formula'] );
		}
		$this->assertSame( array( 'a', 'b' ), Formula::references( '{a}+{b}*{a}' ) );
	}

	public function test_compute_fixtures() {
		foreach ( self::fixture( 'compute-cases.json' ) as $case ) {
			$fields = array();
			foreach ( $case['fields'] as $f ) {
				$fields[] = array_merge( array( 'label' => $f['id'] ), $f );
			}
			$definition = array( 'steps' => array( array( 'id' => 's', 'fields' => $fields ) ) );
			$out        = Calculator::run( $definition, $case['values'], $case['visible'] );
			$this->assertEqualsWithDelta( $case['total'], $out['total'], 0.001, $case['name'] );
			foreach ( $case['calc'] as $id => $expected ) {
				$this->assertSame( $expected, $out['values'][ $id ], $case['name'] . ' ' . $id );
			}
		}
	}

	public function test_merge_tags() {
		$definition = Definition::normalize(
			array(
				'steps' => array(
					array(
						'fields' => array(
							array( 'id' => 'name', 'type' => 'name', 'label' => 'Name' ),
							array( 'id' => 'size', 'type' => 'select', 'label' => 'Size', 'options' => array( array( 'label' => 'Large', 'value' => 'l' ) ) ),
							array( 'id' => 'note', 'type' => 'textarea', 'label' => 'Note' ),
						),
					),
				),
			)
		);
		$context = array(
			'definition' => $definition,
			'data'       => array(
				'name' => array( 'first' => 'Ann', 'last' => 'Lee' ),
				'size' => 'l',
				'note' => '<b>hi</b>',
			),
			'entry_id'   => 3,
			'form_title' => 'Order',
		);
		$this->assertSame( 'Ann Lee wants Large (#3, Order)', Merge_Tags::replace( '{field:name} wants {field:size} (#{entry_id}, {form_title})', $context ) );
		$this->assertStringContainsString( '&lt;b&gt;hi&lt;/b&gt;', Merge_Tags::replace( '{field:note}', $context, 'html' ) );
		$this->assertStringContainsString( '<table', Merge_Tags::replace( '{all_fields}', $context, 'html' ) );
		$this->assertStringContainsString( 'Size: Large', Merge_Tags::replace( '{all_fields}', $context ) );

		$_GET['utm'] = 'mail<script>';
		$this->assertSame( 'mail', Merge_Tags::defaults( '{query:utm}' ) );
		wp_set_current_user( self::factory()->user->create( array( 'user_email' => 'u@example.com' ) ) );
		$this->assertSame( 'u@example.com', Merge_Tags::defaults( '{user:email}' ) );
		unset( $_GET['utm'] );
	}
}
