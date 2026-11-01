<?php
/**
 * Forms are a private custom post type (dev3fb_form); the definition JSON lives in post meta.
 *
 * @package Dev3\AiFormBuilder
 */

namespace Dev3\AiFormBuilder\Forms;

defined( 'ABSPATH' ) || exit;

/**
 * Form storage.
 */
class Form_Repository {

	const POST_TYPE = 'dev3fb_form';
	const META      = '_dev3fb_definition';

	/**
	 * Registers the post type.
	 */
	public static function register() {
		register_post_type(
			self::POST_TYPE,
			array(
				'labels'          => array(
					'name'          => __( 'Forms', 'dev3-ai-form-builder' ),
					'singular_name' => __( 'Form', 'dev3-ai-form-builder' ),
				),
				'public'          => false,
				'show_ui'         => false,
				'show_in_rest'    => false,
				'rewrite'         => false,
				'query_var'       => false,
				'supports'        => array( 'title' ),
				'capability_type' => 'post',
			)
		);
	}

	/**
	 * Loads a form.
	 *
	 * @param int $id Form id.
	 * @return array|null { id, title, status, definition, created_at, updated_at }
	 */
	public static function get( $id ) {
		$post = get_post( (int) $id );
		if ( ! $post || self::POST_TYPE !== $post->post_type || 'trash' === $post->post_status ) {
			return null;
		}
		return self::shape( $post );
	}

	/**
	 * Shapes a post.
	 *
	 * @param \WP_Post $post Post.
	 * @return array
	 */
	private static function shape( \WP_Post $post ) {
		$raw = get_post_meta( $post->ID, self::META, true );
		return array(
			'id'         => (int) $post->ID,
			'title'      => get_the_title( $post ),
			'status'     => 'publish' === $post->post_status ? 'active' : 'draft',
			'definition' => Definition::normalize( is_string( $raw ) && '' !== $raw ? $raw : array() ),
			'created_at' => mysql_to_rfc3339( $post->post_date_gmt ),
			'updated_at' => mysql_to_rfc3339( $post->post_modified_gmt ),
		);
	}

	/**
	 * Lists forms.
	 *
	 * @param array $args { search, status, limit }.
	 * @return array
	 */
	public static function all( array $args = array() ) {
		$query = array(
			'post_type'      => self::POST_TYPE,
			'post_status'    => array( 'publish', 'draft' ),
			'posts_per_page' => isset( $args['limit'] ) ? (int) $args['limit'] : 200,
			'orderby'        => 'modified',
			'order'          => 'DESC',
			'no_found_rows'  => true,
		);
		if ( ! empty( $args['search'] ) ) {
			$query['s'] = (string) $args['search'];
		}
		if ( ! empty( $args['status'] ) && in_array( $args['status'], array( 'active', 'draft' ), true ) ) {
			$query['post_status'] = 'active' === $args['status'] ? 'publish' : 'draft';
		}
		return array_map( array( __CLASS__, 'shape' ), get_posts( $query ) );
	}

	/**
	 * Creates or updates a form.
	 *
	 * @param array $data { id?, title, status, definition }.
	 * @return int|\WP_Error Form id.
	 */
	public static function save( array $data ) {
		$id         = isset( $data['id'] ) ? (int) $data['id'] : 0;
		$definition = Definition::normalize( isset( $data['definition'] ) ? $data['definition'] : array() );
		$title      = isset( $data['title'] ) ? Definition::text( $data['title'], 190 ) : '';
		$postarr    = array(
			'post_type'   => self::POST_TYPE,
			'post_title'  => '' === $title ? __( 'Untitled form', 'dev3-ai-form-builder' ) : $title,
			'post_status' => isset( $data['status'] ) && 'draft' === $data['status'] ? 'draft' : 'publish',
		);
		if ( $id ) {
			$existing = get_post( $id );
			if ( ! $existing || self::POST_TYPE !== $existing->post_type ) {
				return new \WP_Error( 'dev3fb_not_found', __( 'Form not found.', 'dev3-ai-form-builder' ), array( 'status' => 404 ) );
			}
			$postarr['ID'] = $id;
			if ( ! isset( $data['title'] ) ) {
				$postarr['post_title'] = $existing->post_title;
			}
			if ( ! isset( $data['status'] ) ) {
				$postarr['post_status'] = $existing->post_status;
			}
			$result = wp_update_post( wp_slash( $postarr ), true );
		} else {
			$result = wp_insert_post( wp_slash( $postarr ), true );
		}
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		update_post_meta( (int) $result, self::META, wp_slash( wp_json_encode( $definition ) ) );
		/**
		 * Fires after a form is saved.
		 *
		 * @param int   $form_id    Form id.
		 * @param array $definition Definition.
		 */
		do_action( 'dev3fb_form_saved', (int) $result, $definition );
		return (int) $result;
	}

	/**
	 * Duplicates a form (as a draft).
	 *
	 * @param int $id Form id.
	 * @return int|\WP_Error
	 */
	public static function duplicate( $id ) {
		$form = self::get( $id );
		if ( ! $form ) {
			return new \WP_Error( 'dev3fb_not_found', __( 'Form not found.', 'dev3-ai-form-builder' ), array( 'status' => 404 ) );
		}
		return self::save(
			array(
				/* translators: %s: form title */
				'title'      => sprintf( __( '%s (copy)', 'dev3-ai-form-builder' ), $form['title'] ),
				'status'     => 'draft',
				'definition' => $form['definition'],
			)
		);
	}

	/**
	 * Deletes a form (entries are kept unless $entries is true).
	 *
	 * @param int  $id      Form id.
	 * @param bool $entries Also delete entries.
	 * @return bool
	 */
	public static function delete( $id, $entries = false ) {
		$form = self::get( $id );
		if ( ! $form ) {
			return false;
		}
		if ( $entries ) {
			\Dev3\AiFormBuilder\Data\Entries::delete_for_form( (int) $id );
		}
		return (bool) wp_delete_post( (int) $id, true );
	}
}
