<?php
/**
 * Server-side form markup. Works without JavaScript (every step is shown and the form posts
 * normally); the front-end script adds steps, logic, live calculations, validation and AJAX.
 * Visitor-facing text is neutral (no DEV3 branding).
 *
 * @package Dev3\AiFormBuilder
 */

namespace Dev3\AiFormBuilder\Frontend;

use Dev3\AiFormBuilder\Core3\Core\Settings_Store;
use Dev3\AiFormBuilder\Engine\Calculator;
use Dev3\AiFormBuilder\Engine\Logic;
use Dev3\AiFormBuilder\Engine\Merge_Tags;
use Dev3\AiFormBuilder\Forms\Countries;
use Dev3\AiFormBuilder\Forms\Definition;
use Dev3\AiFormBuilder\Payments\Payments;
use Dev3\AiFormBuilder\Submission\Form_Token;
use Dev3\AiFormBuilder\Submission\Submission_Handler;
use Dev3\AiFormBuilder\Submission\Validator;

defined( 'ABSPATH' ) || exit;

/**
 * Renderer.
 */
class Renderer {

	/**
	 * Instance counter (several forms per page).
	 *
	 * @var int
	 */
	private static $instance = 0;

	/**
	 * Current DOM id prefix.
	 *
	 * @var string
	 */
	private $uid = '';

	/**
	 * Form.
	 *
	 * @var array
	 */
	private $form;

	/**
	 * Prefilled values.
	 *
	 * @var array
	 */
	private $values = array();

	/**
	 * Server-side errors (no-JS round trip).
	 *
	 * @var array
	 */
	private $errors = array();

	/**
	 * Currency symbol.
	 *
	 * @param string $currency ISO code.
	 * @return string
	 */
	public static function symbol( $currency ) {
		$map = array(
			'USD' => '$',
			'EUR' => '€',
			'GBP' => '£',
			'JPY' => '¥',
			'INR' => '₹',
			'AUD' => 'A$',
			'CAD' => 'C$',
			'CHF' => 'CHF ',
			'SEK' => 'kr ',
			'NOK' => 'kr ',
			'DKK' => 'kr ',
			'PLN' => 'zł ',
			'BRL' => 'R$',
			'MXN' => 'MX$',
			'KRW' => '₩',
			'CNY' => '¥',
			'AED' => 'AED ',
			'ZAR' => 'R ',
			'NZD' => 'NZ$',
			'SGD' => 'S$',
		);
		return isset( $map[ $currency ] ) ? $map[ $currency ] : $currency . ' ';
	}

	/**
	 * Formatted price.
	 *
	 * @param float  $amount   Amount.
	 * @param string $currency Currency.
	 * @return string
	 */
	public static function money( $amount, $currency ) {
		$decimals = in_array( $currency, Payments::ZERO_DECIMAL, true ) ? 0 : 2;
		return self::symbol( $currency ) . number_format_i18n( (float) $amount, $decimals );
	}

	/**
	 * Renders a form.
	 *
	 * @param array $form    Form (definition may be translated).
	 * @param array $options { values, errors, message, confirmation, preview }.
	 * @return string
	 */
	public static function render( array $form, array $options = array() ) {
		$renderer         = new self();
		$renderer->form   = $form;
		$renderer->values = isset( $options['values'] ) ? (array) $options['values'] : array();
		$renderer->errors = isset( $options['errors'] ) ? (array) $options['errors'] : array();
		++self::$instance;
		$renderer->uid = 'dev3fb-' . (int) $form['id'] . '-' . self::$instance;
		return $renderer->html( $options );
	}

	/**
	 * Value for a field (prefill, else default).
	 *
	 * @param array $field Field.
	 * @return mixed
	 */
	private function value( array $field ) {
		if ( array_key_exists( $field['id'], $this->values ) ) {
			return $this->values[ $field['id'] ];
		}
		$default = Merge_Tags::defaults( isset( $field['default'] ) ? $field['default'] : '' );
		if ( in_array( Definition::kind( $field['type'] ), array( 'choices' ), true ) ) {
			return '' === $default ? array() : array_map( 'trim', explode( ',', $default ) );
		}
		return $default;
	}

