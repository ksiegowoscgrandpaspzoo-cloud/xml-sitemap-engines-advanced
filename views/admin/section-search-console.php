<?php
/**
 * Search Console tab intro copy.
 *
 * @package XMLSE
 */

defined( 'WPINC' ) || die;
?>
<p>
	<?php
	esc_html_e(
		'Connect your site to Google Search Console so this plugin can submit any of its sitemap URLs directly through the Search Console API. The connection uses BYO OAuth — you create a Google Cloud Console project, paste its credentials here, then authorise the connection.',
		'xml-sitemap-engines'
	);
	?>
</p>
<p>
	<?php
	echo wp_kses(
		__( 'This is a <strong>premium</strong> feature. The UI is visible in the free tier so you can review the setup steps; actual submission requires the News Advanced add-on to flip the <code>xmlse_advanced_enabled</code> filter.', 'xml-sitemap-engines' ),
		array(
			'strong' => array(),
			'code'   => array(),
		)
	);
	?>
</p>
