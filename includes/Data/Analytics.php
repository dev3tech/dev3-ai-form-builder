<?php
/**
 * Form funnel analytics: anonymous per-visit session ids (random, created in the browser,
 * never tied to a person), no IPs, no field values. Used for drop-off and conversion advice.
 *
 * @package Dev3\AiFormBuilder
 */

namespace Dev3\AiFormBuilder\Data;

defined( 'ABSPATH' ) || exit;

/**
 * Analytics.
 */
class Analytics {

	const EVENTS = array( 'view', 'start', 'step', 'abandon', 'submit' );

	/**
	 * Table.
	 *
	 * @return string
	 */
	public static function table() {
		return Tables::name( 'events' );
	}

	/**
	 * Records an event (idempotent per session where it matters).
	 *
	 * @param int    $form_id Form id.
	 * @param string $session Session id (32 hex/alnum chars).
	 * @param string $event   Event.
	 * @param int    $step    Step index.
	 * @param string $field   Field id (abandon: last field touched).
	 * @return bool
	 */
	public static function record( $form_id, $session, $event, $step = 0, $field = '' ) {
		global $wpdb;
		if ( ! in_array( $event, self::EVENTS, true ) || ! preg_match( '/^[A-Za-z0-9]{16,32}$/', (string) $session ) ) {
			return false;
		}
		$table = self::table();
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( 'abandon' === $event ) {
			$done = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE form_id = %d AND session = %s AND event = 'submit'", (int) $form_id, $session ) );
			if ( $done ) {
				return false;
			}
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE form_id = %d AND session = %s AND event = 'abandon'", (int) $form_id, $session ) );
		} else {
			$exists = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE form_id = %d AND session = %s AND event = %s AND step = %d", (int) $form_id, $session, $event, (int) $step ) );
			if ( $exists ) {
				return false;
			}
			if ( 'submit' === $event ) {
				$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE form_id = %d AND session = %s AND event = 'abandon'", (int) $form_id, $session ) );
			}
		}
		// phpcs:enable
		return (bool) $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$table,
			array(
				'form_id'    => (int) $form_id,
				'session'    => $session,
				'event'      => $event,
				'step'       => max( 0, min( 100, (int) $step ) ),
				'field'      => substr( sanitize_key( (string) $field ), 0, 64 ),
				'created_at' => current_time( 'mysql', true ),
			)
		);
	}

	/**
	 * Funnel for a form.
	 *
	 * @param int $form_id Form id.
	 * @param int $days    Window in days.
	 * @return array { views, starts, submits, start_rate, completion_rate, conversion_rate, steps[], abandons[] }
	 */
	public static function funnel( $form_id, $days = 30 ) {
		global $wpdb;
		$table = self::table();
		$since = gmdate( 'Y-m-d H:i:s', time() - (int) $days * DAY_IN_SECONDS );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$counts = $wpdb->get_results( $wpdb->prepare( "SELECT event, COUNT(DISTINCT session) n FROM {$table} WHERE form_id = %d AND created_at >= %s GROUP BY event", (int) $form_id, $since ), ARRAY_A );
		$steps  = $wpdb->get_results( $wpdb->prepare( "SELECT step, COUNT(DISTINCT session) n FROM {$table} WHERE form_id = %d AND event = 'step' AND created_at >= %s GROUP BY step ORDER BY step", (int) $form_id, $since ), ARRAY_A );
		$fields = $wpdb->get_results( $wpdb->prepare( "SELECT field, COUNT(*) n FROM {$table} WHERE form_id = %d AND event = 'abandon' AND created_at >= %s GROUP BY field ORDER BY n DESC LIMIT 20", (int) $form_id, $since ), ARRAY_A );
		// phpcs:enable
		$n = array_fill_keys( self::EVENTS, 0 );
		foreach ( (array) $counts as $row ) {
			$n[ $row['event'] ] = (int) $row['n'];
		}
		$abandon_total = array_sum( array_map( 'intval', wp_list_pluck( (array) $fields, 'n' ) ) );
		$abandons      = array();
		foreach ( (array) $fields as $row ) {
			$abandons[] = array(
				'field' => (string) $row['field'],
				'count' => (int) $row['n'],
				'share' => $abandon_total ? round( (int) $row['n'] / $abandon_total, 3 ) : 0,
			);
		}
		$step_rows = array();
		foreach ( (array) $steps as $row ) {
			$step_rows[] = array(
				'step'     => (int) $row['step'],
				'sessions' => (int) $row['n'],
			);
		}
		$rate = static function ( $a, $b ) {
			return $b > 0 ? round( min( 1, $a / $b ), 3 ) : 0;
		};
		return array(
			'days'            => (int) $days,
			'views'           => $n['view'],
			'starts'          => $n['start'],
			'submits'         => $n['submit'],
			'abandoned'       => $n['abandon'],
			'start_rate'      => $rate( $n['start'], $n['view'] ),
			'completion_rate' => $rate( $n['submit'], $n['start'] ),
			'conversion_rate' => $rate( $n['submit'], $n['view'] ),
			'steps'           => $step_rows,
			'abandons'        => $abandons,
		);
	}

	/**
	 * Views and submits per form (dashboard).
	 *
	 * @param int $days Days.
	 * @return array form_id => { views, submits }
	 */
	public static function per_form( $days = 30 ) {
		global $wpdb;
		$table = self::table();
		$since = gmdate( 'Y-m-d H:i:s', time() - (int) $days * DAY_IN_SECONDS );
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT form_id, event, COUNT(DISTINCT session) n FROM {$table} WHERE created_at >= %s AND event IN ('view','submit') GROUP BY form_id, event", $since ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$out   = array();
		foreach ( (array) $rows as $row ) {
			$id = (int) $row['form_id'];
			if ( ! isset( $out[ $id ] ) ) {
				$out[ $id ] = array(
					'views'   => 0,
					'submits' => 0,
				);
			}
			$out[ $id ][ 'view' === $row['event'] ? 'views' : 'submits' ] = (int) $row['n'];
		}
		return $out;
	}

	/**
	 * Removes events older than 180 days.
	 */
	public static function prune() {
		global $wpdb;
		$table = self::table();
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE created_at < %s", gmdate( 'Y-m-d H:i:s', time() - 180 * DAY_IN_SECONDS ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}
}
