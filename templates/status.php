<?php
/**
 * Draft link status page. Variables come from Erdo_Draft_Links_Status_Page::render().
 *
 * @var string $edl_state
 * @var string $edl_heading
 * @var string $edl_message
 * @var string $edl_site_name
 * @var string $edl_site_icon
 * @var string $edl_form_action
 * @var int    $edl_link_id
 * @var string $edl_error
 * @var bool   $edl_can_request
 */

defined( 'ABSPATH' ) || exit;
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php echo esc_attr( get_option( 'blog_charset' ) ); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow, noarchive, nosnippet">
<meta name="referrer" content="no-referrer">
<title><?php echo esc_html( $edl_heading . ' · ' . $edl_site_name ); ?></title>
<style>
	:root {
		--edl-bg: #f6f7f7;
		--edl-card: #ffffff;
		--edl-text: #1e1e1e;
		--edl-muted: #50575e;
		--edl-border: #dcdcde;
		--edl-accent: #2271b1;
		--edl-accent-text: #ffffff;
		--edl-error: #b32d2e;
		color-scheme: light dark;
	}
	@media (prefers-color-scheme: dark) {
		:root {
			--edl-bg: #101517;
			--edl-card: #1d2327;
			--edl-text: #f0f0f1;
			--edl-muted: #a7aaad;
			--edl-border: #3c434a;
			--edl-accent: #72aee6;
			--edl-accent-text: #101517;
			--edl-error: #f86368;
		}
	}
	*, *::before, *::after { box-sizing: border-box; }
	body {
		margin: 0;
		min-height: 100vh;
		display: grid;
		place-items: center;
		padding: 24px 16px;
		background: var(--edl-bg);
		color: var(--edl-text);
		font: 16px/1.55 -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
	}
	main {
		width: 100%;
		max-width: 440px;
		background: var(--edl-card);
		border: 1px solid var(--edl-border);
		border-radius: 12px;
		padding: 32px 28px;
	}
	.edl-site { display: flex; align-items: center; gap: 10px; margin-bottom: 24px; color: var(--edl-muted); font-size: 14px; }
	.edl-site img { width: 28px; height: 28px; border-radius: 6px; }
	h1 { font-size: 22px; line-height: 1.3; margin: 0 0 8px; }
	p { margin: 0 0 20px; color: var(--edl-muted); }
	form { margin: 0; }
	label { display: block; font-size: 14px; font-weight: 600; margin-bottom: 6px; }
	input[type="password"] {
		width: 100%;
		font: inherit;
		padding: 10px 12px;
		border: 1px solid var(--edl-border);
		border-radius: 8px;
		background: transparent;
		color: inherit;
		margin-bottom: 12px;
	}
	button {
		font: inherit;
		font-weight: 600;
		padding: 10px 18px;
		border: 0;
		border-radius: 8px;
		background: var(--edl-accent);
		color: var(--edl-accent-text);
		cursor: pointer;
	}
	button.edl-secondary { background: transparent; color: var(--edl-accent); border: 1px solid var(--edl-border); }
	:focus-visible { outline: 2px solid var(--edl-accent); outline-offset: 2px; }
	.edl-error { color: var(--edl-error); font-size: 14px; margin: -4px 0 12px; }
</style>
</head>
<body>
<main>
	<div class="edl-site">
		<?php if ( $edl_site_icon ) : ?>
			<img src="<?php echo esc_url( $edl_site_icon ); ?>" alt="">
		<?php endif; ?>
		<span><?php echo esc_html( $edl_site_name ); ?></span>
	</div>

	<h1><?php echo esc_html( $edl_heading ); ?></h1>
	<p><?php echo esc_html( $edl_message ); ?></p>

	<?php if ( Erdo_Draft_Links_Status_Page::STATE_PASSWORD === $edl_state ) : ?>
		<form method="post" action="<?php echo esc_url( $edl_form_action ); ?>">
			<?php wp_nonce_field( 'erdo_draft_link_unlock_' . $edl_link_id, 'erdo_unlock_nonce' ); ?>
			<label for="edl-password"><?php esc_html_e( 'Password', 'erdo-draft-links' ); ?></label>
			<input type="password" id="edl-password" name="erdo_link_password" required autocomplete="current-password" autofocus
				<?php echo '' !== $edl_error ? 'aria-invalid="true" aria-describedby="edl-password-error"' : ''; ?>>
			<?php if ( '' !== $edl_error ) : ?>
				<div class="edl-error" id="edl-password-error" role="alert"><?php echo esc_html( $edl_error ); ?></div>
			<?php endif; ?>
			<button type="submit"><?php esc_html_e( 'View draft', 'erdo-draft-links' ); ?></button>
		</form>
	<?php elseif ( $edl_can_request ) : ?>
		<form method="post" action="<?php echo esc_url( $edl_form_action ); ?>">
			<?php wp_nonce_field( 'erdo_draft_link_request_' . $edl_link_id, 'erdo_request_nonce' ); ?>
			<input type="hidden" name="erdo_request_link" value="1">
			<button type="submit" class="edl-secondary"><?php esc_html_e( 'Ask for a new link', 'erdo-draft-links' ); ?></button>
		</form>
	<?php endif; ?>
</main>
</body>
</html>
