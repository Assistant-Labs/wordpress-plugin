<?php
/**
 * Store settings screen.
 *
 * @package AssistantLabs_WooCommerce
 *
 * @var bool  $connected
 * @var array $settings
 * @var int   $saved
 * @var int   $waiting
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="wrap assistantlabs">
	<h1><?php esc_html_e( 'Store', 'assistantlabs-for-woocommerce' ); ?></h1>
	<?php AssistantLabs_Admin::notices(); ?>

	<?php if ( ! $connected ) : ?>
		<div class="notice notice-info inline"><p>
			<?php esc_html_e( 'Connect an agent first. Your store events go to its workspace.', 'assistantlabs-for-woocommerce' ); ?>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=assistantlabs' ) ); ?>"><?php esc_html_e( 'Connect', 'assistantlabs-for-woocommerce' ); ?></a>
		</p></div>
	<?php endif; ?>

	<div class="assistantlabs-card">
		<p class="assistantlabs-lead"><?php esc_html_e( 'Your orders and customers reach AssistantLabs as they happen, so your agent can answer "where is my order?" and your sales journeys can follow up. It all runs in the background: checkout never waits for AssistantLabs.', 'assistantlabs-for-woocommerce' ); ?></p>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( 'assistantlabs_wc_save_store' ); ?>
			<input type="hidden" name="action" value="assistantlabs_wc_save_store" />
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Abandoned carts', 'assistantlabs-for-woocommerce' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="recover_carts" value="1" <?php checked( ! empty( $settings['recover_carts'] ) ); ?> <?php disabled( ! $connected ); ?> />
							<?php esc_html_e( 'Recover abandoned carts', 'assistantlabs-for-woocommerce' ); ?>
						</label>
						<p class="description"><?php esc_html_e( 'When a shopper types an email or phone number at checkout, their cart is saved for 30 days and sent to AssistantLabs with a link that restores it. If they leave without ordering, your sales journey can remind them on WhatsApp or by email. Marketing consent is sent only when your checkout has an opt-in checkbox.', 'assistantlabs-for-woocommerce' ); ?></p>
						<p class="description"><?php esc_html_e( 'Turning this on means collecting shopper contact details before they order. Mention it in your privacy policy (Settings → Privacy suggests the text).', 'assistantlabs-for-woocommerce' ); ?></p>
					</td>
				</tr>
			</table>
			<?php submit_button( __( 'Save', 'assistantlabs-for-woocommerce' ) ); ?>
		</form>

		<p class="assistantlabs-muted">
			<?php
			printf(
				/* translators: 1: number of saved carts, 2: number of store events waiting to be sent. */
				esc_html__( 'Carts saved for recovery: %1$d · Store events waiting to send: %2$d', 'assistantlabs-for-woocommerce' ),
				(int) $saved,
				(int) $waiting
			);
			?>
		</p>
	</div>
</div>
