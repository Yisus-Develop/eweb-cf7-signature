<?php
/**
 * Contact Form 7 compatibility contract: early warning system.
 *
 * @package EWEB_CF7_Signature
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'EWEB_CF7_Signature_Compat' ) ) {

	/**
	 * Verifies, at runtime, that every Contact Form 7 symbol this plugin relies
	 * on still exists, and warns the administrator when a Contact Form 7 update
	 * changes something.
	 *
	 * Contact Form 7 is remarkably backwards compatible, but it does evolve:
	 * callback signatures gain arguments, the form-tag generator was migrated to
	 * `WPCF7_TagGeneratorGenerator`/`WPCF7_HTMLFormatter`, validation moved to
	 * SWV, and helper functions get renamed. Instead of failing silently in
	 * production, this class detects the drift and says exactly what broke, what
	 * the impact is and which fallback is already covering it.
	 */
	final class EWEB_CF7_Signature_Compat {

		/**
		 * Contact Form 7 version this release has been verified against.
		 *
		 * Bump it only after running `php tests/check-cf7-contract.php <cf7-dir>`.
		 */
		public const TESTED_CF7_VERSION = '6.2.2';

		/**
		 * Functions that must exist, keyed by name with the impact on failure.
		 *
		 * @return array<string, string>
		 */
		private static function required_functions(): array {
			return array(
				'wpcf7_add_form_tag'             => __( 'the [signature] form-tag cannot be registered', 'eweb-cf7-signature' ),
				'wpcf7_format_atts'              => __( 'the signature pad cannot be rendered safely', 'eweb-cf7-signature' ),
				'wpcf7_form_controls_class'      => __( 'the signature field loses Contact Form 7 styling', 'eweb-cf7-signature' ),
				'wpcf7_get_validation_error'     => __( 'required-field errors cannot be displayed', 'eweb-cf7-signature' ),
				'wpcf7_get_message'              => __( 'required-field errors cannot be displayed', 'eweb-cf7-signature' ),
				'wpcf7_get_current_contact_form' => __( 'the signature cannot be tied to its form', 'eweb-cf7-signature' ),
			);
		}

		/**
		 * Methods that must exist, as `Class::method` with the impact on failure.
		 *
		 * @return array<string, string>
		 */
		private static function required_methods(): array {
			return array(
				'WPCF7_FormTag::get_option'             => __( 'form-tag options cannot be read', 'eweb-cf7-signature' ),
				'WPCF7_FormTag::has_option'             => __( 'flag options cannot be read', 'eweb-cf7-signature' ),
				'WPCF7_FormTag::is_required'            => __( 'required signatures cannot be enforced', 'eweb-cf7-signature' ),
				'WPCF7_FormTag::get_class_option'       => __( 'custom CSS classes cannot be applied', 'eweb-cf7-signature' ),
				'WPCF7_FormTag::get_id_option'          => __( 'custom element ids cannot be applied', 'eweb-cf7-signature' ),
				'WPCF7_ContactForm::scan_form_tags'     => __( 'the form-tag options cannot be resolved', 'eweb-cf7-signature' ),
				'WPCF7_ContactForm::id'                 => __( 'stored files lose their form id', 'eweb-cf7-signature' ),
				'WPCF7_Validation::invalidate'          => __( 'invalid signatures cannot be rejected', 'eweb-cf7-signature' ),
				'WPCF7_Submission::get_instance'        => __( 'the submitted data cannot be inspected', 'eweb-cf7-signature' ),
				'WPCF7_MailTag::tag_name'               => __( 'mail-tags cannot be matched to signatures', 'eweb-cf7-signature' ),
				'WPCF7_MailTag::corresponding_form_tag' => __( 'per-field mail options cannot be read', 'eweb-cf7-signature' ),
			);
		}

		/**
		 * Classes that must exist, keyed by name with the impact on failure.
		 *
		 * @return array<string, string>
		 */
		private static function required_classes(): array {
			return array(
				'WPCF7_ContactForm' => __( 'the plugin cannot hook into the submission', 'eweb-cf7-signature' ),
				'WPCF7_FormTag'     => __( 'the signature field cannot be rendered', 'eweb-cf7-signature' ),
				'WPCF7_Validation'  => __( 'required signatures cannot be enforced', 'eweb-cf7-signature' ),
				'WPCF7_MailTag'     => __( 'the signature cannot be placed in the e-mail body', 'eweb-cf7-signature' ),
				'WPCF7_Mail'        => __( 'the signature falls back to its public URL', 'eweb-cf7-signature' ),
				'WPCF7_Submission'  => __( 'the submitted data cannot be inspected', 'eweb-cf7-signature' ),
			);
		}

		/**
		 * Optional symbols. Their absence only degrades a feature, and a fallback
		 * is already implemented, so they never trigger an error notice.
		 *
		 * @return array<string, array{impact: string, fallback: string}>
		 */
		private static function optional_features(): array {
			return array(
				'tag_generator_v2'    => array(
					'impact'   => __( 'the form-tag generator uses the legacy panel', 'eweb-cf7-signature' ),
					'fallback' => __( 'a plain HTML panel with the same fields is rendered instead', 'eweb-cf7-signature' ),
				),
				'swv'                 => array(
					'impact'   => __( 'server-side validation relies on the legacy validate filter only', 'eweb-cf7-signature' ),
					'fallback' => __( 'wpcf7_validate_signature still enforces required fields', 'eweb-cf7-signature' ),
				),
				'mail_tag_field_name' => array(
					'impact'   => __( 'mail-tags are matched by tag name instead of field name', 'eweb-cf7-signature' ),
					'fallback' => __( 'identical for plain field names, differs only for [_raw_*] variants', 'eweb-cf7-signature' ),
				),
				'mail_template_name'  => array(
					'impact'   => __( 'external consumers (PDF generators) may receive the public URL', 'eweb-cf7-signature' ),
					'fallback' => __( 'the wpcf7pdf_text filter still swaps the URL for a local path', 'eweb-cf7-signature' ),
				),
				'upload_mimes'        => array(
					'impact'   => __( 'the PNG MIME type cannot be double-checked', 'eweb-cf7-signature' ),
					'fallback' => __( 'raw PNG magic bytes are still verified', 'eweb-cf7-signature' ),
				),
			);
		}

		/**
		 * Hooks the module.
		 *
		 * @return void
		 */
		public static function init(): void {
			add_action( 'admin_notices', array( __CLASS__, 'maybe_notice' ) );
		}

		/**
		 * Returns the Contact Form 7 version this release is verified against.
		 *
		 * @return string
		 */
		public static function tested_version(): string {
			/**
			 * Filters the Contact Form 7 version considered as verified.
			 *
			 * @param string $version Tested version.
			 */
			return (string) apply_filters( 'eweb_cf7_signature_tested_cf7_version', self::TESTED_CF7_VERSION );
		}

		/**
		 * Reports the current compatibility status.
		 *
		 * @return array{version: string, tested: string, drift: string, missing: array<string, string>, degraded: array<string, string>}
		 */
		public static function report(): array {
			$missing = array();

			foreach ( self::required_functions() as $function => $impact ) {
				if ( ! function_exists( $function ) ) {
					$missing[ $function . '()' ] = $impact;
				}
			}

			foreach ( self::required_classes() as $class => $impact ) {
				if ( ! class_exists( $class ) ) {
					$missing[ $class ] = $impact;
				}
			}

			foreach ( self::required_methods() as $method => $impact ) {
				list( $class, $name ) = explode( '::', $method, 2 );

				if ( ! class_exists( $class ) || ! method_exists( $class, $name ) ) {
					$missing[ $method . '()' ] = $impact;
				}
			}

			$degraded = array();

			if ( ! class_exists( 'WPCF7_TagGeneratorGenerator' ) || ! class_exists( 'WPCF7_HTMLFormatter' ) ) {
				$degraded['tag_generator_v2'] = self::optional_features()['tag_generator_v2']['impact'];
			}

			if ( ! function_exists( 'wpcf7_swv_create_rule' ) ) {
				$degraded['swv'] = self::optional_features()['swv']['impact'];
			}

			if ( ! method_exists( 'WPCF7_MailTag', 'field_name' ) ) {
				$degraded['mail_tag_field_name'] = self::optional_features()['mail_tag_field_name']['impact'];
			}

			if ( ! self::has_mail_template_name() ) {
				$degraded['mail_template_name'] = self::optional_features()['mail_template_name']['impact'];
			}

			$report = array(
				'version'  => self::detected_version(),
				'tested'   => self::tested_version(),
				'drift'    => self::version_drift(),
				'missing'  => $missing,
				'degraded' => $degraded,
			);

			/**
			 * Filters the Contact Form 7 compatibility report.
			 *
			 * @param array $report Compatibility report.
			 */
			return (array) apply_filters( 'eweb_cf7_signature_compat_report', $report );
		}

		/**
		 * Tells whether every hard requirement is satisfied.
		 *
		 * @return bool
		 */
		public static function is_healthy(): bool {
			$report = self::report();

			return array() === $report['missing'];
		}

		/**
		 * Displays the compatibility notice to administrators.
		 *
		 * @return void
		 */
		public static function maybe_notice(): void {
			if ( ! current_user_can( 'activate_plugins' ) ) {
				return;
			}

			/**
			 * Filters whether the compatibility notice is displayed.
			 *
			 * @param bool $display Whether to display the notice.
			 */
			if ( ! apply_filters( 'eweb_cf7_signature_compat_notice', true ) ) {
				return;
			}

			$report = self::report();

			if ( array() !== $report['missing'] ) {
				self::render_notice( 'error', $report, 'missing' );

				return;
			}

			if ( '' !== $report['drift'] ) {
				self::render_notice( 'warning', $report, 'drift' );
			}
		}

		/**
		 * Returns a human readable version drift message.
		 *
		 * Only a change of major or minor version raises a warning: patch
		 * releases never do.
		 *
		 * @return string Empty when the installed version is within the tested range.
		 */
		public static function version_drift(): string {
			$version = self::detected_version();
			$tested  = self::tested_version();

			if ( '' === $version ) {
				return '';
			}

			if ( version_compare( $version, $tested, '<' ) ) {
				return sprintf(
					/* translators: 1: installed Contact Form 7 version, 2: tested version. */
					__( 'Contact Form 7 %1$s is older than the tested version %2$s. Some features may be limited.', 'eweb-cf7-signature' ),
					$version,
					$tested
				);
			}

			$installed_minor = self::minor_version( $version );
			$tested_minor    = self::minor_version( $tested );

			if ( version_compare( $installed_minor, $tested_minor, '>' ) ) {
				return sprintf(
					/* translators: 1: installed Contact Form 7 version, 2: tested version. */
					__( 'Contact Form 7 %1$s is newer than the tested version %2$s. Everything keeps working; run the contract check if you notice anything unusual.', 'eweb-cf7-signature' ),
					$version,
					$tested
				);
			}

			return '';
		}

		/**
		 * Returns the installed Contact Form 7 version.
		 *
		 * @return string Empty when Contact Form 7 is not loaded.
		 */
		public static function detected_version(): string {
			return defined( 'WPCF7_VERSION' ) ? (string) WPCF7_VERSION : '';
		}

		/**
		 * Builds the `major.minor` part of a version number.
		 *
		 * @param string $version Full version.
		 * @return string
		 */
		private static function minor_version( string $version ): string {
			$parts = explode( '.', $version );

			return implode( '.', array_slice( $parts, 0, 2 ) );
		}

		/**
		 * Tells whether Contact Form 7 exposes the current mail template name.
		 *
		 * @return bool
		 */
		private static function has_mail_template_name(): bool {
			if ( ! class_exists( 'WPCF7_Mail' ) ) {
				return false;
			}

			return method_exists( 'WPCF7_Mail', 'get_current_template_name' )
				|| method_exists( 'WPCF7_Mail', 'get_current' );
		}

		/**
		 * Prints a compatibility notice.
		 *
		 * @param string $type   Notice type (`error` or `warning`).
		 * @param array  $report Compatibility report.
		 * @param string $mode   Which part of the report to display.
		 * @return void
		 */
		private static function render_notice( string $type, array $report, string $mode ): void {
			$title = 'missing' === $mode
				? __( 'EWEB CF7 Signature detected an incompatible Contact Form 7 update.', 'eweb-cf7-signature' )
				: __( 'EWEB CF7 Signature compatibility notice.', 'eweb-cf7-signature' );

			echo '<div class="notice notice-' . esc_attr( $type ) . '">';
			echo '<p><strong>' . esc_html( $title ) . '</strong></p>';

			if ( 'missing' === $mode ) {
				echo '<ul style="list-style:disc;margin-left:1.5em;">';

				foreach ( $report['missing'] as $symbol => $impact ) {
					printf(
						'<li><code>%1$s</code> — %2$s</li>',
						esc_html( $symbol ),
						esc_html( $impact )
					);
				}

				echo '</ul>';

				printf(
					'<p>%s</p>',
					esc_html__( 'Until this is resolved, signature fields may not submit, store or e-mail correctly. Revert Contact Form 7 or report the issue with the list above.', 'eweb-cf7-signature' )
				);
			} else {
				printf( '<p>%s</p>', esc_html( $report['drift'] ) );
			}

			if ( array() !== $report['degraded'] ) {
				echo '<p><strong>' . esc_html__( 'Running in degraded mode:', 'eweb-cf7-signature' ) . '</strong></p>';
				echo '<ul style="list-style:disc;margin-left:1.5em;">';

				foreach ( $report['degraded'] as $feature => $impact ) {
					$fallback = self::optional_features()[ $feature ]['fallback'] ?? '';

					printf(
						'<li><code>%1$s</code> — %2$s (%3$s)</li>',
						esc_html( $feature ),
						esc_html( $impact ),
						esc_html( $fallback )
					);
				}

				echo '</ul>';
			}

			printf(
				'<p><em>%1$s <code>%2$s</code> · %3$s <code>%4$s</code></em></p>',
				esc_html__( 'Installed Contact Form 7:', 'eweb-cf7-signature' ),
				esc_html( '' !== $report['version'] ? $report['version'] : __( 'unknown', 'eweb-cf7-signature' ) ),
				esc_html__( 'verified against:', 'eweb-cf7-signature' ),
				esc_html( $report['tested'] )
			);

			echo '</div>';
		}
	}
}
