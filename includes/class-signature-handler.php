<?php
/**
 * Signature field handler: form-tag rendering, secure storage and validation.
 *
 * @package EWEB_CF7_Signature
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'EWEB_CF7_Signature_Handler' ) ) {

	/**
	 * Registers the `[signature]` form-tag, stores the submitted canvas as a
	 * PNG inside `wp-content/uploads/eweb_signatures/` and validates the field.
	 *
	 * The folder is intentionally public (Contact Form 7's own temporary upload
	 * folder answers `403` to every request) but sandboxed with an Apache 2.4 /
	 * IIS guard that only exposes `.png` files and never executes anything.
	 */
	final class EWEB_CF7_Signature_Handler {

		/**
		 * Folder inside `wp-content/uploads/` used to store the signatures.
		 */
		public const UPLOAD_DIRNAME = 'eweb_signatures';

		/**
		 * Maximum accepted size, in bytes, of a decoded signature.
		 */
		public const MAX_BYTES = 2097152;

		/**
		 * Cron hook used by the optional retention task.
		 */
		public const CRON_HOOK = 'eweb_cf7_signature_cleanup';

		/**
		 * Default canvas width, in pixels.
		 */
		private const DEFAULT_WIDTH = 600;

		/**
		 * Default canvas height, in pixels.
		 */
		private const DEFAULT_HEIGHT = 240;

		/**
		 * Request payload accepted from the canvas (`canvas.toDataURL()`).
		 */
		private const DATA_URI_PATTERN = '#^data:image/png(?:;[a-z0-9\-\._=]+)*;base64,#i';

		/**
		 * First eight bytes of every valid PNG file.
		 */
		private const PNG_HEADER = "\x89PNG\r\n\x1a\n";

		/**
		 * Signatures stored during the current request, keyed by field name.
		 *
		 * @var array<string, array{path: string, url: string, file: string, bytes: int, width: int, height: int}>
		 */
		private static array $files = array();

		/**
		 * Whether the upload folder has already been checked in this request.
		 *
		 * @var bool
		 */
		private static bool $dir_ready = false;

		/**
		 * Nothing to instantiate: the class only exposes static methods.
		 */
		private function __construct() {}

		/**
		 * Hooks the module.
		 *
		 * @return void
		 */
		public static function init(): void {
			add_action( 'wpcf7_init', array( __CLASS__, 'register_form_tag' ) );
			add_action( 'wpcf7_admin_init', array( __CLASS__, 'register_tag_generator' ) );
			add_filter( 'wpcf7_posted_data', array( __CLASS__, 'process_posted_data' ) );
			add_filter( 'wpcf7_validate_signature', array( __CLASS__, 'validate_signature' ), 10, 2 );
			add_filter( 'wpcf7_validate_signature*', array( __CLASS__, 'validate_signature' ), 10, 2 );
			add_action( 'wpcf7_swv_create_schema', array( __CLASS__, 'register_swv_rules' ), 10, 2 );
			add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
			add_action( 'admin_init', array( __CLASS__, 'maybe_schedule_cleanup' ) );
			add_action( self::CRON_HOOK, array( __CLASS__, 'cleanup_expired' ) );
		}

		/**
		 * Registers the modern (SWV) validation rule for required signatures.
		 *
		 * Both validation layers are registered on purpose:
		 *
		 * - `WPCF7_Validation::invalidate()` ignores a second error for a field
		 *   that is already invalid, so the visitor never sees two messages.
		 * - Legacy `wpcf7_validate_{type}` filters still run in Contact Form 7
		 *   6.x, but the SWV schema is the forward-compatible path if they are
		 *   ever removed.
		 *
		 * @param mixed             $schema       SWV schema object.
		 * @param WPCF7_ContactForm $contact_form Current contact form.
		 * @return void
		 */
		public static function register_swv_rules( $schema, $contact_form = null ): void {
			if (
				! function_exists( 'wpcf7_swv_create_rule' )
				|| ! is_object( $schema )
				|| ! method_exists( $schema, 'add_rule' )
				|| ! $contact_form instanceof WPCF7_ContactForm
			) {
				return;
			}

			try {
				$tags = $contact_form->scan_form_tags(
					array( 'basetype' => array( 'signature' ) )
				);

				foreach ( $tags as $tag ) {
					if ( ! $tag instanceof WPCF7_FormTag || ! $tag->is_required() ) {
						continue;
					}

					$rule = wpcf7_swv_create_rule(
						'required',
						array(
							'field' => (string) $tag->name,
							'error' => wpcf7_get_message( 'invalid_required' ),
						)
					);

					if ( is_object( $rule ) ) {
						$schema->add_rule( $rule );
					}
				}
			} catch ( Throwable $exception ) {
				// A validation rule must never break the submission.
				self::log( 'SWV rule registration skipped: ' . $exception->getMessage() );
			}
		}

		/*
		---------------------------------------------------------------------
		 * Form-tag registration
		 * ------------------------------------------------------------------
		 */

		/**
		 * Declares the `[signature]` and `[signature*]` form-tags.
		 *
		 * @return void
		 */
		public static function register_form_tag(): void {
			wpcf7_add_form_tag(
				array( 'signature', 'signature*' ),
				array( __CLASS__, 'render_form_tag' ),
				array( 'name-attr' => true )
			);
		}

		/**
		 * Declares the form-tag generator panel of the form editor.
		 *
		 * @return void
		 */
		public static function register_tag_generator(): void {
			if ( ! class_exists( 'WPCF7_TagGenerator' ) ) {
				return;
			}

			WPCF7_TagGenerator::get_instance()->add(
				'signature',
				__( 'Signature field', 'eweb-cf7-signature' ),
				array( __CLASS__, 'tag_generator' ),
				array( 'version' => '2' )
			);
		}

		/**
		 * Renders the canvas, its toolbar and the hidden input CF7 submits.
		 *
		 * @param WPCF7_FormTag $tag Current form-tag object.
		 * @return string
		 */
		public static function render_form_tag( $tag ): string {
			if ( ! $tag instanceof WPCF7_FormTag || '' === (string) $tag->name ) {
				return '';
			}

			$name           = (string) $tag->name;
			$validation     = wpcf7_get_validation_error( $name );
			$options        = self::field_options( $tag );
			$controls_class = wpcf7_form_controls_class( $tag->type, 'eweb-sig__field' );
			$canvas_id      = $tag->get_id_option();

			if ( ! is_string( $canvas_id ) || '' === $canvas_id ) {
				$canvas_id = 'eweb-sig-' . sanitize_html_class( $name );
			}

			$ratio = rtrim( rtrim( number_format( $options['width'] / $options['height'], 4, '.', '' ), '0' ), '.' );

			if ( '' === $ratio ) {
				$ratio = '1';
			}

			$wrapper_atts = array(
				'class'      => $tag->get_class_option( 'eweb-sig is-empty' ),
				'data-field' => $name,
				'style'      => sprintf(
					'--eweb-sig-ratio:%1$s;--eweb-sig-pen:%2$s;--eweb-sig-bg:%3$s;',
					$ratio,
					$options['pen'],
					$options['background']
				),
			);

			$canvas_atts = array(
				'id'                 => $canvas_id,
				'class'              => 'eweb-sig__canvas',
				'width'              => $options['width'],
				'height'             => $options['height'],
				'role'               => 'img',
				'aria-label'         => __( 'Signature pad: draw your signature with the mouse, a finger or a stylus.', 'eweb-cf7-signature' ),
				'data-eweb-pen'      => $options['pen'],
				'data-eweb-bg'       => $options['background'],
				'data-eweb-penwidth' => $options['pen_width'],
				'data-eweb-trim'     => $options['trim'] ? 'true' : 'false',
				'data-eweb-field'    => $name,
			);

			$input_atts = array(
				'type'  => 'hidden',
				'name'  => $name,
				'value' => '',
				'class' => $controls_class . ' eweb-sig__value',
			);

			$empty_label = __( 'Sign here', 'eweb-cf7-signature' );

			$clear_label = __( 'Clear', 'eweb-cf7-signature' );

			$clear_atts = array(
				'type'          => 'button',
				'class'         => 'eweb-sig__clear',
				'data-eweb-sig' => 'clear',
			);

			$hint = '';

			if ( '' !== $options['hint'] ) {
				$hint = sprintf(
					'<p class="eweb-sig__hint" id="%1$s-hint">%2$s</p>',
					esc_attr( $canvas_id ),
					esc_html( $options['hint'] )
				);
			}

			return sprintf(
				'<div %1$s><div class="eweb-sig__frame"><canvas %2$s></canvas><span class="eweb-sig__empty" aria-hidden="true">%3$s</span></div><div class="eweb-sig__actions"><button %4$s>%5$s</button>%6$s</div><span class="wpcf7-form-control-wrap" data-name="%7$s"><input %8$s />%9$s</span><noscript><p class="eweb-sig__noscript">%10$s</p></noscript></div>',
				wpcf7_format_atts( $wrapper_atts ),
				wpcf7_format_atts( $canvas_atts ),
				esc_html( $empty_label ),
				wpcf7_format_atts( $clear_atts ),
				esc_html( $clear_label ),
				$hint,
				esc_attr( $name ),
				wpcf7_format_atts( $input_atts ),
				$validation,
				esc_html__( 'JavaScript is required to draw and submit your signature.', 'eweb-cf7-signature' )
			);
		}

		/**
		 * Renders the form-tag generator panel of the form editor.
		 *
		 * @param WPCF7_ContactForm $contact_form Current contact form.
		 * @param array|string      $args         Panel arguments.
		 * @return void
		 */
		public static function tag_generator( $contact_form, $args = '' ): void {
			$args = wp_parse_args(
				$args,
				array(
					'content' => 'signature',
				)
			);

			if ( ! class_exists( 'WPCF7_TagGeneratorGenerator' ) || ! class_exists( 'WPCF7_HTMLFormatter' ) ) {
				self::tag_generator_legacy();

				return;
			}

			$generator = new WPCF7_TagGeneratorGenerator( (string) $args['content'] );
			$formatter = new WPCF7_HTMLFormatter();

			$formatter->append_start_tag( 'header', array( 'class' => 'description-box' ) );
			$formatter->append_start_tag( 'h3' );
			$formatter->append_preformatted( esc_html__( 'Signature field', 'eweb-cf7-signature' ) );
			$formatter->end_tag( 'h3' );
			$formatter->append_start_tag( 'p' );
			$formatter->append_preformatted(
				esc_html__( 'Generates a form-tag for an HTML5 signature pad drawn with the mouse, a finger or a stylus.', 'eweb-cf7-signature' )
			);
			$formatter->end_tag( 'p' );
			$formatter->end_tag( 'header' );

			$formatter->append_start_tag( 'div', array( 'class' => 'control-box' ) );

			$formatter->call_user_func(
				static function () use ( $generator ) {
					$generator->print(
						'field_type',
						array(
							'with_required'  => true,
							'with_optional'  => true,
							'select_options' => array(
								'signature' => __( 'Signature pad', 'eweb-cf7-signature' ),
							),
						)
					);

					$generator->print( 'field_name' );
					$generator->print( 'id_attr' );
					$generator->print( 'class_attr' );
				}
			);

			$formatter->call_user_func(
				static function () use ( $generator ) {
					$markup = self::generator_color_field(
						$generator->ref( 'pen-legend' ),
						__( 'Pen colour', 'eweb-cf7-signature' ),
						'color:',
						'#111827'
					);

					$markup .= self::generator_color_field(
						$generator->ref( 'background-legend' ),
						__( 'Pad background', 'eweb-cf7-signature' ),
						'backcolor:',
						'#ffffff'
					);

					$markup .= self::generator_number_field(
						$generator->ref( 'penwidth-legend' ),
						__( 'Stroke width', 'eweb-cf7-signature' ),
						'penwidth:',
						'2.5',
						'0.1'
					);

					$markup .= self::generator_number_field(
						$generator->ref( 'width-legend' ),
						__( 'Canvas width (px)', 'eweb-cf7-signature' ),
						'width:',
						(string) self::DEFAULT_WIDTH,
						'1'
					);

					$markup .= self::generator_number_field(
						$generator->ref( 'height-legend' ),
						__( 'Canvas height (px)', 'eweb-cf7-signature' ),
						'height:',
						(string) self::DEFAULT_HEIGHT,
						'1'
					);

					$markup .= self::generator_mode_field( $generator->ref( 'mode-legend' ) );

					$markup .= self::generator_text_field(
						$generator->ref( 'hint-legend' ),
						__( 'Hint under the pad', 'eweb-cf7-signature' ),
						'hint:',
						''
					);

					echo $markup; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Every field is escaped by the builders above.
				}
			);

			$formatter->end_tag( 'div' );

			$formatter->append_start_tag( 'footer', array( 'class' => 'insert-box' ) );

			$formatter->call_user_func(
				static function () use ( $generator ) {
					$generator->print( 'insert_box_content' );
					$generator->print( 'mail_tag_tip' );
				}
			);

			$formatter->end_tag( 'footer' );

			$formatter->print();
		}

		/**
		 * Renders the form-tag generator panel for Contact Form 7 versions that
		 * do not ship `WPCF7_TagGeneratorGenerator`.
		 *
		 * The markup follows the same version-2 contract of the current
		 * generator, so the editor JavaScript keeps working: every value is
		 * declared with `data-tag-part` and the insert button with
		 * `data-taggen="insert-tag"`. Without this fallback the "Signature field"
		 * button would silently disappear from the form editor.
		 *
		 * @return void
		 */
		private static function tag_generator_legacy(): void {
			$base = 'eweb-sig-generator';

			$fields = array();

			$fields[] = sprintf(
				'<fieldset><legend id="%1$s-type-legend">%2$s</legend><label><select data-tag-part="basetype"><option value="signature">%3$s</option></select></label> <label><input type="checkbox" data-tag-part="type-suffix" value="*" /> %4$s</label></fieldset>',
				esc_attr( $base ),
				esc_html__( 'Field type', 'eweb-cf7-signature' ),
				esc_html__( 'Signature pad', 'eweb-cf7-signature' ),
				esc_html__( 'This is a required field.', 'eweb-cf7-signature' )
			);

			$fields[] = sprintf(
				'<fieldset><legend id="%1$s-name-legend">%2$s</legend><label><input type="text" data-tag-part="name" pattern="[A-Za-z][A-Za-z0-9_\-]*" /></label></fieldset>',
				esc_attr( $base ),
				esc_html__( 'Name', 'eweb-cf7-signature' )
			);

			$fields[] = self::generator_text_field(
				$base . '-id',
				__( 'Id', 'eweb-cf7-signature' ),
				'id:',
				''
			);

			$fields[] = self::generator_text_field(
				$base . '-class',
				__( 'Class', 'eweb-cf7-signature' ),
				'class:',
				''
			);

			$fields[] = self::generator_color_field(
				$base . '-pen',
				__( 'Pen colour', 'eweb-cf7-signature' ),
				'color:',
				'#111827'
			);

			$fields[] = self::generator_color_field(
				$base . '-background',
				__( 'Pad background', 'eweb-cf7-signature' ),
				'backcolor:',
				'#ffffff'
			);

			$fields[] = self::generator_number_field(
				$base . '-penwidth',
				__( 'Stroke width', 'eweb-cf7-signature' ),
				'penwidth:',
				'2.5',
				'0.1'
			);

			$fields[] = self::generator_number_field(
				$base . '-width',
				__( 'Canvas width (px)', 'eweb-cf7-signature' ),
				'width:',
				(string) self::DEFAULT_WIDTH,
				'1'
			);

			$fields[] = self::generator_number_field(
				$base . '-height',
				__( 'Canvas height (px)', 'eweb-cf7-signature' ),
				'height:',
				(string) self::DEFAULT_HEIGHT,
				'1'
			);

			$fields[] = self::generator_mode_field( $base . '-mode' );

			$fields[] = self::generator_text_field(
				$base . '-hint',
				__( 'Hint under the pad', 'eweb-cf7-signature' ),
				'hint:',
				''
			);

			$html = sprintf(
				'<header class="description-box"><h3>%1$s</h3><p>%2$s</p></header><div class="control-box">%3$s</div><footer class="insert-box"><div class="flex-container"><input type="text" class="code" readonly="readonly" data-tag-part="tag" /> <button type="button" class="button button-primary insert-tag" data-taggen="insert-tag">%4$s</button></div><p class="mail-tag-tip">%5$s</p></footer>',
				esc_html__( 'Signature field', 'eweb-cf7-signature' ),
				esc_html__( 'Generates a form-tag for an HTML5 signature pad drawn with the mouse, a finger or a stylus.', 'eweb-cf7-signature' ),
				implode( '', $fields ),
				esc_html__( 'Insert Tag', 'eweb-cf7-signature' ),
				sprintf(
					/* translators: %s: mail-tag placeholder. */
					esc_html__( 'To use the value input through this field in a mail field, you need to insert the corresponding mail-tag (%s) into the field on the Mail tab.', 'eweb-cf7-signature' ),
					'<strong data-tag-part="mail-tag"></strong>'
				)
			);

			echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Every value is escaped by the builders above.
		}

		/*
		---------------------------------------------------------------------
		 * Submitted data: storage
		 * ------------------------------------------------------------------
		 */

		/**
		 * Replaces every submitted data URI with the public URL of the stored PNG.
		 *
		 * Keeping the public URL (instead of the raw payload) as the field value
		 * makes it usable by mail-tags, PDF generators and logging plugins, and
		 * keeps multi-megabyte strings out of the database.
		 *
		 * @param array<string, mixed> $posted_data Sanitised posted data.
		 * @return array<string, mixed>
		 */
		public static function process_posted_data( array $posted_data ): array {
			$form_id = self::current_form_id();

			foreach ( $posted_data as $field => $value ) {
				if ( ! is_string( $value ) || ! self::is_signature_payload( $value ) ) {
					continue;
				}

				$fingerprint = md5( $value );
				$existing    = self::get_file( (string) $field );

				// The very same payload was already stored in this request.
				if ( null !== $existing && ( $existing['payload'] ?? '' ) === $fingerprint ) {
					$posted_data[ $field ] = $existing['url'];

					continue;
				}

				$stored = self::store_signature( $value, (string) $field, $form_id );

				if ( null === $stored ) {
					$posted_data[ $field ] = '';

					continue;
				}

				$posted_data[ $field ] = $stored['url'];
			}

			return $posted_data;
		}

		/**
		 * Stores a submitted signature.
		 *
		 * @param string $payload    Raw `data:image/png;base64,...` payload.
		 * @param string $field_name Form field name.
		 * @param int    $form_id    Contact form ID, for traceability.
		 * @return array{path: string, url: string, file: string, bytes: int, width: int, height: int}|null
		 */
		public static function store_signature( string $payload, string $field_name, int $form_id = 0 ): ?array {
			$binary = self::decode_payload( $payload );

			if ( null === $binary || ! self::ensure_upload_dir() ) {
				return null;
			}

			$directory = self::upload_dir_info();

			if ( '' !== $directory['error'] ) {
				return null;
			}

			$base = sanitize_file_name( $field_name . '-signature' );
			$base = '' === $base ? 'signature' : $base;

			if ( $form_id > 0 ) {
				$base = $form_id . '-' . $base;
			}

			$filename = sprintf(
				'%1$s-%2$s-%3$s.png',
				$base,
				wp_date( 'Ymd-His' ),
				strtolower( (string) wp_generate_password( 6, false, false ) )
			);

			$filename = wp_unique_filename( $directory['path'], $filename );
			$path     = wp_normalize_path( trailingslashit( $directory['path'] ) . $filename );

			$written = @file_put_contents( $path, $binary, LOCK_EX ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Direct write is required: the visitor is not logged in, so WP_Filesystem credentials are unavailable.

			if ( ! is_int( $written ) || $written < 1 ) {
				self::log( 'Unable to write the signature file: ' . $path );
				return null;
			}

			@chmod( $path, 0644 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Best effort: some hosts forbid chmod and the file stays readable anyway.

			$size = @getimagesize( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Dimensions are optional.

			$info = array(
				'path'    => $path,
				'url'     => trailingslashit( $directory['url'] ) . $filename,
				'file'    => $filename,
				'bytes'   => strlen( $binary ),
				'width'   => is_array( $size ) ? (int) $size[0] : 0,
				'height'  => is_array( $size ) ? (int) $size[1] : 0,
				'payload' => md5( $payload ),
			);

			self::$files[ $field_name ] = $info;

			/**
			 * Fires once a signature has been written to disk.
			 *
			 * @param array{path: string, url: string, file: string, bytes: int, width: int, height: int} $info       Stored file details.
			 * @param string                                                                                $field_name Form field name.
			 * @param int                                                                                   $form_id    Contact form ID.
			 */
			do_action( 'eweb_cf7_signature_stored', $info, $field_name, $form_id );

			return $info;
		}

		/*
		---------------------------------------------------------------------
		 * Submitted data: validation
		 * ------------------------------------------------------------------
		 */

		/**
		 * Validates required signature fields.
		 *
		 * @param WPCF7_Validation $result Validation result.
		 * @param WPCF7_FormTag    $tag    Current form-tag object.
		 * @return WPCF7_Validation
		 */
		public static function validate_signature( $result, $tag ) {
			if ( ! $result instanceof WPCF7_Validation || ! $tag instanceof WPCF7_FormTag ) {
				return $result;
			}

			$name  = (string) $tag->name;
			$value = self::raw_posted_value( $name );

			if ( '' === $value ) {
				if ( $tag->is_required() ) {
					$result->invalidate( $tag, wpcf7_get_message( 'invalid_required' ) );
				}

				return $result;
			}

			if ( ! self::is_signature_payload( $value ) ) {
				$result->invalidate(
					$tag,
					__( 'The signature could not be processed. Please sign again.', 'eweb-cf7-signature' )
				);

				return $result;
			}

			if ( null === self::get_file( $name ) ) {
				$stored = self::store_signature( $value, $name, self::current_form_id() );

				if ( null === $stored ) {
					$result->invalidate(
						$tag,
						__( 'The signature could not be saved on the server. Please sign again.', 'eweb-cf7-signature' )
					);
				}
			}

			return $result;
		}

		/*
		---------------------------------------------------------------------
		 * Storage directory
		 * ------------------------------------------------------------------
		 */

		/**
		 * Returns the absolute path and the public URL of the signature folder.
		 *
		 * @return array{error: string, path: string, url: string}
		 */
		public static function upload_dir_info(): array {
			$uploads = wp_upload_dir( null, false );

			if ( ! empty( $uploads['error'] ) ) {
				return array(
					'error' => (string) $uploads['error'],
					'path'  => '',
					'url'   => '',
				);
			}

			$path = (string) apply_filters(
				'eweb_cf7_signature_upload_path',
				trailingslashit( $uploads['basedir'] ) . self::UPLOAD_DIRNAME
			);

			$url = (string) apply_filters(
				'eweb_cf7_signature_upload_url',
				trailingslashit( $uploads['baseurl'] ) . self::UPLOAD_DIRNAME
			);

			return array(
				'error' => '',
				'path'  => untrailingslashit( wp_normalize_path( $path ) ),
				'url'   => untrailingslashit( $url ),
			);
		}

		/**
		 * Creates the folder and its access guards when needed.
		 *
		 * @param bool $force Rewrite the guard files even if they already exist.
		 * @return bool
		 */
		public static function ensure_upload_dir( bool $force = false ): bool {
			if ( self::$dir_ready && ! $force ) {
				return true;
			}

			$directory = self::upload_dir_info();

			if ( '' !== $directory['error'] ) {
				self::log( 'Uploads folder unavailable: ' . $directory['error'] );
				return false;
			}

			if ( ! wp_mkdir_p( $directory['path'] ) ) {
				self::log( 'Unable to create the signature folder: ' . $directory['path'] );
				return false;
			}

			self::write_file( $directory['path'] . '/.htaccess', self::apache_rules(), $force );
			self::write_file( $directory['path'] . '/web.config', self::iis_rules(), $force );
			self::write_file( $directory['path'] . '/index.php', "<?php\n// Silence is golden.\n", $force );

			self::$dir_ready = true;

			return true;
		}

		/**
		 * Apache 2.4 rules: deny everything but `.png` files.
		 *
		 * Written with `IfModule` fallbacks so it is valid on both Apache 2.4
		 * (`mod_authz_core`) and legacy 2.2 (`mod_access_compat`) servers. The
		 * deprecated `Order/Deny/Allow` block alone would trigger a `500` on any
		 * 2.4 server without `mod_access_compat`.
		 *
		 * @return string
		 */
		public static function apache_rules(): string {
			return implode(
				"\n",
				array(
					'# EWEB CF7 Signature - public PNG storage.',
					'# Signature images must be readable by mail clients and PDF',
					'# generators, but nothing else here should ever be served.',
					'',
					'# 1. Deny everything by default.',
					'<IfModule mod_authz_core.c>',
					"\t" . 'Require all denied',
					'</IfModule>',
					'<IfModule !mod_authz_core.c>',
					"\t" . 'Order deny,allow',
					"\t" . 'Deny from all',
					'</IfModule>',
					'',
					'# 2. Serve PNG files only.',
					'<FilesMatch "\.png$">',
					"\t" . '<IfModule mod_authz_core.c>',
					"\t\t" . 'Require all granted',
					"\t" . '</IfModule>',
					"\t" . '<IfModule !mod_authz_core.c>',
					"\t\t" . 'Order allow,deny',
					"\t\t" . 'Allow from all',
					"\t" . '</IfModule>',
					'</FilesMatch>',
					'',
				)
			);
		}

		/**
		 * IIS rules: read-only access, no script handler.
		 *
		 * @return string
		 */
		public static function iis_rules(): string {
			return implode(
				"\n",
				array(
					'<?xml version="1.0" encoding="UTF-8"?>',
					'<configuration>',
					"\t" . '<system.webServer>',
					"\t\t" . '<!-- Static files only: no script handler in this folder. -->',
					"\t\t" . '<handlers accessPolicy="Read" />',
					"\t" . '</system.webServer>',
					'</configuration>',
					'',
				)
			);
		}

		/*
		---------------------------------------------------------------------
		 * Lookup helpers (used by the mail and PDF modules)
		 * ------------------------------------------------------------------
		 */

		/**
		 * Returns the file stored for a field during the current request.
		 *
		 * @param string $field_name Form field name.
		 * @return array{path: string, url: string, file: string, bytes: int, width: int, height: int}|null
		 */
		public static function get_file( string $field_name ): ?array {
			return self::$files[ $field_name ] ?? null;
		}

		/**
		 * Returns every file stored during the current request.
		 *
		 * @return array<string, array{path: string, url: string, file: string, bytes: int, width: int, height: int}>
		 */
		public static function get_files(): array {
			return self::$files;
		}

		/**
		 * Converts a signature URL into its absolute filesystem path.
		 *
		 * This is what PDF engines (mPDF) must receive: an HTTP loopback request
		 * to the site itself is slow, fails behind proxies and is blocked by
		 * many hosts.
		 *
		 * @param string $url Public URL of a stored signature.
		 * @return string Normalised absolute path, or an empty string.
		 */
		public static function url_to_path( string $url ): string {
			$directory = self::upload_dir_info();

			if ( '' !== $directory['error'] || '' === $url ) {
				return '';
			}

			$url = (string) strtok( $url, '?' );

			if ( ! str_starts_with( $url, $directory['url'] ) ) {
				return '';
			}

			$relative = ltrim( rawurldecode( substr( $url, strlen( $directory['url'] ) ) ), '/\\' );

			if ( '' === $relative || str_contains( $relative, '..' ) ) {
				return '';
			}

			$path = realpath( trailingslashit( $directory['path'] ) . $relative );
			$base = realpath( $directory['path'] );

			if ( false === $path || false === $base ) {
				return '';
			}

			$path = wp_normalize_path( $path );
			$base = wp_normalize_path( $base );

			if ( ! str_starts_with( $path, trailingslashit( $base ) ) || ! is_file( $path ) ) {
				return '';
			}

			return $path;
		}

		/**
		 * Returns the file size, in bytes, of a stored signature.
		 *
		 * @param string $field_name Form field name.
		 * @return int
		 */
		public static function get_file_size( string $field_name ): int {
			$file = self::get_file( $field_name );

			return null === $file ? 0 : $file['bytes'];
		}

		/*
		---------------------------------------------------------------------
		 * Assets
		 * ------------------------------------------------------------------
		 */

		/**
		 * Registers and, when needed, enqueues the front-end assets.
		 *
		 * @return void
		 */
		public static function enqueue_assets(): void {
			wp_register_style(
				'eweb-cf7-signature',
				EWEB_CF7_SIG_URL . 'assets/css/signature-pad.css',
				array(),
				EWEB_CF7_SIG_VERSION
			);

			wp_register_script(
				'eweb-cf7-signature',
				EWEB_CF7_SIG_URL . 'assets/js/signature-pad.js',
				array(),
				EWEB_CF7_SIG_VERSION,
				true
			);

			wp_script_add_data( 'eweb-cf7-signature', 'strategy', 'defer' );

			if ( ! self::should_enqueue() ) {
				return;
			}

			wp_enqueue_style( 'eweb-cf7-signature' );
			wp_enqueue_script( 'eweb-cf7-signature' );

			wp_localize_script(
				'eweb-cf7-signature',
				'ewebCf7Signature',
				array(
					'canvasLabel' => __( 'Signature pad', 'eweb-cf7-signature' ),
					'clearLabel'  => __( 'Clear the signature', 'eweb-cf7-signature' ),
				)
			);
		}

		/**
		 * Tells whether the current page may render a signature field.
		 *
		 * @return bool
		 */
		public static function should_enqueue(): bool {
			$load = false;

			if ( is_singular() ) {
				$post = get_post();

				if ( $post instanceof WP_Post ) {
					$content = (string) $post->post_content;

					if (
						str_contains( $content, 'contact-form-7' )
						|| has_shortcode( $content, 'contact-form' )
					) {
						$load = true;
					} else {
						$elementor = get_post_meta( $post->ID, '_elementor_data', true );

						$load = is_string( $elementor ) && str_contains( $elementor, 'contact-form-7' );
					}
				}
			}

			/**
			 * Filters whether the signature assets must be loaded on this page.
			 *
			 * Useful when the form is rendered outside `post_content` (widgets,
			 * dynamic sections, page builders storing their own markup...).
			 *
			 * @param bool $load Whether the assets will be loaded.
			 */
			return (bool) apply_filters( 'eweb_cf7_signature_enqueue_assets', $load );
		}

		/*
		---------------------------------------------------------------------
		 * Retention
		 * ------------------------------------------------------------------
		 */

		/**
		 * Schedules the retention task when a retention window is configured.
		 *
		 * @return void
		 */
		public static function schedule_cleanup(): void {
			if ( self::retention_days() > 0 ) {
				if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
					wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON_HOOK );
				}

				return;
			}

			wp_clear_scheduled_hook( self::CRON_HOOK );
		}

		/**
		 * Keeps the retention task in sync while browsing the admin.
		 *
		 * @return void
		 */
		public static function maybe_schedule_cleanup(): void {
			$is_scheduled = (bool) wp_next_scheduled( self::CRON_HOOK );

			if ( self::retention_days() > 0 && ! $is_scheduled ) {
				self::schedule_cleanup();
			} elseif ( 0 === self::retention_days() && $is_scheduled ) {
				wp_clear_scheduled_hook( self::CRON_HOOK );
			}
		}

		/**
		 * Deletes signatures older than the configured retention window.
		 *
		 * Disabled by default: nothing is ever deleted unless the
		 * `eweb_cf7_signature_retention_days` filter returns a positive value.
		 *
		 * @return void
		 */
		public static function cleanup_expired(): void {
			$days = self::retention_days();

			if ( $days < 1 ) {
				return;
			}

			$directory = self::upload_dir_info();

			if ( '' !== $directory['error'] ) {
				return;
			}

			$files = glob( trailingslashit( $directory['path'] ) . '*.png' );

			if ( ! is_array( $files ) ) {
				return;
			}

			$threshold = time() - ( $days * DAY_IN_SECONDS );

			foreach ( $files as $file ) {
				$modified = @filemtime( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Race with another process is acceptable.

				if ( is_int( $modified ) && $modified < $threshold ) {
					wp_delete_file( $file );
				}
			}
		}

		/**
		 * Returns the configured retention window, in days.
		 *
		 * @return int
		 */
		public static function retention_days(): int {
			/**
			 * Filters how many days a signature is kept on disk.
			 *
			 * Returns `0` (the default) to keep every signature forever.
			 *
			 * @param int $days Retention window in days.
			 */
			return max( 0, (int) apply_filters( 'eweb_cf7_signature_retention_days', 0 ) );
		}

		/*
		---------------------------------------------------------------------
		 * Internals
		 * ------------------------------------------------------------------
		 */

		/**
		 * Normalises every rendering option of a form-tag.
		 *
		 * @param WPCF7_FormTag $tag Current form-tag object.
		 * @return array{pen: string, background: string, pen_width: string, width: int, height: int, mode: string, attach: bool, attach_to: string, trim: bool, hint: string}
		 */
		public static function field_options( $tag ): array {
			if ( ! $tag instanceof WPCF7_FormTag ) {
				return self::default_field_options();
			}

			$pen_width = $tag->get_option( 'penwidth', 'num', true );
			$pen_width = is_string( $pen_width ) ? (float) $pen_width : 0.0;

			if ( $pen_width <= 0.0 ) {
				$pen_width = 2.5;
			}

			$mode = $tag->get_option( 'mode', '', true );

			if ( ! is_string( $mode ) || ! in_array( $mode, array( 'link', 'base64', 'cid', 'none' ), true ) ) {
				// Backwards compatibility with the legacy `inline` flag.
				$mode = $tag->has_option( 'inline' ) ? 'cid' : 'link';
			}

			$attach_to = $tag->get_option( 'attachto', '', true );

			if ( ! is_string( $attach_to ) || ! in_array( $attach_to, array( 'mail', 'mail_2', 'both' ), true ) ) {
				$attach_to = 'both';
			}

			$width  = (int) $tag->get_option( 'width', 'int', true );
			$height = (int) $tag->get_option( 'height', 'int', true );

			return array(
				'pen'        => self::normalise_colour( (string) $tag->get_option( 'color', '', true ), '#111827' ),
				'background' => self::normalise_colour( (string) $tag->get_option( 'backcolor', '', true ), '#ffffff' ),
				'pen_width'  => (string) min( 8.0, max( 0.5, $pen_width ) ),
				'width'      => min( 2000, max( 200, $width > 0 ? $width : self::DEFAULT_WIDTH ) ),
				'height'     => min( 1200, max( 100, $height > 0 ? $height : self::DEFAULT_HEIGHT ) ),
				'mode'       => $mode,
				'attach'     => ! $tag->has_option( 'noattach' ),
				'attach_to'  => $attach_to,
				'trim'       => ! $tag->has_option( 'notrim' ),
				'hint'       => trim( (string) $tag->get_option( 'hint', '', true ), "\"'" ),
			);
		}

		/**
		 * Returns the default rendering options, used when no form-tag is at hand.
		 *
		 * @return array{pen: string, background: string, pen_width: string, width: int, height: int, mode: string, attach: bool, attach_to: string, trim: bool, hint: string}
		 */
		public static function default_field_options(): array {
			return array(
				'pen'        => '#111827',
				'background' => '#ffffff',
				'pen_width'  => '2.5',
				'width'      => self::DEFAULT_WIDTH,
				'height'     => self::DEFAULT_HEIGHT,
				'mode'       => 'link',
				'attach'     => true,
				'attach_to'  => 'both',
				'trim'       => true,
				'hint'       => '',
			);
		}

		/**
		 * Sanitises a CSS colour value.
		 *
		 * @param string $value    Raw value.
		 * @param string $fallback Value returned when `$value` is not a colour.
		 * @return string
		 */
		private static function normalise_colour( string $value, string $fallback ): string {
			$value = trim( $value );

			if ( '' === $value ) {
				return $fallback;
			}

			if ( 0 === strcasecmp( $value, 'transparent' ) ) {
				return 'transparent';
			}

			if ( '#' !== $value[0] ) {
				$value = '#' . $value;
			}

			if ( 1 === preg_match( '/^#(?:[0-9a-f]{3}|[0-9a-f]{6})$/i', $value ) ) {
				return strtolower( $value );
			}

			return $fallback;
		}

		/**
		 * Decodes and validates a `data:` URI coming from the canvas.
		 *
		 * @param string $payload Raw payload.
		 * @return string|null Binary PNG data, or null when invalid.
		 */
		private static function decode_payload( string $payload ): ?string {
			if ( ! self::is_signature_payload( $payload ) ) {
				return null;
			}

			$separator = strpos( $payload, ',' );

			if ( false === $separator ) {
				return null;
			}

			$encoded = preg_replace( '/\s+/', '', substr( $payload, $separator + 1 ) );

			if ( ! is_string( $encoded ) || '' === $encoded ) {
				return null;
			}

			if ( strlen( $encoded ) > (int) ( self::MAX_BYTES * 1.4 ) + 64 ) {
				self::log( 'Rejected signature: payload exceeds the maximum size.' );
				return null;
			}

			$binary = base64_decode( $encoded, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- The canvas payload is a Base64 PNG data URI by design.

			if ( ! is_string( $binary ) || '' === $binary || strlen( $binary ) > self::MAX_BYTES ) {
				self::log( 'Rejected signature: payload is not valid base64.' );
				return null;
			}

			return self::sanitise_png( $binary );
		}

		/**
		 * Verifies the PNG structure and drops any data appended after `IEND`.
		 *
		 * @param string $binary Decoded payload.
		 * @return string|null Clean PNG data, or null when invalid.
		 */
		private static function sanitise_png( string $binary ): ?string {
			if ( ! str_starts_with( $binary, self::PNG_HEADER ) ) {
				return null;
			}

			$size = @getimagesizefromstring( $binary ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Invalid images are expected here.

			if ( ! is_array( $size ) || ! isset( $size['mime'] ) || 'image/png' !== $size['mime'] ) {
				return null;
			}

			$length = strlen( $binary );
			$offset = 8;
			$end    = 0;

			while ( $offset + 12 <= $length ) {
				$chunk = unpack( 'Nlength/a4type', substr( $binary, $offset, 8 ) );

				if ( ! is_array( $chunk ) ) {
					break;
				}

				$data_size = (int) $chunk['length'];

				if ( $data_size < 0 || $offset + 12 + $data_size > $length ) {
					break;
				}

				$offset += 12 + $data_size;

				if ( 'IEND' === $chunk['type'] ) {
					$end = $offset;
					break;
				}
			}

			if ( 0 === $end ) {
				self::log( 'Rejected signature: truncated or malformed PNG stream.' );
				return null;
			}

			return substr( $binary, 0, $end );
		}

		/**
		 * Tells whether a value looks like a base64 PNG data URI.
		 *
		 * @param string $value Raw value.
		 * @return bool
		 */
		private static function is_signature_payload( string $value ): bool {
			return 1 === preg_match( self::DATA_URI_PATTERN, $value );
		}

		/**
		 * Reads a raw posted value, bypassing the plugin's own filtering.
		 *
		 * @param string $field_name Form field name.
		 * @return string
		 */
		private static function raw_posted_value( string $field_name ): string {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Contact Form 7 verifies its own nonce before validating.
			$raw = $_POST[ $field_name ] ?? '';

			if ( is_array( $raw ) ) {
				$raw = reset( $raw );
			}

			if ( ! is_string( $raw ) ) {
				return '';
			}

			return trim( wp_unslash( $raw ) );
		}

		/**
		 * Returns the ID of the contact form being submitted.
		 *
		 * @return int
		 */
		private static function current_form_id(): int {
			if ( ! function_exists( 'wpcf7_get_current_contact_form' ) ) {
				return 0;
			}

			$contact_form = wpcf7_get_current_contact_form();

			return $contact_form instanceof WPCF7_ContactForm ? (int) $contact_form->id() : 0;
		}

		/**
		 * Writes a guard file, replacing it when its content is outdated.
		 *
		 * @param string $path    Absolute file path.
		 * @param string $content File content.
		 * @param bool   $force   Overwrite an already up-to-date file.
		 * @return void
		 */
		private static function write_file( string $path, string $content, bool $force = false ): void {
			if ( ! $force && is_readable( $path ) ) {
				$current = @file_get_contents( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Our own guard file: a local read with a silent failure is intentional.

				if ( is_string( $current ) && $current === $content ) {
					return;
				}
			}

			@file_put_contents( $path, $content, LOCK_EX ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Guard files must be written without user credentials.
		}

		/**
		 * Logs a message when debugging is enabled.
		 *
		 * @param string $message Message to log.
		 * @return void
		 */
		private static function log( string $message ): void {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( '[eweb-cf7-signature] ' . $message ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Debug only.
			}
		}

		/**
		 * Builds a colour picker field for the form-tag generator.
		 *
		 * @param string $reference Unique field reference.
		 * @param string $legend    Fieldset legend.
		 * @param string $option    Form-tag option name, including the colon.
		 * @param string $fallback   Default value.
		 * @return string
		 */
		private static function generator_color_field( string $reference, string $legend, string $option, string $fallback ): string {
			return sprintf(
				'<fieldset><legend id="%1$s">%2$s</legend><label><input type="color" value="%3$s" data-tag-part="option" data-tag-option="%4$s" /></label></fieldset>',
				esc_attr( $reference ),
				esc_html( $legend ),
				esc_attr( $fallback ),
				esc_attr( $option )
			);
		}

		/**
		 * Builds a number field for the form-tag generator.
		 *
		 * @param string $reference Unique field reference.
		 * @param string $legend    Fieldset legend.
		 * @param string $option    Form-tag option name, including the colon.
		 * @param string $fallback   Default value.
		 * @param string $step      Step attribute.
		 * @return string
		 */
		private static function generator_number_field( string $reference, string $legend, string $option, string $fallback, string $step ): string {
			return sprintf(
				'<fieldset><legend id="%1$s">%2$s</legend><label><input type="number" min="0" step="%5$s" value="%3$s" data-tag-part="option" data-tag-option="%4$s" /></label></fieldset>',
				esc_attr( $reference ),
				esc_html( $legend ),
				esc_attr( $fallback ),
				esc_attr( $option ),
				esc_attr( $step )
			);
		}

		/**
		 * Builds a text field for the form-tag generator.
		 *
		 * @param string $reference Unique field reference.
		 * @param string $legend    Fieldset legend.
		 * @param string $option    Form-tag option name, including the colon.
		 * @param string $fallback   Default value.
		 * @return string
		 */
		private static function generator_text_field( string $reference, string $legend, string $option, string $fallback ): string {
			return sprintf(
				'<fieldset><legend id="%1$s">%2$s</legend><label><input type="text" value="%3$s" data-tag-part="option" data-tag-option="%4$s" /></label></fieldset>',
				esc_attr( $reference ),
				esc_html( $legend ),
				esc_attr( $fallback ),
				esc_attr( $option )
			);
		}

		/**
		 * Builds the mail-mode selector field of the form-tag generator.
		 *
		 * @param string $reference Unique field reference.
		 * @return string
		 */
		private static function generator_mode_field( string $reference ): string {
			$options = array(
				'link'   => __( 'Public URL (recommended)', 'eweb-cf7-signature' ),
				'cid'    => __( 'Embedded image (CID)', 'eweb-cf7-signature' ),
				'base64' => __( 'Inline data URI (Base64)', 'eweb-cf7-signature' ),
				'none'   => __( 'Nothing in the body', 'eweb-cf7-signature' ),
			);

			$html = sprintf(
				'<fieldset><legend id="%1$s">%2$s</legend><label><select data-tag-part="option" data-tag-option="mode:">',
				esc_attr( $reference ),
				esc_html__( 'In the e-mail body', 'eweb-cf7-signature' )
			);

			foreach ( $options as $value => $label ) {
				$html .= sprintf(
					'<option value="%1$s"%2$s>%3$s</option>',
					esc_attr( $value ),
					selected( 'link', $value, false ),
					esc_html( $label )
				);
			}

			return $html . '</select></label></fieldset>';
		}
	}
}
