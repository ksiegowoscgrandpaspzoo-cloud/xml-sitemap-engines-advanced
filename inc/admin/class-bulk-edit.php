<?php
/**
 * Bulk-edit exclude control for the post-list screen.
 *
 * Adds a small `Sitemap` field to the Bulk Edit drawer (Posts / Pages /
 * every enabled post type). Three values:
 *
 *   - `— No change —` (default; preserves the current state per post).
 *   - `Exclude from sitemap` (sets `_xmlse_exclude = '1'`).
 *   - `Include in sitemap` (deletes `_xmlse_exclude` meta).
 *
 * The per-post `Quick Edit` checkbox shipped by the free plugin
 * (Phase 9.3) covers single posts; this is its bulk sibling.
 *
 * @package XMLSE_Advanced
 */

namespace XMLSE\Advanced\Admin;

defined( 'WPINC' ) || die;

/**
 * Bulk-edit exclude control.
 *
 * @since 0.1.0
 */
final class Bulk_Edit {

	/**
	 * Register hooks.
	 *
	 * @since 0.1.0
	 */
	public static function register_hooks() {
		add_action( 'bulk_edit_custom_box', array( __CLASS__, 'render_field' ), 10, 2 );
		add_action( 'save_post', array( __CLASS__, 'save_bulk' ), 10, 1 );
	}

	/**
	 * Render the bulk-edit field. Fires once per column; we only emit
	 * markup when the column we care about hits.
	 *
	 * @since 0.1.0
	 *
	 * @param string $column_name Current column.
	 * @param string $post_type   Current post type.
	 */
	public static function render_field( $column_name, $post_type ) {
		// We piggy-back on the 'xmlse_exclude' column that Phase 9.3
		// Quick_Edit added. If it's not present, fall back to the 'title'
		// column so our field still shows.
		if ( 'xmlse_exclude' !== $column_name && 'title' !== $column_name ) {
			return;
		}
		if ( 'xmlse_exclude' !== $column_name ) {
			// Render exactly once per row (when the title column fires),
			// only if the plugin's own exclude column didn't already
			// handle it.
			$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
			if ( ! $screen || 'edit' !== $screen->base ) {
				return;
			}
			// Skip title column unless the post type isn't one of ours —
			// avoids double render when xmlse_exclude column exists.
			if ( self::is_xmlse_post_type( $post_type ) ) {
				return;
			}
		}

		?>
		<fieldset class="inline-edit-col-right">
			<div class="inline-edit-col">
				<label class="inline-edit-group" style="display:block;margin-top:8px;">
					<span class="title" style="width:auto;display:inline-block;margin-right:6px;">
						<?php esc_html_e( 'XML Sitemap', 'xml-sitemap-engines-advanced' ); ?>
					</span>
					<select name="xmlse_adv_bulk_exclude">
						<option value=""><?php esc_html_e( '— No change —', 'xml-sitemap-engines-advanced' ); ?></option>
						<option value="exclude"><?php esc_html_e( 'Exclude from sitemap', 'xml-sitemap-engines-advanced' ); ?></option>
						<option value="include"><?php esc_html_e( 'Include in sitemap', 'xml-sitemap-engines-advanced' ); ?></option>
					</select>
				</label>
			</div>
		</fieldset>
		<?php

		wp_nonce_field( 'xmlse_adv_bulk_exclude', 'xmlse_adv_bulk_exclude_nonce' );
	}

	/**
	 * Persist the bulk-edit choice.
	 *
	 * save_post fires many times; we only act when the `bulk_edit`
	 * action param is present AND our field is set.
	 *
	 * @since 0.1.0
	 *
	 * @param int $post_id Post being saved.
	 */
	public static function save_bulk( $post_id ) {
		// Autosave / revision guard.
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( wp_is_post_revision( $post_id ) ) {
			return;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- nonce verified below
		if ( ! isset( $_POST['xmlse_adv_bulk_exclude_nonce'] ) ) {
			return;
		}
		if ( ! wp_verify_nonce(
			sanitize_key( wp_unslash( $_POST['xmlse_adv_bulk_exclude_nonce'] ) ),
			'xmlse_adv_bulk_exclude'
		) ) {
			return;
		}
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above
		$action = isset( $_POST['xmlse_adv_bulk_exclude'] )
			? sanitize_key( wp_unslash( $_POST['xmlse_adv_bulk_exclude'] ) )
			: '';

		if ( 'exclude' === $action ) {
			update_post_meta( $post_id, '_xmlse_exclude', '1' );
		} elseif ( 'include' === $action ) {
			delete_post_meta( $post_id, '_xmlse_exclude' );
		}
	}

	/**
	 * Whether the post type is in the plugin's sitemap whitelist.
	 *
	 * @since 0.1.0
	 *
	 * @param string $post_type Post type slug.
	 * @return bool
	 */
	private static function is_xmlse_post_type( $post_type ) {
		$configured = get_option( 'xmlse_post_types', array() );
		if ( ! is_array( $configured ) || empty( $configured ) ) {
			return is_post_type_viewable( $post_type );
		}
		return in_array( $post_type, $configured, true );
	}
}
