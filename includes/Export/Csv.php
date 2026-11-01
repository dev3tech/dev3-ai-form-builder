<?php
/**
 * CSV export of entries (UTF-8 BOM for Excel; formula injection neutralised).
 *
 * @package Dev3\AiFormBuilder
 */

namespace Dev3\AiFormBuilder\Export;

use Dev3\AiFormBuilder\Forms\Definition;
use Dev3\AiFormBuilder\Submission\Entry_Format;

defined( 'ABSPATH' ) || exit;

/**
 * CSV.
 */
class Csv {

	/**
	 * Escapes one cell (prevents =, +, -, @ formula injection in spreadsheets).
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	public static function cell( $value ) {
		$value = is_scalar( $value ) ? (string) $value : '';
		if ( '' !== $value && in_array( $value[0], array( '=', '+', '-', '@', "\t", "\r" ), true ) && ! is_numeric( $value ) ) {
			$value = "'" . $value;
		}
		return '"' . str_replace( '"', '""', $value ) . '"';
	}

	/**
	 * Builds the CSV.
	 *
	 * @param array $form    Form.
	 * @param array $entries Entries.
	 * @return string
	 */
	public static function build( array $form, array $entries ) {
		$fields = array_values(
			array_filter(
				Definition::input_fields( $form['definition'] ),
				static function ( $field ) {
					return 'password' !== $field['type'];
				}
			)
		);
		$header = array( 'Entry ID', 'Submitted (UTC)', 'Status' );
		foreach ( $fields as $field ) {
			$header[] = '' !== $field['label'] ? $field['label'] : $field['id'];
		}
		$extra  = array( 'Payment status', 'Payment total', 'Currency', 'Lead score', 'Sentiment', 'Intent', 'AI summary' );
		$header = array_merge( $header, $extra );
		$lines  = array( implode( ',', array_map( array( __CLASS__, 'cell' ), $header ) ) );
		foreach ( $entries as $entry ) {
			$row = array( $entry['id'], gmdate( 'Y-m-d H:i:s', strtotime( $entry['created_at'] ) ), $entry['status'] );
			foreach ( $fields as $field ) {
				$row[] = Entry_Format::display( $field, isset( $entry['data'][ $field['id'] ] ) ? $entry['data'][ $field['id'] ] : '' );
			}
			$row[]   = $entry['payment_status'];
			$row[]   = $entry['payment_total'] > 0 ? number_format( $entry['payment_total'], 2, '.', '' ) : '';
			$row[]   = $entry['payment_currency'];
			$row[]   = null === $entry['lead_score'] ? '' : $entry['lead_score'];
			$row[]   = $entry['sentiment'];
			$row[]   = isset( $entry['ai']['intent'] ) ? $entry['ai']['intent'] : '';
			$row[]   = isset( $entry['ai']['summary'] ) ? $entry['ai']['summary'] : '';
			$lines[] = implode( ',', array_map( array( __CLASS__, 'cell' ), $row ) );
		}
		return "\xEF\xBB\xBF" . implode( "\r\n", $lines ) . "\r\n";
	}
}
