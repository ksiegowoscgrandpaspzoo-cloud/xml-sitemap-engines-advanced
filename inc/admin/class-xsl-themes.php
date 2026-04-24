<?php
/**
 * Custom XSL colour themes.
 *
 * The free plugin ships a single branded stylesheet
 * (`assets/sitemap.xsl`) with CSS custom properties at the top
 * (`--primary`, `--primary-hover`, `--primary-soft`, etc., introduced
 * in Phase 33). This add-on lets users pick an alternative palette
 * (Orange default / Blue / Green / Dark / Custom) by intercepting
 * the `xmlse_stylesheet_url` filter and returning a PHP-served
 * endpoint that rewrites those CSS variable values.
 *
 * The endpoint is `?xmlse_adv_xsl=1`. It reads the free plugin's
 * raw XSL, replaces the palette block, sets the right XML
 * content-type, and exits. No file duplication needed.
 *
 * @package XMLSE_Advanced
 */

namespace XMLSE\Advanced\Admin;

defined( 'WPINC' ) || die;

/**
 * XSL theme picker + PHP-rewrite endpoint.
 *
 * @since 0.1.0
 */
final class XSL_Themes {

	/**
	 * Settings option.
	 */
	const OPTION = 'xmlse_adv_xsl_theme';

	/**
	 * Query var that triggers the theme-rewrite handler.
	 */
	const QUERY_VAR = 'xmlse_adv_xsl';

	/**
	 * Built-in palettes.
	 *
	 * @return array<string, array{label:string, palette:array<string,string>}>
	 */
	public static function themes() {
		return array(
			'default' => array(
				'label'   => __( 'Orange (default)', 'xml-sitemap-engines-advanced' ),
				'palette' => array(), // Signals: don't rewrite, use the stock XSL URL.
			),
			'blue'    => array(
				'label'   => __( 'Blue', 'xml-sitemap-engines-advanced' ),
				'palette' => array(
					'--primary'       => '#2563eb',
					'--primary-hover' => '#1d4ed8',
					'--primary-soft'  => '#eff6ff',
				),
			),
			'green'   => array(
				'label'   => __( 'Green', 'xml-sitemap-engines-advanced' ),
				'palette' => array(
					'--primary'       => '#15803d',
					'--primary-hover' => '#166534',
					'--primary-soft'  => '#f0fdf4',
				),
			),
			'dark'    => array(
				'label'   => __( 'Dark', 'xml-sitemap-engines-advanced' ),
				'palette' => array(
					'--primary'       => '#f97316',
					'--primary-hover' => '#ea580c',
					'--primary-soft'  => '#1f2937',
					'--text'          => '#e5e7eb',
					'--text-muted'    => '#9ca3af',
					'--border'        => '#374151',
					'--bg'            => '#111827',
					'--card-bg'       => '#1f2937',
				),
			),
		);
	}

	/**
	 * Register hooks.
	 *
	 * @since 0.1.0
	 */
	public static function register_hooks() {
		add_filter( 'xmlse_stylesheet_url', array( __CLASS__, 'filter_stylesheet_url' ) );
		add_action( 'init', array( __CLASS__, 'maybe_serve' ), 0 );
	}

	/**
	 * Override the stylesheet URL when a non-default theme is active.
	 *
	 * @since 0.1.0
	 *
	 * @param string $url Default URL (free plugin).
	 * @return string
	 */
	public static function filter_stylesheet_url( $url ) {
		$theme = (string) get_option( self::OPTION, 'default' );
		if ( 'default' === $theme || ! isset( self::themes()[ $theme ] ) ) {
			return $url;
		}
		return add_query_arg(
			array( self::QUERY_VAR => $theme ),
			home_url( '/' )
		);
	}

	/**
	 * Serve the rewritten XSL when our query var is present.
	 *
	 * Runs on `init` priority 0 so we short-circuit before WordPress
	 * tries to match a post permalink.
	 *
	 * @since 0.1.0
	 */
	public static function maybe_serve() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Public read-only endpoint that returns XSL bytes.
		if ( empty( $_GET[ self::QUERY_VAR ] ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$theme = sanitize_key( wp_unslash( $_GET[ self::QUERY_VAR ] ) );
		$map   = self::themes();
		if ( ! isset( $map[ $theme ] ) || empty( $map[ $theme ]['palette'] ) ) {
			return; // Default theme or unknown — let WP serve the real asset.
		}

		$source = self::resolve_source_xsl();
		if ( '' === $source || ! is_readable( $source ) ) {
			return;
		}

		$body = (string) file_get_contents( $source ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading a local plugin asset, WP_Filesystem is overkill.
		if ( '' === $body ) {
			return;
		}

		$palette = $map[ $theme ]['palette'];
		foreach ( $palette as $var => $value ) {
			$pattern = '/(' . preg_quote( $var, '/' ) . '\s*:\s*)[^;]+(\s*;)/';
			$body    = preg_replace( $pattern, '${1}' . $value . '${2}', $body );
		}

		header( 'Content-Type: application/xslt+xml; charset=UTF-8' );
		header( 'X-Robots-Tag: noindex, follow' );
		echo $body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Raw XSL bytes.
		exit;
	}

	/**
	 * Resolve the source XSL path. Currently: the free plugin's
	 * assets dir. Filterable for forks.
	 *
	 * @since 0.1.0
	 *
	 * @return string Absolute filesystem path, or '' if unresolvable.
	 */
	public static function resolve_source_xsl() {
		$default = '';
		if ( defined( 'XMLSE_DIR' ) ) {
			$default = rtrim( (string) XMLSE_DIR, '/' ) . '/assets/sitemap.xsl';
		}
		return (string) apply_filters( 'xmlse_adv_source_xsl_path', $default );
	}
}
