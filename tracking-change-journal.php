<?php
/**
 * Plugin Name: Tracking Change Journal
 * Description: Keeps a journal of the tracking tags and consent mode defaults in your public pages and records what changed after plugin, theme and core updates.
 * Version: 0.1.0
 * Requires at least: 6.5
 * Requires PHP: 7.4
 * Author: LW IT Solutions
 * Author URI: https://www.lukaswojcik.com/
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: tracking-change-journal
 *
 * @package TrackingChangeJournal
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
define( 'TRCJ_FILE', __FILE__ );
require_once __DIR__ . '/includes/class-trcj-detector.php';
require_once __DIR__ . '/includes/class-trcj-journal.php';

register_activation_hook( __FILE__, array( 'TRCJ_Journal', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'TRCJ_Journal', 'deactivate' ) );

add_action( 'trcj_check', array( 'TRCJ_Journal', 'run_scheduled' ) );
add_action( 'trcj_daily', array( 'TRCJ_Journal', 'run_daily' ) );
add_action( 'upgrader_process_complete', 'trcj_after_upgrade', 10, 2 );
add_action( 'activated_plugin', 'trcj_after_plugin_change' );
add_action( 'deactivated_plugin', 'trcj_after_plugin_change' );
add_action( 'deleted_plugin', 'trcj_after_plugin_change' );
add_action( 'switch_theme', 'trcj_after_theme_change' );
add_action( 'admin_menu', 'trcj_admin_menu' );
add_action( 'admin_enqueue_scripts', 'trcj_admin_assets' );
add_action( 'admin_post_trcj_check', 'trcj_handle_check' );
add_action( 'admin_post_trcj_save', 'trcj_handle_save' );
add_action( 'admin_post_trcj_clear', 'trcj_handle_clear' );

/**
 * Plan a check after updates of plugins, themes or core.
 *
 * @param WP_Upgrader $upgrader Upgrader instance.
 * @param array       $extra    Details of the update.
 */
function trcj_after_upgrade( $upgrader, $extra ) {
	if ( isset( $extra['type'] ) && 'translation' === $extra['type'] ) {
		return;
	}
	TRCJ_Journal::schedule( 'change' );
}

/**
 * Plan a check after a plugin was activated, deactivated or deleted.
 *
 * @param string $plugin Plugin file.
 */
function trcj_after_plugin_change( $plugin ) {
	if ( plugin_basename( TRCJ_FILE ) === $plugin ) {
		return;
	}
	TRCJ_Journal::schedule( 'change' );
}

/** Plan a check after the theme was switched. */
function trcj_after_theme_change() {
	TRCJ_Journal::schedule( 'change' );
}

/** Register the tools screen. */
function trcj_admin_menu() {
	add_management_page( __( 'Tracking Change Journal', 'tracking-change-journal' ), __( 'Tracking Journal', 'tracking-change-journal' ), 'manage_options', 'tracking-change-journal', 'trcj_admin_page' );
}

/**
 * Load local assets only on our screen.
 *
 * @param string $hook Current screen.
 */
function trcj_admin_assets( $hook ) {
	if ( 'tools_page_tracking-change-journal' !== $hook ) {
		return;
	}
	wp_enqueue_style( 'trcj-admin', plugins_url( 'assets/admin.css', __FILE__ ), array(), '0.1.0' );
	wp_enqueue_script( 'trcj-admin', plugins_url( 'assets/admin.js', __FILE__ ), array(), '0.1.0', true );
	$rows = array();
	foreach ( TRCJ_Journal::journal() as $entry ) {
		$time = wp_date( 'Y-m-d H:i', $entry['time'] );
		foreach ( $entry['components'] as $c ) {
			$rows[] = array( $time, TRCJ_Journal::reason_label( $entry['reason'] ), '', TRCJ_Journal::describe_component( $c ) );
		}
		foreach ( $entry['pages'] as $path => $changes ) {
			foreach ( $changes as $c ) {
				$rows[] = array( $time, TRCJ_Journal::reason_label( $entry['reason'] ), $path, TRCJ_Journal::describe_page( $c ) );
			}
		}
		if ( ! $entry['components'] && ! $entry['pages'] ) {
			$rows[] = array( $time, TRCJ_Journal::reason_label( $entry['reason'] ), '', __( 'No change.', 'tracking-change-journal' ) );
		}
	}
	wp_localize_script(
		'trcj-admin',
		'TRCJ',
		array(
			'head'    => array( __( 'Time', 'tracking-change-journal' ), __( 'Check', 'tracking-change-journal' ), __( 'Page', 'tracking-change-journal' ), __( 'Change', 'tracking-change-journal' ) ),
			'rows'    => $rows,
			'confirm' => __( 'Delete all journal entries?', 'tracking-change-journal' ),
		)
	);
}

