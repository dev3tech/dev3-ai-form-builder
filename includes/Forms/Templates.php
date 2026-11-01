<?php
/**
 * Starter templates (normalized through Definition like everything else).
 *
 * @package Dev3\AiFormBuilder
 */

namespace Dev3\AiFormBuilder\Forms;

defined( 'ABSPATH' ) || exit;

/**
 * Templates.
 */
class Templates {

	/**
	 * Option list helper.
	 *
	 * @param array $labels Labels.
	 * @return array
	 */
	private static function opts( array $labels ) {
		return array_map(
			static function ( $label ) {
				return array( 'label' => $label );
			},
			$labels
		);
	}

	/**
	 * All templates: id => { title, description, icon, category, definition }.
	 *
	 * @return array
	 */
	public static function all() {
		$t = array(
			'contact'      => array(
				'title'       => __( 'Contact form', 'dev3-ai-form-builder' ),
				'description' => __( 'Name, email, subject and message with paste-to-fill.', 'dev3-ai-form-builder' ),
				'icon'        => 'inbox',
				'category'    => 'basic',
				'definition'  => array(
					'steps'      => array(
						array(
							'fields' => array(
								array(
									'id'       => 'name',
									'type'     => 'name',
									'label'    => __( 'Your name', 'dev3-ai-form-builder' ),
									'required' => true,
								),
								array(
									'id'       => 'email',
									'type'     => 'email',
									'label'    => __( 'Email', 'dev3-ai-form-builder' ),
									'required' => true,
									'width'    => 'half',
								),
								array(
									'id'    => 'phone',
									'type'  => 'phone',
									'label' => __( 'Phone', 'dev3-ai-form-builder' ),
									'width' => 'half',
								),
								array(
									'id'      => 'topic',
									'type'    => 'select',
									'label'   => __( 'Topic', 'dev3-ai-form-builder' ),
									'options' => self::opts( array( __( 'General question', 'dev3-ai-form-builder' ), __( 'Sales', 'dev3-ai-form-builder' ), __( 'Support', 'dev3-ai-form-builder' ), __( 'Other', 'dev3-ai-form-builder' ) ) ),
								),
								array(
									'id'       => 'message',
									'type'     => 'textarea',
									'label'    => __( 'Message', 'dev3-ai-form-builder' ),
									'required' => true,
									'rows'     => 5,
								),
								array(
									'id'       => 'consent',
									'type'     => 'consent',
									'label'    => __( 'Privacy', 'dev3-ai-form-builder' ),
									'content'  => __( 'I agree that my details are stored to answer my request.', 'dev3-ai-form-builder' ),
									'required' => true,
								),
							),
						),
					),
					'protection' => array( 'paste_fill' => true ),
				),
			),
			'quote'        => array(
				'title'       => __( 'Quote request (multi-step)', 'dev3-ai-form-builder' ),
				'description' => __( 'Three steps: project, budget and contact, with conditional logic.', 'dev3-ai-form-builder' ),
				'icon'        => 'chart',
				'category'    => 'business',
				'definition'  => array(
					'steps'    => array(
						array(
							'title'  => __( 'Your project', 'dev3-ai-form-builder' ),
							'fields' => array(
								array(
									'id'       => 'service',
									'type'     => 'radio',
									'label'    => __( 'What do you need?', 'dev3-ai-form-builder' ),
									'required' => true,
									'options'  => self::opts( array( __( 'New website', 'dev3-ai-form-builder' ), __( 'Online shop', 'dev3-ai-form-builder' ), __( 'Redesign', 'dev3-ai-form-builder' ), __( 'Other', 'dev3-ai-form-builder' ) ) ),
								),
								array(
									'id'    => 'service_other',
									'type'  => 'text',
									'label' => __( 'Please describe', 'dev3-ai-form-builder' ),
									'logic' => array(
										'action' => 'show',
										'rules'  => array(
											array(
												'field' => 'service',
												'op'    => 'is',
												'value' => __( 'Other', 'dev3-ai-form-builder' ),
											),
										),
									),
								),
								array(
									'id'    => 'details',
									'type'  => 'textarea',
									'label' => __( 'Project details', 'dev3-ai-form-builder' ),
								),
							),
						),
						array(
							'title'  => __( 'Budget & timing', 'dev3-ai-form-builder' ),
							'fields' => array(
								array(
									'id'       => 'budget',
									'type'     => 'select',
									'label'    => __( 'Budget', 'dev3-ai-form-builder' ),
									'required' => true,
									'options'  => self::opts( array( '< $2,000', '$2,000 - $5,000', '$5,000 - $15,000', '$15,000+' ) ),
								),
								array(
									'id'    => 'deadline',
									'type'  => 'date',
									'label' => __( 'Ideal launch date', 'dev3-ai-form-builder' ),
								),
							),
						),
						array(
							'title'  => __( 'Contact', 'dev3-ai-form-builder' ),
							'fields' => array(
								array(
									'id'       => 'name',
									'type'     => 'name',
									'label'    => __( 'Name', 'dev3-ai-form-builder' ),
									'required' => true,
								),
								array(
									'id'       => 'email',
									'type'     => 'email',
									'label'    => __( 'Work email', 'dev3-ai-form-builder' ),
									'required' => true,
									'width'    => 'half',
								),
								array(
									'id'    => 'company',
									'type'  => 'text',
									'label' => __( 'Company', 'dev3-ai-form-builder' ),
									'width' => 'half',
								),
							),
						),
					),
					'settings' => array( 'save_continue' => true ),
				),
			),
			'registration' => array(
				'title'       => __( 'Event registration', 'dev3-ai-form-builder' ),
				'description' => __( 'Tickets with prices, quantity, total and Stripe/PayPal payment.', 'dev3-ai-form-builder' ),
				'icon'        => 'users',
				'category'    => 'payment',
				'definition'  => array(
					'steps'    => array(
						array(
							'fields' => array(
								array(
									'id'       => 'name',
									'type'     => 'name',
									'label'    => __( 'Attendee name', 'dev3-ai-form-builder' ),
									'required' => true,
								),
								array(
									'id'       => 'email',
									'type'     => 'email',
									'label'    => __( 'Email', 'dev3-ai-form-builder' ),
									'required' => true,
								),
								array(
									'id'       => 'ticket',
									'type'     => 'product',
									'label'    => __( 'Ticket', 'dev3-ai-form-builder' ),
									'required' => true,
									'options'  => array(
										array(
											'label' => __( 'Standard', 'dev3-ai-form-builder' ),
											'price' => 49,
										),
										array(
											'label' => __( 'VIP', 'dev3-ai-form-builder' ),
											'price' => 129,
										),
									),
								),
								array(
									'id'      => 'tickets_qty',
									'type'    => 'quantity',
									'label'   => __( 'Number of tickets', 'dev3-ai-form-builder' ),
									'product' => 'ticket',
									'min'     => 1,
									'max'     => 10,
									'default' => '1',
								),
								array(
									'id'    => 'total',
									'type'  => 'total',
									'label' => __( 'Total', 'dev3-ai-form-builder' ),
								),
							),
						),
					),
					'payments' => array(
						'enabled'   => true,
						'providers' => array( 'stripe', 'paypal' ),
						'currency'  => 'USD',
					),
				),
			),
			'feedback'     => array(
				'title'       => __( 'Customer feedback (NPS)', 'dev3-ai-form-builder' ),
				'description' => __( 'NPS, star rating and a follow-up question shown for low scores.', 'dev3-ai-form-builder' ),
				'icon'        => 'sparkles',
				'category'    => 'survey',
				'definition'  => array(
					'steps' => array(
						array(
							'fields' => array(
								array(
									'id'       => 'nps',
									'type'     => 'nps',
									'label'    => __( 'How likely are you to recommend us to a friend?', 'dev3-ai-form-builder' ),
									'required' => true,
								),
								array(
									'id'    => 'improve',
									'type'  => 'textarea',
									'label' => __( 'What could we do better?', 'dev3-ai-form-builder' ),
									'logic' => array(
										'action' => 'show',
										'rules'  => array(
											array(
												'field' => 'nps',
												'op'    => 'lte',
												'value' => '6',
											),
										),
									),
								),
								array(
									'id'    => 'rating',
									'type'  => 'rating',
									'label' => __( 'Rate our support', 'dev3-ai-form-builder' ),
								),
								array(
									'id'    => 'email',
									'type'  => 'email',
									'label' => __( 'Email (optional, if you want a reply)', 'dev3-ai-form-builder' ),
								),
							),
						),
					),
				),
			),
			'job'          => array(
				'title'       => __( 'Job application', 'dev3-ai-form-builder' ),
				'description' => __( 'CV upload, position, experience and signature.', 'dev3-ai-form-builder' ),
				'icon'        => 'file',
				'category'    => 'business',
				'definition'  => array(
					'steps' => array(
						array(
							'title'  => __( 'About you', 'dev3-ai-form-builder' ),
							'fields' => array(
								array(
									'id'       => 'name',
									'type'     => 'name',
									'label'    => __( 'Full name', 'dev3-ai-form-builder' ),
									'required' => true,
								),
								array(
									'id'       => 'email',
									'type'     => 'email',
									'label'    => __( 'Email', 'dev3-ai-form-builder' ),
									'required' => true,
									'width'    => 'half',
								),
								array(
									'id'    => 'phone',
									'type'  => 'phone',
									'label' => __( 'Phone', 'dev3-ai-form-builder' ),
									'width' => 'half',
								),
								array(
									'id'    => 'linkedin',
									'type'  => 'url',
									'label' => __( 'LinkedIn profile', 'dev3-ai-form-builder' ),
								),
							),
						),
						array(
							'title'  => __( 'The role', 'dev3-ai-form-builder' ),
							'fields' => array(
								array(
									'id'       => 'position',
									'type'     => 'select',
									'label'    => __( 'Position', 'dev3-ai-form-builder' ),
									'required' => true,
									'options'  => self::opts( array( __( 'Frontend developer', 'dev3-ai-form-builder' ), __( 'Backend developer', 'dev3-ai-form-builder' ), __( 'Designer', 'dev3-ai-form-builder' ), __( 'Project manager', 'dev3-ai-form-builder' ) ) ),
								),
								array(
									'id'    => 'years',
									'type'  => 'range',
									'label' => __( 'Years of experience', 'dev3-ai-form-builder' ),
									'min'   => 0,
									'max'   => 30,
								),
								array(
									'id'       => 'cv',
									'type'     => 'file',
									'label'    => __( 'CV (PDF)', 'dev3-ai-form-builder' ),
									'accept'   => 'pdf,doc,docx',
									'max_size' => 5,
									'required' => true,
								),
								array(
									'id'       => 'signature',
									'type'     => 'signature',
									'label'    => __( 'Signature', 'dev3-ai-form-builder' ),
									'help'     => __( 'Sign to confirm that the information is correct.', 'dev3-ai-form-builder' ),
									'required' => true,
								),
							),
						),
					),
				),
			),
			'calculator'   => array(
				'title'       => __( 'Price calculator', 'dev3-ai-form-builder' ),
				'description' => __( 'Live calculation with a formula, shown as the visitor types.', 'dev3-ai-form-builder' ),
				'icon'        => 'bolt',
				'category'    => 'payment',
				'definition'  => array(
					'steps' => array(
						array(
							'fields' => array(
								array(
									'id'       => 'area',
									'type'     => 'number',
									'label'    => __( 'Area (m²)', 'dev3-ai-form-builder' ),
									'min'      => 1,
									'required' => true,
									'width'    => 'half',
								),
								array(
									'id'      => 'quality',
									'type'    => 'select',
									'label'   => __( 'Finish', 'dev3-ai-form-builder' ),
									'width'   => 'half',
									'options' => array(
										array(
											'label' => __( 'Basic', 'dev3-ai-form-builder' ),
											'value' => '20',
										),
										array(
											'label' => __( 'Premium', 'dev3-ai-form-builder' ),
											'value' => '35',
										),
									),
								),
								array(
									'id'      => 'estimate',
									'type'    => 'calculation',
									'label'   => __( 'Estimated price', 'dev3-ai-form-builder' ),
									'formula' => 'round({area} * {quality}, 2)',
									'prefix'  => '$',
								),
								array(
									'id'       => 'email',
									'type'     => 'email',
									'label'    => __( 'Send me the quote', 'dev3-ai-form-builder' ),
									'required' => true,
								),
							),
						),
					),
				),
			),
			'newsletter'   => array(
				'title'       => __( 'Newsletter signup', 'dev3-ai-form-builder' ),
				'description' => __( 'Email + consent, ready for Mailchimp or Brevo.', 'dev3-ai-form-builder' ),
				'icon'        => 'inbox',
				'category'    => 'marketing',
				'definition'  => array(
					'steps'        => array(
						array(
							'fields' => array(
								array(
									'id'    => 'first_name',
									'type'  => 'text',
									'label' => __( 'First name', 'dev3-ai-form-builder' ),
									'width' => 'half',
								),
								array(
									'id'       => 'email',
									'type'     => 'email',
									'label'    => __( 'Email', 'dev3-ai-form-builder' ),
									'required' => true,
									'width'    => 'half',
								),
								array(
									'id'       => 'consent',
									'type'     => 'consent',
									'label'    => __( 'Consent', 'dev3-ai-form-builder' ),
									'content'  => __( 'Yes, send me news and offers. I can unsubscribe at any time.', 'dev3-ai-form-builder' ),
									'required' => true,
								),
							),
						),
					),
					'settings'     => array( 'submit_label' => __( 'Subscribe', 'dev3-ai-form-builder' ) ),
					'integrations' => array( 'consent_field' => 'consent' ),
				),
			),
			'booking'      => array(
				'title'       => __( 'Appointment request', 'dev3-ai-form-builder' ),
				'description' => __( 'Date, time, service and address with a conditional step.', 'dev3-ai-form-builder' ),
				'icon'        => 'clock',
				'category'    => 'business',
				'definition'  => array(
					'steps' => array(
						array(
							'title'  => __( 'Appointment', 'dev3-ai-form-builder' ),
							'fields' => array(
								array(
									'id'       => 'service',
									'type'     => 'image_choice',
									'label'    => __( 'Service', 'dev3-ai-form-builder' ),
									'required' => true,
									'options'  => self::opts( array( __( 'Consultation', 'dev3-ai-form-builder' ), __( 'Repair', 'dev3-ai-form-builder' ), __( 'Installation', 'dev3-ai-form-builder' ) ) ),
								),
								array(
									'id'       => 'date',
									'type'     => 'date',
									'label'    => __( 'Preferred date', 'dev3-ai-form-builder' ),
									'required' => true,
									'width'    => 'half',
								),
								array(
									'id'    => 'time',
									'type'  => 'time',
									'label' => __( 'Preferred time', 'dev3-ai-form-builder' ),
									'width' => 'half',
								),
								array(
									'id'    => 'onsite',
									'type'  => 'toggle',
									'label' => __( 'Visit at my address', 'dev3-ai-form-builder' ),
								),
							),
						),
						array(
							'title'  => __( 'Where', 'dev3-ai-form-builder' ),
							'logic'  => array(
								'action' => 'show',
								'rules'  => array(
									array(
										'field' => 'onsite',
										'op'    => 'is',
										'value' => '1',
									),
								),
							),
							'fields' => array(
								array(
									'id'       => 'address',
									'type'     => 'address',
									'label'    => __( 'Address', 'dev3-ai-form-builder' ),
									'required' => true,
								),
							),
						),
						array(
							'title'  => __( 'Contact', 'dev3-ai-form-builder' ),
							'fields' => array(
								array(
									'id'       => 'name',
									'type'     => 'name',
									'label'    => __( 'Name', 'dev3-ai-form-builder' ),
									'required' => true,
								),
								array(
									'id'       => 'email',
									'type'     => 'email',
									'label'    => __( 'Email', 'dev3-ai-form-builder' ),
									'required' => true,
									'width'    => 'half',
								),
								array(
									'id'       => 'phone',
									'type'     => 'phone',
									'label'    => __( 'Phone', 'dev3-ai-form-builder' ),
									'required' => true,
									'width'    => 'half',
								),
							),
						),
					),
				),
			),
		);
		/**
		 * Filters the starter templates.
		 *
		 * @param array $templates Templates.
		 */
		return (array) apply_filters( 'dev3fb_templates', $t );
	}

	/**
	 * Summaries for the UI.
	 *
	 * @return array [ { id, title, description, icon, category, fields, steps } ]
	 */
	public static function summaries() {
		$out = array();
		foreach ( self::all() as $id => $template ) {
			$definition = Definition::normalize( $template['definition'] );
			$out[]      = array(
				'id'          => $id,
				'title'       => $template['title'],
				'description' => $template['description'],
				'icon'        => $template['icon'],
				'category'    => $template['category'],
				'fields'      => count( Definition::input_fields( $definition ) ),
				'steps'       => count( $definition['steps'] ),
			);
		}
		return $out;
	}

	/**
	 * Normalized definition of a template.
	 *
	 * @param string $id Template id.
	 * @return array|null { title, definition }
	 */
	public static function get( $id ) {
		$all = self::all();
		if ( ! isset( $all[ $id ] ) ) {
			return null;
		}
		$definition = Definition::normalize( $all[ $id ]['definition'] );
		$email      = Definition::email_field( $definition );
		if ( '' !== $email && isset( $definition['notifications'][0] ) ) {
			$definition['notifications'][0]['reply_to'] = '{field:' . $email . '}';
		}
		return array(
			'title'      => $all[ $id ]['title'],
			'definition' => $definition,
		);
	}
}
