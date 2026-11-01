<?php
/**
 * AI auto-reply: drafts a reply to an entry; the admin edits/approves it and only then it is sent.
 *
 * @package Dev3\AiFormBuilder
 */

namespace Dev3\AiFormBuilder\AI;

use Dev3\AiFormBuilder\Core3\Api\Prompts;
use Dev3\AiFormBuilder\Core3\Core\Settings_Store;
use Dev3\AiFormBuilder\Data\Entries;
use Dev3\AiFormBuilder\Data\Entry_Log;
use Dev3\AiFormBuilder\Forms\Form_Repository;
use Dev3\AiFormBuilder\Submission\Entry_Format;

defined( 'ABSPATH' ) || exit;

/**
 * Auto reply.
 */
class Auto_Reply {

	const TONES = array( 'friendly', 'professional', 'concise', 'apologetic', 'enthusiastic' );

	/**
	 * Drafts a reply.
	 *
	 * @param int    $entry_id Entry id.
	 * @param string $tone     Tone.
	 * @param string $notes    Owner notes (facts the reply may use).
	 * @return array|\WP_Error { subject, body, to, model, tokens }
	 */
	public static function draft( $entry_id, $tone = 'friendly', $notes = '' ) {
		$entry = Entries::get( $entry_id );
		$form  = $entry ? Form_Repository::get( $entry['form_id'] ) : null;
		if ( ! $entry || ! $form ) {
			return new \WP_Error( 'dev3fb_not_found', __( 'Entry not found.', 'dev3-ai-form-builder' ), array( 'status' => 404 ) );
		}
		if ( '' === $entry['email'] ) {
			return new \WP_Error( 'dev3fb_no_email', __( 'This entry has no email address to reply to.', 'dev3-ai-form-builder' ), array( 'status' => 400 ) );
		}
		$tone      = in_array( $tone, self::TONES, true ) ? $tone : 'friendly';
		$name      = Entry_Format::sender_name( $form['definition'], $entry['data'] );
		$signature = (string) Settings_Store::get( 'reply_signature', '' );
		$result    = Prompts::run(
			'auto_reply',
			array(
				'site'      => wp_strip_all_tags( get_bloginfo( 'name' ) ),
				'language'  => '' !== $entry['lang'] ? $entry['lang'] : ( isset( $entry['ai']['language'] ) && '' !== $entry['ai']['language'] ? $entry['ai']['language'] : 'the same language as the submission' ),
				'tone'      => $tone,
				'signature' => '' !== $signature ? $signature : wp_strip_all_tags( get_bloginfo( 'name' ) ) . ' team',
				'form'      => $form['title'],
				'name'      => '' !== $name ? $name : 'unknown',
				'notes'     => '' !== trim( (string) $notes ) ? mb_substr( (string) $notes, 0, 2000 ) : 'none',
				'entry'     => Entry_Format::plain( $form['definition'], $entry['data'], array( 'password', 'signature', 'file', 'hidden' ) ),
			),
			'admin',
			array( 'max_tokens' => 3000 )
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$data = isset( $result['data'] ) && is_array( $result['data'] ) ? $result['data'] : array();
		return array(
			'subject' => mb_substr( sanitize_text_field( isset( $data['subject'] ) ? (string) $data['subject'] : '' ), 0, 150 ),
			'body'    => mb_substr( sanitize_textarea_field( isset( $data['body'] ) ? (string) $data['body'] : '' ), 0, 3000 ),
			'to'      => $entry['email'],
			'model'   => isset( $result['model'] ) ? $result['model'] : '',
			'tokens'  => isset( $result['usage']['total'] ) ? (int) $result['usage']['total'] : 0,
		);
	}

	/**
	 * Sends an approved reply.
	 *
	 * @param int    $entry_id Entry id.
	 * @param string $subject  Subject.
	 * @param string $body     Body (plain text).
	 * @return true|\WP_Error
	 */
	public static function send( $entry_id, $subject, $body ) {
		$entry = Entries::get( $entry_id );
		if ( ! $entry ) {
			return new \WP_Error( 'dev3fb_not_found', __( 'Entry not found.', 'dev3-ai-form-builder' ), array( 'status' => 404 ) );
		}
		$to = sanitize_email( $entry['email'] );
		if ( ! is_email( $to ) ) {
			return new \WP_Error( 'dev3fb_no_email', __( 'This entry has no email address to reply to.', 'dev3-ai-form-builder' ), array( 'status' => 400 ) );
		}
		$subject = trim( sanitize_text_field( (string) $subject ) );
		$body    = trim( sanitize_textarea_field( (string) $body ) );
		if ( '' === $subject || '' === $body ) {
			return new \WP_Error( 'dev3fb_empty_reply', __( 'Add a subject and a message.', 'dev3-ai-form-builder' ), array( 'status' => 400 ) );
		}
		$headers = array( 'Content-Type: text/plain; charset=UTF-8' );
		$from    = \Dev3\AiFormBuilder\Delivery\Notifications::from_header();
		if ( $from ) {
			$headers[] = $from;
		}
		$sent = wp_mail( $to, $subject, $body, $headers );
		if ( ! $sent ) {
			Entry_Log::add( $entry_id, 'reply', 'error', sprintf( 'Reply to %s could not be sent.', $to ) );
			return new \WP_Error( 'dev3fb_mail_failed', __( 'The email could not be sent. Check your site\'s mail settings.', 'dev3-ai-form-builder' ), array( 'status' => 500 ) );
		}
		Entry_Log::add( $entry_id, 'reply', 'ok', sprintf( "Reply sent to %s: %s\n\n%s", $to, $subject, $body ) );
		Entries::merge_meta( $entry_id, array( 'replied_at' => gmdate( 'c' ) ) );
		if ( 'unread' === $entry['status'] ) {
			Entries::update( $entry_id, array( 'status' => 'read' ) );
		}
		return true;
	}
}