/**
 * Date and time in the site's format.
 *
 * @param int $time Unix time.
 * @return string
 */
function trcj_time( $time ) {
	return wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $time );
}

/** Render the tools screen. */
function trcj_admin_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	$settings = TRCJ_Journal::settings();
	$state    = TRCJ_Journal::state();
	$journal  = TRCJ_Journal::journal();
	$next     = wp_next_scheduled( 'trcj_check' );
	$daily    = wp_next_scheduled( 'trcj_daily' );
	$action   = admin_url( 'admin-post.php' );
	$notice   = get_transient( 'trcj_notice_' . get_current_user_id() );
	delete_transient( 'trcj_notice_' . get_current_user_id() );
	$done     = is_array( $notice ) ? (string) $notice['done'] : '';
	$rejected = is_array( $notice ) ? (int) $notice['rejected'] : 0;
	?>
	<div class="wrap trcj-wrap">
		<h1><?php esc_html_e( 'Tracking Change Journal', 'tracking-change-journal' ); ?></h1>
		<?php if ( 'check' === $done ) : ?>
			<div class="notice notice-success"><p><?php esc_html_e( 'Check finished.', 'tracking-change-journal' ); ?></p></div>
		<?php elseif ( 'saved' === $done ) : ?>
			<div class="notice notice-success"><p><?php esc_html_e( 'Settings saved.', 'tracking-change-journal' ); ?></p></div>
		<?php elseif ( 'cleared' === $done ) : ?>
			<div class="notice notice-success"><p><?php esc_html_e( 'Journal deleted.', 'tracking-change-journal' ); ?></p></div>
		<?php endif; ?>
		<?php if ( $rejected ) : ?>
			<div class="notice notice-warning"><p>
				<?php
				/* translators: %d: number of lines. */
				echo esc_html( sprintf( _n( '%d line was skipped: not an address on this site, or more than ten pages.', '%d lines were skipped: not addresses on this site, or more than ten pages.', $rejected, 'tracking-change-journal' ), $rejected ) );
				?>
			</p></div>
		<?php endif; ?>

		<p><?php esc_html_e( 'Which tracking tags do your public pages load, and what changed after the last update? The journal loads the pages below the way a first-time visitor without cookies receives them, reads Google Tag Manager containers, Google tags, pixels and analytics scripts with their IDs plus Google consent mode defaults, and compares them with the previous check.', 'tracking-change-journal' ); ?></p>
		<p><?php esc_html_e( 'A check runs one minute after a plugin, theme or WordPress update, after a plugin is activated, deactivated or deleted, after a theme switch, and once a day. Each entry lists the plugin, theme and core changes since the previous check next to the tracking changes.', 'tracking-change-journal' ); ?></p>

		<form method="post" action="<?php echo esc_url( $action ); ?>" class="trcj-actions">
			<input type="hidden" name="action" value="trcj_check">
			<?php wp_nonce_field( 'trcj_check' ); ?>
			<?php submit_button( __( 'Check now', 'tracking-change-journal' ), 'primary', 'submit', false ); ?>
		</form>
		<p class="trcj-muted">
			<?php
			if ( $state['time'] ) {
				/* translators: %s: date and time. */
				echo esc_html( sprintf( __( 'Last check: %s.', 'tracking-change-journal' ), trcj_time( $state['time'] ) ) ) . ' ';
			}
			if ( $next ) {
				/* translators: %s: date and time. */
				echo esc_html( sprintf( __( 'Check after a change planned for %s.', 'tracking-change-journal' ), trcj_time( $next ) ) ) . ' ';
			}
			if ( $daily ) {
				/* translators: %s: date and time. */
				echo esc_html( sprintf( __( 'Next daily check: %s.', 'tracking-change-journal' ), trcj_time( $daily ) ) );
			}
			?>
		</p>

		<h2><?php esc_html_e( 'Tags now', 'tracking-change-journal' ); ?></h2>
		<?php if ( ! $state['pages'] ) : ?>
			<p><?php esc_html_e( 'No check yet.', 'tracking-change-journal' ); ?></p>
		<?php endif; ?>
		<?php foreach ( TRCJ_Journal::group_pages( $state['pages'] ) as list( $paths, $page ) ) : ?>
			<h3 class="trcj-path">
				<?php
				foreach ( $paths as $i => $path ) {
					echo ( $i ? ', ' : '' ) . '<a href="' . esc_url( TRCJ_Journal::origin() . $path ) . '">' . esc_html( $path ) . '</a>';
				}
				?>
			</h3>
			<?php if ( ! TRCJ_Journal::ok( $page['status'] ) ) : ?>
				<p class="trcj-bad">
					<?php
					echo esc_html(
						$page['status']
						/* translators: %d: HTTP status. */
						? sprintf( __( 'The page answered with HTTP status %d.', 'tracking-change-journal' ), $page['status'] )
						/* translators: %s: error message. */
						: sprintf( __( 'The page could not be loaded: %s', 'tracking-change-journal' ), $page['error'] )
					);
					?>
				</p>
			<?php elseif ( ! $page['tags'] ) : ?>
				<p class="trcj-muted"><?php esc_html_e( 'No known tracking tag found.', 'tracking-change-journal' ); ?></p>
			<?php else : ?>
				<div class="trcj-table">
					<table class="widefat striped">
						<thead><tr>
							<th scope="col"><?php esc_html_e( 'Tag', 'tracking-change-journal' ); ?></th>
							<th scope="col"><?php esc_html_e( 'ID', 'tracking-change-journal' ); ?></th>
							<th scope="col"><?php esc_html_e( 'How it is included', 'tracking-change-journal' ); ?></th>
						</tr></thead>
						<tbody>
						<?php foreach ( $page['tags'] as $t ) : ?>
							<tr>
								<td><?php echo esc_html( $t['l'] ); ?></td>
								<td><code><?php echo esc_html( $t['i'] ); ?></code></td>
								<td>
									<?php foreach ( trcj_tag_notes( $t ) as $note ) : ?>
										<div class="<?php echo esc_attr( $note[1] ); ?>"><?php echo esc_html( $note[0] ); ?></div>
									<?php endforeach; ?>
								</td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			<?php endif; ?>
			<?php if ( TRCJ_Journal::ok( $page['status'] ) ) : ?>
				<p>
					<strong><?php esc_html_e( 'Consent mode defaults:', 'tracking-change-journal' ); ?></strong>
					<?php
					echo esc_html( $page['consent'] ? TRCJ_Journal::consent_text( implode( ' | ', $page['consent'] ) ) : __( 'none set in the page', 'tracking-change-journal' ) );
					?>
				</p>
			<?php endif; ?>
		<?php endforeach; ?>

		<h2><?php esc_html_e( 'Journal', 'tracking-change-journal' ); ?></h2>
		<?php if ( ! $journal ) : ?>
			<p><?php esc_html_e( 'No entries yet.', 'tracking-change-journal' ); ?></p>
		<?php else : ?>
			<p class="trcj-actions">
				<button type="button" class="button" id="trcj-csv"><?php esc_html_e( 'Export as CSV', 'tracking-change-journal' ); ?></button>
			</p>
			<ol class="trcj-journal">
				<?php foreach ( array_slice( $journal, 0, 50 ) as $entry ) : ?>
					<li class="<?php echo esc_attr( $entry['changed'] ? 'trcj-changed' : '' ); ?>">
						<div class="trcj-when">
							<strong><?php echo esc_html( trcj_time( $entry['time'] ) ); ?></strong>
							<span class="trcj-muted"><?php echo esc_html( TRCJ_Journal::reason_label( $entry['reason'] ) ); ?></span>
						</div>
						<?php if ( $entry['components'] ) : ?>
							<ul class="trcj-components">
								<?php foreach ( $entry['components'] as $c ) : ?>
									<li><?php echo esc_html( TRCJ_Journal::describe_component( $c ) ); ?></li>
								<?php endforeach; ?>
							</ul>
						<?php endif; ?>
						<?php foreach ( TRCJ_Journal::group_pages( $entry['pages'] ) as list( $paths, $changes ) ) : ?>
							<div class="trcj-path"><?php echo esc_html( implode( ', ', $paths ) ); ?></div>
							<ul>
								<?php foreach ( $changes as $c ) : ?>
									<li><?php echo esc_html( TRCJ_Journal::describe_page( $c ) ); ?></li>
								<?php endforeach; ?>
							</ul>
						<?php endforeach; ?>
						<?php if ( ! $entry['changed'] && ! array_filter( $entry['pages'] ) ) : ?>
							<div class="trcj-muted"><?php esc_html_e( 'No tracking change.', 'tracking-change-journal' ); ?></div>
						<?php endif; ?>
					</li>
				<?php endforeach; ?>
			</ol>
			<?php if ( count( $journal ) > 50 ) : ?>
				<p class="trcj-muted"><?php esc_html_e( 'The page shows the latest 50 entries; the CSV export contains all of them.', 'tracking-change-journal' ); ?></p>
			<?php endif; ?>
		<?php endif; ?>

		<h2><?php esc_html_e( 'Settings', 'tracking-change-journal' ); ?></h2>
		<form method="post" action="<?php echo esc_url( $action ); ?>">
			<input type="hidden" name="action" value="trcj_save">
			<?php wp_nonce_field( 'trcj_save' ); ?>
			<p>
				<label for="trcj-pages"><?php esc_html_e( 'Pages to check, one address or path of this site per line (up to ten). Add the pages that load different tags, for example a checkout or a landing page.', 'tracking-change-journal' ); ?></label><br>
				<textarea id="trcj-pages" name="trcj_pages" rows="5" class="large-text code"><?php echo esc_textarea( implode( "\n", $settings['pages'] ) ); ?></textarea>
			</p>
			<p>
				<label>
					<input type="checkbox" name="trcj_email" value="1" <?php checked( $settings['email'] ); ?>>
					<?php
					/* translators: %s: email address. */
					echo esc_html( sprintf( __( 'Send an email to %s when tracking tags or consent defaults change.', 'tracking-change-journal' ), get_option( 'admin_email' ) ) );
					?>
				</label>
			</p>
			<?php submit_button( __( 'Save settings', 'tracking-change-journal' ), 'secondary', 'submit', false ); ?>
		</form>

		<?php if ( $journal ) : ?>
			<form method="post" action="<?php echo esc_url( $action ); ?>" id="trcj-clear" class="trcj-actions">
				<input type="hidden" name="action" value="trcj_clear">
				<?php wp_nonce_field( 'trcj_clear' ); ?>
				<?php submit_button( __( 'Delete journal', 'tracking-change-journal' ), 'delete', 'submit', false ); ?>
			</form>
		<?php endif; ?>

		<h2><?php esc_html_e( 'Limits', 'tracking-change-journal' ); ?></h2>
		<p><?php esc_html_e( 'The check reads the HTML your server delivers. Tags that a Tag Manager container or another script adds later in the browser are not visible; for a container, its ID and how it is loaded are recorded. "Waits for consent or a loader script" means the code is in the page but marked so that it does not run on load, as consent tools and script delay features do.', 'tracking-change-journal' ); ?></p>
		<p><?php esc_html_e( 'Only tag names, IDs, loader host names, consent defaults, HTTP status codes and plugin, theme and WordPress versions are stored. No page content, cookies or visitor data. The plugin requests pages of this site only.', 'tracking-change-journal' ); ?></p>
	</div>
	<?php
}

