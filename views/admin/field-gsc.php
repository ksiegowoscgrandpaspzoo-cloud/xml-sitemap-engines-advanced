<?php
/**
 * Search Console tab body — three-stage BYO OAuth wizard + per-sitemap
 * submit buttons + submission log.
 *
 * Always rendered inside `Premium_Lock::open/close` — free-tier installs see
 * the disabled placeholder with an upsell; installs with the News Advanced
 * add-on (which flips `xmlse_advanced_enabled`) see the full working form.
 *
 * @package XMLSE
 */

defined( 'WPINC' ) || die;

use XMLSE\Admin\Premium_Lock;
use XMLSE\Advanced\Admin\GSC_Integration;

$xmlse_cfg          = GSC_Integration::get_config();
$xmlse_redirect_uri = GSC_Integration::redirect_uri();
$xmlse_connected    = GSC_Integration::is_connected();
$xmlse_configured   = GSC_Integration::is_configured();
$xmlse_log          = GSC_Integration::get_log();
$xmlse_enabled      = GSC_Integration::is_enabled();

// Enumerate which sitemap URLs exist on this install so the Submit
// buttons have real targets.
$xmlse_sitemaps    = (array) get_option( 'xmlse_sitemaps', array() );
$xmlse_submit_urls = array();
if ( ! empty( $xmlse_sitemaps['sitemap'] ) ) {
	$xmlse_submit_urls[] = array(
		'label' => __( 'Main sitemap index', 'xml-sitemap-engines' ),
		'url'   => home_url( '/wp-sitemap.xml' ),
	);
}
if ( ! empty( $xmlse_sitemaps['sitemap-news'] ) ) {
	$xmlse_submit_urls[] = array(
		'label' => __( 'Google News sitemap', 'xml-sitemap-engines' ),
		'url'   => home_url( '/sitemap-news.xml' ),
	);
}
$xmlse_image = (array) get_option( 'xmlse_image_settings', array() );
if ( ! empty( $xmlse_image['enabled'] ) ) {
	$xmlse_submit_urls[] = array(
		'label' => __( 'Image sitemap', 'xml-sitemap-engines' ),
		'url'   => home_url( '/' . ( ! empty( $xmlse_image['slug'] ) ? $xmlse_image['slug'] : 'sitemap-image' ) . '.xml' ),
	);
}
$xmlse_video = (array) get_option( 'xmlse_video_settings', array() );
if ( ! empty( $xmlse_video['enabled'] ) ) {
	$xmlse_submit_urls[] = array(
		'label' => __( 'Video sitemap', 'xml-sitemap-engines' ),
		'url'   => home_url( '/' . ( ! empty( $xmlse_video['slug'] ) ? $xmlse_video['slug'] : 'sitemap-video' ) . '.xml' ),
	);
}

Premium_Lock::open(
	__( 'Connect your site to Google Search Console', 'xml-sitemap-engines' ),
	__( 'Three-stage BYO OAuth: create a Google Cloud project, obtain OAuth credentials, authorise the connection. Tokens are stored in a non-autoloaded option so they do not bloat WordPress\' alloptions cache.', 'xml-sitemap-engines' )
);
?>

<div class="xmlse-section__row">
	<div class="xmlse-section__row-label"><?php esc_html_e( 'Prerequisites', 'xml-sitemap-engines' ); ?></div>
	<div class="xmlse-section__row-control">
		<ul style="margin:0 0 0 18px;list-style:disc;">
			<li>
				<?php
				echo wp_kses(
					__( 'Your site property must already be set up in <a href="https://search.google.com/search-console" target="_blank" rel="noopener">Google Search Console</a>. If you have not yet verified ownership, follow <a href="https://support.google.com/webmasters/answer/34592" target="_blank" rel="noopener">Google\'s instructions</a> first.', 'xml-sitemap-engines' ),
					array(
						'a' => array(
							'href'   => array(),
							'target' => array(),
							'rel'    => array(),
						),
					)
				);
				?>
			</li>
			<li>
				<?php
				echo wp_kses(
					__( 'You need a Google Cloud account. If you do not already have one, <a href="https://console.cloud.google.com/" target="_blank" rel="noopener">sign up</a>.', 'xml-sitemap-engines' ),
					array(
						'a' => array(
							'href'   => array(),
							'target' => array(),
							'rel'    => array(),
						),
					)
				);
				?>
			</li>
		</ul>
	</div>
</div>

<!-- Stage I -->
<div class="xmlse-section__row">
	<div class="xmlse-section__row-label"><?php esc_html_e( 'Stage I · Create a project', 'xml-sitemap-engines' ); ?></div>
	<div class="xmlse-section__row-control">
		<ol style="margin:0 0 0 18px;list-style:decimal;">
			<li><?php esc_html_e( 'Open the Google Cloud Console and either create a new project or pick an existing one.', 'xml-sitemap-engines' ); ?></li>
			<li><?php esc_html_e( 'Go to APIs & Services → OAuth consent screen. Click Get started, give the app a recognisable name, pick a support email, and hit Next.', 'xml-sitemap-engines' ); ?></li>
			<li><?php esc_html_e( 'At Audience choose Internal if available (Google Workspace users). Otherwise choose External and Publish the app on the Audience page.', 'xml-sitemap-engines' ); ?></li>
			<li>
				<?php
				echo wp_kses(
					__( 'Navigate to APIs & Services → Library. Search for <strong>Google Search Console API</strong> and enable it for your project.', 'xml-sitemap-engines' ),
					array( 'strong' => array() )
				);
				?>
			</li>
		</ol>
	</div>
