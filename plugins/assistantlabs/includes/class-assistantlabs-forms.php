<?php
/**
 * Form leads: Contact Form 7, WPForms, Elementor Pro and Gravity Forms → the CRM.
 *
 * Off until the owner turns it on. Sending never blocks a visitor for long:
 * the request is fire-and-forget with a short timeout, and failures are ignored.
 *
 * @package AssistantLabs
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Hooks into the form plugins and posts normalized submissions to the server.
 */
class AssistantLabs_Forms {

	const VALUE_LIMIT = 2000;

	/** Field types never sent. */
	const SKIP_TYPES = array( 'password', 'creditcard', 'credit-card', 'stripe-credit-card', 'stripe_creditcard', 'square_creditcard', 'authorize_net', 'square', 'paypal-commerce', 'mollie', 'captcha', 'recaptcha', 'recaptcha_v3', 'hcaptcha', 'turnstile', 'honeypot', 'html', 'section', 'page', 'divider', 'pagebreak', 'step', 'submit', 'hidden_honeypot' );

	/**
	 * Hooks (only when enabled and connected).
	 */
	public static function init() {
		$settings = AssistantLabs_Options::forms();
		if ( empty( $settings['enabled'] ) || ! AssistantLabs_Options::is_connected() ) {
			return;
		}
		add_action( 'wpcf7_mail_sent', array( __CLASS__, 'on_cf7' ), 10, 1 );
		add_action( 'wpforms_process_complete', array( __CLASS__, 'on_wpforms' ), 10, 4 );
		add_action( 'elementor_pro/forms/new_record', array( __CLASS__, 'on_elementor' ), 10, 2 );
		add_action( 'gform_after_submission', array( __CLASS__, 'on_gravity' ), 10, 2 );
	}

	/**
	 * Supported form plugins and whether each is active.
	 *
	 * @return array<string,array{name:string,active:bool}>
	 */
	public static function detected() {
		return array(
			'cf7'          => array(
				'name'   => 'Contact Form 7',
				'active' => defined( 'WPCF7_VERSION' ),
			),
			'wpforms'      => array(
				'name'   => 'WPForms',
				'active' => function_exists( 'wpforms' ),
			),
			'elementor'    => array(
				'name'   => 'Elementor Pro Forms',
				'active' => defined( 'ELEMENTOR_PRO_VERSION' ),
			),
			'gravityforms' => array(
				'name'   => 'Gravity Forms',
				'active' => class_exists( 'GFForms' ),
			),
		);
	}

	/**
	 * Contact Form 7.
	 *
	 * @param WPCF7_ContactForm $form Form.
	 */
	public static function on_cf7( $form ) {
		if ( ! class_exists( 'WPCF7_Submission' ) ) {
			return;
		}
		$submission = WPCF7_Submission::get_instance();
		if ( ! $submission ) {
			return;
		}
		$types = array();
		foreach ( $form->scan_form_tags() as $tag ) {
			if ( ! empty( $tag->name ) ) {
				$types[ $tag->name ] = $tag->basetype;
			}
		}
		$fields = array();
		foreach ( (array) $submission->get_posted_data() as $key => $value ) {
			if ( 0 === strpos( (string) $key, '_' ) ) {
				continue;
			}
			$type = isset( $types[ $key ] ) ? $types[ $key ] : '';
			if ( in_array( $type, self::SKIP_TYPES, true ) || 'quiz' === $type ) {
				continue;
			}
			$fields[] = self::field( $key, self::label_from_key( $key ), $value );
		}
		self::send(
			array(
				'source'   => 'cf7',
				'formId'   => (string) $form->id(),
				'formName' => (string) $form->title(),
				'pageUrl'  => (string) $submission->get_meta( 'url' ),
				'fields'   => $fields,
			)
		);
	}

	/**
	 * WPForms.
	 *
	 * @param array $fields    Processed fields.
	 * @param array $entry     Raw entry.
	 * @param array $form_data Form settings.
	 * @param int   $entry_id  Entry id.
	 */
	public static function on_wpforms( $fields, $entry, $form_data, $entry_id = 0 ) {
		$out = array();
		foreach ( (array) $fields as $field ) {
			$type = isset( $field['type'] ) ? $field['type'] : '';
			if ( in_array( $type, self::SKIP_TYPES, true ) || 0 === strpos( $type, 'payment' ) ) {
				continue;
			}
			$out[] = self::field(
				isset( $field['id'] ) ? 'field_' . $field['id'] : '',
				isset( $field['name'] ) ? $field['name'] : '',
				isset( $field['value'] ) ? $field['value'] : ''
			);
		}
		self::send(
			array(
				'source'   => 'wpforms',
				'formId'   => isset( $form_data['id'] ) ? (string) $form_data['id'] : '',
				'formName' => isset( $form_data['settings']['form_title'] ) ? (string) $form_data['settings']['form_title'] : '',
				'pageUrl'  => self::page_url(),
				'fields'   => $out,
			)
		);
	}

