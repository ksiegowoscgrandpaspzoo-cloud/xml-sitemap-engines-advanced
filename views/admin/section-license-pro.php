<?php
/**
 * License activation form — rendered into the free plugin's License tab via
 * `do_action( 'xmlse_add_settings', 'license' )` (see
 * `views/admin/section-license.php` in the free repo).
 *
 * Three states are possible:
 *
 *   1. Never activated → key input + Activate button.
 *   2. Activated + valid → masked key + Deactivate + Check now buttons.
 *   3. Activated + expired/invalid → same as (2) plus a warning notice.
 *
 * The form intentionally uses GET-link buttons for Deactivate / Check now to
 * avoid the nested-`<form>` bug fixed in the GSC tab (see CHANGELOG entry of
 * commit `1e295cc`). The Activate flow is the exception — it must POST the
 * license key, not put it in a GET URL where it could leak to access logs /
 * Referer headers / browser history.
 *
 * @package XMLSE_Advanced
 */

defined( 'WPINC' ) || die;

if ( ! current_user_can( 'manage_options' ) ) {
	return;
}

use XMLSE\Advanced\License;

$xmlse_state    = License::status();
$xmlse_is_valid = License::is_active();
$xmlse_has_key  = '' !== $xmlse_state['key'];
$xmlse_status   = $xmlse_state['status'];

$xmlse_status_label = '';
$xmlse_status_color = '';
switch ( $xmlse_status ) {
	case License::STATUS_VALID:
		$xmlse_status_label = $xmlse_is_valid
			? __( 'Active', 'xml-sitemap-engines-advanced' )
			: __( 'Stale', 'xml-sitemap-engines-advanced' );
		$xmlse_status_color = $xmlse_is_valid ? '#15803d' : '#b45309';
		break;
	case License::STATUS_EXPIRED:
		$xmlse_status_label = __( 'Expired', 'xml-sitemap-engines-advanced' );
		$xmlse_status_color = '#b45309';
		break;
	case License::STATUS_INVALID:
		$xmlse_status_label = __( 'Invalid', 'xml-sitemap-engines-advanced' );
		$xmlse_status_color = '#b91c1c';
		break;
	case License::STATUS_NEVER_ACTIVATED:
	default:
		$xmlse_status_label = __( 'Inactive', 'xml-sitemap-engines-advanced' );
		$xmlse_status_color = '#b91c1c';
		break;
}
?>

<div class="xmlse-section">
	<div class="xmlse-section__head">
		<h3 class="xmlse-section__title">
			<?php esc_html_e( 'License key', 'xml-sitemap-engines-advanced' ); ?>
		</h3>
		<p class="xmlse-section__description">
			<?php esc_html_e( 'Activate your license to unlock premium features (Search Console integration, multi-engine connectors, bulk-edit, custom XSL themes) and receive automatic plugin updates.', 'xml-sitemap-engines-advanced' ); ?>
		</p>
	</div>

	<?php if ( $xmlse_has_key ) : ?>
		<div class="xmlse-section__row">
			<div class="xmlse-section__row-label">
				<?php esc_html_e( 'Key', 'xml-sitemap-engines-advanced' ); ?>
			</div>
			<div class="xmlse-section__row-control">
				<code><?php echo esc_html( License::mask_key( $xmlse_state['key'] ) ); ?></code>
			</div>
		</div>

		<div class="xmlse-section__row">
			<div class="xmlse-section__row-label">
				<?php esc_html_e( 'Status', 'xml-sitemap-engines-advanced' ); ?>
			</div>
			<div class="xmlse-section__row-control">
				<span style="color: <?php echo esc_attr( $xmlse_status_color ); ?>; font-weight: 600;">
					<?php echo esc_html( $xmlse_status_label ); ?>
				</span>
			</div>
		</div>

		<?php if ( '' !== $xmlse_state['expires'] ) : ?>
			<div class="xmlse-section__row">
				<div class="xmlse-section__row-label">
					<?php esc_html_e( 'Expires', 'xml-sitemap-engines-advanced' ); ?>
				</div>
				<div class="xmlse-section__row-control">
					<?php echo esc_html( $xmlse_state['expires'] ); ?>
				</div>
			</div>
		<?php endif; ?>

		<?php if ( '' !== $xmlse_state['customer_email'] ) : ?>
			<div class="xmlse-section__row">
				<div class="xmlse-section__row-label">
					<?php esc_html_e( 'Customer', 'xml-sitemap-engines-advanced' ); ?>
				</div>
				<div class="xmlse-section__row-control">
					<?php echo esc_html( $xmlse_state['customer_email'] ); ?>
				</div>
			</div>
		<?php endif; ?>

		<div class="xmlse-section__row">
			<div class="xmlse-section__row-label">
				<?php esc_html_e( 'Last check', 'xml-sitemap-engines-advanced' ); ?>
			</div>
			<div class="xmlse-section__row-control">
				<?php
				if ( 0 === (int) $xmlse_state['last_check'] ) {
					esc_html_e( 'Never', 'xml-sitemap-engines-advanced' );
				} else {
					echo esc_html(
						sprintf(
							/* translators: %s: human-readable time diff e.g. "2 hours" */
							__( '%s ago', 'xml-sitemap-engines-advanced' ),
							human_time_diff( (int) $xmlse_state['last_check'], time() )
						)
					);
				}
				?>
			</div>
		</div>

		<div class="xmlse-section__row">
			<div class="xmlse-section__row-label">
				<?php esc_html_e( 'Actions', 'xml-sitemap-engines-advanced' ); ?>
			</div>
			<div class="xmlse-section__row-control">
				<?php
				$xmlse_check_url = wp_nonce_url(
					add_query_arg( 'action', 'xmlse_pro_check_license', admin_url( 'admin-post.php' ) ),
					License::NONCE_CHECK
				);
				$xmlse_deact_url = wp_nonce_url(
					add_query_arg( 'action', 'xmlse_pro_deactivate_license', admin_url( 'admin-post.php' ) ),
					License::NONCE_DEACTIVATE
				);
				?>
				<a href="<?php echo esc_url( $xmlse_check_url ); ?>" class="button">
					<?php esc_html_e( 'Check now', 'xml-sitemap-engines-advanced' ); ?>
				</a>
				<a href="<?php echo esc_url( $xmlse_deact_url ); ?>" class="button" style="margin-left:8px;">
					<?php esc_html_e( 'Deactivate', 'xml-sitemap-engines-advanced' ); ?>
				</a>
			</div>
		</div>
	<?php else : ?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="xmlse_pro_activate_license" />
			<?php wp_nonce_field( License::NONCE_ACTIVATE ); ?>

			<div class="xmlse-section__row">
				<div class="xmlse-section__row-label">
					<label for="xmlse_pro_license_key">
						<?php esc_html_e( 'License key', 'xml-sitemap-engines-advanced' ); ?>
					</label>
				</div>
				<div class="xmlse-section__row-control">
					<input type="text"
						id="xmlse_pro_license_key"
						name="license_key"
						value=""
						class="regular-text"
						autocomplete="off" />
					<p class="description">
						<?php esc_html_e( 'Paste the license key you received with your purchase.', 'xml-sitemap-engines-advanced' ); ?>
					</p>
				</div>
			</div>

			<div class="xmlse-section__row">
				<div class="xmlse-section__row-label">&nbsp;</div>
				<div class="xmlse-section__row-control">
					<button type="submit" class="button button-primary">
						<?php esc_html_e( 'Activate license', 'xml-sitemap-engines-advanced' ); ?>
					</button>
				</div>
			</div>
		</form>
	<?php endif; ?>
</div>