	/**
	 * Client config (no secrets).
	 *
	 * @param array $visible Visibility map.
	 * @return array
	 */
	private function client_config( array $visible ) {
		$definition = $this->form['definition'];
		$fields     = array();
		foreach ( $definition['steps'] as $i => $step ) {
			foreach ( $step['fields'] as $field ) {
				$item = array(
					'id'       => $field['id'],
					'type'     => $field['type'],
					'step'     => $i,
					'required' => (bool) $field['required'],
				);
				foreach ( array( 'logic', 'formula', 'decimals', 'options', 'price', 'product', 'add_to_total', 'min', 'max', 'maxlength', 'prefix', 'suffix', 'max_files', 'max_size', 'accept' ) as $key ) {
					if ( isset( $field[ $key ] ) && null !== $field[ $key ] && '' !== $field[ $key ] ) {
						$item[ $key ] = 'options' === $key ? array_map(
							static function ( $o ) {
								return array_intersect_key( $o, array_flip( array( 'value', 'price' ) ) );
							},
							$field['options']
						) : $field[ $key ];
					}
				}
				$fields[] = $item;
			}
		}
		$steps = array();
		foreach ( $definition['steps'] as $step ) {
			$steps[] = array(
				'id'    => $step['id'],
				'logic' => $step['logic'],
			);
		}
		$messages = array_merge( Validator::messages(), self::frontend_messages() );
		$lang     = isset( $definition['lang'] ) ? $definition['lang'] : '';
		if ( '' !== $lang && isset( $definition['translations'][ $lang ] ) ) {
			foreach ( $definition['translations'][ $lang ] as $key => $text ) {
				if ( 0 === strpos( $key, 'message.' ) ) {
					$messages[ substr( $key, 8 ) ] = $text;
				}
			}
		}
		return array(
			'id'        => (int) $this->form['id'],
			'uid'       => $this->uid,
			'fields'    => $fields,
			'steps'     => $steps,
			'visible'   => $visible,
			'messages'  => $messages,
			'currency'  => $definition['payments']['enabled'] ? $definition['payments']['currency'] : '',
			'symbol'    => $definition['payments']['enabled'] ? self::symbol( $definition['payments']['currency'] ) : '',
			'paste'     => $definition['protection']['paste_fill'] && '' !== Settings_Store::api_key() && Settings_Store::get( 'public_ai', true ) && Settings_Store::get( 'ai_enabled', true ),
			'save'      => (bool) $definition['settings']['save_continue'],
			'analytics' => (bool) Settings_Store::get( 'analytics', true ),
			'ttl'       => Form_Token::ttl(),
		);
	}

	/**
	 * Visitor-facing UI strings for the script (translatable; neutral wording).
	 *
	 * @return array
	 */
	public static function frontend_messages() {
		return array(
			'fix'          => __( 'Please fix the highlighted fields.', 'dev3-ai-form-builder' ),
			'network'      => __( 'Something went wrong. Please check your connection and try again.', 'dev3-ai-form-builder' ),
			'saved'        => __( 'Saved. Use this link to continue later:', 'dev3-ai-form-builder' ),
			'saved_sent'   => __( 'Saved. We also emailed you this link to continue later:', 'dev3-ai-form-builder' ),
			'copy'         => __( 'Copy link', 'dev3-ai-form-builder' ),
			'paste_short'  => __( 'Paste a little more text first.', 'dev3-ai-form-builder' ),
			'paste_busy'   => __( 'Reading your details…', 'dev3-ai-form-builder' ),
			/* translators: %d: number of fields */
			'paste_done'   => __( 'Filled %d fields. Please check them.', 'dev3-ai-form-builder' ),
			'paste_none'   => __( 'No new details found in the text.', 'dev3-ai-form-builder' ),
			'paste_error'  => __( 'Autofill is unavailable right now. Please fill the form by hand.', 'dev3-ai-form-builder' ),
			'paste_undone' => __( 'Undone.', 'dev3-ai-form-builder' ),
		);
	}

