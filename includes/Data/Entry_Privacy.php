<?php
/**
 * GDPR exporter/eraser for form entries (matched by the entry's email address).
 *
 * @package Dev3\AiFormBuilder
 */

namespace Dev3\AiFormBuilder\Data;

use Dev3\AiFormBuilder\Core3\Core\Privacy;
use Dev3\AiFormBuilder\Forms\Form_Repository;
use Dev3\AiFormBuilder\Submission\Entry_Format;

defined( 'ABSPATH' ) || exit;

/**
 * Privacy tools.
 */
class Entry_Privacy {

	/**
	 * Registers the exporter and eraser.
	 */
	public static function register() {
		Privacy::add_exporter( __( 'Form entries', 'dev3-ai-form-builder' ), array( __CLASS__, 'export' ) );
		Privacy::add_eraser( __( 'Form entries', 'dev3-ai-form-builder' ), array( __CLASS__, 'erase' ) );
	}

	/**
	 * Entry ids for an email.
	 *
	 * @param string $email Email.
	 * @param int    $page  Page (1-based).
	 * @return array [ ids, done ]
	 */
	private static function ids( $email, $page ) {
		global $wpdb;
		$table = Entries::table();
		$ids   = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$table} WHERE email = %s ORDER BY id LIMIT 50 OFFSET %d", (string) $email, max( 0, ( (int) $page - 1 ) * 50 ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return array( array_map( 'intval', (array) $ids ), count( (array) $ids ) < 50 );
	}

	/**
	 * Exporter callback.
	 *
	 * @param string $email Email.
	 * @param int    $page  Page.
	 * @return array
	 */
	public static function export( $email, $page = 1 ) {
		list( $ids, $done ) = self::ids( $email, $page );
		$items              = array();
		foreach ( $ids as $id ) {
			$entry = Entries::get( $id );
			$form  = $entry ? Form_Repository::get( $entry['form_id'] ) : null;
			if ( ! $entry || ! $form ) {
				continue;
			}
			$data = array(
				array(
					'name'  => __( 'Form', 'dev3-ai-form-builder' ),
					'value' => $form['title'],
				),
				array(
					'name'  => __( 'Submitted', 'dev3-ai-form-builder' ),
					'value' => $entry['created_at'],
				),
			);
			foreach ( Entry_Format::rows( $form['definition'], $entry['data'] ) as $row ) {
				$data[] = array(
					'name'  => $row['label'],
					'value' => $row['value'],
				);
			}
			$items[] = array(
				'group_id'    => 'dev3fb-entries',
				'group_label' => __( 'Form entries', 'dev3-ai-form-builder' ),
				'item_id'     => 'dev3fb-entry-' . $id,
				'data'        => $data,
			);
		}
		return array(
			'data' => $items,
			'done' => $done,
		);
	}

	/**
	 * Eraser callback (deletes the entries and their files).
	 *
	 * @param string $email Email.
	 * @param int    $page  Page.
	 * @return array
	 */
	public static function erase( $email, $page = 1 ) {
		unset( $page );
		list( $ids, $done ) = self::ids( $email, 1 );
		$removed            = 0;
		foreach ( $ids as $id ) {
			$removed += Entries::delete( $id ) ? 1 : 0;
		}
		return array(
			'items_removed'  => $removed > 0,
			'items_retained' => false,
			'messages'       => array(),
			'done'           => $done,
		);
	}
}
