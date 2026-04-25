<?php
/**
 * Engines tab body — one card per connector (Bing, Yandex, Baidu).
 *
 * Google's card is not here — it lives on the dedicated Search Console
 * tab with the full OAuth wizard. Everything else is compact enough
 * to coexist on one page.
 *
 * @package XMLSE_Advanced
 */

defined( 'WPINC' ) || die;

use XMLSE\Advanced\Connectors\Baidu;
use XMLSE\Advanced\Connectors\Bing;
use XMLSE\Advanced\Connectors\Yandex;

$xmlse_adv_sitemaps = (array) get_option( 'xmlse_sitemaps', array() );
$xmlse_adv_submit_urls = array();
if ( ! empty( $xmlse_adv_sitemaps['sitemap'] ) ) {
	$xmlse_adv_submit_urls[] = array(
		'label' => __( 'Main sitemap', 'xml-sitemap-engines-advanced' ),
		'url'   => home_url( '/wp-sitemap.xml' ),
	);
}
if ( ! empty( $xmlse_adv_sitemaps['sitemap-news'] ) ) {
	$xmlse_adv_submit_urls[] = array(
		'label' => __( 'News sitemap', 'xml-sitemap-engines-advanced' ),
		'url'   => home_url( '/sitemap-news.xml' ),
	);
}

$xmlse_adv_bing_cfg   = Bing::get_config();
$xmlse_adv_yandex_cfg = Yandex::get_config();
$xmlse_adv_baidu_cfg  = Baidu::get_config();
?>

<!-- ============= Bing ============= -->
<div class="xmlse-section">
	<div class="xmlse-section__head">
		<h3 class="xmlse-section__title">
			<?php esc_html_e( 'Bing Webmaster', 'xml-sitemap-engines-advanced' ); ?>
		</h3>
		<p class="xmlse-section__description">
			<?php esc_html_e( 'Bing consolidated URL push around IndexNow (already wired in the free plugin). This connector adds optional Bing Webmaster API credentials for status lookups via GetUrlInfo. Submit-now delegates to IndexNow.', 'xml-sitemap-engines-advanced' ); ?>
		</p>
	</div>
	<div class="xmlse-section__row">
		<div class="xmlse-section__row-label">
			<label for="xmlse_adv_bing_api_key"><?php esc_html_e( 'API key', 'xml-sitemap-engines-advanced' ); ?></label>
		</div>
		<div class="xmlse-section__row-control">
			<input type="password"
				id="xmlse_adv_bing_api_key"
				name="<?php echo esc_attr( Bing::CONFIG_OPTION ); ?>[api_key]"
				value="<?php echo esc_attr( $xmlse_adv_bing_cfg['api_key'] ); ?>"
				class="regular-text"
				autocomplete="off" />
			<p class="description">
				<?php
				echo wp_kses(
					__( 'Create at <a href="https://www.bing.com/webmasters/apisettings" target="_blank" rel="noopener">Bing Webmaster → API access</a>. Alphanumeric string.', 'xml-sitemap-engines-advanced' ),
					array(
						'a' => array(
							'href'   => array(),
							'target' => array(),
							'rel'    => array(),
						),
					)
				);
				?>
			</p>
		</div>
	</div>
	<div class="xmlse-section__row">
		<div class="xmlse-section__row-label">
			<label for="xmlse_adv_bing_site_url"><?php esc_html_e( 'Verified site URL', 'xml-sitemap-engines-advanced' ); ?></label>
		</div>
		<div class="xmlse-section__row-control">
			<input type="url"
				id="xmlse_adv_bing_site_url"
				name="<?php echo esc_attr( Bing::CONFIG_OPTION ); ?>[site_url]"
				value="<?php echo esc_attr( trailingslashit( (string) home_url() ) ); ?>"
				class="regular-text"
				readonly />
			<p class="description">
				<?php esc_html_e( 'Auto-filled from your WordPress install. The plugin only pushes to search-engine properties registered for this exact host.', 'xml-sitemap-engines-advanced' ); ?>
			</p>
		</div>
	</div>
</div>

