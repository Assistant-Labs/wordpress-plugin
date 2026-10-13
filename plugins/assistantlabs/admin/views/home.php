<?php
/**
 * Home screen.
 *
 * @package AssistantLabs
 *
 * @var bool                $connected
 * @var array|WP_Error|null $status
 * @var int                 $pending
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="wrap assistantlabs">
	<h1 class="assistantlabs-title">
		<img src="<?php echo esc_attr( AssistantLabs_Admin::icon() ); ?>" alt="" width="28" height="28" />
		<?php esc_html_e( 'AssistantLabs', 'assistantlabs' ); ?>
	</h1>
	<?php AssistantLabs_Admin::notices(); ?>

	<?php if ( ! $connected ) : ?>
		<div class="assistantlabs-card assistantlabs-hero">
			<h2><?php esc_html_e( 'An AI agent that answers your customers and runs your site with you.', 'assistantlabs' ); ?></h2>
			<p class="assistantlabs-lead">
				<?php esc_html_e( 'It chats with visitors, sends form leads to your CRM, and drafts pages, posts and products when you ask from Claude, ChatGPT or WhatsApp. Nothing goes live until you approve it.', 'assistantlabs' ); ?>
			</p>
			<div class="assistantlabs-choices">
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="assistantlabs-choice">
					<?php wp_nonce_field( 'assistantlabs_connect' ); ?>
					<input type="hidden" name="action" value="assistantlabs_connect" />
					<input type="hidden" name="intent" value="build" />
					<h3><?php esc_html_e( 'New to AssistantLabs?', 'assistantlabs' ); ?></h3>
					<p><?php esc_html_e( 'We read this site and build an agent that already knows your business.', 'assistantlabs' ); ?></p>
					<button type="submit" class="button button-primary button-hero"><?php esc_html_e( 'Build an agent from this site', 'assistantlabs' ); ?></button>
				</form>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="assistantlabs-choice">
					<?php wp_nonce_field( 'assistantlabs_connect' ); ?>
					<input type="hidden" name="action" value="assistantlabs_connect" />
					<input type="hidden" name="intent" value="existing" />
					<h3><?php esc_html_e( 'Already have an agent?', 'assistantlabs' ); ?></h3>
					<p><?php esc_html_e( 'Sign in to AssistantLabs and pick the agent that should work with this site.', 'assistantlabs' ); ?></p>
					<button type="submit" class="button button-secondary button-hero"><?php esc_html_e( 'Connect my agent', 'assistantlabs' ); ?></button>
				</form>
			</div>
			<p class="description">
				<?php
				printf(
					/* translators: %s: AssistantLabs host name. */
					esc_html__( 'You will sign in on %s and come straight back here.', 'assistantlabs' ),
					esc_html( (string) wp_parse_url( ASSISTANTLABS_APP_URL, PHP_URL_HOST ) )
				);
				?>
			</p>
		</div>
	<?php else : ?>
		<?php
		$assistantlabs_links     = AssistantLabs_Admin::app_links( $status );
		$assistantlabs_reachable = is_array( $status );
		$assistantlabs_knowledge = $assistantlabs_reachable && isset( $status['knowledge'] ) && is_array( $status['knowledge'] ) ? $status['knowledge'] : array();
		$assistantlabs_k_status  = isset( $assistantlabs_knowledge['status'] ) ? $assistantlabs_knowledge['status'] : '';
		$assistantlabs_synced    = isset( $assistantlabs_knowledge['lastSyncedAt'] ) ? $assistantlabs_knowledge['lastSyncedAt'] : null;
		$assistantlabs_name      = $assistantlabs_reachable && ! empty( $status['assistant']['name'] ) ? $status['assistant']['name'] : AssistantLabs_Options::assistant_name();
		$assistantlabs_avatar    = $assistantlabs_reachable && ! empty( $status['assistant']['avatar'] ) ? $status['assistant']['avatar'] : '';
		$assistantlabs_workspace = $assistantlabs_reachable && ! empty( $status['workspace']['name'] ) ? $status['workspace']['name'] : AssistantLabs_Options::workspace_name();
		?>
		<div class="assistantlabs-card assistantlabs-agent">
			<div class="assistantlabs-agent-head">
				<?php if ( $assistantlabs_avatar ) : ?>
					<img class="assistantlabs-avatar" src="<?php echo esc_url( $assistantlabs_avatar ); ?>" alt="" width="48" height="48" />
				<?php else : ?>
					<span class="assistantlabs-avatar assistantlabs-avatar-empty dashicons dashicons-format-chat"></span>
				<?php endif; ?>
				<div>
					<?php if ( $assistantlabs_workspace ) : ?>
						<p class="assistantlabs-eyebrow">
							<?php
							/* translators: %s: workspace name. */
							printf( esc_html__( 'Workspace: %s', 'assistantlabs' ), esc_html( $assistantlabs_workspace ) );
							?>
						</p>
					<?php endif; ?>
					<h2>
						<?php
						/* translators: %s: agent name. */
						printf( esc_html__( 'Agent: %s', 'assistantlabs' ), esc_html( $assistantlabs_name ? $assistantlabs_name : __( 'Your agent', 'assistantlabs' ) ) );
						?>
					</h2>
				</div>
				<span class="assistantlabs-pill assistantlabs-pill-ok"><?php esc_html_e( 'Connected', 'assistantlabs' ); ?></span>
			</div>

			<?php if ( is_wp_error( $status ) && in_array( $status->get_error_code(), array( 'assistantlabs_http_401', 'assistantlabs_http_404' ), true ) ) : ?>
				<div class="assistantlabs-callout">
					<span class="dashicons dashicons-warning"></span>
					<?php esc_html_e( 'AssistantLabs no longer recognizes this connection. Disconnect below and connect again.', 'assistantlabs' ); ?>
				</div>
			<?php elseif ( ! $assistantlabs_reachable ) : ?>
				<p class="assistantlabs-muted">
					<?php
					printf(
						/* translators: %s: error message. */
						esc_html__( 'AssistantLabs could not be reached right now (%s). Your site keeps working as usual.', 'assistantlabs' ),
						esc_html( is_wp_error( $status ) ? $status->get_error_message() : '' )
					);
					?>
				</p>
			<?php endif; ?>

			<p class="description assistantlabs-agent-note"><?php esc_html_e( 'To use a different agent for this site, ask your AssistantLabs assistant or reconnect.', 'assistantlabs' ); ?></p>

			<div class="assistantlabs-links">
				<a class="button button-primary" href="<?php echo esc_url( $assistantlabs_links['dashboard'] ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Open dashboard', 'assistantlabs' ); ?> <span class="dashicons dashicons-external"></span></a>
				<a class="button" href="<?php echo esc_url( $assistantlabs_links['inbox'] ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Conversations', 'assistantlabs' ); ?> <span class="dashicons dashicons-external"></span></a>
				<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=assistantlabs-changes' ) ); ?>">
					<?php
					printf(
						/* translators: %d: number of pending changes. */
						esc_html( _n( '%d pending change', '%d pending changes', $pending, 'assistantlabs' ) ),
						(int) $pending
					);
					?>
				</a>
			</div>
		</div>

		<div class="assistantlabs-card">
			<h2><?php esc_html_e( 'What your agent knows', 'assistantlabs' ); ?></h2>
			<p>
				<?php
				if ( 'running' === $assistantlabs_k_status ) {
					esc_html_e( 'Learning your site right now. This takes a few minutes.', 'assistantlabs' );
				} elseif ( 'done' === $assistantlabs_k_status ) {
					if ( $assistantlabs_synced ) {
						printf(
							/* translators: %s: date and time. */
							esc_html__( 'Learned your site on %s.', 'assistantlabs' ),
							esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), AssistantLabs_Freshness::to_timestamp( $assistantlabs_synced ) ) )
						);
					} else {
						esc_html_e( 'Your agent has learned this site.', 'assistantlabs' );
					}
				} elseif ( 'failed' === $assistantlabs_k_status ) {
					printf(
						/* translators: %s: error message. */
						esc_html__( 'The last update did not finish: %s', 'assistantlabs' ),
						esc_html( isset( $assistantlabs_knowledge['error'] ) ? (string) $assistantlabs_knowledge['error'] : __( 'unknown error', 'assistantlabs' ) )
					);
				} elseif ( $assistantlabs_reachable ) {
					esc_html_e( 'Your agent has not learned this site yet.', 'assistantlabs' );
				} else {
					esc_html_e( 'Status unavailable.', 'assistantlabs' );
				}
				?>
			</p>

			<?php if ( 'running' !== $assistantlabs_k_status && AssistantLabs_Freshness::is_stale( $assistantlabs_synced ) ) : ?>
				<div class="assistantlabs-callout">
					<span class="dashicons dashicons-update"></span>
					<?php esc_html_e( 'Your site changed since the agent last learned it.', 'assistantlabs' ); ?>
				</div>
			<?php endif; ?>

			<?php if ( 'running' !== $assistantlabs_k_status ) : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<?php wp_nonce_field( 'assistantlabs_sync_knowledge' ); ?>
					<input type="hidden" name="action" value="assistantlabs_sync_knowledge" />
					<button type="submit" class="button"><?php esc_html_e( 'Update the agent from my site', 'assistantlabs' ); ?></button>
				</form>
			<?php endif; ?>
		</div>

		<div class="assistantlabs-card assistantlabs-grid">
			<a class="assistantlabs-tile" href="<?php echo esc_url( admin_url( 'admin.php?page=assistantlabs-chat' ) ); ?>">
				<span class="dashicons dashicons-format-chat"></span>
				<strong><?php esc_html_e( 'Chat on your site', 'assistantlabs' ); ?></strong>
				<span><?php echo esc_html( AssistantLabs_Options::chat()['enabled'] ? __( 'On', 'assistantlabs' ) : __( 'Off', 'assistantlabs' ) ); ?></span>
			</a>
			<a class="assistantlabs-tile" href="<?php echo esc_url( admin_url( 'admin.php?page=assistantlabs-forms' ) ); ?>">
				<span class="dashicons dashicons-feedback"></span>
				<strong><?php esc_html_e( 'Form leads', 'assistantlabs' ); ?></strong>
				<span><?php echo esc_html( AssistantLabs_Options::forms()['enabled'] ? __( 'On', 'assistantlabs' ) : __( 'Off', 'assistantlabs' ) ); ?></span>
			</a>
			<a class="assistantlabs-tile" href="<?php echo esc_url( admin_url( 'admin.php?page=assistantlabs-changes' ) ); ?>">
				<span class="dashicons dashicons-yes-alt"></span>
				<strong><?php esc_html_e( 'Pending changes', 'assistantlabs' ); ?></strong>
				<span><?php echo esc_html( (string) $pending ); ?></span>
			</a>
		</div>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="assistantlabs-disconnect" data-assistantlabs-confirm="disconnect">
			<?php wp_nonce_field( 'assistantlabs_disconnect' ); ?>
			<input type="hidden" name="action" value="assistantlabs_disconnect" />
			<button type="submit" class="button-link assistantlabs-danger"><?php esc_html_e( 'Disconnect this site', 'assistantlabs' ); ?></button>
		</form>
	<?php endif; ?>
</div>
