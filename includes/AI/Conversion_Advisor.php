<?php
/**
 * Form conversion advice: which field causes drop-off, with a suggested fix.
 * Rule-based hints always work; DEV3 AI adds a written diagnosis and concrete fixes.
 * A fix is applied only when the admin clicks "Apply fix" (the builder then saves it).
 *
 * @package Dev3\AiFormBuilder
 */

namespace Dev3\AiFormBuilder\AI;

use Dev3\AiFormBuilder\Core3\Api\Prompts;
use Dev3\AiFormBuilder\Data\Analytics;
use Dev3\AiFormBuilder\Forms\Definition;

defined( 'ABSPATH' ) || exit;

/**
 * Advisor.
 */
class Conversion_Advisor {

	/**
	 * Rule-based hints from the funnel.
	 *
	 * @param array $definition Definition.
	 * @param array $funnel     Analytics::funnel().
	 * @return array [ { field, problem, suggestion, fix, value, impact, source } ]
	 */
	public static function heuristics( array $definition, array $funnel ) {
		$items  = array();
		$fields = array();
		foreach ( Definition::fields( $definition ) as $field ) {
			$fields[ $field['id'] ] = $field;
		}
		foreach ( $funnel['abandons'] as $row ) {
			if ( ! isset( $fields[ $row['field'] ] ) || $row['count'] < 3 || $row['share'] < 0.2 ) {
				continue;
			}
			$field = $fields[ $row['field'] ];
			$pct   = (int) round( $row['share'] * 100 );
			if ( $field['required'] && in_array( $field['type'], array( 'phone', 'address', 'file', 'signature', 'number', 'url' ), true ) ) {
				$items[] = self::item(
					$field['id'],
					/* translators: 1: percent, 2: field label */
					sprintf( __( '%1$d%% of visitors who gave up stopped at "%2$s".', 'dev3-ai-form-builder' ), $pct, $field['label'] ),
					__( 'Make this field optional, or explain why you need it.', 'dev3-ai-form-builder' ),
					'make_optional',
					'',
					$pct >= 40 ? 'high' : 'medium'
				);
			} elseif ( '' === $field['help'] ) {
				$items[] = self::item(
					$field['id'],
					/* translators: 1: percent, 2: field label */
					sprintf( __( '%1$d%% of visitors who gave up stopped at "%2$s".', 'dev3-ai-form-builder' ), $pct, $field['label'] ),
					__( 'Add a short help text so visitors know what to enter.', 'dev3-ai-form-builder' ),
					'add_help',
					'',
					$pct >= 40 ? 'high' : 'medium'
				);
			}
		}
		$count = count( Definition::input_fields( $definition, false ) );
		if ( $count >= 10 && 1 === count( $definition['steps'] ) ) {
			$items[] = self::item(
				'',
				/* translators: %d: number of fields */
				sprintf( __( 'This form shows %d fields on one page.', 'dev3-ai-form-builder' ), $count ),
				__( 'Split it into 2-3 steps; multi-step forms usually convert better when they are long.', 'dev3-ai-form-builder' ),
				'none',
				'',
				'medium'
			);
		}
		if ( $funnel['views'] >= 20 && $funnel['start_rate'] < 0.15 ) {
			$items[] = self::item(
				'',
				/* translators: %d: percent */
				sprintf( __( 'Only %d%% of visitors start the form.', 'dev3-ai-form-builder' ), (int) round( $funnel['start_rate'] * 100 ) ),
				__( 'Make the first question easy (a choice instead of free text) and place the form higher on the page.', 'dev3-ai-form-builder' ),
				'none',
				'',
				'medium'
			);
		}
		return array_slice( $items, 0, 6 );
	}

	/**
	 * Item shape.
	 *
	 * @param string $field      Field id.
	 * @param string $problem    Problem.
	 * @param string $suggestion Suggestion.
	 * @param string $fix        Fix type.
	 * @param string $value      Fix value.
	 * @param string $impact     Impact.
	 * @param string $source     rules|ai.
	 * @return array
	 */
	private static function item( $field, $problem, $suggestion, $fix, $value, $impact, $source = 'rules' ) {
		return array(
			'field'      => $field,
			'problem'    => $problem,
			'suggestion' => $suggestion,
			'fix'        => $fix,
			'value'      => $value,
			'impact'     => $impact,
			'source'     => $source,
		);
	}

