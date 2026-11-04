<?php
defined( 'ABSPATH' ) || exit;

/**
 * Emails sent about a draft link. Recipients are the people who care about
 * that specific link — whoever created it and the post's author — rather
 * than always the site admin, who is only the fallback.
 */
class Erdo_Draft_Links_Notifications {

	public function first_view( object $link ): void {
		$post_title = get_the_title( (int) $link->post_id );

		$this->send(
			'first_view',
			$link,
			sprintf(
				/* translators: %s: post title */
				__( 'Your draft link was opened: %s', 'erdo-draft-links' ),
				$post_title
			),
			sprintf(
				/* translators: 1: link label, 2: post title, 3: admin URL to manage links */
				__( "The \"%1\$s\" draft link for \"%2\$s\" was just opened for the first time.\n\nManage links: %3\$s", 'erdo-draft-links' ),
				$this->label( $link ),
				$post_title,
				Erdo_Draft_Links_Admin_Pages::url( 'links' )
			)
		);
	}

	public function feedback( object $link, string $name, string $message ): void {
		$post_title = get_the_title( (int) $link->post_id );

		$this->send(
			'feedback',
			$link,
			sprintf(
				/* translators: %s: post title */
				__( 'New feedback on draft preview: %s', 'erdo-draft-links' ),
				$post_title
			),
			sprintf(
				/* translators: 1: commenter name, 2: post title, 3: feedback message, 4: admin URL to manage feedback */
				__( "%1\$s left feedback on the draft preview of \"%2\$s\":\n\n%3\$s\n\nManage feedback: %4\$s", 'erdo-draft-links' ),
				$name,
				$post_title,
				$message,
				Erdo_Draft_Links_Admin_Pages::url( 'feedback', array( 'post_id' => (int) $link->post_id ) )
			)
		);
	}

	public function review( object $link, string $status, string $name, string $message ): void {
		$post_title = get_the_title( (int) $link->post_id );
		$approved   = Erdo_Draft_Links_DB::REVIEW_APPROVED === $status;

		$subject = $approved
			/* translators: %s: post title */
			? sprintf( __( 'Draft approved: %s', 'erdo-draft-links' ), $post_title )
			/* translators: %s: post title */
			: sprintf( __( 'Changes requested: %s', 'erdo-draft-links' ), $post_title );

		$body = $approved
			/* translators: 1: reviewer name, 2: post title */
			? sprintf( __( '%1$s approved the draft "%2$s".', 'erdo-draft-links' ), $name, $post_title )
			/* translators: 1: reviewer name, 2: post title */
			: sprintf( __( '%1$s requested changes to the draft "%2$s".', 'erdo-draft-links' ), $name, $post_title );

		if ( '' !== $message ) {
			$body .= "\n\n" . $message;
		}
		$edit_link = get_edit_post_link( (int) $link->post_id, 'raw' );
		if ( $edit_link ) {
			/* translators: %s: URL of the post editor */
			$body .= "\n\n" . sprintf( __( 'Open the post: %s', 'erdo-draft-links' ), $edit_link );
		}

		$this->send( 'review', $link, $subject, $body );
	}

	public function link_requested( object $link ): void {
		$post_title = get_the_title( (int) $link->post_id );

		$this->send(
			'link_requested',
			$link,
			sprintf(
				/* translators: %s: post title */
				__( 'Someone asked for a new draft link: %s', 'erdo-draft-links' ),
				$post_title
			),
			sprintf(
				/* translators: 1: link label, 2: post title, 3: URL of the post editor */
				__( "Someone opened the \"%1\$s\" draft link for \"%2\$s\" after it stopped working, and asked for a new one.\n\nGenerate a new link from the post editor: %3\$s", 'erdo-draft-links' ),
				$this->label( $link ),
				$post_title,
				(string) get_edit_post_link( (int) $link->post_id, 'raw' )
			)
		);
	}

