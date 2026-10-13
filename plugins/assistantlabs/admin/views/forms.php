<?php
/**
 * Form leads.
 *
 * @package AssistantLabs
 *
 * @var bool  $connected
 * @var array $settings
 * @var array $plugins
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$assistantlabs_any_active = false;
foreach ( $plugins as $plugin ) {
	$assistantlabs_any_active = $assistantlabs_any_active || $plugin['active'];
}
?>
<div class="wrap assistantlabs">
	<h1><?php esc_html_e( 'Form leads', 'assistantlabs' ); ?></h1>
	<?php AssistantLabs_Admin::notices(); ?>

	<?php if ( ! $connected ) : ?>
		<div class="notice notice-info inline"><p>
			<?php esc_html_e( 'Connect an agent first. Leads go to its CRM.', 'assistantlabs' ); ?>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=assistantlabs' ) ); ?>"><?php esc_html_e( 'Connect', 'assistantlabs' ); ?></a>
		</p></div>
	<?php endif; ?>

	<div class="assistantlabs-card">
		<p class="assistantlabs-lead"><?php esc_html_e( 'Every form someone fills in on your site becomes a contact in your AssistantLabs CRM, and your agent can follow up on WhatsApp. No webhook to paste.', 'assistantlabs' ); ?></p>

		<h2><?php esc_html_e( 'Form plugins on this site', 'assistantlabs' ); ?></h2>
		<ul class="assistantlabs-plugin-list">
			<?php foreach ( $plugins as $plugin ) : ?>
				<li>
					<?php if ( $plugin['active'] ) : ?>
						<span class="dashicons dashicons-yes-alt assistantlabs-ok"></span>
						<?php echo esc_html( $plugin['name'] ); ?>
						<span class="assistantlabs-muted"><?php esc_html_e( 'active', 'assistantlabs' ); ?></span>
					<?php else : ?>
						<span class="dashicons dashicons-minus assistantlabs-muted"></span>
						<span class="assistantlabs-muted"><?php echo esc_html( $plugin['name'] ); ?> — <?php esc_html_e( 'not installed', 'assistantlabs' ); ?></span>
					<?php endif; ?>
				</li>
			<?php endforeach; ?>
		</ul>
		<?php if ( ! $assistantlabs_any_active ) : ?>
			<p class="description"><?php esc_html_e( 'None of the supported form plugins is active, so there is nothing to send yet.', 'assistantlabs' ); ?></p>
		<?php endif; ?>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( 'assistantlabs_save_forms' ); ?>
			<input type="hidden" name="action" value="assistantlabs_save_forms" />
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Send leads', 'assistantlabs' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="enabled" value="1" <?php checked( ! empty( $settings['enabled'] ) ); ?> <?php disabled( ! $connected ); ?> />
							<?php esc_html_e( 'Send form submissions to my AssistantLabs CRM', 'assistantlabs' ); ?>
						</label>
						<p class="description"><?php esc_html_e( 'Passwords and card numbers are never sent. If AssistantLabs is slow or down, your forms still work as usual.', 'assistantlabs' ); ?></p>
					</td>
				</tr>
			</table>
			<?php submit_button( __( 'Save', 'assistantlabs' ) ); ?>
		</form>
	</div>
</div>