	/**
	 * AI advice.
	 *
	 * @param array  $definition Definition.
	 * @param string $title      Form title.
	 * @param array  $funnel     Funnel.
	 * @return array|\WP_Error { summary, items, model, tokens }
	 */
	public static function advise( array $definition, $title, array $funnel ) {
		$lines = array();
		foreach ( $definition['steps'] as $i => $step ) {
			foreach ( $step['fields'] as $field ) {
				if ( 'content' === Definition::kind( $field['type'] ) ) {
					continue;
				}
				$lines[] = sprintf( '- %s | %s | %s | %s | step %d', $field['id'], $field['type'], $field['label'], $field['required'] ? 'required' : 'optional', $i + 1 );
			}
		}
		$summary = array(
			'views'           => $funnel['views'],
			'starts'          => $funnel['starts'],
			'submits'         => $funnel['submits'],
			'start_rate'      => $funnel['start_rate'],
			'completion_rate' => $funnel['completion_rate'],
			'steps_reached'   => $funnel['steps'],
			'abandoned_at'    => $funnel['abandons'],
		);
		$result  = Prompts::run(
			'conversion_advice',
			array(
				'form'           => $title,
				'days'           => $funnel['days'],
				'funnel'         => $summary,
				'fields'         => implode( "\n", $lines ),
				'admin_language' => Form_Generator::language_name( get_locale() ),
			),
			'admin',
			array( 'max_tokens' => 3000 )
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$data  = isset( $result['data'] ) && is_array( $result['data'] ) ? $result['data'] : array();
		$known = wp_list_pluck( Definition::fields( $definition ), 'id' );
		$items = array();
		foreach ( isset( $data['items'] ) && is_array( $data['items'] ) ? $data['items'] : array() as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$field = isset( $item['field'] ) ? sanitize_key( (string) $item['field'] ) : '';
			$fix   = isset( $item['fix'] ) && in_array( $item['fix'], Prompt_Library::FIXES, true ) ? $item['fix'] : 'none';
			if ( '' !== $field && ! in_array( $field, $known, true ) ) {
				$field = '';
			}
			if ( '' === $field ) {
				$fix = 'none';
			}
			$items[] = self::item(
				$field,
				mb_substr( sanitize_text_field( isset( $item['problem'] ) ? (string) $item['problem'] : '' ), 0, 240 ),
				mb_substr( sanitize_text_field( isset( $item['suggestion'] ) ? (string) $item['suggestion'] : '' ), 0, 300 ),
				$fix,
				mb_substr( sanitize_text_field( isset( $item['value'] ) ? (string) $item['value'] : '' ), 0, 200 ),
				isset( $item['impact'] ) && in_array( $item['impact'], array( 'high', 'medium', 'low' ), true ) ? $item['impact'] : 'medium',
				'ai'
			);
		}
		return array(
			'summary' => mb_substr( sanitize_text_field( isset( $data['summary'] ) ? (string) $data['summary'] : '' ), 0, 500 ),
			'items'   => array_slice( $items, 0, 5 ),
			'model'   => isset( $result['model'] ) ? $result['model'] : '',
			'tokens'  => isset( $result['usage']['total'] ) ? (int) $result['usage']['total'] : 0,
		);
	}

	/**
	 * Applies one fix to a definition (pure; the caller saves).
	 *
	 * @param array $definition Definition.
	 * @param array $item       Advice item.
	 * @return array|\WP_Error New definition.
	 */
	public static function apply_fix( array $definition, array $item ) {
		$field_id = isset( $item['field'] ) ? (string) $item['field'] : '';
		$fix      = isset( $item['fix'] ) ? (string) $item['fix'] : 'none';
		$value    = isset( $item['value'] ) ? (string) $item['value'] : '';
		if ( '' === $field_id || 'none' === $fix || ! Definition::field( $definition, $field_id ) ) {
			return new \WP_Error( 'dev3fb_no_fix', __( 'This suggestion has no automatic fix.', 'dev3-ai-form-builder' ), array( 'status' => 400 ) );
		}
		foreach ( $definition['steps'] as $s => $step ) {
			foreach ( $step['fields'] as $f => $field ) {
				if ( $field['id'] !== $field_id ) {
					continue;
				}
				$target = &$definition['steps'][ $s ]['fields'][ $f ];
				switch ( $fix ) {
					case 'make_optional':
						$target['required'] = false;
						break;
					case 'change_label':
						if ( '' !== $value ) {
							$target['label'] = $value;
						}
						break;
					case 'change_placeholder':
						$target['placeholder'] = $value;
						break;
					case 'add_help':
						$target['help'] = '' !== $value ? $value : __( 'Why we ask: this helps us answer you faster.', 'dev3-ai-form-builder' );
						break;
					case 'change_type':
						$types = Definition::types();
						if ( isset( $types[ $value ] ) ) {
							$target['type'] = $value;
						}
						break;
					case 'remove_field':
						array_splice( $definition['steps'][ $s ]['fields'], $f, 1 );
						break;
					case 'move_to_new_step':
						$moved = $definition['steps'][ $s ]['fields'][ $f ];
						array_splice( $definition['steps'][ $s ]['fields'], $f, 1 );
						$definition['steps'][] = array(
							'id'          => 'step_' . ( count( $definition['steps'] ) + 1 ),
							'title'       => __( 'A few more details', 'dev3-ai-form-builder' ),
							'description' => '',
							'logic'       => null,
							'fields'      => array( $moved ),
						);
						break;
				}
				unset( $target );
				return Definition::normalize( $definition );
			}
		}
		return Definition::normalize( $definition );
	}

	/**
	 * Funnel + rule-based hints for a form.
	 *
	 * @param int   $form_id    Form id.
	 * @param array $definition Definition.
	 * @param int   $days       Days.
	 * @return array { funnel, hints }
	 */
	public static function overview( $form_id, array $definition, $days = 30 ) {
		$funnel = Analytics::funnel( $form_id, $days );
		$labels = array();
		foreach ( Definition::fields( $definition ) as $field ) {
			$labels[ $field['id'] ] = $field['label'];
		}
		foreach ( $funnel['abandons'] as $i => $row ) {
			$funnel['abandons'][ $i ]['label'] = isset( $labels[ $row['field'] ] ) ? $labels[ $row['field'] ] : ( '' === $row['field'] ? __( '(before any field)', 'dev3-ai-form-builder' ) : $row['field'] );
		}
		return array(
			'funnel' => $funnel,
			'hints'  => self::heuristics( $definition, $funnel ),
		);
	}
}
