<?php
defined( 'ABSPATH' ) || exit;

/**
 * Plugin settings: one option array registered through the Settings API,
 * shown on Draft Links → Settings. Supported post types keep their own
 * option (Erdo_Draft_Links_Post_Types::OPTION_KEY) for backward
 * compatibility but are edited on the same page.
 */
class Erdo_Draft_Links_Settings {

	const OPTION = 'erdo_draft_link_settings';
	const GROUP  = 'erdo_draft_links';
	const PAGE   = 'erdo-draft-links-settings';

	const EXPIRIES = array( '24h', '48h', '7d', '30d' );
	const EVENTS   = array( 'first_view', 'feedback', 'review', 'link_requested', 'expiring' );

	public static function defaults(): array {
		return array(
			'default_expiry'   => '7d',
			'notify_events'    => self::EVENTS,
			'notify_extra'     => '',
			// Settings saved before notify_known existed offered only these.
			'notify_known'     => array( 'first_view', 'feedback', 'review', 'link_requested' ),
			'close_on_publish' => false,
			'cleanup_days'     => 7,
			'delete_data'      => true,
			'ai_enabled'       => true,
		);
	}

	/**
	 * One setting, falling back to its default.
	 *
	 * @return mixed
	 */
	public static function get( string $key ) {
		$saved = get_option( self::OPTION );
		$all   = array_merge( self::defaults(), is_array( $saved ) ? $saved : array() );
		return $all[ $key ] ?? null;
	}

	public static function notify_enabled( string $event ): bool {
		// Events added after the settings were last saved weren't offered on
		// that form, so they start switched on like for a fresh install.
		$known = self::get( 'notify_known' );
		if ( is_array( $known ) && ! in_array( $event, $known, true ) ) {
			return in_array( $event, self::EVENTS, true );
		}
		return in_array( $event, (array) self::get( 'notify_events' ), true );
	}

	/**
	 * @return string[] Valid addresses from the "also notify" field.
	 */
	public static function extra_recipients(): array {
		return array_values( array_filter( array_map( 'trim', explode( ',', (string) self::get( 'notify_extra' ) ) ), 'is_email' ) );
	}

	public function register( Erdo_Draft_Links_Loader $loader ): void {
		$loader->add_action( 'admin_init', $this, 'register_settings' );
	}