	public function expiring( object $link ): void {
		$post_title = get_the_title( (int) $link->post_id );
		$expires    = strtotime( $link->expires_at . ' UTC' );

		$body = sprintf(
			/* translators: 1: link label, 2: post title, 3: time left, e.g. "23 hours", 4: date and time in the site's timezone */
			__( "The \"%1\$s\" draft link for \"%2\$s\" stops working in %3\$s (%4\$s).", 'erdo-draft-links' ),
			$this->label( $link ),
			$post_title,
			human_time_diff( time(), $expires ),
			wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $expires )
		);
		$body .= "\n\n" . ( Erdo_Draft_Links_DB::REVIEW_CHANGES === $link->review_status
			? __( 'The reviewer asked for changes and has not approved it yet.', 'erdo-draft-links' )
			: __( 'The draft has not been approved yet.', 'erdo-draft-links' ) );
		$body .= "\n\n" . sprintf(
			/* translators: %s: admin URL of the links screen */
			__( 'To give the reviewer more time, extend it here: %s', 'erdo-draft-links' ),
			Erdo_Draft_Links_Admin_Pages::url( 'links', array( 's' => rawurlencode( $post_title ) ) )
		);

		$this->send(
			'expiring',
			$link,
			sprintf(
				/* translators: %s: post title */
				__( 'Draft link expires soon: %s', 'erdo-draft-links' ),
				$post_title
			),
			$body
		);
	}

	/**
	 * Tells a reviewer who left their email that someone replied to them.
	 *
	 * @param object $item  The reviewer's top-level feedback item.
	 * @param object $reply The reply row.
	 * @param string $url   Their review link, or '' if it no longer opens.
	 */
	public function reply_to_visitor( object $item, object $reply, string $url ): void {
		$post_title = get_the_title( (int) $item->post_id );

		$body = sprintf(
			/* translators: 1: name of the person who replied, 2: post title, 3: reply text */
			__( "%1\$s replied to your feedback on \"%2\$s\":\n\n%3\$s", 'erdo-draft-links' ),
			(string) $reply->author_name,
			$post_title,
			(string) $reply->message
		);
		if ( '' !== $url ) {
			/* translators: %s: the reviewer's draft link */
			$body .= "\n\n" . sprintf( __( 'See the conversation: %s', 'erdo-draft-links' ), $url );
		}

		wp_mail(
			(string) $item->author_email,
			/* translators: %s: post title */
			sprintf( __( 'New reply about "%s"', 'erdo-draft-links' ), $post_title ),
			$body
		);
	}

	/**
	 * @return string[]
	 */
	public function recipients( object $link ): array {
		$emails = array();

		foreach ( array( (int) ( $link->created_by ?? 0 ), (int) get_post_field( 'post_author', (int) $link->post_id ) ) as $user_id ) {
			$user = $user_id ? get_userdata( $user_id ) : false;
			if ( $user && is_email( $user->user_email ) ) {
				$emails[] = $user->user_email;
			}
		}

		if ( ! $emails ) {
			$emails[] = (string) get_option( 'admin_email' );
		}
		$emails = array_merge( $emails, Erdo_Draft_Links_Settings::extra_recipients() );

		/**
		 * Filters who receives emails about a draft link.
		 *
		 * @param string[] $emails Email addresses.
		 * @param object   $link   The link row.
		 */
		return array_values( array_unique( array_filter( (array) apply_filters( 'erdo_draft_links_notification_recipients', $emails, $link ), 'is_email' ) ) );
	}

	/**
	 * @param string $event One of Erdo_Draft_Links_Settings::EVENTS; skipped when switched off.
	 */
	private function send( string $event, object $link, string $subject, string $body ): void {
		if ( ! Erdo_Draft_Links_Settings::notify_enabled( $event ) ) {
			return;
		}
		$to = $this->recipients( $link );
		if ( $to ) {
			wp_mail( $to, $subject, $body );
		}
	}

	private function label( object $link ): string {
		return '' !== (string) $link->label ? (string) $link->label : __( 'Untitled link', 'erdo-draft-links' );
	}
}