	/**
	 * Full markup.
	 *
	 * @param array $options Options.
	 * @return string
	 */
	private function html( array $options ) {
		$form       = $this->form;
		$definition = $form['definition'];
		$settings   = $definition['settings'];
		$classes    = array( 'dev3fb', 'dev3fb-wrap' );
		if ( '' !== $settings['css_class'] ) {
			$classes[] = $settings['css_class'];
		}
		$style = '' !== $settings['accent'] ? '--dev3fb-accent:' . $settings['accent'] . ';' : '';

		$open = Submission_Handler::availability( $form );
		if ( is_wp_error( $open ) && empty( $options['preview'] ) ) {
			return '<div class="' . esc_attr( implode( ' ', $classes ) ) . '"><p class="dev3fb-notice">' . esc_html( $open->get_error_message() ) . '</p></div>';
		}

		// Initial logic with prefilled/default values (no layout shift once JS runs).
		$values = array();
		foreach ( Definition::input_fields( $definition, false ) as $field ) {
			$values[ $field['id'] ] = $this->value( $field );
		}
		$logic   = Logic::evaluate( $definition, $values );
		$calc    = Calculator::run( $definition, $logic['values'], $logic['fields'] );
		$visible = $logic['fields'];
		$multi   = count( $definition['steps'] ) > 1;
		$start   = isset( $options['step'] ) ? max( 0, min( count( $definition['steps'] ) - 1, (int) $options['step'] ) ) : 0;
		$config  = $this->client_config( $visible );

		$out = '<div class="' . esc_attr( implode( ' ', $classes ) ) . '" id="' . esc_attr( $this->uid ) . '"' . ( $style ? ' style="' . esc_attr( $style ) . '"' : '' ) . ' data-dev3fb="' . esc_attr( (string) wp_json_encode( $config ) ) . '">';
		if ( $settings['show_title'] ) {
			$out .= '<h2 class="dev3fb-title">' . esc_html( $form['title'] ) . '</h2>';
		}
		if ( '' !== $settings['description'] ) {
			$out .= '<div class="dev3fb-description">' . wp_kses_post( wpautop( $settings['description'] ) ) . '</div>';
		}
		if ( ! empty( $options['confirmation'] ) ) {
			return $out . '<div class="dev3fb-confirmation" role="status" tabindex="-1">' . wp_kses_post( $options['confirmation'] ) . '</div></div>';
		}

		$out .= '<form class="dev3fb-form" method="post" enctype="multipart/form-data" novalidate' . ( $multi ? ' data-multi="1"' : '' ) . '>';
		$out .= '<input type="hidden" name="dev3fb_form_id" value="' . esc_attr( (string) $form['id'] ) . '">';
		$out .= '<input type="hidden" name="dev3fb_token" value="' . esc_attr( Form_Token::create( $form['id'] ) ) . '">';
		$out .= '<input type="hidden" name="dev3fb_lang" value="' . esc_attr( isset( $definition['lang'] ) ? $definition['lang'] : '' ) . '">';
		$out .= '<input type="hidden" name="dev3fb_page" value="' . esc_attr( self::current_url() ) . '">';
		$out .= '<input type="hidden" name="dev3fb_resume" value="' . esc_attr( isset( $options['resume'] ) ? $options['resume'] : '' ) . '">';
		$out .= '<input type="hidden" name="dev3fb_session" value="">';
		if ( $definition['protection']['honeypot'] ) {
			$out .= '<div class="dev3fb-hp" aria-hidden="true"><label>' . esc_html__( 'Leave this field empty', 'dev3-ai-form-builder' ) . '<input type="text" name="dev3fb_hp" value="" tabindex="-1" autocomplete="off"></label></div>';
		}

		if ( $multi && 'none' !== $settings['progress'] ) {
			$out .= $this->progress( $settings['progress'], $start, $logic['steps'] );
		}
		if ( $config['paste'] ) {
			$out .= $this->paste_box();
		}
		$summary = '';
		if ( ! empty( $options['message'] ) ) {
			$summary = '<p>' . esc_html( $options['message'] ) . '</p>';
		}
		$out .= '<div class="dev3fb-alert" role="alert"' . ( '' === $summary ? ' hidden' : '' ) . '>' . $summary . '</div>';

		foreach ( $definition['steps'] as $i => $step ) {
			$step_hidden = ! $logic['steps'][ $step['id'] ];
			$attrs       = ' data-step="' . esc_attr( (string) $i ) . '" data-step-id="' . esc_attr( $step['id'] ) . '"';
			$out        .= '<fieldset class="dev3fb-step' . ( $multi && $i !== $start ? ' dev3fb-step--later' : '' ) . '"' . $attrs . ( $step_hidden ? ' hidden' : '' ) . '>';
			if ( '' !== $step['title'] ) {
				$out .= '<legend class="dev3fb-step-title">' . esc_html( $step['title'] ) . '</legend>';
			} elseif ( $multi ) {
				/* translators: %d: step number */
				$out .= '<legend class="dev3fb-sr">' . esc_html( sprintf( __( 'Step %d', 'dev3-ai-form-builder' ), $i + 1 ) ) . '</legend>';
			}
			if ( '' !== $step['description'] ) {
				$out .= '<p class="dev3fb-step-desc">' . esc_html( $step['description'] ) . '</p>';
			}
			$out .= '<div class="dev3fb-grid">';
			foreach ( $step['fields'] as $field ) {
				$out .= $this->field( $field, ! $visible[ $field['id'] ], $calc );
			}
			$out .= '</div></fieldset>';
		}

		$payments = $definition['payments'];
		if ( $payments['enabled'] ) {
			$available = array_values( array_filter( $payments['providers'], array( Payments::class, 'available' ) ) );
			if ( count( $available ) > 1 ) {
				$out .= '<fieldset class="dev3fb-field dev3fb-providers"><legend class="dev3fb-label">' . esc_html__( 'Pay with', 'dev3-ai-form-builder' ) . '</legend><div class="dev3fb-choices dev3fb-choices--inline">';
				foreach ( $available as $n => $provider ) {
					$out .= '<label class="dev3fb-choice"><input type="radio" name="dev3fb_provider" value="' . esc_attr( $provider ) . '"' . checked( 0, $n, false ) . '><span>' . esc_html( 'stripe' === $provider ? __( 'Card', 'dev3-ai-form-builder' ) : 'PayPal' ) . '</span></label>';
				}
				$out .= '</div></fieldset>';
			} elseif ( $available ) {
				$out .= '<input type="hidden" name="dev3fb_provider" value="' . esc_attr( $available[0] ) . '">';
			}
		}

		$out .= '<div class="dev3fb-nav">';
		if ( $multi ) {
			$out .= '<button type="button" class="dev3fb-btn dev3fb-btn--ghost dev3fb-prev" hidden>' . esc_html( $settings['prev_label'] ) . '</button>';
			$out .= '<button type="button" class="dev3fb-btn dev3fb-next" hidden>' . esc_html( $settings['next_label'] ) . '</button>';
		}
		$out .= '<button type="submit" class="dev3fb-btn dev3fb-submit">' . esc_html( $settings['submit_label'] ) . '</button>';
		if ( $settings['save_continue'] ) {
			$out .= '<button type="button" class="dev3fb-link dev3fb-save" hidden>' . esc_html( $settings['save_label'] ) . '</button>';
		}
		$out .= '</div>';
		$out .= '<div class="dev3fb-saved" hidden></div>';
		$out .= '</form>';
		$out .= '<div class="dev3fb-confirmation" role="status" tabindex="-1" hidden></div>';
		$out .= '</div>';
		return $out;
	}

