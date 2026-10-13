<?php
/**
 * Pending changes.
 *
 * @package AssistantLabs
 *
 * @var string $tab     pending|history.
 * @var array  $changes Change arrays.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$assistantlabs_states = array(
	'pending'   => __( 'Waiting for you', 'assistantlabs' ),
	'scheduled' => __( 'Scheduled', 'assistantlabs' ),
	'published' => __( 'Published', 'assistantlabs' ),
	'discarded' => __( 'Discarded', 'assistantlabs' ),
);
$assistantlabs_base   = admin_url( 'admin.php?page=assistantlabs-changes' );
$assistantlabs_min    = wp_date( 'Y-m-d\TH:i' );
?>
<div class="wrap assistantlabs">
	<h1><?php esc_html_e( 'Pending changes', 'assistantlabs' ); ?></h1>
	<?php AssistantLabs_Admin::notices(); ?>

	<p class="assistantlabs-lead"><?php esc_html_e( 'Your agent saves everything it writes as a draft. Nothing here is on your live site until you publish it — here, in Claude, or on WhatsApp.', 'assistantlabs' ); ?></p>

	<nav class="nav-tab-wrapper">
		<a href="<?php echo esc_url( $assistantlabs_base ); ?>" class="nav-tab <?php echo 'pending' === $tab ? 'nav-tab-active' : ''; ?>"><?php esc_html_e( 'Waiting', 'assistantlabs' ); ?></a>
		<a href="<?php echo esc_url( add_query_arg( 'tab', 'history', $assistantlabs_base ) ); ?>" class="nav-tab <?php echo 'history' === $tab ? 'nav-tab-active' : ''; ?>"><?php esc_html_e( 'History', 'assistantlabs' ); ?></a>
	</nav>

	<?php if ( ! $changes ) : ?>
		<div class="assistantlabs-empty">
			<span class="dashicons dashicons-yes-alt"></span>
			<p>
				<?php
				if ( 'pending' === $tab ) {
					esc_html_e( 'Nothing is waiting for you. When your agent drafts a change, it shows up here.', 'assistantlabs' );
				} else {
					esc_html_e( 'No published or discarded changes yet.', 'assistantlabs' );
				}
				?>
			</p>
		</div>
	<?php else : ?>
		<table class="wp-list-table widefat fixed striped assistantlabs-changes">
			<thead>
				<tr>
					<th scope="col" class="column-primary"><?php esc_html_e( 'Change', 'assistantlabs' ); ?></th>
					<th scope="col" class="column-created"><?php esc_html_e( 'Created', 'assistantlabs' ); ?></th>
					<th scope="col" class="column-state"><?php esc_html_e( 'Status', 'assistantlabs' ); ?></th>
					<th scope="col" class="column-actions"><?php echo 'pending' === $tab ? esc_html__( 'Actions', 'assistantlabs' ) : esc_html__( 'When', 'assistantlabs' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $changes as $assistantlabs_change ) : ?>
					<?php $assistantlabs_nonce_action = 'assistantlabs_change_' . (int) $assistantlabs_change['id']; ?>
					<tr>
						<td class="column-primary" data-colname="<?php esc_attr_e( 'Change', 'assistantlabs' ); ?>">
							<strong><?php echo esc_html( $assistantlabs_change['summary'] ); ?></strong>
							<div class="row-actions visible">
								<?php if ( $assistantlabs_change['previewUrl'] ) : ?>
									<span><a href="<?php echo esc_url( $assistantlabs_change['previewUrl'] ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Preview', 'assistantlabs' ); ?></a></span>
								<?php endif; ?>
								<?php if ( $assistantlabs_change['editUrl'] ) : ?>
									<span><?php echo $assistantlabs_change['previewUrl'] ? ' | ' : ''; ?><a href="<?php echo esc_url( $assistantlabs_change['editUrl'] ); ?>"><?php esc_html_e( 'Open in editor', 'assistantlabs' ); ?></a></span>
								<?php endif; ?>
								<?php if ( $assistantlabs_change['liveUrl'] ) : ?>
									<span> | <a href="<?php echo esc_url( $assistantlabs_change['liveUrl'] ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'View live', 'assistantlabs' ); ?></a></span>
								<?php endif; ?>
							</div>
							<?php if ( ! empty( $assistantlabs_change['error'] ) ) : ?>
								<p class="assistantlabs-error">
									<?php
									/* translators: %s: error message. */
									printf( esc_html__( 'Last attempt failed: %s', 'assistantlabs' ), esc_html( $assistantlabs_change['error'] ) );
									?>
								</p>
							<?php endif; ?>
						</td>
						<td class="column-created" data-colname="<?php esc_attr_e( 'Created', 'assistantlabs' ); ?>"><?php echo esc_html( AssistantLabs_Admin::human_date( $assistantlabs_change['createdAt'] ) ); ?></td>
						<td class="column-state" data-colname="<?php esc_attr_e( 'Status', 'assistantlabs' ); ?>">
							<span class="assistantlabs-pill assistantlabs-pill-<?php echo esc_attr( $assistantlabs_change['state'] ); ?>"><?php echo esc_html( isset( $assistantlabs_states[ $assistantlabs_change['state'] ] ) ? $assistantlabs_states[ $assistantlabs_change['state'] ] : $assistantlabs_change['state'] ); ?></span>
							<?php if ( 'scheduled' === $assistantlabs_change['state'] ) : ?>
								<br /><span class="assistantlabs-muted"><?php echo esc_html( AssistantLabs_Admin::human_date( $assistantlabs_change['scheduledFor'] ) ); ?></span>
							<?php endif; ?>
						</td>
						<td class="column-actions" data-colname="<?php esc_attr_e( 'Actions', 'assistantlabs' ); ?>">
							<?php if ( 'pending' === $tab ) : ?>
								<div class="assistantlabs-actions">
									<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" data-assistantlabs-confirm="publish">
										<?php wp_nonce_field( $assistantlabs_nonce_action ); ?>
										<input type="hidden" name="action" value="assistantlabs_change" />
										<input type="hidden" name="change_id" value="<?php echo esc_attr( $assistantlabs_change['id'] ); ?>" />
										<input type="hidden" name="op" value="publish" />
										<button type="submit" class="button button-primary"><?php esc_html_e( 'Publish now', 'assistantlabs' ); ?></button>
									</form>
									<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="assistantlabs-schedule">
										<?php wp_nonce_field( $assistantlabs_nonce_action ); ?>
										<input type="hidden" name="action" value="assistantlabs_change" />
										<input type="hidden" name="change_id" value="<?php echo esc_attr( $assistantlabs_change['id'] ); ?>" />
										<input type="hidden" name="op" value="schedule" />
										<label class="screen-reader-text" for="al-at-<?php echo esc_attr( $assistantlabs_change['id'] ); ?>"><?php esc_html_e( 'Publish at', 'assistantlabs' ); ?></label>
										<input type="datetime-local" id="al-at-<?php echo esc_attr( $assistantlabs_change['id'] ); ?>" name="at" min="<?php echo esc_attr( $assistantlabs_min ); ?>" required />
										<button type="submit" class="button"><?php echo 'scheduled' === $assistantlabs_change['state'] ? esc_html__( 'Reschedule', 'assistantlabs' ) : esc_html__( 'Schedule', 'assistantlabs' ); ?></button>
									</form>
									<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" data-assistantlabs-confirm="discard">
										<?php wp_nonce_field( $assistantlabs_nonce_action ); ?>
										<input type="hidden" name="action" value="assistantlabs_change" />
										<input type="hidden" name="change_id" value="<?php echo esc_attr( $assistantlabs_change['id'] ); ?>" />
										<input type="hidden" name="op" value="discard" />
										<button type="submit" class="button-link assistantlabs-danger"><?php esc_html_e( 'Discard', 'assistantlabs' ); ?></button>
									</form>
								</div>
							<?php else : ?>
								<?php echo esc_html( AssistantLabs_Admin::human_date( 'published' === $assistantlabs_change['state'] ? $assistantlabs_change['publishedAt'] : null ) ); ?>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<p class="description">
			<?php
			printf(
				/* translators: %s: time zone name. */
				esc_html__( 'Times are in the site time zone (%s).', 'assistantlabs' ),
				esc_html( wp_timezone_string() )
			);
			?>
		</p>
	<?php endif; ?>
</div>