</div>

<!-- Stage II -->
<div class="xmlse-section__row">
	<div class="xmlse-section__row-label"><?php esc_html_e( 'Stage II · Obtain credentials', 'xml-sitemap-engines' ); ?></div>
	<div class="xmlse-section__row-control">
		<ol style="margin:0 0 8px 18px;list-style:decimal;">
			<li><?php esc_html_e( 'Go to APIs & Services → Credentials → + Create credentials → OAuth client ID.', 'xml-sitemap-engines' ); ?></li>
			<li><?php esc_html_e( 'Choose Web application as the application type.', 'xml-sitemap-engines' ); ?></li>
			<li>
				<?php esc_html_e( 'In the Authorised redirect URIs field, paste this exact URI:', 'xml-sitemap-engines' ); ?>
				<br/>
				<code style="display:inline-block;padding:4px 8px;background:#f0f0f1;border-radius:4px;margin-top:4px;"><?php echo esc_html( $xmlse_redirect_uri ); ?></code>
			</li>
			<li><?php esc_html_e( 'Click Create. Copy the Client ID and Client Secret that appear, and paste them into the fields below.', 'xml-sitemap-engines' ); ?></li>
		</ol>
		<p class="description">
			<?php esc_html_e( 'Important: the Redirect URI must match exactly. Any trailing slash or http/https mismatch will make Google reject the connection.', 'xml-sitemap-engines' ); ?>
		</p>
	</div>
</div>

<div class="xmlse-section__row">
	<div class="xmlse-section__row-label">
		<label for="xmlse_gsc_client_id"><?php esc_html_e( 'Client ID', 'xml-sitemap-engines' ); ?></label>
	</div>
	<div class="xmlse-section__row-control">
		<input type="text"
			id="xmlse_gsc_client_id"
			name="xmlse_gsc_config[client_id]"
			value="<?php echo esc_attr( $xmlse_cfg['client_id'] ); ?>"
			class="regular-text"
			autocomplete="off" />
		<p class="description">
			<?php esc_html_e( 'From Google Cloud Console → APIs & Services → Credentials.', 'xml-sitemap-engines' ); ?>
		</p>
	</div>
</div>

<div class="xmlse-section__row">
	<div class="xmlse-section__row-label">
		<label for="xmlse_gsc_client_secret"><?php esc_html_e( 'Client Secret', 'xml-sitemap-engines' ); ?></label>
	</div>
	<div class="xmlse-section__row-control">
		<input type="password"
			id="xmlse_gsc_client_secret"
			name="xmlse_gsc_config[client_secret]"
			value="<?php echo esc_attr( $xmlse_cfg['client_secret'] ); ?>"
			class="regular-text"
			autocomplete="off" />
		<p class="description">
			<?php esc_html_e( 'Keep this confidential. If you lose it, create a new one in the Google Cloud Console.', 'xml-sitemap-engines' ); ?>
		</p>
	</div>
</div>

<div class="xmlse-section__row">
	<div class="xmlse-section__row-label">
		<label for="xmlse_gsc_site_url"><?php esc_html_e( 'Search Console property', 'xml-sitemap-engines' ); ?></label>
	</div>
	<div class="xmlse-section__row-control">
		<input type="text"
			id="xmlse_gsc_site_url"
			name="xmlse_gsc_config[site_url]"
			value="<?php echo esc_attr( trailingslashit( (string) home_url() ) ); ?>"
			class="regular-text"
			readonly />
		<p class="description">
			<?php esc_html_e( 'Auto-filled from your WordPress install. URL-prefix property assumed; if your Search Console property uses the domain (sc-domain:) format instead, override via the xmlse_gsc_config option through code/WP-CLI.', 'xml-sitemap-engines' ); ?>
		</p>
	</div>
</div>