/**
 * Notes on how a tag is included.
 *
 * @param array $t Tag entry.
 * @return array List of [text, CSS class].
 */
function trcj_tag_notes( $t ) {
	$notes = array();
	if ( $t['a'] ) {
		/* translators: %d: number of times. */
		$notes[] = array( sprintf( _n( 'loaded %d time', 'loaded %d times', $t['a'], 'tracking-change-journal' ), $t['a'] ), $t['a'] > 1 ? 'trcj-bad' : '' );
	}
	if ( $t['c'] ) {
		/* translators: %d: number of times. */
		$notes[] = array( sprintf( _n( 'configured %d time', 'configured %d times', $t['c'], 'tracking-change-journal' ), $t['c'] ), $t['c'] > 1 ? 'trcj-bad' : '' );
	}
	if ( $t['n'] ) {
		$notes[] = array( __( 'noscript fallback', 'tracking-change-journal' ), '' );
	}
	if ( ! empty( $t['p'] ) ) {
		$notes[] = array( __( 'preloaded', 'tracking-change-journal' ), '' );
	}
	if ( $t['h'] ) {
		$notes[] = array( __( 'waits for consent or a loader script', 'tracking-change-journal' ), 'trcj-held' );
	}
	if ( $t['s'] ) {
		/* translators: %s: host names. */
		$notes[] = array( sprintf( __( 'from %s', 'tracking-change-journal' ), implode( ', ', $t['s'] ) ), 'trcj-muted' );
	}
	if ( TRCJ_Detector::duplicate( $t ) ) {
		$notes[] = array( __( 'More than once on this page: hits may be counted twice.', 'tracking-change-journal' ), 'trcj-bad' );
	}
	return $notes;
}

