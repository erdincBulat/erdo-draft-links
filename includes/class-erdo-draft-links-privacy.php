<?php
defined( 'ABSPATH' ) || exit;

/**
 * Hooks into WordPress' privacy tools (Tools → Export/Erase Personal Data)
 * for what reviewers leave behind: their name, optional email and the
 * comments they wrote. Feedback is matched by the email they gave; feedback
 * left without an email can't be tied to a person and is not included.
 */
class Erdo_Draft_Links_Privacy {

	const PER_PAGE = 100;

	private Erdo_Draft_Links_DB $db;

	public function __construct( Erdo_Draft_Links_DB $db ) {
		$this->db = $db;
	}

	public function register( Erdo_Draft_Links_Loader $loader ): void {
		$loader->add_action( 'admin_init', $this, 'add_policy_content' );
		$loader->add_filter( 'wp_privacy_personal_data_exporters', $this, 'register_exporter' );
		$loader->add_filter( 'wp_privacy_personal_data_erasers', $this, 'register_eraser' );
	}

	public function add_policy_content(): void {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}

		$content  = '<p class="privacy-policy-tutorial">' . esc_html__( 'Erdo Draft Links lets you share unpublished content with reviewers through private links. Suggested text for your policy:', 'erdo-draft-links' ) . '</p>';
		$content .= '<strong class="privacy-policy-tutorial">' . esc_html__( 'Suggested text:', 'erdo-draft-links' ) . '</strong> ';
		$content .= '<p>' . esc_html__( 'When you review a draft through a private review link, we store the name you enter, your comments and notes, your approval or change request, and — only if you choose to give it — your email address, so we can tell you when someone replies. Your name and email are also kept in your browser so you don’t have to type them again. A cookie remembers that you opened the review link; it lasts until the link expires, at most 30 days.', 'erdo-draft-links' ) . '</p>';
		$content .= '<p>' . esc_html__( 'Your feedback is kept until the reviewed page is deleted, or until you ask us to erase it.', 'erdo-draft-links' ) . '</p>';
		$content .= '<p>' . esc_html__( 'If the site’s editors use the optional AI helpers (for example to summarise or translate feedback), the text of the comments is sent to the AI provider the site has connected. Your email address and IP address are never sent, and your name is replaced with “Reviewer”.', 'erdo-draft-links' ) . '</p>';

		wp_add_privacy_policy_content( __( 'Erdo Draft Links', 'erdo-draft-links' ), wp_kses_post( $content ) );
	}

	public function register_exporter( array $exporters ): array {
		$exporters['erdo-draft-links'] = array(
			'exporter_friendly_name' => __( 'Draft review feedback', 'erdo-draft-links' ),
			'callback'               => array( $this, 'export' ),
		);
		return $exporters;
	}

	public function register_eraser( array $erasers ): array {
		$erasers['erdo-draft-links'] = array(
			'eraser_friendly_name' => __( 'Draft review feedback', 'erdo-draft-links' ),
			'callback'             => array( $this, 'erase' ),
		);
		return $erasers;
	}

	/**
	 * @return array{data: array, done: bool}
	 */
	public function export( string $email, int $page = 1 ): array {
		$rows = $this->db->get_feedback_by_email( $email, self::PER_PAGE, ( max( 1, $page ) - 1 ) * self::PER_PAGE );
		$data = array();

		foreach ( $rows as $row ) {
			$item = array(
				array( 'name' => __( 'Page', 'erdo-draft-links' ), 'value' => get_the_title( (int) $row->post_id ) ),
				array( 'name' => __( 'Name', 'erdo-draft-links' ), 'value' => $row->author_name ),
				array( 'name' => __( 'Email', 'erdo-draft-links' ), 'value' => $row->author_email ),
				array( 'name' => __( 'Type', 'erdo-draft-links' ), 'value' => $row->type ),
				array( 'name' => __( 'Message', 'erdo-draft-links' ), 'value' => $row->message ),
				array( 'name' => __( 'Date', 'erdo-draft-links' ), 'value' => get_date_from_gmt( $row->created_at ) ),
			);

			$data[] = array(
				'group_id'          => 'erdo-draft-links',
				'group_label'       => __( 'Draft review feedback', 'erdo-draft-links' ),
				'group_description' => __( 'Comments, notes and decisions left on shared drafts.', 'erdo-draft-links' ),
				'item_id'           => 'erdo-draft-feedback-' . (int) $row->id,
				'data'              => $item,
			);
		}

		return array(
			'data' => $data,
			'done' => count( $rows ) < self::PER_PAGE,
		);
	}

	/**
	 * Anonymises rather than deletes, like WordPress does for comments: the
	 * feedback stays useful to the editors but no longer identifies anyone.
	 * Every call works on the first page, because anonymised rows drop out of
	 * the email match.
	 *
	 * @return array{items_removed: bool, items_retained: bool, messages: string[], done: bool}
	 */
	public function erase( string $email, int $page = 1 ): array {
		$rows = $this->db->get_feedback_by_email( $email, self::PER_PAGE, 0 );

		foreach ( $rows as $row ) {
			$this->db->anonymize_feedback( (int) $row->id );
		}

		return array(
			'items_removed'  => count( $rows ) > 0,
			'items_retained' => false,
			'messages'       => array(),
			'done'           => count( $rows ) < self::PER_PAGE,
		);
	}
}
