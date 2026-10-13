<?php
/**
 * Core updater.
 *
 * @package hzf-updater
 */

defined( 'ABSPATH' ) || exit;

/**
 * GitHub release updater. Hooks the core `update_{plugins,themes}_github.com`
 * filters, so packages need no update code of their own.
 */
final class HZF_Updater {

	const OPTION       = 'hzf_updater_state';
	const DEFAULT_BASE = 'https://api.github.com';

	/**
	 * Register hooks.
	 */
	public static function init() {
		add_filter( 'update_plugins_github.com', array( __CLASS__, 'plugin_update' ), 10, 3 );
		add_filter( 'update_themes_github.com', array( __CLASS__, 'theme_update' ), 10, 3 );
		add_filter( 'plugins_api', array( __CLASS__, 'plugin_info' ), 10, 3 );
		add_filter( 'themes_api', array( __CLASS__, 'theme_info' ), 10, 3 );
		add_filter( 'http_request_args', array( __CLASS__, 'auth_download' ), 10, 2 );
		add_filter( 'http_response', array( __CLASS__, 'follow_redirect' ), 10, 3 );
		add_filter( 'upgrader_source_selection', array( __CLASS__, 'fix_folder' ), 10, 4 );
		add_filter( 'extra_plugin_headers', array( __CLASS__, 'extra_headers' ) );
		add_filter( 'extra_theme_headers', array( __CLASS__, 'extra_headers' ) );
		add_action( 'load-update-core.php', array( __CLASS__, 'on_check_again' ), 1 ); // Before core's own check on this hook.
		add_action( 'init', array( __CLASS__, 'load_textdomain' ) );
	}

	/**
	 * Load translations.
	 */
	public static function load_textdomain() {
		load_plugin_textdomain( 'hzf-updater', false, dirname( plugin_basename( __DIR__ ) ) . '/languages' );
	}

	/**
	 * Ask core to read "Tested up to" from plugin/theme headers.
	 *
	 * @param string[] $headers Extra headers.
	 * @return string[]
	 */
	public static function extra_headers( $headers ) {
		$headers[] = 'Tested up to';
		return $headers;
	}

	/**
	 * The token, from wp-config.php only.
	 *
	 * @return string
	 */
	public static function token() {
		return defined( 'HZF_UPDATER_GITHUB_TOKEN' ) && is_string( HZF_UPDATER_GITHUB_TOKEN ) ? trim( HZF_UPDATER_GITHUB_TOKEN ) : '';
	}

	/**
	 * Fetch base URL (filterable, e.g. to point at a proxy that holds the token).
	 *
	 * @return string
	 */
	public static function base() {
		return untrailingslashit( (string) apply_filters( 'hzf_updater_api_base', self::DEFAULT_BASE ) );
	}

	/**
	 * True when requests would go to GitHub without a token.
	 *
	 * @return bool
	 */
	public static function token_missing() {
		return '' === self::token() && self::DEFAULT_BASE === self::base();
	}

	/**
	 * Strip the token from any string that may be shown or stored.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	public static function redact( $text ) {
		$token = self::token();
		$text  = '' === $token ? (string) $text : str_replace( $token, '[redacted]', (string) $text );
		return mb_substr( wp_strip_all_tags( $text ), 0, 300 );
	}

	/**
	 * Network-wide state (per-repo cache, ETag, errors, back-off); never autoloaded.
	 *
	 * @return array
	 */
	public static function state() {
		return (array) get_site_option( self::OPTION, array() );
	}

	/**
	 * Make every repo due for a re-check (ETag and last good data are kept).
	 */
	public static function clear_cache() {
		$state = self::state();
		foreach ( $state as $repo => $entry ) {
			$state[ $repo ]['checked'] = 0;
			$state[ $repo ]['backoff'] = 0;
		}
		update_site_option( self::OPTION, $state );
	}

	/**
	 * Clear the cache and re-run the core update checks.
	 */
	public static function check_now() {
		self::clear_cache();
		delete_site_transient( 'update_plugins' );
		delete_site_transient( 'update_themes' );
		wp_update_plugins();
		wp_update_themes();
	}