<!-- ============= Yandex ============= -->
<div class="xmlse-section">
	<div class="xmlse-section__head">
		<h3 class="xmlse-section__title">
			<?php esc_html_e( 'Yandex Webmaster', 'xml-sitemap-engines-advanced' ); ?>
		</h3>
		<p class="xmlse-section__description">
			<?php esc_html_e( 'BYO OAuth — create a Yandex OAuth application at oauth.yandex.com, paste Client ID + Client Secret here, authorise the connection.', 'xml-sitemap-engines-advanced' ); ?>
		</p>
	</div>
	<div class="xmlse-section__row">
		<div class="xmlse-section__row-label">
			<label for="xmlse_adv_yandex_client_id"><?php esc_html_e( 'Client ID', 'xml-sitemap-engines-advanced' ); ?></label>
		</div>
		<div class="xmlse-section__row-control">
			<input type="text"
				id="xmlse_adv_yandex_client_id"
				name="<?php echo esc_attr( Yandex::CONFIG_OPTION ); ?>[client_id]"
				value="<?php echo esc_attr( $xmlse_adv_yandex_cfg['client_id'] ); ?>"
				class="regular-text"
				autocomplete="off" />
		</div>
	</div>
	<div class="xmlse-section__row">
		<div class="xmlse-section__row-label">
			<label for="xmlse_adv_yandex_client_secret"><?php esc_html_e( 'Client Secret', 'xml-sitemap-engines-advanced' ); ?></label>
		</div>
		<div class="xmlse-section__row-control">
			<input type="password"
				id="xmlse_adv_yandex_client_secret"
				name="<?php echo esc_attr( Yandex::CONFIG_OPTION ); ?>[client_secret]"
				value="<?php echo esc_attr( $xmlse_adv_yandex_cfg['client_secret'] ); ?>"
				class="regular-text"
				autocomplete="off" />
		</div>
	</div>
	<div class="xmlse-section__row">
		<div class="xmlse-section__row-label">
			<label for="xmlse_adv_yandex_site_url"><?php esc_html_e( 'Verified site URL', 'xml-sitemap-engines-advanced' ); ?></label>
		</div>
		<div class="xmlse-section__row-control">
			<input type="url"
				id="xmlse_adv_yandex_site_url"
				name="<?php echo esc_attr( Yandex::CONFIG_OPTION ); ?>[site_url]"
				value="<?php echo esc_attr( trailingslashit( (string) home_url() ) ); ?>"
				class="regular-text"
				readonly />
			<p class="description">
				<?php
				/* translators: %s: current site host */
				printf(
					esc_html__( 'Must share this site\'s host (%s). Foreign URLs are cleared on save.', 'xml-sitemap-engines-advanced' ),
					'<code>' . esc_html( (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST ) ) . '</code>'
				);
				?>
			</p>
		</div>
	</div>
	<div class="xmlse-section__row">
		<div class="xmlse-section__row-label"><?php esc_html_e( 'Redirect URI (paste into Yandex OAuth app)', 'xml-sitemap-engines-advanced' ); ?></div>
		<div class="xmlse-section__row-control">
			<code style="display:inline-block;padding:4px 8px;background:#f0f0f1;border-radius:4px;"><?php echo esc_html( Yandex::redirect_uri() ); ?></code>
		</div>
	</div>
	<div class="xmlse-section__row">
		<div class="xmlse-section__row-label"><?php esc_html_e( 'Connection', 'xml-sitemap-engines-advanced' ); ?></div>
		<div class="xmlse-section__row-control">
			<?php
			// GET-link buttons (not nested <form>) — this view is included from the
			// xmlse_search_console settings field callback, so its DOM lives INSIDE
			// the outer <form action="options.php">. HTML5 disallows nested forms;
			// the browser would close the parent form at our inner </form>, orphaning
			// the parent "Save changes" submit and breaking the whole tab.
			$xmlse_yandex_disconnect_url = wp_nonce_url(
				add_query_arg( 'action', 'xmlse_adv_yandex_disconnect', admin_url( 'admin-post.php' ) ),
				'xmlse_adv_yandex_disconnect'
			);
			$xmlse_yandex_oauth_url = wp_nonce_url(
				add_query_arg( 'action', 'xmlse_adv_yandex_oauth_start', admin_url( 'admin-post.php' ) ),
				'xmlse_adv_yandex_oauth_start'
			);
			?>
			<?php if ( Yandex::is_connected() ) : ?>
				<strong style="color:#15803d;">✓ <?php esc_html_e( 'Connected', 'xml-sitemap-engines-advanced' ); ?></strong>
				<a href="<?php echo esc_url( $xmlse_yandex_disconnect_url ); ?>" class="button button-small" style="margin-left:8px;">
					<?php esc_html_e( 'Disconnect', 'xml-sitemap-engines-advanced' ); ?>
				</a>
			<?php elseif ( Yandex::is_configured() ) : ?>
				<a href="<?php echo esc_url( $xmlse_yandex_oauth_url ); ?>" class="button button-primary">
					<?php esc_html_e( 'Connect Yandex', 'xml-sitemap-engines-advanced' ); ?>
				</a>
			<?php else : ?>
				<p class="description"><?php esc_html_e( 'Save Client ID / Secret / site URL first, then return to authorise.', 'xml-sitemap-engines-advanced' ); ?></p>
			<?php endif; ?>
		</div>
	</div>
</div>