	/**
	 * Current URL (for payment returns and the no-JS round trip).
	 *
	 * @return string
	 */
	public static function current_url() {
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '/';
		$url = home_url( $uri );
		return remove_query_arg( array( 'dev3fb_done', 'dev3fb_err', 'dev3fb_pay', 'dev3fb_entry', 'dev3fb_sig', 'session_id', 'token', 'PayerID' ), $url );
	}

	/**
	 * Progress indicator.
	 *
	 * @param string $style   bar|steps.
	 * @param int    $current Current step.
	 * @param array  $visible Step visibility.
	 * @return string
	 */
	private function progress( $style, $current, array $visible ) {
		$steps = $this->form['definition']['steps'];
		$total = count( array_filter( $visible ) );
		$out   = '<div class="dev3fb-progress dev3fb-progress--' . esc_attr( $style ) . '">';
		if ( 'steps' === $style ) {
			$out .= '<ol class="dev3fb-progress-steps">';
			$n    = 0;
			foreach ( $steps as $i => $step ) {
				++$n;
				$label = '' !== $step['title'] ? $step['title'] : sprintf( /* translators: %d: step number */ __( 'Step %d', 'dev3-ai-form-builder' ), $n );
				$out  .= '<li data-step="' . esc_attr( (string) $i ) . '"' . ( $visible[ $step['id'] ] ? '' : ' hidden' ) . ( $i === $current ? ' aria-current="step"' : '' ) . '><span class="dev3fb-progress-num" aria-hidden="true">' . esc_html( (string) $n ) . '</span><span class="dev3fb-progress-label">' . esc_html( $label ) . '</span></li>';
			}
			$out .= '</ol>';
		} else {
			$pct = $total ? (int) round( 100 * ( $current + 1 ) / $total ) : 0;
			/* translators: 1: current step, 2: total steps */
			$out .= '<div class="dev3fb-progress-text">' . esc_html( sprintf( __( 'Step %1$d of %2$d', 'dev3-ai-form-builder' ), $current + 1, $total ) ) . '</div>';
			$out .= '<div class="dev3fb-progress-track" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="' . esc_attr( (string) $pct ) . '"><span style="width:' . esc_attr( (string) $pct ) . '%"></span></div>';
		}
		return $out . '</div>';
	}

	/**
	 * Paste-to-fill box (visitor-facing, neutral wording).
	 *
	 * @return string
	 */
	private function paste_box() {
		$id = $this->uid . '-paste';
		return '<div class="dev3fb-paste">'
			. '<button type="button" class="dev3fb-link dev3fb-paste-toggle" aria-expanded="false" aria-controls="' . esc_attr( $id ) . '">' . esc_html__( 'Paste your details to fill the form automatically', 'dev3-ai-form-builder' ) . '</button>'
			. '<div class="dev3fb-paste-panel" id="' . esc_attr( $id ) . '" hidden>'
			. '<label class="dev3fb-label" for="' . esc_attr( $id ) . '-text">' . esc_html__( 'Paste an email signature, a message or your contact details', 'dev3-ai-form-builder' ) . '</label>'
			. '<textarea class="dev3fb-input" id="' . esc_attr( $id ) . '-text" rows="4" maxlength="6000"></textarea>'
			. '<div class="dev3fb-paste-actions"><button type="button" class="dev3fb-btn dev3fb-btn--small dev3fb-paste-fill">' . esc_html__( 'Fill the form', 'dev3-ai-form-builder' ) . '</button>'
			. '<button type="button" class="dev3fb-link dev3fb-paste-undo" hidden>' . esc_html__( 'Undo', 'dev3-ai-form-builder' ) . '</button>'
			. '<span class="dev3fb-paste-status" role="status"></span></div>'
			. '<p class="dev3fb-help">' . esc_html__( 'Only empty fields are filled. Please check them before you submit.', 'dev3-ai-form-builder' ) . '</p>'
			. '</div></div>';
	}