	/**
	 * "Check again" on Dashboard -> Updates.
	 */
	public static function on_check_again() {
		if ( isset( $_GET['force-check'] ) && current_user_can( 'update_plugins' ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- core's own link has no nonce.
			self::clear_cache();
			delete_site_transient( 'update_plugins' ); // Core only throttles these to one check a minute here.
			delete_site_transient( 'update_themes' );
		}
	}

	/**
	 * "owner/repo" from an Update URI, or '' if it is not ours.
	 *
	 * @param string $uri Update URI header.
	 * @return string
	 */
	public static function repo_from_uri( $uri ) {
		if ( ! preg_match( '#^https://github\.com/([A-Za-z0-9_.-]+)/([A-Za-z0-9_.-]+?)(?:\.git)?/?$#', trim( (string) $uri ), $m ) ) {
			return '';
		}
		$repo = $m[1] . '/' . $m[2];
		return apply_filters( 'hzf_updater_manage_repo', true, $repo ) ? $repo : '';
	}

	/**
	 * Core filter for plugins.
	 *
	 * @param array|false $update Update data.
	 * @param array       $data   Plugin headers.
	 * @param string      $file   Plugin file.
	 * @return array|false
	 */
	public static function plugin_update( $update, $data, $file ) {
		return self::build_update( $update, $data, self::plugin_slug( $file ), $file, false );
	}

	/**
	 * Core filter for themes.
	 *
	 * @param array|false $update Update data.
	 * @param array       $data   Theme headers.
	 * @param string      $css    Theme stylesheet (= slug).
	 * @return array|false
	 */
	public static function theme_update( $update, $data, $css ) {
		return self::build_update( $update, $data, $css, $css, true );
	}

	/**
	 * Build the update array for one package.
	 *
	 * @param array|false $update   Incoming value.
	 * @param array       $data     Headers.
	 * @param string      $slug     Package slug.
	 * @param string      $id       Plugin file or theme stylesheet.
	 * @param bool        $is_theme Whether it is a theme.
	 * @return array|false
	 */
	private static function build_update( $update, $data, $slug, $id, $is_theme ) {
		$repo = self::repo_from_uri( isset( $data['UpdateURI'] ) ? $data['UpdateURI'] : '' );
		$rel  = $repo ? self::release( $repo ) : array();
		if ( ! $rel ) {
			return $update;
		}
		$out = array(
			'slug'    => $slug,
			'version' => $data['Version'],
			'url'     => $rel['url'],
		);
		if ( $is_theme ) {
			$out['theme'] = $id;
		}
		$asset = self::asset_id( $rel, $slug );
		if ( $asset && version_compare( $rel['version'], $data['Version'], '>' ) ) {
			$out['version'] = $rel['version'];
			$out['package'] = self::asset_url( $repo, $asset );
			$headers        = $is_theme ? self::theme_headers( $id ) : $data;
			foreach ( array(
				'requires'     => 'RequiresWP',
				'requires_php' => 'RequiresPHP',
				'tested'       => 'Tested up to',
			) as $key => $header ) {
				if ( ! empty( $headers[ $header ] ) ) {
					$out[ $key ] = $headers[ $header ];
				}
			}
		}
		return $out;
	}

	/**
	 * Id of the release asset `<slug>.zip` or `<slug>-<version>.zip`, else 0.
	 *
	 * @param array  $rel  Release data.
	 * @param string $slug Package slug.
	 * @return int
	 */
	private static function asset_id( $rel, $slug ) {
		foreach ( array( $slug . '.zip', $slug . '-' . $rel['version'] . '.zip' ) as $name ) {
			if ( ! empty( $rel['assets'][ $name ] ) ) {
				return (int) $rel['assets'][ $name ];
			}
		}
		return 0;
	}

	/**
	 * Header values of an installed theme.
	 *
	 * @param string $css Stylesheet.
	 * @return array
	 */
	private static function theme_headers( $css ) {
		$theme = wp_get_theme( $css );
		$out   = array();
		foreach ( array( 'RequiresWP', 'RequiresPHP', 'Tested up to' ) as $header ) {
			$out[ $header ] = $theme->exists() ? $theme->get( $header ) : '';
		}
		return $out;
	}

	/**
	 * Slug of a plugin file.
	 *
	 * @param string $file Plugin file relative to the plugins dir.
	 * @return string
	 */
	public static function plugin_slug( $file ) {
		return false === strpos( $file, '/' ) ? basename( $file, '.php' ) : dirname( $file );
	}

	/**
	 * API URL of a release asset (this is the `package` URL).
	 *
	 * @param string $repo     owner/repo.
	 * @param int    $asset_id Asset id.
	 * @return string
	 */
	private static function asset_url( $repo, $asset_id ) {
		return self::base() . '/repos/' . $repo . '/releases/assets/' . (int) $asset_id;
	}

	/**
	 * GitHub may only be called from admin, cron and WP-CLI.
	 *
	 * @return bool
	 */
	private static function can_fetch() {
		return is_admin() || wp_doing_cron() || ( defined( 'WP_CLI' ) && WP_CLI );
	}

	/**
	 * Latest release of a repo: cached 6 h, revalidated by ETag, backed off on errors.
	 *
	 * @param string $repo owner/repo.
	 * @return array Release data, or empty array.
	 */
	public static function release( $repo ) {
		$state = self::state();
		$entry = wp_parse_args(
			isset( $state[ $repo ] ) ? $state[ $repo ] : array(),
			array(
				'etag'      => '',
				'release'   => array(),
				'checked'   => 0,
				'attempted' => 0,
				'backoff'   => 0,
				'failures'  => 0,
				'code'      => 0,
				'error'     => '',
			)
		);
		$old   = isset( $state[ $repo ] ) ? $state[ $repo ] : null;
		$now   = time();
		$ttl   = (int) apply_filters( 'hzf_updater_cache_ttl', 6 * HOUR_IN_SECONDS, $repo );
		$due   = ! $entry['release'] || $entry['checked'] + $ttl <= $now;

		if ( $due && $entry['backoff'] <= $now && self::can_fetch() && ! self::token_missing() ) {
			$entry = self::fetch( $repo, $entry, $ttl );
		}
		if ( $entry !== $old ) {
			$state[ $repo ] = $entry;
			update_site_option( self::OPTION, $state );
		}
		return $entry['release'];
	}

	/**
	 * One conditional request for /releases/latest.
	 *
	 * @param string $repo  owner/repo.
	 * @param array  $entry Cache entry.
	 * @param int    $ttl   Cache lifetime.
	 * @return array Updated entry.
	 */
	private static function fetch( $repo, $entry, $ttl ) {
		$headers = array(
			'Accept'               => 'application/vnd.github+json',
			'X-GitHub-Api-Version' => '2022-11-28',
		);
		if ( '' !== self::token() ) {
			$headers['Authorization'] = 'Bearer ' . self::token();
		}
		if ( $entry['etag'] && $entry['release'] ) {
			$headers['If-None-Match'] = $entry['etag'];
		}
		$res = wp_remote_get(
			self::base() . '/repos/' . $repo . '/releases/latest',
			array(
				'timeout' => 15,
				'headers' => $headers,
			)
		);
		if ( is_wp_error( $res ) ) {
			return self::fail( $entry, 0, $res->get_error_message(), array(), $ttl );
		}
		$code = (int) wp_remote_retrieve_response_code( $res );
		if ( 304 === $code && $entry['release'] ) {
			return array_merge( $entry, self::ok() );
		}
		$json = json_decode( wp_remote_retrieve_body( $res ), true );
		if ( 200 !== $code ) {
			/* translators: %d: HTTP status code. */
			$msg = sprintf( __( 'GitHub returned HTTP %d.', 'hzf-updater' ), $code );
			if ( is_array( $json ) && ! empty( $json['message'] ) ) {
				$msg .= ' ' . $json['message'];
			}
			return self::fail( $entry, $code, $msg, wp_remote_retrieve_headers( $res ), $ttl );
		}
		$release = self::parse_release( $json );
		if ( ! $release ) {
			return self::fail( $entry, 200, __( 'The latest release is a draft, a prerelease or has no usable version tag.', 'hzf-updater' ), array(), $ttl );
		}
		return array_merge(
			$entry,
			self::ok(),
			array(
				'etag'    => (string) wp_remote_retrieve_header( $res, 'etag' ),
				'release' => $release,
			)
		);
	}

	/**
	 * Cache fields after a successful check.
	 *
	 * @return array
	 */
	private static function ok() {
		return array(
			'checked'   => time(),
			'attempted' => time(),
			'backoff'   => 0,
			'failures'  => 0,
			'code'      => 200,
			'error'     => '',
		);
	}

	/**
	 * Record a failure; the last good release stays in place.
	 *
	 * @param array             $entry   Cache entry.
	 * @param int               $code    HTTP status (0 = transport error).
	 * @param string            $msg     Message (redacted here).
	 * @param array|object|null $headers Response headers.
	 * @param int               $ttl     Cache lifetime (back-off cap).
	 * @return array
	 */
	private static function fail( $entry, $code, $msg, $headers, $ttl ) {
		++$entry['failures'];
		$wait = min( max( $ttl, 300 ), 300 * ( 2 ** min( $entry['failures'] - 1, 10 ) ) );
		if ( in_array( $code, array( 403, 429 ), true ) && ( isset( $headers['retry-after'] ) || ( isset( $headers['x-ratelimit-remaining'] ) && '0' === (string) $headers['x-ratelimit-remaining'] ) ) ) {
			$reset = isset( $headers['retry-after'] ) ? time() + (int) $headers['retry-after'] : (int) ( isset( $headers['x-ratelimit-reset'] ) ? $headers['x-ratelimit-reset'] : 0 );
			$wait  = min( DAY_IN_SECONDS, max( $wait, $reset - time() ) );
			$code  = 429;
		}
		$entry['attempted'] = time();
		$entry['code']      = $code;
		$entry['error']     = self::redact( $msg );
		$entry['backoff']   = time() + $wait;
		return $entry;
	}

	/**
	 * Reduce the GitHub payload to what we use.
	 *
	 * @param mixed $json Decoded response.
	 * @return array
	 */
	private static function parse_release( $json ) {
		if ( ! is_array( $json ) || ! empty( $json['draft'] ) || ! empty( $json['prerelease'] ) || empty( $json['tag_name'] ) ) {
			return array();
		}
		$version = ltrim( (string) $json['tag_name'], 'vV' );
		if ( ! preg_match( '/^\d+(\.\d+)*([-+.]?[0-9A-Za-z.-]+)?$/', $version ) ) {
			return array();
		}
		$assets = array();
		foreach ( isset( $json['assets'] ) ? (array) $json['assets'] : array() as $asset ) {
			if ( ! empty( $asset['name'] ) && ! empty( $asset['id'] ) && ( ! isset( $asset['state'] ) || 'uploaded' === $asset['state'] ) ) {
				$assets[ (string) $asset['name'] ] = (int) $asset['id'];
			}
		}
		return array(
			'version' => $version,
			'url'     => isset( $json['html_url'] ) ? esc_url_raw( $json['html_url'] ) : '',
			'date'    => isset( $json['published_at'] ) ? (string) $json['published_at'] : '',
			'body'    => isset( $json['body'] ) ? mb_substr( (string) $json['body'], 0, 20000 ) : '',
			'assets'  => $assets,
		);
	}

	/**
	 * Repo (from our state) whose asset URL this is, else ''.
	 *
	 * @param string $url Request URL.
	 * @return string
	 */
	private static function asset_repo( $url ) {
		$base = wp_parse_url( self::base() );
		$u    = wp_parse_url( $url );
		if ( empty( $base['host'] ) || empty( $u['host'] ) || isset( $u['query'] ) || isset( $u['user'] )
			|| strtolower( $u['scheme'] ) !== strtolower( $base['scheme'] ) || strtolower( $u['host'] ) !== strtolower( $base['host'] )
			|| ( isset( $u['port'] ) ? $u['port'] : 0 ) !== ( isset( $base['port'] ) ? $base['port'] : 0 ) ) {
			return '';
		}
		$prefix = preg_quote( isset( $base['path'] ) ? $base['path'] : '', '#' );
		if ( ! isset( $u['path'] ) || ! preg_match( '#^' . $prefix . '/repos/([^/]+/[^/]+)/releases/assets/\d+$#', $u['path'], $m ) ) {
			return '';
		}
		return array_key_exists( $m[1], self::state() ) ? $m[1] : '';
	}

	/**
	 * Authenticate asset downloads on the API host only, and take over redirects.
	 *
	 * @param array  $args Request args.
	 * @param string $url  Request URL.
	 * @return array
	 */
	public static function auth_download( $args, $url ) {
		if ( ! self::asset_repo( $url ) ) {
			return $args;
		}
		$args['headers'] = array_merge( is_array( $args['headers'] ) ? $args['headers'] : array(), array( 'Accept' => 'application/octet-stream' ) );
		if ( '' !== self::token() ) {
			$args['headers']['Authorization'] = 'Bearer ' . self::token();
		}
		$args['redirection'] = 0; // The signed S3 URL must be fetched by us, without the token.
		$args['hzf_updater'] = true;
		return $args;
	}

	/**
	 * Follow GitHub's 302 to the signed download URL without the Authorization header.
	 *
	 * @param array|WP_Error $response Response.
	 * @param array          $args     Request args.
	 * @param string         $url      Request URL.
	 * @return array|WP_Error
	 */
	public static function follow_redirect( $response, $args, $url ) {
		if ( empty( $args['hzf_updater'] ) || is_wp_error( $response ) ) {
			return $response;
		}
		$code     = (int) wp_remote_retrieve_response_code( $response );
		$location = wp_remote_retrieve_header( $response, 'location' );
		if ( $code < 300 || $code > 399 || ! is_string( $location ) || '' === $location ) {
			return $response;
		}
		unset( $args['hzf_updater'] );
		$args['headers']     = array();
		$args['redirection'] = 5;
		return wp_safe_remote_get( WP_Http::make_absolute_url( $location, $url ), $args );
	}

	/**
	 * Rename an extracted package folder to the installed slug.
	 *
	 * @param string|WP_Error $source        Extracted folder.
	 * @param string          $remote_source Temp working dir.
	 * @param WP_Upgrader     $upgrader      Upgrader.
	 * @param array           $extra         Hook extra (`plugin` or `theme`).
	 * @return string|WP_Error
	 */
	public static function fix_folder( $source, $remote_source, $upgrader, $extra = array() ) {
		global $wp_filesystem;
		if ( is_wp_error( $source ) || ! is_array( $extra ) ) {
			return $source;
		}
		if ( ! empty( $extra['plugin'] ) && false !== strpos( $extra['plugin'], '/' ) ) {
			$slug = dirname( $extra['plugin'] );
			$uri  = get_plugin_data( WP_PLUGIN_DIR . '/' . $extra['plugin'], false, false )['UpdateURI'];
		} elseif ( ! empty( $extra['theme'] ) ) {
			$slug = $extra['theme'];
			$uri  = wp_get_theme( $slug )->get( 'UpdateURI' );
		} else {
			return $source;
		}
		$dir = untrailingslashit( $source );
		if ( ! self::repo_from_uri( $uri ) || basename( $dir ) === $slug || untrailingslashit( $remote_source ) === $dir ) {
			return $source;
		}
		$target = trailingslashit( $remote_source ) . $slug;
		if ( ! $wp_filesystem->move( $dir, $target, true ) ) {
			return new WP_Error( 'hzf_updater_rename', __( 'Could not rename the extracted package folder.', 'hzf-updater' ) );
		}
		return trailingslashit( $target );
	}

	/**
	 * Managed packages (those with a github.com Update URI) and their release state.
	 *
	 * @return array[] Rows: type, id, slug, name, repo, installed, latest, checked, code, error.
	 */
	public static function packages() {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$items = array();
		foreach ( get_plugins() as $file => $data ) {
			$items[] = array( 'plugin', $file, self::plugin_slug( $file ), $data['Name'], $data['UpdateURI'], $data['Version'] );
		}
		foreach ( wp_get_themes() as $css => $theme ) {
			$items[] = array( 'theme', $css, $css, $theme->get( 'Name' ), $theme->get( 'UpdateURI' ), $theme->get( 'Version' ) );
		}
		$state = self::state();
		$rows  = array();
		foreach ( $items as $item ) {
			$repo = self::repo_from_uri( $item[4] );
			if ( $repo ) {
				$entry  = isset( $state[ $repo ] ) ? $state[ $repo ] : array();
				$rows[] = array(
					'type'      => $item[0],
					'id'        => $item[1],
					'slug'      => $item[2],
					'name'      => $item[3],
					'repo'      => $repo,
					'installed' => $item[5],
					'latest'    => ! empty( $entry['release']['version'] ) ? $entry['release']['version'] : '',
					'checked'   => ! empty( $entry['attempted'] ) ? (int) $entry['attempted'] : 0,
					'code'      => ! empty( $entry['code'] ) ? (int) $entry['code'] : 0,
					'error'     => ! empty( $entry['error'] ) ? $entry['error'] : ( self::token_missing() ? __( 'Token constant missing.', 'hzf-updater' ) : '' ),
				);
			}
		}
		return $rows;
	}

	/**
	 * Find a managed package by type and slug.
	 *
	 * @param string $type plugin|theme.
	 * @param string $slug Slug.
	 * @return array Row, or empty array.
	 */
	private static function find( $type, $slug ) {
		foreach ( self::packages() as $row ) {
			if ( $row['type'] === $type && $row['slug'] === $slug ) {
				return $row;
			}
		}
		return array();
	}

	/**
	 * "View details" for plugins.
	 *
	 * @param false|object|array $result Result.
	 * @param string             $action API action.
	 * @param object             $args   Request args.
	 * @return false|object|array
	 */
	public static function plugin_info( $result, $action, $args ) {
		$row = 'plugin_information' === $action && ! empty( $args->slug ) ? self::find( 'plugin', $args->slug ) : array();
		return $row ? self::info( $row ) : $result;
	}

	/**
	 * "View details" for themes.
	 *
	 * @param false|object|array $result Result.
	 * @param string             $action API action.
	 * @param object             $args   Request args.
	 * @return false|object|array
	 */
	public static function theme_info( $result, $action, $args ) {
		$row = 'theme_information' === $action && ! empty( $args->slug ) ? self::find( 'theme', $args->slug ) : array();
		return $row ? self::info( $row ) : $result;
	}

	/**
	 * Information object for the details modal.
	 *
	 * @param array $row Package row.
	 * @return object
	 */
	private static function info( $row ) {
		$rel   = self::release( $row['repo'] );
		$asset = $rel ? self::asset_id( $rel, $row['slug'] ) : 0;
		return (object) array(
			'name'          => $row['name'],
			'slug'          => $row['slug'],
			'version'       => $rel ? $rel['version'] : $row['installed'],
			'author'        => '',
			'homepage'      => 'https://github.com/' . $row['repo'],
			'last_updated'  => $rel ? $rel['date'] : '',
			'download_link' => $asset ? self::asset_url( $row['repo'], $asset ) : '',
			'sections'      => array( 'changelog' => $rel ? self::markdown( $rel['body'] ) : '' ),
		);
	}

	/**
	 * Tiny Markdown to HTML (headings, lists, emphasis, code, links), then kses.
	 *
	 * @param string $md Markdown.
	 * @return string
	 */
	private static function markdown( $md ) {
		$html = '';
		$mode = '';
		foreach ( explode( "\n", esc_html( str_replace( "\r", '', $md ) ) ) as $line ) {
			$line = trim( $line );
			$tag  = preg_match( '/^[-*+]\s+(.*)$/', $line, $m ) ? 'ul' : ( preg_match( '/^#{1,6}\s+(.*)$/', $line, $m ) ? 'h4' : ( '' === $line ? '' : 'p' ) );
			$text = isset( $m[1] ) && 'p' !== $tag ? $m[1] : $line;
			if ( 'ul' !== $tag && 'ul' === $mode ) {
				$html .= '</ul>';
			}
			if ( 'ul' === $tag && 'ul' !== $mode ) {
				$html .= '<ul>';
			}
			$mode  = $tag;
			$text  = preg_replace(
				array( '/`([^`]+)`/', '/\*\*(.+?)\*\*/', '/(?<![\w*])\*(?!\s)(.+?)\*(?!\w)/', '#\[([^\]]+)\]\((https?://[^\s)]+)\)#' ),
				array( '<code>$1</code>', '<strong>$1</strong>', '<em>$1</em>', '<a href="$2">$1</a>' ),
				$text
			);
			$html .= '' === $tag ? '' : ( 'ul' === $tag ? "<li>$text</li>" : "<$tag>$text</$tag>" );
		}
		return wp_kses_post( $html . ( 'ul' === $mode ? '</ul>' : '' ) );
	}
}
