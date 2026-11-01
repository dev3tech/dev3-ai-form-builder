<?php
/**
 * Entries ({prefix}dev3fb_entries).
 *
 * @package Dev3\AiFormBuilder
 */

namespace Dev3\AiFormBuilder\Data;

defined( 'ABSPATH' ) || exit;

/**
 * Entry data access.
 */
class Entries {

	const STATUSES = array( 'unread', 'read', 'spam', 'trash', 'pending' );

	/**
	 * Table.
	 *
	 * @return string
	 */
	public static function table() {
		return Tables::name( 'entries' );
	}

	/**
	 * Inserts an entry.
	 *
	 * @param array $row Row (data/meta/ai arrays are JSON-encoded).
	 * @return int
	 */
	public static function insert( array $row ) {
		global $wpdb;
		$now = current_time( 'mysql', true );
		$row = array_merge(
			array(
				'form_id'          => 0,
				'status'           => 'unread',
				'starred'          => 0,
				'data'             => array(),
				'meta'             => array(),
				'ai'               => null,
				'email'            => '',
				'lang'             => '',
				'user_id'          => get_current_user_id(),
				'payment_status'   => '',
				'payment_total'    => 0,
				'payment_currency' => '',
				'payment_provider' => '',
				'payment_ref'      => '',
				'created_at'       => $now,
				'updated_at'       => $now,
			),
			$row
		);
		$row = self::encode( $row );
		$wpdb->insert( self::table(), $row ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return (int) $wpdb->insert_id;
	}

	/**
	 * JSON-encodes array columns.
	 *
	 * @param array $row Row.
	 * @return array
	 */
	private static function encode( array $row ) {
		foreach ( array( 'data', 'meta', 'ai' ) as $col ) {
			if ( array_key_exists( $col, $row ) && ( is_array( $row[ $col ] ) || null === $row[ $col ] ) ) {
				$row[ $col ] = null === $row[ $col ] ? null : wp_json_encode( $row[ $col ] );
			}
		}
		return $row;
	}

	/**
	 * Updates columns.
	 *
	 * @param int   $id  Entry id.
	 * @param array $row Columns.
	 * @return bool
	 */
	public static function update( $id, array $row ) {
		global $wpdb;
		$row['updated_at'] = current_time( 'mysql', true );
		return false !== $wpdb->update( self::table(), self::encode( $row ), array( 'id' => (int) $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Merges keys into the meta column.
	 *
	 * @param int   $id    Entry id.
	 * @param array $patch Keys.
	 * @return bool
	 */
	public static function merge_meta( $id, array $patch ) {
		$entry = self::get( $id );
		if ( ! $entry ) {
			return false;
		}
		return self::update( $id, array( 'meta' => array_merge( $entry['meta'], $patch ) ) );
	}

	/**
	 * Shapes a DB row.
	 *
	 * @param array $row Row.
	 * @return array
	 */
	public static function shape( array $row ) {
		$data = json_decode( (string) $row['data'], true );
		$meta = json_decode( (string) $row['meta'], true );
		$ai   = null === $row['ai'] ? null : json_decode( (string) $row['ai'], true );
		return array(
			'id'               => (int) $row['id'],
			'form_id'          => (int) $row['form_id'],
			'status'           => (string) $row['status'],
			'starred'          => (bool) $row['starred'],
			'data'             => is_array( $data ) ? $data : array(),
			'meta'             => is_array( $meta ) ? $meta : array(),
			'ai'               => is_array( $ai ) ? $ai : null,
			'lead_score'       => null === $row['lead_score'] ? null : (int) $row['lead_score'],
			'sentiment'        => (string) $row['sentiment'],
			'email'            => (string) $row['email'],
			'lang'             => (string) $row['lang'],
			'user_id'          => (int) $row['user_id'],
			'payment_status'   => (string) $row['payment_status'],
			'payment_total'    => (float) $row['payment_total'],
			'payment_currency' => (string) $row['payment_currency'],
			'payment_provider' => (string) $row['payment_provider'],
			'payment_ref'      => (string) $row['payment_ref'],
			'created_at'       => mysql_to_rfc3339( $row['created_at'] ),
			'updated_at'       => mysql_to_rfc3339( $row['updated_at'] ),
		);
	}

	/**
	 * One entry.
	 *
	 * @param int $id Entry id.
	 * @return array|null
	 */
	public static function get( $id ) {
		global $wpdb;
		$table = self::table();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", (int) $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $row ? self::shape( $row ) : null;
	}

	/**
	 * Entry by payment reference.
	 *
	 * @param string $ref Provider reference.
	 * @return array|null
	 */
	public static function by_payment_ref( $ref ) {
		global $wpdb;
		if ( '' === (string) $ref ) {
			return null;
		}
		$table = self::table();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE payment_ref = %s LIMIT 1", (string) $ref ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $row ? self::shape( $row ) : null;
	}

	/**
	 * WHERE clause for list queries.
	 *
	 * @param array $args Args.
	 * @return array [ sql, params ]
	 */
	private static function where( array $args ) {
		$where  = array( '1=1' );
		$params = array();
		if ( ! empty( $args['form_id'] ) ) {
			$where[]  = 'form_id = %d';
			$params[] = (int) $args['form_id'];
		}
		$status = isset( $args['status'] ) ? (string) $args['status'] : '';
		if ( 'starred' === $status ) {
			$where[] = 'starred = 1 AND status NOT IN (\'trash\',\'spam\')';
		} elseif ( in_array( $status, self::STATUSES, true ) ) {
			$where[]  = 'status = %s';
			$params[] = $status;
		} elseif ( 'all' !== $status ) {
			$where[] = 'status NOT IN (\'trash\',\'spam\')';
		}
		if ( ! empty( $args['search'] ) ) {
			global $wpdb;
			$like     = '%' . $wpdb->esc_like( (string) $args['search'] ) . '%';
			$where[]  = '(data LIKE %s OR email LIKE %s)';
			$params[] = $like;
			$params[] = $like;
		}
		if ( ! empty( $args['after'] ) ) {
			$where[]  = 'created_at >= %s';
			$params[] = (string) $args['after'];
		}
		if ( ! empty( $args['before'] ) ) {
			$where[]  = 'created_at <= %s';
			$params[] = (string) $args['before'];
		}
		if ( ! empty( $args['ids'] ) ) {
			$ids     = array_map( 'absint', (array) $args['ids'] );
			$where[] = 'id IN (' . implode( ',', array_fill( 0, count( $ids ), '%d' ) ) . ')';
			$params  = array_merge( $params, $ids );
		}
		return array( implode( ' AND ', $where ), $params );
	}

	/**
	 * Lists entries.
	 *
	 * @param array $args { form_id, status, search, page, per_page, orderby, order, after, before, ids }.
	 * @return array { rows, total }
	 */
	public static function query( array $args = array() ) {
		global $wpdb;
		$table                  = self::table();
		list( $where, $params ) = self::where( $args );
		$per_page               = isset( $args['per_page'] ) ? max( 1, min( 5000, (int) $args['per_page'] ) ) : 20;
		$page                   = isset( $args['page'] ) ? max( 1, (int) $args['page'] ) : 1;
		$orderby                = isset( $args['orderby'] ) && in_array( $args['orderby'], array( 'id', 'created_at', 'lead_score', 'status', 'payment_total' ), true ) ? $args['orderby'] : 'id';
		$order                  = isset( $args['order'] ) && 'asc' === strtolower( (string) $args['order'] ) ? 'ASC' : 'DESC';
		$offset                 = ( $page - 1 ) * $per_page;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		$count_sql = "SELECT COUNT(*) FROM {$table} WHERE {$where}";
		$total     = (int) $wpdb->get_var( $params ? $wpdb->prepare( $count_sql, $params ) : $count_sql );
		$rows_sql  = "SELECT * FROM {$table} WHERE {$where} ORDER BY {$orderby} {$order}, id DESC LIMIT %d OFFSET %d";
		$rows      = $wpdb->get_results( $wpdb->prepare( $rows_sql, array_merge( $params, array( $per_page, $offset ) ) ), ARRAY_A );
		// phpcs:enable
		return array(
			'rows'  => array_map( array( __CLASS__, 'shape' ), (array) $rows ),
			'total' => $total,
		);
	}

	/**
	 * Counts per status for a form (0 = all forms).
	 *
	 * @param int $form_id Form id.
	 * @return array
	 */
	public static function counts( $form_id = 0 ) {
		global $wpdb;
		$table = self::table();
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $form_id
			? $wpdb->get_results( $wpdb->prepare( "SELECT status, COUNT(*) n, SUM(starred) s FROM {$table} WHERE form_id = %d GROUP BY status", (int) $form_id ), ARRAY_A )
			: $wpdb->get_results( "SELECT status, COUNT(*) n, SUM(starred) s FROM {$table} GROUP BY status", ARRAY_A );
		// phpcs:enable
		$out            = array_fill_keys( self::STATUSES, 0 );
		$out['starred'] = 0;
		foreach ( (array) $rows as $row ) {
			$out[ $row['status'] ] = (int) $row['n'];
			if ( ! in_array( $row['status'], array( 'trash', 'spam' ), true ) ) {
				$out['starred'] += (int) $row['s'];
			}
		}
		$out['all'] = $out['unread'] + $out['read'] + $out['pending'];
		return $out;
	}

	/**
	 * Entries per form (excluding spam/trash), for lists.
	 *
	 * @return array form_id => count
	 */
	public static function per_form() {
		global $wpdb;
		$table = self::table();
		$rows  = $wpdb->get_results( "SELECT form_id, COUNT(*) n, SUM(status = 'unread') u FROM {$table} WHERE status NOT IN ('trash','spam') GROUP BY form_id", ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$out   = array();
		foreach ( (array) $rows as $row ) {
			$out[ (int) $row['form_id'] ] = array(
				'total'  => (int) $row['n'],
				'unread' => (int) $row['u'],
			);
		}
		return $out;
	}

	/**
	 * Daily entry counts for the last N days.
	 *
	 * @param int $days    Days.
	 * @param int $form_id Form id (0 = all).
	 * @return array [ { date, count } ]
	 */
	public static function daily( $days = 30, $form_id = 0 ) {
		global $wpdb;
		$table = self::table();
		$since = gmdate( 'Y-m-d 00:00:00', time() - ( $days - 1 ) * DAY_IN_SECONDS );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $form_id
			? $wpdb->get_results( $wpdb->prepare( "SELECT DATE(created_at) d, COUNT(*) n FROM {$table} WHERE created_at >= %s AND form_id = %d AND status NOT IN ('trash','spam') GROUP BY d", $since, (int) $form_id ), ARRAY_A )
			: $wpdb->get_results( $wpdb->prepare( "SELECT DATE(created_at) d, COUNT(*) n FROM {$table} WHERE created_at >= %s AND status NOT IN ('trash','spam') GROUP BY d", $since ), ARRAY_A );
		// phpcs:enable
		$map = array();
		foreach ( (array) $rows as $row ) {
			$map[ $row['d'] ] = (int) $row['n'];
		}
		$out = array();
		for ( $i = $days - 1; $i >= 0; $i-- ) {
			$date  = gmdate( 'Y-m-d', time() - $i * DAY_IN_SECONDS );
			$out[] = array(
				'date'  => $date,
				'count' => isset( $map[ $date ] ) ? $map[ $date ] : 0,
			);
		}
		return $out;
	}

	/**
	 * Non-spam entries for a form (limit checks).
	 *
	 * @param int $form_id Form id.
	 * @return int
	 */
	public static function count_for_form( $form_id ) {
		global $wpdb;
		$table = self::table();
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE form_id = %d AND status NOT IN ('trash','spam')", (int) $form_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Deletes an entry, its log and its files.
	 *
	 * @param int $id Entry id.
	 * @return bool
	 */
	public static function delete( $id ) {
		global $wpdb;
		$entry = self::get( $id );
		if ( ! $entry ) {
			return false;
		}
		\Dev3\AiFormBuilder\Submission\Uploads::delete_for_entry( $entry );
		$wpdb->delete( Entry_Log::table(), array( 'entry_id' => (int) $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return (bool) $wpdb->delete( self::table(), array( 'id' => (int) $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Deletes every entry of a form.
	 *
	 * @param int $form_id Form id.
	 * @return int Deleted.
	 */
	public static function delete_for_form( $form_id ) {
		$count = 0;
		do {
			$batch = self::query(
				array(
					'form_id'  => $form_id,
					'status'   => 'all',
					'per_page' => 200,
				)
			);
			foreach ( $batch['rows'] as $row ) {
				$count += self::delete( $row['id'] ) ? 1 : 0;
			}
		} while ( $batch['rows'] );
		return $count;
	}

	/**
	 * Deletes entries older than N days (retention setting).
	 *
	 * @param int $days Days (0 = keep forever).
	 * @return int
	 */
	public static function prune( $days ) {
		global $wpdb;
		$days = (int) $days;
		if ( $days <= 0 ) {
			return 0;
		}
		$table  = self::table();
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );
		$ids    = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$table} WHERE created_at < %s LIMIT 500", $cutoff ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$count  = 0;
		foreach ( (array) $ids as $id ) {
			$count += self::delete( (int) $id ) ? 1 : 0;
		}
		return $count;
	}
}