	/**
	 * One field.
	 *
	 * @param array $field  Field.
	 * @param bool  $hidden Hidden by logic.
	 * @param array $calc   Calculator result.
	 * @return string
	 */
	private function field( array $field, $hidden, array $calc ) {
		$type  = $field['type'];
		$id    = $this->uid . '-' . $field['id'];
		$value = $this->value( $field );
		$error = isset( $this->errors[ $field['id'] ] ) ? (string) $this->errors[ $field['id'] ] : '';
		if ( 'hidden' === $type ) {
			return '<input type="hidden" name="dev3fb[' . esc_attr( $field['id'] ) . ']" value="' . esc_attr( is_scalar( $value ) ? (string) $value : '' ) . '" data-field="' . esc_attr( $field['id'] ) . '">';
		}
		$classes = array( 'dev3fb-field', 'dev3fb-field--' . $type, 'dev3fb-w-' . $field['width'] );
		if ( '' !== $field['css'] ) {
			$classes[] = $field['css'];
		}
		if ( '' !== $error ) {
			$classes[] = 'dev3fb-has-error';
		}
		$group = in_array( $type, array( 'radio', 'checkbox', 'image_choice', 'name', 'address', 'rating', 'nps', 'scale', 'product' ), true ) && ! ( 'product' === $type && empty( $field['options'] ) );
		$tag   = $group ? 'fieldset' : 'div';
		$help  = '' !== $field['help'] ? '<p class="dev3fb-help" id="' . esc_attr( $id ) . '-help">' . esc_html( $field['help'] ) . '</p>' : '';
		$err   = '<p class="dev3fb-error" id="' . esc_attr( $id ) . '-error"' . ( '' === $error ? ' hidden' : '' ) . '>' . esc_html( $error ) . '</p>';
		$req   = $field['required'] ? '<span class="dev3fb-req" aria-hidden="true">*</span>' : '';
		$label = $field['label'] . ( $field['required'] ? '' : '' );
		$sr    = ! empty( $field['hide_label'] ) ? ' dev3fb-sr' : '';
		$out   = '<' . $tag . ' class="' . esc_attr( implode( ' ', $classes ) ) . '" data-field="' . esc_attr( $field['id'] ) . '" data-type="' . esc_attr( $type ) . '"' . ( $hidden ? ' hidden' : '' ) . '>';

		if ( in_array( $type, array( 'heading', 'paragraph', 'divider' ), true ) ) {
			if ( 'heading' === $type ) {
				$level = 'h' . (int) $field['level'];
				$out  .= '<' . $level . ' class="dev3fb-heading">' . esc_html( $field['label'] ) . '</' . $level . '>';
				$out  .= '' !== $field['help'] ? '<p class="dev3fb-help">' . esc_html( $field['help'] ) . '</p>' : '';
			} elseif ( 'paragraph' === $type ) {
				$out .= '<div class="dev3fb-paragraph">' . wp_kses_post( wpautop( $field['content'] ) ) . '</div>';
			} else {
				$out .= '<hr class="dev3fb-divider">';
			}
			return $out . '</' . $tag . '>';
		}

		if ( $group ) {
			$out .= '<legend class="dev3fb-label' . $sr . '">' . esc_html( $label ) . $req . '</legend>';
		} elseif ( ! in_array( $type, array( 'consent', 'toggle' ), true ) ) {
			$out .= '<label class="dev3fb-label' . $sr . '" for="' . esc_attr( $id ) . '">' . esc_html( $label ) . $req . '</label>';
		}
		$out .= $help;
		$out .= $this->control( $field, $id, $value, $calc, '' !== $error );
		$out .= $err;
		return $out . '</' . $tag . '>';
	}

	/**
	 * Shared input attributes.
	 *
	 * @param array  $field Field.
	 * @param string $id    DOM id.
	 * @param bool   $error Has error.
	 * @return string
	 */
	private function aria( array $field, $id, $error ) {
		$described = array( $id . '-error' );
		if ( '' !== $field['help'] ) {
			$described[] = $id . '-help';
		}
		return ' aria-describedby="' . esc_attr( implode( ' ', $described ) ) . '"' . ( $field['required'] ? ' aria-required="true"' : '' ) . ( $error ? ' aria-invalid="true"' : '' );
	}

	/**
	 * Price suffix for options.
	 *
	 * @param array $option Option.
	 * @return string
	 */
	private function price_label( array $option ) {
		$currency = $this->form['definition']['payments']['currency'];
		return isset( $option['price'] ) && (float) $option['price'] > 0 ? ' <span class="dev3fb-price">' . esc_html( self::money( (float) $option['price'], $currency ) ) . '</span>' : '';
	}