/** Stop unless the user is an administrator. Nonces never substitute for permissions. */
function trcj_require_admin() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'Administrator permission required.', 'tracking-change-journal' ), 403 );
	}
}

/**
 * Back to the screen with a notice for the current user.
 *
 * @param string $done     What was done: check, saved or cleared.
 * @param int    $rejected Skipped lines of the page list.
 */
function trcj_back( $done, $rejected = 0 ) {
	set_transient(
		'trcj_notice_' . get_current_user_id(),
		array(
			'done'     => $done,
			'rejected' => $rejected,
		),
		MINUTE_IN_SECONDS
	);
	wp_safe_redirect( admin_url( 'tools.php?page=tracking-change-journal' ) );
	exit;
}

/** Run a check now. */
function trcj_handle_check() {
	trcj_require_admin();
	check_admin_referer( 'trcj_check' );
	TRCJ_Journal::check( 'manual' );
	trcj_back( 'check' );
}

/** Save the settings; a changed page list is checked right away. */
function trcj_handle_save() {
	trcj_require_admin();
	check_admin_referer( 'trcj_save' );
	$text   = isset( $_POST['trcj_pages'] ) && is_string( $_POST['trcj_pages'] ) ? sanitize_textarea_field( wp_unslash( $_POST['trcj_pages'] ) ) : '';
	$before = TRCJ_Journal::settings();
	list( $pages, $rejected ) = TRCJ_Journal::clean_paths( $text );
	TRCJ_Journal::save_settings( $pages, ! empty( $_POST['trcj_email'] ) );
	if ( TRCJ_Journal::settings()['pages'] !== $before['pages'] ) {
		TRCJ_Journal::check( 'settings' );
	}
	trcj_back( 'saved', $rejected );
}

/** Delete the journal. */
function trcj_handle_clear() {
	trcj_require_admin();
	check_admin_referer( 'trcj_clear' );
	TRCJ_Journal::clear_journal();
	trcj_back( 'cleared' );
}