	public function register_settings(): void {
		register_setting(
			self::GROUP,
			self::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize' ),
				'default'           => self::defaults(),
			)
		);
		register_setting(
			self::GROUP,
			Erdo_Draft_Links_Post_Types::OPTION_KEY,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize_post_types' ),
				'default'           => array( 'post', 'page' ),
			)
		);

		add_settings_section( 'sharing', __( 'Sharing', 'erdo-draft-links' ), '__return_null', self::PAGE );
		add_settings_section( 'notifications', __( 'Email notifications', 'erdo-draft-links' ), array( $this, 'render_notifications_intro' ), self::PAGE );
		add_settings_section( 'ai', __( 'AI helpers', 'erdo-draft-links' ), array( $this, 'render_ai_intro' ), self::PAGE );
		add_settings_section( 'data', __( 'Data', 'erdo-draft-links' ), '__return_null', self::PAGE );

		$fields = array(
			array( 'post_types', __( 'Where links can be shared', 'erdo-draft-links' ), 'sharing' ),
			array( 'default_expiry', __( 'Links expire after', 'erdo-draft-links' ), 'sharing' ),
			array( 'close_on_publish', __( 'When a post is published', 'erdo-draft-links' ), 'sharing' ),
			array( 'notify_events', __( 'Email me when', 'erdo-draft-links' ), 'notifications' ),
			array( 'notify_extra', __( 'Also notify', 'erdo-draft-links' ), 'notifications' ),
			array( 'ai_enabled', __( 'AI helpers', 'erdo-draft-links' ), 'ai' ),
			array( 'cleanup_days', __( 'Expired links', 'erdo-draft-links' ), 'data' ),
			array( 'delete_data', __( 'When the plugin is deleted', 'erdo-draft-links' ), 'data' ),
		);
		foreach ( $fields as list( $id, $title, $section ) ) {
			add_settings_field(
				$id,
				$title,
				array( $this, 'render_' . $id ),
				self::PAGE,
				$section,
				in_array( $id, array( 'default_expiry', 'notify_extra', 'cleanup_days' ), true ) ? array( 'label_for' => 'erdo-dl-' . $id ) : array()
			);
		}
	}

	public function sanitize( $input ): array {
		$input = is_array( $input ) ? $input : array();
		$out   = self::defaults();

		if ( in_array( $input['default_expiry'] ?? '', self::EXPIRIES, true ) ) {
			$out['default_expiry'] = $input['default_expiry'];
		}

		$out['notify_known']  = self::EVENTS;
		$out['notify_events'] = array_values( array_intersect( self::EVENTS, array_map( 'sanitize_key', (array) ( $input['notify_events'] ?? array() ) ) ) );

		$entered = array_filter( array_map( 'trim', explode( ',', (string) ( $input['notify_extra'] ?? '' ) ) ) );
		$valid   = array_filter( array_map( 'sanitize_email', $entered ), 'is_email' );
		if ( count( $valid ) < count( $entered ) ) {
			add_settings_error( self::OPTION, 'notify_extra', __( 'Some of the extra email addresses were not valid and were removed.', 'erdo-draft-links' ) );
		}
		$out['notify_extra'] = implode( ', ', $valid );

		$out['close_on_publish'] = ! empty( $input['close_on_publish'] );
		$out['cleanup_days']     = max( 1, min( 365, absint( $input['cleanup_days'] ?? 7 ) ) );
		$out['delete_data']      = ! empty( $input['delete_data'] );
		$out['ai_enabled']       = ! empty( $input['ai_enabled'] );

		return $out;
	}

	public function sanitize_post_types( $input ): array {
		// Only keep slugs that are actually selectable, so a tampered request
		// can't store an arbitrary/internal post type.
		$valid = wp_list_pluck( Erdo_Draft_Links_Post_Types::get_selectable(), 'name' );
		return array_values( array_intersect( array_map( 'sanitize_key', (array) $input ), $valid ) );
	}

	// -------------------------------------------------------------------------
	// Page
	// -------------------------------------------------------------------------

	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'erdo-draft-links' ) );
		}
		?>
		<div class="wrap erdo-dl-admin">
			<h1><?php esc_html_e( 'Draft Links settings', 'erdo-draft-links' ); ?></h1>
			<?php
			// Only pages under Settings print these automatically.
			settings_errors();
			?>
			<form method="post" action="options.php">
				<?php
				settings_fields( self::GROUP );
				do_settings_sections( self::PAGE );
				submit_button();
				?>
			</form>
		</div>
		<?php
	}

	public function render_notifications_intro(): void {
		echo '<p>' . esc_html__( 'Emails go to whoever created the link and the post’s author (or the site admin email if neither has one).', 'erdo-draft-links' ) . '</p>';
	}

	private function name( string $key ): string {
		return self::OPTION . '[' . $key . ']';
	}

	public function render_post_types(): void {
		$supported = Erdo_Draft_Links_Post_Types::get_supported();
		echo '<fieldset>';
		foreach ( Erdo_Draft_Links_Post_Types::get_selectable() as $post_type ) {
			printf(
				'<label><input type="checkbox" name="%1$s[]" value="%2$s" %3$s /> %4$s</label><br />',
				esc_attr( Erdo_Draft_Links_Post_Types::OPTION_KEY ),
				esc_attr( $post_type->name ),
				checked( in_array( $post_type->name, $supported, true ), true, false ),
				esc_html( $post_type->labels->name )
			);
		}
		// Keeps the option saved as an empty list when every box is unticked.
		printf( '<input type="hidden" name="%s[]" value="" />', esc_attr( Erdo_Draft_Links_Post_Types::OPTION_KEY ) );
		echo '</fieldset>';
		if ( has_filter( 'erdo_draft_links_supported_post_types' ) ) {
			echo '<p class="description">' . esc_html__( 'A theme or plugin also changes this list with the erdo_draft_links_supported_post_types filter, which takes precedence.', 'erdo-draft-links' ) . '</p>';
		}
	}

	public function render_default_expiry(): void {
		$labels = array(
			'24h' => __( '24 hours', 'erdo-draft-links' ),
			'48h' => __( '48 hours', 'erdo-draft-links' ),
			'7d'  => __( '7 days', 'erdo-draft-links' ),
			'30d' => __( '30 days', 'erdo-draft-links' ),
		);
		printf( '<select id="erdo-dl-default_expiry" name="%s">', esc_attr( $this->name( 'default_expiry' ) ) );
		foreach ( $labels as $value => $label ) {
			printf( '<option value="%1$s" %2$s>%3$s</option>', esc_attr( $value ), selected( self::get( 'default_expiry' ), $value, false ), esc_html( $label ) );
		}
		echo '</select>';
		echo '<p class="description">' . esc_html__( 'Used by “Create share link”. You can still pick another duration for any link.', 'erdo-draft-links' ) . '</p>';
	}

	public function render_close_on_publish(): void {
		printf(
			'<label><input type="checkbox" name="%1$s" value="1" %2$s /> %3$s</label>',
			esc_attr( $this->name( 'close_on_publish' ) ),
			checked( (bool) self::get( 'close_on_publish' ), true, false ),
			esc_html__( 'Turn off its share links automatically', 'erdo-draft-links' )
		);
		echo '<p class="description">' . esc_html__( 'Otherwise the editor offers to turn them off right after publishing.', 'erdo-draft-links' ) . '</p>';
	}

	public function render_notify_events(): void {
		$labels = array(
			'first_view'     => __( 'A link is opened for the first time', 'erdo-draft-links' ),
			'feedback'       => __( 'A reviewer comments or leaves a note', 'erdo-draft-links' ),
			'review'         => __( 'A reviewer approves or requests changes', 'erdo-draft-links' ),
			'link_requested' => __( 'Someone asks for a new link after one stopped working', 'erdo-draft-links' ),
			'expiring'       => __( 'A link that hasn’t been approved expires within a day', 'erdo-draft-links' ),
		);
		echo '<fieldset>';
		foreach ( $labels as $event => $label ) {
			printf(
				'<label><input type="checkbox" name="%1$s[]" value="%2$s" %3$s /> %4$s</label><br />',
				esc_attr( $this->name( 'notify_events' ) ),
				esc_attr( $event ),
				checked( self::notify_enabled( $event ), true, false ),
				esc_html( $label )
			);
		}
		echo '</fieldset>';
		echo '<p class="description">' . esc_html__( 'Reviewers who leave their email are always told when someone replies to them.', 'erdo-draft-links' ) . '</p>';
	}

	public function render_notify_extra(): void {
		printf(
			'<input type="text" id="erdo-dl-notify_extra" class="regular-text" name="%1$s" value="%2$s" placeholder="%3$s" />',
			esc_attr( $this->name( 'notify_extra' ) ),
			esc_attr( (string) self::get( 'notify_extra' ) ),
			esc_attr__( 'pm@example.com, team@example.com', 'erdo-draft-links' )
		);
		echo '<p class="description">' . esc_html__( 'Comma-separated addresses that get every notification above.', 'erdo-draft-links' ) . '</p>';
	}

	public function render_ai_intro(): void {
		echo '<p>' . esc_html__( 'Summarise feedback into a to-do list, draft replies, translate comments, write the message to send with a link and a “what changed” note for reviewers. They run only when you click them, on your own AI provider account.', 'erdo-draft-links' ) . '</p>';
	}

	public function render_ai_enabled(): void {
		printf(
			'<label><input type="checkbox" name="%1$s" value="1" %2$s /> %3$s</label>',
			esc_attr( $this->name( 'ai_enabled' ) ),
			checked( (bool) self::get( 'ai_enabled' ), true, false ),
			esc_html__( 'Show the AI helpers in the editor', 'erdo-draft-links' )
		);

		if ( ! function_exists( 'wp_ai_client_prompt' ) ) {
			$status = __( 'Needs WordPress 7.0 or newer.', 'erdo-draft-links' );
		} else {
			$ai     = new Erdo_Draft_Links_AI( Erdo_Draft_Links_DB::get_instance(), new Erdo_Draft_Links_Token() );
			$status = $ai->status()['available']
				? __( 'An AI provider is connected.', 'erdo-draft-links' )
				: __( 'No AI provider is connected yet.', 'erdo-draft-links' );
		}
		printf(
			'<p class="description">%1$s %2$s</p>',
			esc_html( $status ),
			function_exists( 'wp_ai_client_prompt' ) ? sprintf(
				/* translators: %s: link to the Settings → Connectors screen */
				esc_html__( 'Add or change your own API key under %s. Draft Links never stores it.', 'erdo-draft-links' ),
				'<a href="' . esc_url( admin_url( 'options-connectors.php' ) ) . '">' . esc_html__( 'Settings → Connectors', 'erdo-draft-links' ) . '</a>'
			) : ''
		);
		echo '<p class="description">' . esc_html__( 'Comment text and the page’s text are sent to your provider; reviewer emails and IP addresses never are, and names are replaced with “Reviewer”.', 'erdo-draft-links' ) . '</p>';
	}

	public function render_cleanup_days(): void {
		printf(
			/* translators: %s: number input for days */
			esc_html__( 'Delete expired links after %s days', 'erdo-draft-links' ),
			sprintf(
				'<input type="number" id="erdo-dl-cleanup_days" class="small-text" min="1" max="365" name="%1$s" value="%2$d" />',
				esc_attr( $this->name( 'cleanup_days' ) ),
				(int) self::get( 'cleanup_days' )
			)
		);
		echo '<p class="description">' . esc_html__( 'Feedback on a post is kept until the post itself is deleted.', 'erdo-draft-links' ) . '</p>';
	}

	public function render_delete_data(): void {
		printf(
			'<label><input type="checkbox" name="%1$s" value="1" %2$s /> %3$s</label>',
			esc_attr( $this->name( 'delete_data' ) ),
			checked( (bool) self::get( 'delete_data' ), true, false ),
			esc_html__( 'Remove all links, feedback and settings', 'erdo-draft-links' )
		);
		echo '<p class="description">' . esc_html__( 'Untick to keep the data if you plan to reinstall.', 'erdo-draft-links' ) . '</p>';
	}
}
