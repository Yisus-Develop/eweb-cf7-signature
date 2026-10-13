<?php
/**
 * EWEB GitHub Updater.
 *
 * Silent-engine updater for the EWEB plugin suite: it reads the `latest`
 * release of a GitHub repository and feeds WordPress with a clean update
 * package, professional details view and the remote compatibility metadata.
 *
 * Deliberate design notes:
 *
 * - Only the `releases/latest` endpoint is queried (never the whole `/releases`
 *   collection), and the answer is cached for six hours, so no admin page can
 *   saturate memory or the GitHub API.
 * - A release **asset** is preferred over the auto-generated zipball, so the
 *   downloaded folder is already named after the plugin. The folder-mapping
 *   routine stays in place as a fallback for zipball installs.
 * - `tested`, `requires` and `requires_php` are parsed from the remote
 *   `readme.txt` and injected into the update object.
 * - Every remote call validates the HTTP status and every file-system call is
 *   guarded, so a GitHub outage can never break the site.
 * - The whole class is wrapped in a `class_exists` shield: several EWEB plugins
 *   can ship it and only the first one defines it.
 *
 * @package EWEB_CF7_Signature
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'EWEB_GitHub_Updater' ) ) {

	/**
	 * Connects a self-hosted plugin to a GitHub repository.
	 */
	class EWEB_GitHub_Updater {

		/**
		 * How long the GitHub answer is cached, in seconds.
		 */
		private const CACHE_TTL = 6 * HOUR_IN_SECONDS;

		/**
		 * Absolute path of the plugin main file.
		 *
		 * @var string
		 */
		private string $plugin_file;

		/**
		 * GitHub account owning the repository.
		 *
		 * @var string
		 */
		private string $github_user;

		/**
		 * Repository name, which must match the plugin folder slug.
		 *
		 * @var string
		 */
		private string $github_repo;

		/**
		 * Branch used to resolve raw assets.
		 *
		 * @var string
		 */
		private string $branch;

		/**
		 * Plugin basename, e.g. `eweb-cf7-signature/eweb-cf7-signature.php`.
		 *
		 * @var string
		 */
		private string $basename;

		/**
		 * Cached GitHub release object.
		 *
		 * @var object|null
		 */
		private ?object $release = null;

		/**
		 * Cached remote readme metadata.
		 *
		 * @var array<string, mixed>|null
		 */
		private ?array $readme = null;

		/**
		 * Registers the updater.
		 *
		 * @param string $plugin_file Absolute path of the plugin main file.
		 * @param string $github_user GitHub account.
		 * @param string $github_repo Repository name (must match the folder slug).
		 * @param string $branch      Branch used for raw assets. Default `main`.
		 */
		public function __construct( string $plugin_file, string $github_user, string $github_repo, string $branch = 'main' ) {
			$this->plugin_file = $plugin_file;
			$this->github_user = $github_user;
			$this->github_repo = $github_repo;
			$this->branch      = $branch;
			$this->basename    = plugin_basename( $plugin_file );

			add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'check_update' ) );
			add_filter( 'plugins_api', array( $this, 'plugin_information' ), 20, 3 );
			add_filter( 'upgrader_post_install', array( $this, 'map_folder_name' ), 10, 3 );
		}

		/**
		 * Announces an available update to WordPress.
		 *
		 * @param object $transient Update transient.
		 * @return object
		 */
		public function check_update( $transient ) {
			if ( ! is_object( $transient ) || empty( $transient->checked ) ) {
				return $transient;
			}

			if ( ! isset( $transient->checked[ $this->basename ] ) ) {
				return $transient;
			}

			$release = $this->get_release();

			if ( ! $release ) {
				return $transient;
			}

			$version = $this->release_version( $release );

			if ( '' === $version || ! version_compare( (string) $transient->checked[ $this->basename ], $version, '<' ) ) {
				return $transient;
			}

			$readme = $this->get_readme_metadata();

			$update               = new stdClass();
			$update->slug         = $this->github_repo;
			$update->plugin       = $this->basename;
			$update->new_version  = $version;
			$update->url          = $this->repository_url();
			$update->package      = $this->release_package( $release );
			$update->requires     = $readme['requires'];
			$update->tested       = $readme['tested'];
			$update->requires_php = $readme['requires_php'];
			$update->icons        = $this->icons();
			$update->banners      = $this->banners();

			$transient->response[ $this->basename ] = $update;

			return $transient;
		}

		/**
		 * Feeds the "View version details" screen.
		 *
		 * @param mixed  $result Current result.
		 * @param string $action Requested action.
		 * @param object $args   Request arguments.
		 * @return mixed
		 */
		public function plugin_information( $result, $action = '', $args = null ) {
			if ( 'plugin_information' !== $action || ! is_object( $args ) ) {
				return $result;
			}

			if ( ! isset( $args->slug ) || $this->github_repo !== $args->slug ) {
				return $result;
			}

			$release = $this->get_release();

			if ( ! $release ) {
				return $result;
			}

			$readme = $this->get_readme_metadata();

			$information                  = new stdClass();
			$information->name            = $readme['name'];
			$information->slug            = $this->github_repo;
			$information->version         = $this->release_version( $release );
			$information->author          = $readme['author'];
			$information->author_homepage = $readme['author_uri'];
			$information->homepage        = $this->repository_url();
			$information->requires        = $readme['requires'];
			$information->tested          = $readme['tested'];
			$information->requires_php    = $readme['requires_php'];
			$information->download_link   = $this->release_package( $release );
			$information->last_updated    = (string) ( $release->published_at ?? '' );
			$information->icons           = $this->icons();
			$information->banners         = $this->banners();
			$information->sections        = $readme['sections'];

			$information->sections['changelog'] = $this->changelog_section( $release, $readme );

			return $information;
		}

		/**
		 * Renames the extracted folder after an install or update.
		 *
		 * GitHub zipballs always extract into `{repo}-{tag}`, while release
		 * assets built by the plugin already contain the right folder. This
		 * routine makes both cases converge on the official slug.
		 *
		 * @param array $response  Installation response.
		 * @param array $hook_extra Extra arguments.
		 * @param array $result    Installation result.
		 * @return array
		 */
		public function map_folder_name( $response, $hook_extra = array(), $result = array() ) {
			global $wp_filesystem;

			if ( ! is_array( $result ) || empty( $result['destination'] ) || ! $wp_filesystem instanceof WP_Filesystem_Base ) {
				return $result;
			}

			$target = trailingslashit( wp_normalize_path( plugin_dir_path( $this->plugin_file ) ) );
			$source = trailingslashit( wp_normalize_path( (string) $result['destination'] ) );

			if ( $source === $target || ! $wp_filesystem->is_dir( $result['destination'] ) ) {
				return $result;
			}

			if ( $wp_filesystem->move( $result['destination'], $target, true ) ) {
				$result['destination']        = untrailingslashit( $target );
				$result['remote_destination'] = untrailingslashit( $target );
				$result['destination_name']   = $this->github_repo;
			}

			return $result;
		}

		/*
		---------------------------------------------------------------------
		 * Remote data
		 * ------------------------------------------------------------------
		 */

		/**
		 * Returns the latest release of the repository.
		 *
		 * @return object|null
		 */
		private function get_release(): ?object {
			if ( null !== $this->release ) {
				return $this->release;
			}

			$cache_key = 'eweb_gh_updater_' . md5( $this->github_user . '/' . $this->github_repo );
			$cached    = get_site_transient( $cache_key );

			if ( is_object( $cached ) ) {
				$this->release = $cached;

				return $this->release;
			}

			if ( 'false' === $cached ) {
				return null;
			}

			$release = $this->request( 'https://api.github.com/repos/' . $this->github_user . '/' . $this->github_repo . '/releases/latest' );

			if ( ! is_object( $release ) || empty( $release->tag_name ) ) {
				set_site_transient( $cache_key, 'false', 15 * MINUTE_IN_SECONDS );

				return null;
			}

			set_site_transient( $cache_key, $release, self::CACHE_TTL );

			$this->release = $release;

			return $this->release;
		}

		/**
		 * Returns the compatibility metadata declared in the remote readme.txt.
		 *
		 * @return array<string, mixed>
		 */
		private function get_readme_metadata(): array {
			if ( null !== $this->readme ) {
				return $this->readme;
			}

			$fallback = array(
				'name'         => $this->github_repo,
				'author'       => 'Yisus Develop',
				'author_uri'   => 'https://enlaweb.agency/',
				'requires'     => '6.4',
				'tested'       => '',
				'requires_php' => '8.1',
				'sections'     => array(
					'description'  => sprintf(
						'<p>%s</p>',
						esc_html__( 'Professional plugin distributed from GitHub as part of the EWEB Suite.', 'eweb-cf7-signature' )
					),
					'installation' => sprintf(
						'<p>%s</p>',
						esc_html__( 'Upload the plugin folder to /wp-content/plugins/ and activate it from the Plugins screen.', 'eweb-cf7-signature' )
					),
				),
			);

			$this->readme = $fallback;

			$release = $this->get_release();

			if ( ! $release ) {
				return $this->readme;
			}

			$ref = rawurlencode( (string) $release->tag_name );

			$body = $this->request(
				'https://raw.githubusercontent.com/' . $this->github_user . '/' . $this->github_repo . '/' . $ref . '/readme.txt',
				true
			);

			if ( ! is_string( $body ) || '' === $body ) {
				$body = $this->request(
					'https://raw.githubusercontent.com/' . $this->github_user . '/' . $this->github_repo . '/' . $this->branch . '/readme.txt',
					true
				);
			}

			if ( ! is_string( $body ) || '' === $body ) {
				return $this->readme;
			}

			$headers = $this->readme_headers( $body );

			$this->readme['name']         = $headers['plugin_name'] ?? $this->readme['name'];
			$this->readme['author']       = $headers['author'] ?? $this->readme['author'];
			$this->readme['author_uri']   = $headers['author_uri'] ?? $this->readme['author_uri'];
			$this->readme['requires']     = $headers['requires'] ?? $this->readme['requires'];
			$this->readme['tested']       = $headers['tested'] ?? $this->readme['tested'];
			$this->readme['requires_php'] = $headers['requires_php'] ?? $this->readme['requires_php'];

			$sections = $this->readme_sections( $body );

			foreach ( array( 'description', 'installation', 'faq' ) as $section ) {
				if ( isset( $sections[ $section ] ) ) {
					$this->readme['sections'][ $section ] = $sections[ $section ];
				}
			}

			if ( isset( $sections['changelog'] ) ) {
				$this->readme['sections']['changelog'] = $sections['changelog'];
			}

			return $this->readme;
		}

		/**
		 * Performs a GitHub request.
		 *
		 * @param string $url  Absolute URL.
		 * @param bool   $plain Return the raw body instead of JSON.
		 * @return object|string|null
		 */
		private function request( string $url, bool $plain = false ) {
			$response = wp_remote_get(
				$url,
				array(
					'timeout'     => 10,
					'redirection' => 2,
					'user-agent'  => 'WordPress/' . get_bloginfo( 'version' ) . '; ' . home_url(),
					'headers'     => array( 'Accept' => 'application/vnd.github+json' ),
				)
			);

			if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
				return null;
			}

			$body = (string) wp_remote_retrieve_body( $response );

			if ( $plain ) {
				return $body;
			}

			$decoded = json_decode( $body );

			return is_object( $decoded ) ? $decoded : null;
		}

		/**
		 * Normalises the version of a release (`v1.2.3` becomes `1.2.3`).
		 *
		 * @param object $release Release object.
		 * @return string
		 */
		private function release_version( object $release ): string {
			return ltrim( (string) $release->tag_name, 'vV' );
		}

		/**
		 * Returns the download URL of a release.
		 *
		 * A ZIP asset attached to the release is preferred because its top
		 * folder is already the plugin slug and it excludes development files.
		 *
		 * @param object $release Release object.
		 * @return string
		 */
		private function release_package( object $release ): string {
			if ( ! empty( $release->assets ) && is_array( $release->assets ) ) {
				foreach ( $release->assets as $asset ) {
					if (
						isset( $asset->browser_download_url, $asset->name )
						&& str_ends_with( strtolower( (string) $asset->name ), '.zip' )
					) {
						return (string) $asset->browser_download_url;
					}
				}
			}

			return (string) ( $release->zipball_url ?? '' );
		}

		/**
		 * Builds the changelog section of the details modal.
		 *
		 * @param object               $release Release object.
		 * @param array<string, mixed> $readme  Remote readme metadata.
		 * @return string
		 */
		private function changelog_section( object $release, array $readme ): string {
			$html = '';

			if ( ! empty( $release->body ) ) {
				$html .= wp_kses_post( wpautop( (string) $release->body ) );
			}

			$html .= sprintf(
				'<p><strong>%1$s</strong> %2$s</p>',
				esc_html__( 'Version:', 'eweb-cf7-signature' ),
				esc_html( $this->release_version( $release ) )
			);

			$html .= sprintf(
				'<p><a href="%1$s" target="_blank" rel="noopener noreferrer">%2$s</a></p>',
				esc_url( $readme['sections']['changelog'] ?? $this->repository_url() ),
				esc_html__( 'Full changelog on GitHub', 'eweb-cf7-signature' )
			);

			if ( ! empty( $readme['sections']['changelog'] ) && str_starts_with( (string) $readme['sections']['changelog'], '<' ) ) {
				$html .= wp_kses_post( (string) $readme['sections']['changelog'] );
			}

			return $html;
		}

		/**
		 * Returns the repository URL.
		 *
		 * @return string
		 */
		private function repository_url(): string {
			return 'https://github.com/' . $this->github_user . '/' . $this->github_repo;
		}

		/**
		 * Returns the raw URL of a repository asset.
		 *
		 * @param string $path Repository relative path.
		 * @return string
		 */
		private function raw_url( string $path ): string {
			return 'https://raw.githubusercontent.com/' . $this->github_user . '/' . $this->github_repo . '/' . $this->branch . '/' . ltrim( $path, '/' );
		}

		/**
		 * Returns the icon URLs WordPress expects.
		 *
		 * @return array<string, string>
		 */
		private function icons(): array {
			return array(
				'1x' => $this->raw_url( 'assets/icon-128x128.png' ),
				'2x' => $this->raw_url( 'assets/icon-256x256.png' ),
			);
		}

		/**
		 * Returns the banner URLs WordPress expects.
		 *
		 * @return array<string, string>
		 */
		private function banners(): array {
			return array(
				'low'  => $this->raw_url( 'assets/banner-772x250.png' ),
				'high' => $this->raw_url( 'assets/banner-1544x500.png' ),
			);
		}

		/*
		---------------------------------------------------------------------
		 * readme.txt parsing
		 * ------------------------------------------------------------------
		 */

		/**
		 * Extracts the header fields of a WordPress readme.
		 *
		 * @param string $body Remote readme contents.
		 * @return array<string, string>
		 */
		private function readme_headers( string $body ): array {
			$fields = array(
				'plugin_name'  => 'Plugin Name',
				'author'       => 'Contributors',
				'author_uri'   => 'Author URI',
				'requires'     => 'Requires at least',
				'tested'       => 'Tested up to',
				'requires_php' => 'Requires PHP',
			);

			$headers = array();

			foreach ( $fields as $key => $label ) {
				if ( preg_match( '/^\s*' . preg_quote( $label, '/' ) . '\s*:\s*(.+)$/mi', $body, $match ) ) {
					$headers[ $key ] = trim( $match[1] );
				}
			}

			return $headers;
		}

		/**
		 * Extracts the `== Section ==` blocks of a WordPress readme.
		 *
		 * @param string $body Remote readme contents.
		 * @return array<string, string> Section contents, as HTML.
		 */
		private function readme_sections( string $body ): array {
			$body = str_replace( array( "\r\n", "\r" ), "\n", $body );

			if ( ! preg_match_all( '/^==\s*(.+?)\s*==\s*$/mi', $body, $matches, PREG_OFFSET_CAPTURE ) ) {
				return array();
			}

			$sections = array();
			$count    = count( $matches[0] );

			for ( $index = 0; $index < $count; $index++ ) {
				$title   = strtolower( trim( $matches[1][ $index ][0] ) );
				$start   = $matches[0][ $index ][1] + strlen( $matches[0][ $index ][0] );
				$end     = $index + 1 < $count ? $matches[0][ $index + 1 ][1] : strlen( $body );
				$content = trim( substr( $body, $start, $end - $start ) );

				$key = str_replace( ' ', '_', $title );

				if ( 'frequently_asked_questions' === $key ) {
					$key = 'faq';
				}

				if ( ! isset( $sections[ $key ] ) ) {
					$sections[ $key ] = wp_kses_post( wpautop( $this->readme_markup( $content ) ) );
				}
			}

			return $sections;
		}

		/**
		 * Applies the light markup of readme.txt files.
		 *
		 * @param string $content Raw section contents.
		 * @return string
		 */
		private function readme_markup( string $content ): string {
			$content = preg_replace( '/^\*\s+(.+)$/m', '<li>$1</li>', $content );
			$content = preg_replace( '/(<li>.*<\/li>\n?)+/s', '<ul>$0</ul>', (string) $content );
			$content = preg_replace( '/^=\s*(.+?)\s*=$/m', '<h4>$1</h4>', (string) $content );
			$content = preg_replace( '/\*\*(.+?)\*\*/s', '<strong>$1</strong>', (string) $content );
			$content = preg_replace( '/`(.+?)`/s', '<code>$1</code>', (string) $content );

			return (string) $content;
		}
	}
}