	/**
	 * Control markup.
	 *
	 * @param array  $field Field.
	 * @param string $id    DOM id.
	 * @param mixed  $value Value.
	 * @param array  $calc  Calculator result.
	 * @param bool   $error Has error.
	 * @return string
	 */
	private function control( array $field, $id, $value, array $calc, $error ) {
		$type = $field['type'];
		$name = 'dev3fb[' . $field['id'] . ']';
		$aria = $this->aria( $field, $id, $error );
		$req  = $field['required'] ? ' required' : '';
		$ph   = '' !== $field['placeholder'] ? ' placeholder="' . esc_attr( $field['placeholder'] ) . '"' : '';
		$str  = is_scalar( $value ) ? (string) $value : '';
		switch ( $type ) {
			case 'textarea':
				$max = ! empty( $field['maxlength'] ) ? ' maxlength="' . (int) $field['maxlength'] . '"' : '';
				return '<textarea class="dev3fb-input" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" rows="' . (int) $field['rows'] . '"' . $ph . $max . $req . $aria . '>' . esc_textarea( $str ) . '</textarea>';
			case 'select':
			case 'country':
			case 'multiselect':
				$multiple = 'multiselect' === $type;
				$options  = 'country' === $type ? Countries::options() : $field['options'];
				$selected = $multiple ? (array) $value : array( $str );
				$out      = '<select class="dev3fb-input dev3fb-select" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name . ( $multiple ? '[]' : '' ) ) . '"' . ( $multiple ? ' multiple size="' . esc_attr( (string) min( 6, max( 3, count( $options ) ) ) ) . '"' : '' ) . $req . $aria . ( 'country' === $type ? ' autocomplete="country"' : '' ) . '>';
				if ( ! $multiple ) {
					$out .= '<option value="">' . esc_html( '' !== $field['placeholder'] ? $field['placeholder'] : __( 'Choose…', 'dev3-ai-form-builder' ) ) . '</option>';
				}
				foreach ( $options as $option ) {
					$out .= '<option value="' . esc_attr( $option['value'] ) . '"' . selected( in_array( (string) $option['value'], array_map( 'strval', $selected ), true ), true, false ) . '>' . esc_html( $option['label'] . ( isset( $option['price'] ) && (float) $option['price'] > 0 ? ' (' . self::money( (float) $option['price'], $this->form['definition']['payments']['currency'] ) . ')' : '' ) ) . '</option>';
				}
				return $out . '</select>';
			case 'radio':
			case 'checkbox':
			case 'image_choice':
			case 'product':
				if ( 'product' === $type && empty( $field['options'] ) ) {
					return '<p class="dev3fb-product-fixed" id="' . esc_attr( $id ) . '"><span class="dev3fb-price">' . esc_html( self::money( (float) $field['price'], $this->form['definition']['payments']['currency'] ) ) . '</span></p><input type="hidden" name="' . esc_attr( $name ) . '" value="1">';
				}
				$multi    = 'checkbox' === $type;
				$selected = $multi ? (array) $value : array( $str );
				$layout   = 'image_choice' === $type ? 'grid' : ( isset( $field['layout'] ) ? $field['layout'] : 'stacked' );
				$out      = '<div class="dev3fb-choices dev3fb-choices--' . esc_attr( $layout ) . '"' . ( 'image_choice' === $type ? ' data-images="1"' : '' ) . '>';
				foreach ( $field['options'] as $n => $option ) {
					$oid   = $id . '-' . $n;
					$media = '';
					if ( 'image_choice' === $type ) {
						$media = ! empty( $option['image'] )
							? '<img class="dev3fb-choice-img" src="' . esc_url( $option['image'] ) . '" alt="" loading="lazy" width="160" height="110">'
							: '<span class="dev3fb-choice-tile" aria-hidden="true">' . esc_html( mb_strtoupper( mb_substr( $option['label'], 0, 1 ) ) ) . '</span>';
					}
					$out .= '<label class="dev3fb-choice" for="' . esc_attr( $oid ) . '"><input type="' . ( $multi ? 'checkbox' : 'radio' ) . '" id="' . esc_attr( $oid ) . '" name="' . esc_attr( $name . ( $multi ? '[]' : '' ) ) . '" value="' . esc_attr( $option['value'] ) . '"' . checked( in_array( (string) $option['value'], array_map( 'strval', $selected ), true ), true, false ) . ( 0 === $n ? $aria : '' ) . ( $field['required'] && ! $multi ? ' required' : '' ) . '>' . $media . '<span class="dev3fb-choice-label">' . esc_html( $option['label'] ) . $this->price_label( $option ) . '</span></label>';
				}
				return $out . '</div>';
			case 'name':
				$v = is_array( $value ) ? $value : array();
				return '<div class="dev3fb-sub">'
					. $this->sub_input( $id . '-first', $name . '[first]', isset( $v['first'] ) ? $v['first'] : '', __( 'First name', 'dev3-ai-form-builder' ), 'given-name', $field['required'] )
					. $this->sub_input( $id . '-last', $name . '[last]', isset( $v['last'] ) ? $v['last'] : '', __( 'Last name', 'dev3-ai-form-builder' ), 'family-name', $field['required'] )
					. '</div>';
			case 'address':
				$v       = is_array( $value ) ? $value : array();
				$out     = '<div class="dev3fb-sub dev3fb-sub--address">';
				$out    .= $this->sub_input( $id . '-line1', $name . '[line1]', isset( $v['line1'] ) ? $v['line1'] : '', __( 'Street address', 'dev3-ai-form-builder' ), 'address-line1', $field['required'], 'full' );
				$out    .= $this->sub_input( $id . '-line2', $name . '[line2]', isset( $v['line2'] ) ? $v['line2'] : '', __( 'Apartment, suite, etc.', 'dev3-ai-form-builder' ), 'address-line2', false, 'full' );
				$out    .= $this->sub_input( $id . '-city', $name . '[city]', isset( $v['city'] ) ? $v['city'] : '', __( 'City', 'dev3-ai-form-builder' ), 'address-level2', $field['required'] );
				$out    .= $this->sub_input( $id . '-state', $name . '[state]', isset( $v['state'] ) ? $v['state'] : '', __( 'State / Region', 'dev3-ai-form-builder' ), 'address-level1', false );
				$out    .= $this->sub_input( $id . '-zip', $name . '[zip]', isset( $v['zip'] ) ? $v['zip'] : '', __( 'Postal code', 'dev3-ai-form-builder' ), 'postal-code', false );
				$country = isset( $v['country'] ) ? $v['country'] : '';
				$out    .= '<div class="dev3fb-subfield"><label class="dev3fb-sublabel" for="' . esc_attr( $id ) . '-country">' . esc_html__( 'Country', 'dev3-ai-form-builder' ) . '</label><select class="dev3fb-input dev3fb-select" id="' . esc_attr( $id ) . '-country" name="' . esc_attr( $name ) . '[country]" autocomplete="country"><option value="">' . esc_html__( 'Choose…', 'dev3-ai-form-builder' ) . '</option>';
				foreach ( Countries::all() as $code => $label ) {
					$out .= '<option value="' . esc_attr( $code ) . '"' . selected( $country, $code, false ) . '>' . esc_html( $label ) . '</option>';
				}
				return $out . '</select></div></div>';
			case 'file':
				$max = (int) $field['max_files'];
				$acc = '' !== $field['accept'] ? ' accept="' . esc_attr( '.' . implode( ',.', explode( ',', $field['accept'] ) ) ) . '"' : '';
				/* translators: 1: extensions, 2: size in MB */
				$hint = sprintf( __( '%1$s · max %2$s MB', 'dev3-ai-form-builder' ), strtoupper( str_replace( ',', ', ', $field['accept'] ) ), number_format_i18n( min( (float) $field['max_size'], (float) Settings_Store::get( 'upload_max_mb', 10 ) ), 0 ) );
				return '<div class="dev3fb-file"><input class="dev3fb-file-input" type="file" id="' . esc_attr( $id ) . '" name="' . esc_attr( 'dev3fb_files_' . $field['id'] . '[]' ) . '"' . ( $max > 1 ? ' multiple' : '' ) . $acc . $req . $aria . '><span class="dev3fb-file-hint">' . esc_html( $hint ) . '</span></div>';
			case 'signature':
				return '<div class="dev3fb-signature"><canvas class="dev3fb-signature-pad" width="600" height="180" role="img" aria-label="' . esc_attr__( 'Signature pad: draw with your mouse or finger', 'dev3-ai-form-builder' ) . '"></canvas>'
					. '<input type="hidden" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" value="">'
					. '<div class="dev3fb-signature-bar"><span class="dev3fb-help">' . esc_html__( 'Sign above', 'dev3-ai-form-builder' ) . '</span><button type="button" class="dev3fb-link dev3fb-signature-clear">' . esc_html__( 'Clear', 'dev3-ai-form-builder' ) . '</button></div>'
					. '<noscript><p class="dev3fb-help">' . esc_html__( 'Signing needs JavaScript.', 'dev3-ai-form-builder' ) . '</p></noscript></div>';
			case 'rating':
				$out = '<div class="dev3fb-rating">';
				for ( $i = (int) $field['max']; $i >= 1; $i-- ) {
					$oid = $id . '-' . $i;
					/* translators: 1: stars, 2: max */
					$out .= '<input type="radio" id="' . esc_attr( $oid ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( (string) $i ) . '"' . checked( $str, (string) $i, false ) . '><label for="' . esc_attr( $oid ) . '" title="' . esc_attr( sprintf( __( '%1$d of %2$d', 'dev3-ai-form-builder' ), $i, (int) $field['max'] ) ) . '"><span class="dev3fb-sr">' . esc_html( sprintf( __( '%1$d of %2$d', 'dev3-ai-form-builder' ), $i, (int) $field['max'] ) ) . '</span><svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M12 2.8l2.8 5.8 6.4.9-4.6 4.5 1.1 6.3L12 17.3l-5.7 3 1.1-6.3L2.8 9.5l6.4-.9z"/></svg></label>';
				}
				return $out . '</div>';
			case 'nps':
			case 'scale':
				$min = 'nps' === $type ? 0 : (int) $field['min'];
				$max = 'nps' === $type ? 10 : (int) $field['max'];
				$out = '<div class="dev3fb-scale" style="--dev3fb-cols:' . esc_attr( (string) ( $max - $min + 1 ) ) . '">';
				for ( $i = $min; $i <= $max; $i++ ) {
					$oid  = $id . '-' . $i;
					$out .= '<input type="radio" id="' . esc_attr( $oid ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( (string) $i ) . '"' . checked( $str, (string) $i, false ) . '><label for="' . esc_attr( $oid ) . '">' . esc_html( (string) $i ) . '</label>';
				}
				$out .= '</div>';
				if ( '' !== $field['min_label'] || '' !== $field['max_label'] ) {
					$out .= '<div class="dev3fb-scale-labels"><span>' . esc_html( $field['min_label'] ) . '</span><span>' . esc_html( $field['max_label'] ) . '</span></div>';
				}
				return $out;
			case 'range':
				$current = '' === $str ? (string) $field['min'] : $str;
				return '<div class="dev3fb-range"><input class="dev3fb-range-input" type="range" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" min="' . esc_attr( (string) $field['min'] ) . '" max="' . esc_attr( (string) $field['max'] ) . '" step="' . esc_attr( (string) $field['step'] ) . '" value="' . esc_attr( $current ) . '"' . $aria . '><output class="dev3fb-range-value" for="' . esc_attr( $id ) . '">' . esc_html( $field['prefix'] . $current . $field['suffix'] ) . '</output></div>';
			case 'toggle':
				return '<label class="dev3fb-toggle" for="' . esc_attr( $id ) . '"><input type="checkbox" role="switch" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" value="1"' . checked( ! empty( $value ), true, false ) . $aria . '><span class="dev3fb-toggle-track" aria-hidden="true"></span><span class="dev3fb-toggle-label">' . esc_html( $field['label'] ) . ( $field['required'] ? '<span class="dev3fb-req" aria-hidden="true">*</span>' : '' ) . '</span></label>';
			case 'consent':
				$text = '' !== $field['content'] ? wp_kses_post( $field['content'] ) : esc_html( $field['label'] );
				return '<label class="dev3fb-consent" for="' . esc_attr( $id ) . '"><input type="checkbox" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" value="1"' . checked( ! empty( $value ), true, false ) . $req . $aria . '><span>' . $text . ( $field['required'] ? '<span class="dev3fb-req" aria-hidden="true">*</span>' : '' ) . '</span></label>';
			case 'calculation':
			case 'total':
				$current = isset( $calc['values'][ $field['id'] ] ) ? (string) $calc['values'][ $field['id'] ] : '';
				if ( 'total' === $type ) {
					$display = self::money( (float) $current, $this->form['definition']['payments']['currency'] );
				} else {
					$display = $field['prefix'] . ( '' === $current ? '0' : number_format_i18n( (float) $current, (int) $field['decimals'] ) ) . $field['suffix'];
				}
				$hide = 'calculation' === $type && empty( $field['show'] ) ? ' dev3fb-sr' : '';
				return '<output class="dev3fb-output' . $hide . '" id="' . esc_attr( $id ) . '" aria-live="polite">' . esc_html( $display ) . '</output>';
			case 'number':
			case 'quantity':
			case 'amount':
				$attrs = '';
				foreach ( array( 'min', 'max', 'step' ) as $key ) {
					if ( isset( $field[ $key ] ) && '' !== $field[ $key ] ) {
						$attrs .= ' ' . $key . '="' . esc_attr( (string) $field[ $key ] ) . '"';
					}
				}
				$prefix = 'amount' === $type ? self::symbol( $this->form['definition']['payments']['currency'] ) : $field['prefix'];
				$input  = '<input class="dev3fb-input" type="number" inputmode="decimal" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( $str ) . '"' . $attrs . $ph . $req . $aria . '>';
				if ( '' !== trim( $prefix ) || '' !== $field['suffix'] ) {
					return '<div class="dev3fb-affix">' . ( '' !== trim( $prefix ) ? '<span class="dev3fb-affix-text">' . esc_html( trim( $prefix ) ) . '</span>' : '' ) . $input . ( '' !== $field['suffix'] ? '<span class="dev3fb-affix-text">' . esc_html( $field['suffix'] ) . '</span>' : '' ) . '</div>';
				}
				return $input;
		}
		$types  = array(
			'email'    => array( 'email', 'email', 'email' ),
			'phone'    => array( 'tel', 'tel', 'tel' ),
			'url'      => array( 'url', 'url', 'url' ),
			'date'     => array( 'date', '', '' ),
			'time'     => array( 'time', '', '' ),
			'color'    => array( 'color', '', '' ),
			'password' => array( 'password', 'new-password', '' ),
		);
		$spec   = isset( $types[ $type ] ) ? $types[ $type ] : array( 'text', '', '' );
		$extra  = '' !== $spec[1] ? ' autocomplete="' . esc_attr( $spec[1] ) . '"' : '';
		$extra .= '' !== $spec[2] ? ' inputmode="' . esc_attr( $spec[2] ) . '"' : '';
		if ( 'text' === $type ) {
			$role = '' !== $field['role'] ? $field['role'] : '';
			$auto = array(
				'first_name' => 'given-name',
				'last_name'  => 'family-name',
				'company'    => 'organization',
				'city'       => 'address-level2',
				'zip'        => 'postal-code',
			);
			if ( isset( $auto[ $role ] ) ) {
				$extra .= ' autocomplete="' . esc_attr( $auto[ $role ] ) . '"';
			}
		}
		if ( 'date' === $type ) {
			$extra .= ! empty( $field['min_date'] ) ? ' min="' . esc_attr( $field['min_date'] ) . '"' : '';
			$extra .= ! empty( $field['max_date'] ) ? ' max="' . esc_attr( $field['max_date'] ) . '"' : '';
		}
		if ( 'color' === $type && '' === $str ) {
			$str = '#4f46e5';
		}
		$max = ! empty( $field['maxlength'] ) ? ' maxlength="' . (int) $field['maxlength'] . '"' : '';
		return '<input class="dev3fb-input" type="' . esc_attr( $spec[0] ) . '" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( 'password' === $type ? '' : $str ) . '"' . $ph . $max . $extra . $req . $aria . '>';
	}

	/**
	 * Sub input (name/address parts).
	 *
	 * @param string $id       DOM id.
	 * @param string $name     Name.
	 * @param string $value    Value.
	 * @param string $label    Label.
	 * @param string $auto     Autocomplete.
	 * @param bool   $required Required.
	 * @param string $width    half|full.
	 * @return string
	 */
	private function sub_input( $id, $name, $value, $label, $auto, $required, $width = 'half' ) {
		return '<div class="dev3fb-subfield dev3fb-subfield--' . esc_attr( $width ) . '"><label class="dev3fb-sublabel" for="' . esc_attr( $id ) . '">' . esc_html( $label ) . '</label><input class="dev3fb-input" type="text" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( (string) $value ) . '" autocomplete="' . esc_attr( $auto ) . '"' . ( $required ? ' required aria-required="true"' : '' ) . '></div>';
	}
}