<!-- Stage III -->
<div class="xmlse-section__row">
	<div class="xmlse-section__row-label"><?php esc_html_e( 'Stage III · Authorise', 'xml-sitemap-engines' ); ?></div>
	<div class="xmlse-section__row-control">
		<?php if ( $xmlse_connected ) : ?>
			<p>
				<strong style="color:#15803d;">
					<?php esc_html_e( '✓ Connected to Google Search Console.', 'xml-sitemap-engines' ); ?>
				</strong>
			</p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;">
				<?php wp_nonce_field( 'xmlse_gsc_disconnect' ); ?>
				<input type="hidden" name="action" value="xmlse_gsc_disconnect" />
				<button type="submit" class="button">
					<?php esc_html_e( 'Disconnect', 'xml-sitemap-engines' ); ?>
				</button>
			</form>
		<?php elseif ( $xmlse_configured ) : ?>
			<p class="description">
				<?php esc_html_e( 'Save changes first, then click Connect. You will be redirected to Google to grant permission, then back here.', 'xml-sitemap-engines' ); ?>
			</p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;">
				<?php wp_nonce_field( 'xmlse_gsc_oauth_start' ); ?>
				<input type="hidden" name="action" value="xmlse_gsc_oauth_start" />
				<button type="submit" class="button button-primary">
					<?php esc_html_e( 'Connect to Google Search Console', 'xml-sitemap-engines' ); ?>
				</button>
			</form>
		<?php else : ?>
			<p class="description" style="color:#b45309;">
				<?php esc_html_e( 'Fill in Client ID, Client Secret, and Search Console property above, save changes, then return here to authorise.', 'xml-sitemap-engines' ); ?>
			</p>
		<?php endif; ?>
	</div>
</div>

<?php Premium_Lock::close(); ?>

<?php if ( $xmlse_connected && ! empty( $xmlse_submit_urls ) ) : ?>
	<div class="xmlse-section">
		<div class="xmlse-section__head">
			<h3 class="xmlse-section__title"><?php esc_html_e( 'Submit sitemaps', 'xml-sitemap-engines' ); ?></h3>
			<p class="xmlse-section__description">
				<?php esc_html_e( 'One-click submission per enabled sitemap. Google re-reads the sitemap on its own schedule after the first submission; use this mainly when launching a new sitemap URL.', 'xml-sitemap-engines' ); ?>
			</p>
		</div>
		<?php foreach ( $xmlse_submit_urls as $xmlse_sm ) : ?>
			<div class="xmlse-section__row">
				<div class="xmlse-section__row-label">
					<?php echo esc_html( (string) $xmlse_sm['label'] ); ?>
				</div>
				<div class="xmlse-section__row-control">
					<a href="<?php echo esc_url( (string) $xmlse_sm['url'] ); ?>" target="_blank" rel="noopener">
						<code><?php echo esc_html( (string) $xmlse_sm['url'] ); ?></code>
					</a>
					<form method="post"
						action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
						style="display:inline-block;margin-left:8px;">
						<?php wp_nonce_field( 'xmlse_gsc_submit' ); ?>
						<input type="hidden" name="action" value="xmlse_gsc_submit" />
						<input type="hidden" name="sitemap_url" value="<?php echo esc_attr( (string) $xmlse_sm['url'] ); ?>" />
						<button type="submit" class="button">
							<?php esc_html_e( 'Submit now', 'xml-sitemap-engines' ); ?>
						</button>
					</form>
				</div>
			</div>
		<?php endforeach; ?>
	</div>
<?php endif; ?>

<?php if ( ! empty( $xmlse_log ) ) : ?>
	<div class="xmlse-section">
		<div class="xmlse-section__head">
			<h3 class="xmlse-section__title"><?php esc_html_e( 'Submission log', 'xml-sitemap-engines' ); ?></h3>
			<p class="xmlse-section__description">
				<?php
				printf(
					/* translators: %d: log buffer size */
					esc_html__( 'Last %d submission attempts. Older entries drop off automatically.', 'xml-sitemap-engines' ),
					(int) GSC_Integration::LOG_MAX
				);
				?>
			</p>
		</div>
		<div class="xmlse-section__row">
			<div class="xmlse-section__row-control" style="width:100%;">
				<table class="widefat striped">
					<thead>
						<tr>
							<th style="width:25%;"><?php esc_html_e( 'When', 'xml-sitemap-engines' ); ?></th>
							<th style="width:45%;"><?php esc_html_e( 'Sitemap URL', 'xml-sitemap-engines' ); ?></th>
							<th style="width:10%;"><?php esc_html_e( 'HTTP', 'xml-sitemap-engines' ); ?></th>
							<th style="width:20%;"><?php esc_html_e( 'Message', 'xml-sitemap-engines' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $xmlse_log as $xmlse_row ) : ?>
							<tr>
								<td>
									<?php
									echo esc_html(
										sprintf(
											/* translators: %s: human-readable time diff */
											__( '%s ago', 'xml-sitemap-engines' ),
											human_time_diff( (int) $xmlse_row['time'], time() )
										)
									);
									?>
								</td>
								<td><code><?php echo esc_html( (string) $xmlse_row['url'] ); ?></code></td>
								<td>
									<?php
									$xmlse_status = (int) $xmlse_row['status'];
									$xmlse_colour = ( $xmlse_status >= 200 && $xmlse_status < 300 ) ? '#15803d' : '#b91c1c';
									?>
									<span style="color:<?php echo esc_attr( $xmlse_colour ); ?>;font-variant-numeric:tabular-nums;">
										<?php echo 0 === $xmlse_status ? '—' : esc_html( (string) $xmlse_status ); ?>
									</span>
								</td>
								<td><?php echo esc_html( mb_substr( (string) $xmlse_row['message'], 0, 120 ) ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		</div>
	</div>
<?php endif; ?>
