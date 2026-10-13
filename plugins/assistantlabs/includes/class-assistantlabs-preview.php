<?php
/**
 * Public preview of a pending change: ?al_preview={id}&al_token=…
 *
 * @package AssistantLabs
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders the target with the proposed values while the change is open.
 */
class AssistantLabs_Preview {

	/**
	 * The change being previewed on this request.
	 *
	 * @var array|null {id, record, handler}
	 */
	private static $active = null;

	/**
	 * Hooks.
	 */
	public static function init() {
		add_filter( 'request', array( __CLASS__, 'filter_request' ), 99 );
		add_action( 'wp', array( __CLASS__, 'setup' ) );
	}

	/**
	 * Whether this request is a valid preview.
	 *
	 * @return bool
	 */
	public static function is_active() {
		return null !== self::$active;
	}

	/**
	 * Point the main query at the change's target (including drafts).
	 *
	 * @param array $query_vars Query vars.
	 * @return array
	 */
	public static function filter_request( $query_vars ) {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- the HMAC token is the check.
		if ( ! isset( $_GET['al_preview'], $_GET['al_token'] ) ) {
			return $query_vars;
		}
		$id    = absint( $_GET['al_preview'] );
		$token = sanitize_text_field( wp_unslash( $_GET['al_token'] ) );
		// phpcs:enable

		$expected = AssistantLabs_Signer::preview_token( $id );
		if ( ! $id || '' === $expected || ! hash_equals( $expected, $token ) ) {
			self::deny( __( 'This preview link is not valid.', 'assistantlabs' ), 403 );
		}
		$post = get_post( $id );
		if ( ! $post || AssistantLabs_Changes::POST_TYPE !== $post->post_type ) {
			self::deny( __( 'This preview link is not valid.', 'assistantlabs' ), 404 );
		}
		$state = AssistantLabs_Changes::state( $id );
		if ( ! in_array( $state, array( AssistantLabs_Changes::PENDING, AssistantLabs_Changes::SCHEDULED ), true ) ) {
			self::deny( __( 'This change was already published or discarded, so its preview is closed.', 'assistantlabs' ), 410 );
		}

		$record  = AssistantLabs_Changes::record( $id );
		$handler = AssistantLabs_Changes::handler( (string) get_post_meta( $id, AssistantLabs_Changes::META_KIND, true ) );
		$target  = $record['target_id'] ? get_post( $record['target_id'] ) : null;
		if ( ! $handler || ! $handler->has_preview( $record ) || ! $target || 'trash' === $target->post_status ) {
			self::deny( __( 'This change has nothing to preview.', 'assistantlabs' ), 404 );
		}

		self::$active = array(
			'id'      => $id,
			'record'  => $record,
			'handler' => $handler,
		);

		$statuses = array( 'publish', 'draft', 'pending', 'future', 'private' );
		if ( 'page' === $target->post_type ) {
			return array(
				'page_id'     => $target->ID,
				'post_status' => $statuses,
			);
		}
		return array(
			'p'           => $target->ID,
			'post_type'   => $target->post_type,
			'post_status' => $statuses,
		);
	}

	/**
	 * Once the query ran: no caching, no indexing, no canonical redirect, and the handler's filters.
	 */
	public static function setup() {
		if ( ! self::$active ) {
			return;
		}
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- the convention caching plugins read.
		}
		nocache_headers();
		add_filter( 'redirect_canonical', '__return_false' );
		add_filter( 'wp_robots', 'wp_robots_no_robots' );
		add_filter( 'show_admin_bar', '__return_false' );
		add_action( 'wp_footer', array( __CLASS__, 'banner' ), 1 );
		add_filter( 'assistantlabs_show_chat', '__return_false' );

		self::$active['handler']->preview( self::$active['record'] );

		/**
		 * A preview is being rendered.
		 *
		 * @param int   $id     Change id.
		 * @param array $record Record.
		 */
		do_action( 'assistantlabs_preview', self::$active['id'], self::$active['record'] );
	}

	/**
	 * A small fixed banner so nobody mistakes the preview for the live page.
	 */
	public static function banner() {
		$summary = self::$active['record']['summary'];
		?>
		<div style="position:fixed;inset:auto 0 0 0;z-index:2147483647;background:#1d2327;color:#fff;font:14px/1.4 -apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;padding:10px 16px;text-align:center;box-shadow:0 -2px 8px rgba(0,0,0,.2)">
			<strong><?php esc_html_e( 'Preview — not live yet.', 'assistantlabs' ); ?></strong>
			<?php echo esc_html( $summary ); ?>
		</div>
		<?php
	}

	/**
	 * Stop with a plain message.
	 *
	 * @param string $message Message.
	 * @param int    $status  HTTP status.
	 */
	private static function deny( $message, $status ) {
		nocache_headers();
		wp_die( esc_html( $message ), esc_html__( 'Preview unavailable', 'assistantlabs' ), array( 'response' => (int) $status ) );
	}
}
