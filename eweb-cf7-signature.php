<?php
/**
 * Plugin Name:       EWEB CF7 Signature
 * Plugin URI:        https://enlaweb.agency/
 * Description:       Digital signature (HTML5 canvas) field for Contact Form 7. Public but sandboxed PNG storage, base64/CID inline e-mails, automatic attachments and a native mPDF path bridge. Zero external dependencies.
 * Version:           1.0.0
 * Requires at least: 6.4
 * Requires PHP:      8.1
 * Requires Plugins:  contact-form-7
 * Author:            enlaweb
 * Author URI:        https://enlaweb.agency/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       eweb-cf7-signature
 * Domain Path:       /languages
 * Update URI:        false
 *
 * @package EWEB_CF7_Signature
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

if ( version_compare( PHP_VERSION, '8.1', '<' ) ) {
	add_action( 'admin_notices', 'eweb_cf7_signature_php_version_notice' );

	/**
	 * Warns about the unsupported PHP version.
	 *
	 * @return void
	 */
	function eweb_cf7_signature_php_version_notice() {
		printf(
			'<div class="notice notice-error"><p>%s</p></div>',
			esc_html(
				sprintf(
					/* translators: %s: minimum required PHP version. */
					__( 'EWEB CF7 Signature requires PHP %s or higher and has been disabled.', 'eweb-cf7-signature' ),
					'8.1'
				)
			)
		);
	}

	return;
}

define( 'EWEB_CF7_SIG_VERSION', '1.0.0' );
define( 'EWEB_CF7_SIG_FILE', __FILE__ );
define( 'EWEB_CF7_SIG_DIR', plugin_dir_path( __FILE__ ) );
define( 'EWEB_CF7_SIG_URL', plugin_dir_url( __FILE__ ) );
define( 'EWEB_CF7_SIG_BASENAME', plugin_basename( __FILE__ ) );
define( 'EWEB_CF7_SIG_SLUG', 'eweb-cf7-signature' );
define( 'EWEB_CF7_SIG_MIN_PHP', '8.1' );
define( 'EWEB_CF7_SIG_GITHUB_USER', 'Yisus-Develop' );
define( 'EWEB_CF7_SIG_GITHUB_REPO', 'eweb-cf7-signature' );

require_once EWEB_CF7_SIG_DIR . 'includes/class-eweb-github-updater.php';
require_once EWEB_CF7_SIG_DIR . 'includes/class-compat.php';
require_once EWEB_CF7_SIG_DIR . 'includes/class-signature-handler.php';
require_once EWEB_CF7_SIG_DIR . 'includes/class-mail-integrator.php';
require_once EWEB_CF7_SIG_DIR . 'includes/class-pdf-bridge.php';

/**
 * Starts the silent update engine.
 *
 * Runs before the Contact Form 7 check on purpose: the plugin must be able to
 * update itself even while the dependency is disabled.
 *
 * @return void
 */
function eweb_cf7_signature_init_updater(): void {
	if ( ! class_exists( 'EWEB_GitHub_Updater' ) ) {
		return;
	}

	new EWEB_GitHub_Updater(
		EWEB_CF7_SIG_FILE,
		EWEB_CF7_SIG_GITHUB_USER,
		EWEB_CF7_SIG_GITHUB_REPO
	);
}
add_action( 'plugins_loaded', 'eweb_cf7_signature_init_updater', 5 );

/**
 * Loads the plugin translations.
 *
 * @return void
 */
function eweb_cf7_signature_load_textdomain(): void {
	load_plugin_textdomain(
		'eweb-cf7-signature',
		false,
		dirname( EWEB_CF7_SIG_BASENAME ) . '/languages'
	);
}
add_action( 'init', 'eweb_cf7_signature_load_textdomain', 1 );

/**
 * Boots the three plugin modules once the whole plugin stack is loaded.
 *
 * Contact Form 7 requires its own files at plugin load time, so
 * `WPCF7_ContactForm` is already available on `plugins_loaded` priority 20.
 *
 * @return void
 */
function eweb_cf7_signature_bootstrap(): void {
	if ( ! class_exists( 'WPCF7_ContactForm' ) ) {
		add_action( 'admin_notices', 'eweb_cf7_signature_missing_cf7_notice' );
		return;
	}

	EWEB_CF7_Signature_Compat::init();
	EWEB_CF7_Signature_Handler::init();
	EWEB_CF7_Signature_Mail_Integrator::init();
	EWEB_CF7_Signature_PDF_Bridge::init();
}
add_action( 'plugins_loaded', 'eweb_cf7_signature_bootstrap', 20 );

/**
 * Warns when Contact Form 7 is not active.
 *
 * @return void
 */
function eweb_cf7_signature_missing_cf7_notice(): void {
	if ( ! current_user_can( 'activate_plugins' ) ) {
		return;
	}

	printf(
		'<div class="notice notice-warning"><p>%s</p></div>',
		esc_html__( 'EWEB CF7 Signature needs Contact Form 7 to be installed and activated.', 'eweb-cf7-signature' )
	);
}

/**
 * Creates the signature folder (and its Apache/IIS guards) on activation.
 *
 * @return void
 */
function eweb_cf7_signature_activate(): void {
	EWEB_CF7_Signature_Handler::ensure_upload_dir();
	EWEB_CF7_Signature_Handler::schedule_cleanup();
}
register_activation_hook( __FILE__, 'eweb_cf7_signature_activate' );

/**
 * Clears the retention cron on deactivation.
 *
 * @return void
 */
function eweb_cf7_signature_deactivate(): void {
	wp_clear_scheduled_hook( EWEB_CF7_Signature_Handler::CRON_HOOK );
}
register_deactivation_hook( __FILE__, 'eweb_cf7_signature_deactivate' );
