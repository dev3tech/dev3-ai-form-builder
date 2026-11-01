<?php
/**
 * Email notifications (per form, with conditional logic and merge tags).
 *
 * @package Dev3\AiFormBuilder
 */

namespace Dev3\AiFormBuilder\Delivery;

use Dev3\AiFormBuilder\Core3\Core\Settings_Store;
use Dev3\AiFormBuilder\Data\Entry_Log;
use Dev3\AiFormBuilder\Engine\Logic;
use Dev3\AiFormBuilder\Engine\Merge_Tags;
use Dev3\AiFormBuilder\Submission\Uploads;

defined( 'ABSPATH' ) || exit;

/**
 * Notifications.
 */
class Notifications {

	/**
	 * "From:" header from the settings ('' = WordPress default).
	 *
	 * @return string
	 */
	public static function from_header() {
		$address = (string) Settings_Store::get( 'email_from_address', '' );
		if ( ! is_email( $address ) ) {
			return '';
		}
		$name = (string) Settings_Store::get( 'email_from_name', '' );
		$name = '' === $name ? wp_strip_all_tags( get_bloginfo( 'name' ) ) : $name;
		return sprintf( 'From: %s <%s>', str_replace( array( "\r", "\n", '<', '>' ), '', $name ), $address );
	}

	/**
	 * Parses an address list with merge tags into valid emails.
	 *
	 * @param string $addresses Comma separated.
	 * @param array  $context   Merge context.
	 * @return string[]
	 */
	public static function addresses( $addresses, array $context ) {
		$out = array();
		foreach ( preg_split( '/[,;]+/', Merge_Tags::replace( (string) $addresses, $context, 'raw' ) ) as $address ) {
			$address = sanitize_email( trim( $address ) );
			if ( is_email( $address ) && ! in_array( $address, $out, true ) ) {
				$out[] = $address;
			}
		}
		return array_slice( $out, 0, 20 );
	}

	/**
	 * Sends every matching notification.
	 *
	 * @param array $form    Form.
	 * @param array $entry   Entry.
	 * @param array $context Merge context.
	 * @return int Emails sent.
	 */
	public static function send_all( array $form, array $entry, array $context ) {
		$sent = 0;
		foreach ( $form['definition']['notifications'] as $notification ) {
			if ( empty( $notification['enabled'] ) || ! Logic::visible( $notification['logic'], $entry['data'] ) ) {
				continue;
			}
			$sent += self::send( $notification, $entry, $context ) ? 1 : 0;
		}
		return $sent;
	}

	/**
	 * Sends one notification.
	 *
	 * @param array $notification Notification.
	 * @param array $entry        Entry.
	 * @param array $context      Merge context.
	 * @return bool
	 */
	public static function send( array $notification, array $entry, array $context ) {
		$to = self::addresses( $notification['to'], $context );
		if ( ! $to ) {
			Entry_Log::add( $entry['id'], 'email', 'error', sprintf( '"%s": no valid recipient.', $notification['name'] ) );
			return false;
		}
		$subject = wp_strip_all_tags( Merge_Tags::replace( '' !== $notification['subject'] ? $notification['subject'] : '{form_title}', $context, 'raw' ) );
		$body    = Merge_Tags::replace( wpautop( $notification['message'] ), $context, 'html' );
		$headers = array( 'Content-Type: text/html; charset=UTF-8' );
		$from    = self::from_header();
		if ( '' !== $notification['from_name'] && is_email( (string) Settings_Store::get( 'email_from_address', '' ) ) ) {
			$from = sprintf( 'From: %s <%s>', str_replace( array( "\r", "\n", '<', '>' ), '', Merge_Tags::replace( $notification['from_name'], $context, 'raw' ) ), Settings_Store::get( 'email_from_address' ) );
		}
		if ( $from ) {
			$headers[] = $from;
		}
		$reply = self::addresses( $notification['reply_to'], $context );
		if ( $reply ) {
			$headers[] = 'Reply-To: ' . $reply[0];
		}
		foreach ( self::addresses( $notification['cc'], $context ) as $cc ) {
			$headers[] = 'Cc: ' . $cc;
		}
		foreach ( self::addresses( $notification['bcc'], $context ) as $bcc ) {
			$headers[] = 'Bcc: ' . $bcc;
		}
		$attachments = array();
		if ( ! empty( $notification['attach'] ) ) {
			foreach ( Uploads::files_of( $entry ) as $file ) {
				$path = Uploads::absolute( $file['path'] );
				if ( $path ) {
					$attachments[] = $path;
				}
			}
		}
		/**
		 * Filters a notification email before it is sent.
		 *
		 * @param array $mail  { to, subject, body, headers, attachments }.
		 * @param array $entry Entry.
		 */
		$mail = apply_filters( 'dev3fb_notification_email', compact( 'to', 'subject', 'body', 'headers', 'attachments' ), $entry );
		$ok   = wp_mail( $mail['to'], $mail['subject'], $mail['body'], $mail['headers'], $mail['attachments'] );
		Entry_Log::add( $entry['id'], 'email', $ok ? 'ok' : 'error', sprintf( '"%s" %s %s', $notification['name'], $ok ? 'sent to' : 'failed for', implode( ', ', $mail['to'] ) ) );
		return (bool) $ok;
	}
}
