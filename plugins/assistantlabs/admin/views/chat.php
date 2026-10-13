<?php
/**
 * Chat on your site.
 *
 * @package AssistantLabs
 *
 * @var bool  $connected
 * @var array $settings
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$assistantlabs_languages = array(
	'auto' => __( 'Same as the site', 'assistantlabs' ),
	'he'   => 'עברית',
	'en'   => 'English',
	'ar'   => 'العربية',
	'ru'   => 'Русский',
	'es'   => 'Español',
	'fr'   => 'Français',
	'de'   => 'Deutsch',
);
?>
<div class="wrap assistantlabs">
	<h1><?php esc_html_e( 'Chat on your site', 'assistantlabs' ); ?></h1>
	<?php AssistantLabs_Admin::notices(); ?>

	<?php if ( ! $connected ) : ?>
		<div class="notice notice-info inline"><p>
			<?php esc_html_e( 'Connect an agent first. The chat shows your agent, so it needs one.', 'assistantlabs' ); ?>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=assistantlabs' ) ); ?>"><?php esc_html_e( 'Connect', 'assistantlabs' ); ?></a>
		</p></div>
	<?php endif; ?>

	<div class="assistantlabs-card">
		<p class="assistantlabs-lead"><?php esc_html_e( 'Your agent answers visitors in a chat bubble on every page. Its look and greeting come from the Website channel in your AssistantLabs dashboard.', 'assistantlabs' ); ?></p>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( 'assistantlabs_save_chat' ); ?>
			<input type="hidden" name="action" value="assistantlabs_save_chat" />
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Show the chat', 'assistantlabs' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="enabled" value="1" <?php checked( ! empty( $settings['enabled'] ) ); ?> <?php disabled( ! $connected ); ?> />
							<?php esc_html_e( 'Show the chat on my site', 'assistantlabs' ); ?>
						</label>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Position', 'assistantlabs' ); ?></th>
					<td>
						<fieldset>
							<label><input type="radio" name="position" value="right" <?php checked( 'left' !== $settings['position'] ); ?> /> <?php esc_html_e( 'Bottom right', 'assistantlabs' ); ?></label><br />
							<label><input type="radio" name="position" value="left" <?php checked( 'left' === $settings['position'] ); ?> /> <?php esc_html_e( 'Bottom left', 'assistantlabs' ); ?></label>
						</fieldset>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="assistantlabs-lang"><?php esc_html_e( 'Language', 'assistantlabs' ); ?></label></th>
					<td>
						<select id="assistantlabs-lang" name="lang">
							<?php foreach ( $assistantlabs_languages as $assistantlabs_code => $assistantlabs_label ) : ?>
								<option value="<?php echo esc_attr( $assistantlabs_code ); ?>" <?php selected( $settings['lang'], $assistantlabs_code ); ?>><?php echo esc_html( $assistantlabs_label ); ?></option>
							<?php endforeach; ?>
						</select>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="assistantlabs-hide"><?php esc_html_e( 'Hide on these pages', 'assistantlabs' ); ?></label></th>
					<td>
						<textarea id="assistantlabs-hide" name="hide_on" rows="5" cols="50" class="large-text code" placeholder="/checkout/&#10;/my-account/*"><?php echo esc_textarea( $settings['hide_on'] ); ?></textarea>
						<p class="description"><?php esc_html_e( 'One address per line. End with * to hide on everything under it.', 'assistantlabs' ); ?></p>
					</td>
				</tr>
			</table>
			<?php submit_button( __( 'Save', 'assistantlabs' ) ); ?>
		</form>
	</div>
</div>
