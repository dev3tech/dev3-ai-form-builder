<?php
/**
 * Spam shield log ({prefix}dev3fb_spam_log). Ported from DEV3 AI Form Autofill & Spam Shield.
 *
 * Privacy by default: only salted hashes (content, sender email, IP) and a short redacted
 * excerpt are stored here. When the action is "mark as spam", the full entry is kept in the
 * entries table with status "spam" (linked through entry_id) so it can be restored.
 *
 * @package Dev3\AiFormBuilder
 */

namespace Dev3\AiFormBuilder\Spam;

use Dev3\AiFormBuilder\Data\Tables;

defined( 'ABSPATH' ) || exit;

/**
 * Spam log access.
 */
class Spam_Log {

	const ALLOWLIST_OPTION = 'dev3fb_allowlist';

	const STATUSES = array( 'open', 'not_spam', 'confirmed' );

	/**
	 * Table.
	 *
	 * @return string
	 */
	public static function table() {
		return Tables::name( 'spam_log' );
	}

	/**
	 * Salted hash.
	 *
	 * @param string $value Value.
	 * @return string
	 */
	public static function hash( $value ) {
		$value = strtolower( trim( (string) $value ) );
		return '' === $value ? '' : hash_hmac( 'sha256', $value, wp_salt( 'auth' ) );
	}

	/**
	 * Short excerpt with emails, phone numbers and URLs redacted.
	 *
	 * @param string $text Text.
	 * @param int    $max  Max characters.
	 * @return string
	 */
	public static function excerpt( $text, $max = 140 ) {
		$text = preg_replace( '/[^\s@]+@[^\s@]+\.[a-z]{2,}/iu', '[email]', (string) $text );
		$text = preg_replace_callback(
			'#\bhttps?://([^/\s]+)\S*#iu',
			static function ( $m ) {
				return '[link: ' . strtolower( $m[1] ) . ']';
			},
			$text
		);
		$text = preg_replace( '/\+?\d[\d\s().\-]{6,}\d/u', '[phone]', $text );
		$text = trim( preg_replace( '/\s+/u', ' ', $text ) );
		return mb_strlen( $text ) > $max ? rtrim( mb_substr( $text, 0, $max - 1 ) ) . '…' : $text;
	}

	/**
	 * Inserts a row.
	 *
	 * @param array $row Row.
	 * @return int
	 */
	public static function insert( array $row ) {
		global $wpdb;
		$defaults = array(
			'created_at'   => current_time( 'mysql', true ),
			'form_id'      => 0,
			'form_title'   => '',
			'action'       => '',
			'trigger_type' => '',
			'status'       => 'open',
			'score'        => 0,
			'abusive'      => 0,
			'sentiment'    => '',
			'language'     => '',
			'reason'       => '',
			'excerpt'      => '',
			'content_hash' => '',
			'email_hash'   => '',
			'ip_hash'      => '',
			'entry_id'     => 0,
		);
		$row      = array_intersect_key( array_merge( $defaults, $row ), $defaults );

		$row['form_title'] = mb_substr( (string) $row['form_title'], 0, 190 );
		$row['reason']     = mb_substr( (string) $row['reason'], 0, 255 );
		$row['excerpt']    = mb_substr( (string) $row['excerpt'], 0, 255 );
		$ok                = $wpdb->insert( self::table(), $row ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return $ok ? (int) $wpdb->insert_id : 0;
	}

	/**
	 * One row.
	 *
	 * @param int $id Id.
	 * @return array|null
	 */
	public static function get( $id ) {
		global $wpdb;
		$table = self::table();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", (int) $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $row ? self::shape( $row ) : null;
	}

	/**
	 * Paged query.
	 *
	 * @param array $args { page, per_page, action, status, form_id, search }.
	 * @return array { items, total }
	 */
	public static function query( array $args ) {
		global $wpdb;
		$table    = self::table();
		$per_page = max( 1, min( 100, (int) ( isset( $args['per_page'] ) ? $args['per_page'] : 20 ) ) );
		$page     = max( 1, (int) ( isset( $args['page'] ) ? $args['page'] : 1 ) );
		$where    = array( '1=1' );
		$params   = array();
		foreach ( array( 'action', 'status' ) as $col ) {
			if ( ! empty( $args[ $col ] ) ) {
				$where[]  = "{$col} = %s";
				$params[] = (string) $args[ $col ];
			}
		}
		if ( ! empty( $args['form_id'] ) ) {
			$where[]  = 'form_id = %d';
			$params[] = (int) $args['form_id'];
		}
		if ( ! empty( $args['search'] ) ) {
			$like     = '%' . $wpdb->esc_like( (string) $args['search'] ) . '%';
			$where[]  = '(excerpt LIKE %s OR reason LIKE %s OR form_title LIKE %s)';
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
		}
		$sql_where = implode( ' AND ', $where );
		$count_sql = "SELECT COUNT(*) FROM {$table} WHERE {$sql_where}";
		$list_sql  = "SELECT * FROM {$table} WHERE {$sql_where} ORDER BY id DESC LIMIT %d OFFSET %d";
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		$total = (int) ( $params ? $wpdb->get_var( $wpdb->prepare( $count_sql, $params ) ) : $wpdb->get_var( $count_sql ) );
		$rows  = $wpdb->get_results( $wpdb->prepare( $list_sql, array_merge( $params, array( $per_page, ( $page - 1 ) * $per_page ) ) ), ARRAY_A );
		// phpcs:enable
		return array(
			'items' => array_map( array( __CLASS__, 'shape' ), (array) $rows ),
			'total' => $total,
		);
	}

	/**
	 * Counters for the last N days.
	 *
	 * @param int $days Window.
	 * @return array
	 */
	public static function counts( $days = 30 ) {
		global $wpdb;
		$table = self::table();
		$since = gmdate( 'Y-m-d H:i:s', time() - (int) $days * DAY_IN_SECONDS );
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT action, status, COUNT(*) AS n FROM {$table} WHERE created_at >= %s GROUP BY action, status", $since ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$out   = array(
			'block'    => 0,
			'spam'     => 0,
			'flag'     => 0,
			'allow'    => 0,
			'not_spam' => 0,
			'total'    => 0,
		);
		foreach ( (array) $rows as $row ) {
			$n = (int) $row['n'];
			if ( isset( $out[ $row['action'] ] ) ) {
				$out[ $row['action'] ] += $n;
			}
			if ( 'not_spam' === $row['status'] ) {
				$out['not_spam'] += $n;
			}
			$out['total'] += $n;
		}
		return $out;
	}

