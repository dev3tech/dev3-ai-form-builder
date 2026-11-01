<?php
/**
 * Runs everything that happens after an entry is accepted: notifications, webhooks and
 * integrations. Payment forms run it when the payment completes.
 *
 * @package Dev3\AiFormBuilder
 */

namespace Dev3\AiFormBuilder\Delivery;

use Dev3\AiFormBuilder\Data\Entries;
use Dev3\AiFormBuilder\Forms\Form_Repository;

defined( 'ABSPATH' ) || exit;

/**
 * Dispatcher.
 */
class Dispatcher {

	/**
	 * Merge-tag context for an entry.
	 *
	 * @param array $form  Form.
	 * @param array $entry Entry.
	 * @return array
	 */
	public static function context( array $form, array $entry ) {
		return array(
			'definition'     => $form['definition'],
			'data'           => $entry['data'],
			'entry_id'       => $entry['id'],
			'form_id'        => $form['id'],
			'form_title'     => $form['title'],
			'total'          => $entry['payment_total'],
			'payment_status' => $entry['payment_status'],
			'page_url'       => isset( $entry['meta']['page_url'] ) ? $entry['meta']['page_url'] : '',
		);
	}

	/**
	 * Delivers an entry.
	 *
	 * @param array $form  Form.
	 * @param array $entry Entry.
	 * @param array $what  Subset: notifications, webhooks, integrations.
	 * @return array Summary.
	 */
	public static function deliver( array $form, array $entry, array $what = array( 'notifications', 'webhooks', 'integrations' ) ) {
		$summary = array();
		if ( in_array( 'notifications', $what, true ) ) {
			$summary['emails'] = Notifications::send_all( $form, $entry, self::context( $form, $entry ) );
		}
		if ( in_array( 'webhooks', $what, true ) ) {
			$summary['webhooks'] = Webhooks::send_all( $form, $entry );
		}
		if ( in_array( 'integrations', $what, true ) ) {
			$summary['integrations'] = array_map(
				static function ( $r ) {
					return is_wp_error( $r ) ? $r->get_error_message() : 'ok';
				},
				Integrations::run_all( $form, $entry )
			);
		}
		if ( $entry['id'] ) {
			Entries::merge_meta( $entry['id'], array( 'delivered_at' => gmdate( 'c' ) ) );
		}
		/**
		 * Fires after an entry was delivered (emails, webhooks, integrations).
		 *
		 * @param array $entry   Entry.
		 * @param array $form    Form.
		 * @param array $summary Summary.
		 */
		do_action( 'dev3fb_entry_delivered', $entry, $form, $summary );
		return $summary;
	}

	/**
	 * Re-sends for an existing entry (admin "Resend").
	 *
	 * @param int   $entry_id Entry id.
	 * @param array $what     Subset.
	 * @return array|\WP_Error
	 */
	public static function resend( $entry_id, array $what ) {
		$entry = Entries::get( $entry_id );
		$form  = $entry ? Form_Repository::get( $entry['form_id'] ) : null;
		if ( ! $entry || ! $form ) {
			return new \WP_Error( 'dev3fb_not_found', __( 'Entry not found.', 'dev3-ai-form-builder' ), array( 'status' => 404 ) );
		}
		$what = array_values( array_intersect( array( 'notifications', 'webhooks', 'integrations' ), $what ) );
		return self::deliver( $form, $entry, $what ? $what : array( 'notifications' ) );
	}
}