<!-- ============= Baidu ============= -->
<div class="xmlse-section">
	<div class="xmlse-section__head">
		<h3 class="xmlse-section__title">
			<?php esc_html_e( 'Baidu (百度)', 'xml-sitemap-engines-advanced' ); ?>
		</h3>
		<p class="xmlse-section__description">
			<?php esc_html_e( 'Baidu uses a static push token instead of OAuth. Verify your site in Baidu Ziyuan (资源平台), copy the site URL + token from the push-API page, paste here.', 'xml-sitemap-engines-advanced' ); ?>
		</p>
	</div>
	<div class="xmlse-section__row">
		<div class="xmlse-section__row-label">
			<label for="xmlse_adv_baidu_site"><?php esc_html_e( 'Verified site URL', 'xml-sitemap-engines-advanced' ); ?></label>
		</div>
		<div class="xmlse-section__row-control">
			<input type="url"
				id="xmlse_adv_baidu_site"
				name="<?php echo esc_attr( Baidu::CONFIG_OPTION ); ?>[site]"
				value="<?php echo esc_attr( trailingslashit( (string) home_url() ) ); ?>"
				class="regular-text"
				readonly />
			<p class="description">
				<?php
				/* translators: %s: current site host */
				printf(
					esc_html__( 'Must share this site\'s host (%s). Foreign URLs are cleared on save.', 'xml-sitemap-engines-advanced' ),
					'<code>' . esc_html( (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST ) ) . '</code>'
				);
				?>
			</p>
		</div>
	</div>
	<div class="xmlse-section__row">
		<div class="xmlse-section__row-label">
			<label for="xmlse_adv_baidu_token"><?php esc_html_e( 'Push token', 'xml-sitemap-engines-advanced' ); ?></label>
		</div>
		<div class="xmlse-section__row-control">
			<input type="password"
				id="xmlse_adv_baidu_token"
				name="<?php echo esc_attr( Baidu::CONFIG_OPTION ); ?>[token]"
				value="<?php echo esc_attr( $xmlse_adv_baidu_cfg['token'] ); ?>"
				class="regular-text"
				autocomplete="off" />
			<p class="description">
				<?php
				echo wp_kses(
					__( 'From <a href="https://ziyuan.baidu.com/linksubmit/index" target="_blank" rel="noopener">Baidu Ziyuan → Link submit</a>. Alphanumeric string.', 'xml-sitemap-engines-advanced' ),
					array(
						'a' => array(
							'href'   => array(),
							'target' => array(),
							'rel'    => array(),
						),
					)
				);
				?>
			</p>
		</div>
	</div>
</div>

<!-- ============= Submit now ============= -->
<?php if ( ! empty( $xmlse_adv_submit_urls ) ) : ?>
	<div class="xmlse-section">
		<div class="xmlse-section__head">
			<h3 class="xmlse-section__title"><?php esc_html_e( 'Submit sitemaps', 'xml-sitemap-engines-advanced' ); ?></h3>
			<p class="xmlse-section__description">
				<?php esc_html_e( 'One-click push per engine × per sitemap. Each engine uses its own per-URL rate guard; clicking twice in a row is safe.', 'xml-sitemap-engines-advanced' ); ?>
			</p>
		</div>
		<?php foreach ( $xmlse_adv_submit_urls as $xmlse_sm ) : ?>
			<div class="xmlse-section__row">
				<div class="xmlse-section__row-label"><?php echo esc_html( (string) $xmlse_sm['label'] ); ?></div>
				<div class="xmlse-section__row-control">
					<code><?php echo esc_html( (string) $xmlse_sm['url'] ); ?></code>
					<div style="margin-top:8px;display:flex;gap:6px;flex-wrap:wrap;">
						<?php
						foreach ( array( 'bing', 'yandex', 'baidu' ) as $xmlse_eng ) :
							// GET link, not nested <form> — see comment on Yandex card above.
							$xmlse_eng_submit_url = wp_nonce_url(
								add_query_arg(
									array(
										'action'      => 'xmlse_adv_' . $xmlse_eng . '_submit',
										'sitemap_url' => rawurlencode( (string) $xmlse_sm['url'] ),
									),
									admin_url( 'admin-post.php' )
								),
								'xmlse_adv_' . $xmlse_eng . '_submit'
							);
							?>
							<a href="<?php echo esc_url( $xmlse_eng_submit_url ); ?>" class="button button-small">
								<?php
								/* translators: %s: engine label */
								printf( esc_html__( 'Submit → %s', 'xml-sitemap-engines-advanced' ), esc_html( ucfirst( $xmlse_eng ) ) );
								?>
							</a>
						<?php endforeach; ?>
					</div>
				</div>
			</div>
		<?php endforeach; ?>
	</div>
<?php endif; ?>