	/**
	 * Updates status / entry link.
	 *
	 * @param int   $id   Id.
	 * @param array $data Columns.
	 * @return bool
	 */
	public static function update( $id, array $data ) {
		global $wpdb;
		$data = array_intersect_key( $data, array_flip( array( 'status', 'entry_id' ) ) );
		if ( ! $data ) {
			return false;
		}
		return false !== $wpdb->update( self::table(), $data, array( 'id' => (int) $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Deletes one row.
	 *
	 * @param int $id Id.
	 * @return int
	 */
	public static function delete( $id ) {
		global $wpdb;
		return (int) $wpdb->delete( self::table(), array( 'id' => (int) $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Retention.
	 *
	 * @param int $days Days to keep.
	 * @return int
	 */
	public static function prune( $days ) {
		global $wpdb;
		$table = self::table();
		return (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE created_at < %s", gmdate( 'Y-m-d H:i:s', time() - max( 1, (int) $days ) * DAY_IN_SECONDS ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Whether a sender hash was marked "not spam".
	 *
	 * @param string $email_hash Hash.
	 * @return bool
	 */
	public static function is_allowlisted( $email_hash ) {
		if ( '' === (string) $email_hash ) {
			return false;
		}
		$list = get_option( self::ALLOWLIST_OPTION, array() );
		return is_array( $list ) && in_array( $email_hash, $list, true );
	}

	/**
	 * Adds a sender hash to the allowlist (max 1000).
	 *
	 * @param string $email_hash Hash.
	 */
	public static function allowlist( $email_hash ) {
		if ( '' === (string) $email_hash ) {
			return;
		}
		$list   = get_option( self::ALLOWLIST_OPTION, array() );
		$list   = is_array( $list ) ? $list : array();
		$list[] = $email_hash;
		update_option( self::ALLOWLIST_OPTION, array_slice( array_values( array_unique( $list ) ), -1000 ), false );
	}

	/**
	 * Row → API shape.
	 *
	 * @param array $row Row.
	 * @return array
	 */
	public static function shape( array $row ) {
		return array(
			'id'         => (int) $row['id'],
			'created_at' => mysql_to_rfc3339( $row['created_at'] ),
			'form_id'    => (int) $row['form_id'],
			'form_title' => $row['form_title'],
			'action'     => $row['action'],
			'trigger'    => $row['trigger_type'],
			'status'     => $row['status'],
			'score'      => (int) $row['score'],
			'abusive'    => (bool) $row['abusive'],
			'sentiment'  => $row['sentiment'],
			'language'   => $row['language'],
			'reason'     => $row['reason'],
			'excerpt'    => $row['excerpt'],
			'has_sender' => '' !== $row['email_hash'],
			'entry_id'   => (int) $row['entry_id'],
		);
	}
}