	/**
	 * Elementor Pro forms.
	 *
	 * @param \ElementorPro\Modules\Forms\Classes\Form_Record $record  Record.
	 * @param \ElementorPro\Modules\Forms\Classes\Ajax_Handler $handler Handler.
	 */
	public static function on_elementor( $record, $handler ) {
		$out = array();
		foreach ( (array) $record->get( 'fields' ) as $id => $field ) {
			$type = isset( $field['type'] ) ? $field['type'] : '';
			if ( in_array( $type, self::SKIP_TYPES, true ) || in_array( $type, array( 'acceptance', 'step' ), true ) ) {
				continue;
			}
			$out[] = self::field(
				isset( $field['id'] ) ? $field['id'] : $id,
				isset( $field['title'] ) && '' !== $field['title'] ? $field['title'] : $id,
				isset( $field['value'] ) ? $field['value'] : ''
			);
		}
		$meta     = (array) $record->get( 'meta' );
		$page_url = isset( $meta['page_url']['value'] ) ? $meta['page_url']['value'] : self::page_url();
		self::send(
			array(
				'source'   => 'elementor',
				'formId'   => (string) $record->get_form_settings( 'id' ),
				'formName' => (string) $record->get_form_settings( 'form_name' ),
				'pageUrl'  => (string) $page_url,
				'fields'   => $out,
			)
		);
	}

	/**
	 * Gravity Forms.
	 *
	 * @param array $entry Entry.
	 * @param array $form  Form.
	 */
	public static function on_gravity( $entry, $form ) {
		$out = array();
		foreach ( isset( $form['fields'] ) ? (array) $form['fields'] : array() as $field ) {
			$type = is_object( $field ) ? $field->type : ( isset( $field['type'] ) ? $field['type'] : '' );
			if ( in_array( $type, self::SKIP_TYPES, true ) || 0 === strpos( (string) $type, 'product' ) || in_array( $type, array( 'total', 'shipping', 'option', 'quantity' ), true ) ) {
				continue;
			}
			$id    = is_object( $field ) ? $field->id : $field['id'];
			$label = is_object( $field ) ? $field->label : $field['label'];
			if ( is_object( $field ) && method_exists( $field, 'get_value_export' ) ) {
				$value = $field->get_value_export( $entry, (string) $id, true );
			} else {
				$value = isset( $entry[ (string) $id ] ) ? $entry[ (string) $id ] : '';
			}
			$out[] = self::field( 'input_' . $id, $label, $value );
		}
		self::send(
			array(
				'source'   => 'gravityforms',
				'formId'   => isset( $form['id'] ) ? (string) $form['id'] : '',
				'formName' => isset( $form['title'] ) ? (string) $form['title'] : '',
				'pageUrl'  => isset( $entry['source_url'] ) ? (string) $entry['source_url'] : self::page_url(),
				'fields'   => $out,
			)
		);
	}

	/**
	 * One normalized field.
	 *
	 * @param string $key   Key.
	 * @param string $label Label.
	 * @param mixed  $value Value (arrays are joined).
	 * @return array{key:string,label:string,value:string}
	 */
	private static function field( $key, $label, $value ) {
		if ( is_array( $value ) ) {
			$value = implode( ', ', array_map( 'strval', array_filter( $value, 'is_scalar' ) ) );
		}
		$value = sanitize_textarea_field( (string) $value );
		if ( function_exists( 'mb_substr' ) ) {
			$value = mb_substr( $value, 0, self::VALUE_LIMIT );
		}
		return array(
			'key'   => sanitize_text_field( (string) $key ),
			'label' => sanitize_text_field( (string) $label ),
			'value' => $value,
		);
	}

	/**
	 * "your-email" → "Your email".
	 *
	 * @param string $key Key.
	 * @return string
	 */
	private static function label_from_key( $key ) {
		$label = trim( preg_replace( '/[-_]+/', ' ', preg_replace( '/^your[-_]/', '', (string) $key ) ) );
		return '' === $label ? (string) $key : ucfirst( $label );
	}

	/**
	 * The page the form was on, best effort.
	 *
	 * @return string
	 */
	private static function page_url() {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- read-only, the form plugin verified the submission.
		if ( ! empty( $_POST['referrer'] ) ) {
			return esc_url_raw( wp_unslash( $_POST['referrer'] ) );
		}
		if ( ! empty( $_POST['page_url'] ) ) {
			return esc_url_raw( wp_unslash( $_POST['page_url'] ) );
		}
		// phpcs:enable
		$referer = wp_get_referer();
		return $referer ? esc_url_raw( $referer ) : '';
	}

	/**
	 * POST the submission, signed, without holding up the visitor.
	 *
	 * @param array $submission Normalized submission.
	 */
	public static function send( array $submission ) {
		/**
		 * Filter a form submission before it is sent. Return false to skip it.
		 *
		 * @param array|false $submission Submission.
		 */
		$submission = apply_filters( 'assistantlabs_form_submission', $submission );
		if ( ! is_array( $submission ) || empty( $submission['fields'] ) ) {
			return;
		}
		AssistantLabs_Api_Client::request(
			'POST',
			'/site/forms/submission',
			$submission,
			array(
				'blocking' => false,
				'timeout'  => 3,
			)
		);
	}
}
