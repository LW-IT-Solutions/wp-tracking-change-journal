<?php
/**
 * Plugin Name: Tracking Change Journal
 * Description: Keeps a journal of the tracking tags and consent mode defaults in your public pages and records what changed after plugin, theme and core updates.
 * Version: 0.3.1
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
define( 'TRCJ_VERSION', '0.3.1' );
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
	wp_enqueue_style( 'trcj-admin', plugins_url( 'assets/admin.css', __FILE__ ), array(), TRCJ_VERSION );
	wp_enqueue_script( 'trcj-admin', plugins_url( 'assets/admin.js', __FILE__ ), array(), TRCJ_VERSION, true );
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
			'text'    => array(
				'checking' => __( 'Checking…', 'tracking-change-journal' ),
				/* translators: 1: number of pages entered, 2: maximum number of pages. */
				'lines'    => __( '%1$d of %2$d pages', 'tracking-change-journal' ),
				/* translators: 1: number of entries shown, 2: number of entries. */
				'shown'    => __( 'Showing %1$d of %2$d entries.', 'tracking-change-journal' ),
				'max'      => TRCJ_Journal::MAX_PAGES,
				/* translators: %d: seconds. */
				'elapsed'  => __( '%d s', 'tracking-change-journal' ),
				'none'     => __( 'No entry matches the filter.', 'tracking-change-journal' ),
				/* translators: %s: date. */
				'day'      => __( 'Day: %s', 'tracking-change-journal' ),
				'copied'   => __( 'Copied', 'tracking-change-journal' ),
				'copy'     => __( 'Copy as text', 'tracking-change-journal' ),
			),
			'dialog'  => array(
				'title'  => __( 'Delete journal', 'tracking-change-journal' ),
				'text'   => __( 'Delete all journal entries?', 'tracking-change-journal' ),
				'ok'     => __( 'Delete journal', 'tracking-change-journal' ),
				'cancel' => __( 'Cancel', 'tracking-change-journal' ),
			),
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
		<h1 class="trcj-title"><span class="dashicons dashicons-chart-line" aria-hidden="true"></span> <?php esc_html_e( 'Tracking Change Journal', 'tracking-change-journal' ); ?></h1>
		<?php if ( 'check' === $done ) : ?>
			<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Check finished.', 'tracking-change-journal' ); ?></p></div>
		<?php elseif ( 'saved' === $done ) : ?>
			<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Settings saved.', 'tracking-change-journal' ); ?></p></div>
		<?php elseif ( 'cleared' === $done ) : ?>
			<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Journal deleted.', 'tracking-change-journal' ); ?></p></div>
		<?php endif; ?>
		<?php if ( $rejected ) : ?>
			<div class="notice notice-warning"><p>
				<?php
				/* translators: %d: number of lines. */
				echo esc_html( sprintf( _n( '%d line was skipped: not an address on this site, or more than ten pages.', '%d lines were skipped: not addresses on this site, or more than ten pages.', $rejected, 'tracking-change-journal' ), $rejected ) );
				?>
			</p></div>
		<?php endif; ?>

		<p class="trcj-lead"><?php esc_html_e( 'Which tracking tags do your public pages load, and what changed after the last update? The journal loads the pages below the way a first-time visitor without cookies receives them, reads Google Tag Manager containers, Google tags, pixels and analytics scripts with their IDs plus Google consent mode defaults, and compares them with the previous check.', 'tracking-change-journal' ); ?></p>
		<details class="trcj-how">
			<summary><?php esc_html_e( 'When checks run', 'tracking-change-journal' ); ?></summary>
			<p><?php esc_html_e( 'A check runs one minute after a plugin, theme or WordPress update, after a plugin is activated, deactivated or deleted, after a theme switch, and once a day. Each entry lists the plugin, theme and core changes since the previous check next to the tracking changes.', 'tracking-change-journal' ); ?></p>
		</details>

		<div class="trcj-card trcj-runbar">
			<form method="post" action="<?php echo esc_url( $action ); ?>" class="trcj-check-form" id="trcj-check">
				<input type="hidden" name="action" value="trcj_check">
				<?php wp_nonce_field( 'trcj_check' ); ?>
				<button type="submit" class="button button-primary" id="trcj-check-button"><span class="dashicons dashicons-update" aria-hidden="true"></span> <span class="trcj-check-label"><?php esc_html_e( 'Check now', 'tracking-change-journal' ); ?></span></button>
				<span class="spinner" id="trcj-spinner"></span>
			</form>
			<div class="trcj-running" id="trcj-running" hidden>
				<div class="trcj-running-head">
					<strong><?php esc_html_e( 'Loading the pages without cookies and reading their tags', 'tracking-change-journal' ); ?></strong>
					<span class="trcj-elapsed" id="trcj-elapsed" aria-hidden="true"></span>
				</div>
				<div class="trcj-running-bar" aria-hidden="true"><span></span></div>
				<ul class="trcj-running-pages">
					<?php foreach ( $settings['pages'] as $path ) : ?>
						<li><span class="trcj-running-dot" aria-hidden="true"></span><code><?php echo esc_html( $path ); ?></code></li>
					<?php endforeach; ?>
				</ul>
				<p class="trcj-muted"><?php esc_html_e( 'This can take up to a minute. The page reloads with the result.', 'tracking-change-journal' ); ?></p>
			</div>
			<ul class="trcj-schedule">
				<?php if ( $state['time'] ) : ?>
					<li title="<?php echo esc_attr( trcj_time( $state['time'] ) ); ?>"><span class="dashicons dashicons-clock" aria-hidden="true"></span>
						<?php
						/* translators: %s: date and time. */
						echo esc_html( sprintf( __( 'Last check: %s.', 'tracking-change-journal' ), trcj_time( $state['time'] ) ) );
						?>
						<span class="trcj-ago">
							<?php
							/* translators: %s: time span such as "2 hours". */
							echo esc_html( sprintf( __( '(%s ago)', 'tracking-change-journal' ), human_time_diff( $state['time'] ) ) );
							?>
						</span>
					</li>
				<?php endif; ?>
				<?php if ( $next ) : ?>
					<li><span class="dashicons dashicons-update" aria-hidden="true"></span>
						<?php
						/* translators: %s: date and time. */
						echo esc_html( sprintf( __( 'Check after a change planned for %s.', 'tracking-change-journal' ), trcj_time( $next ) ) );
						?>
					</li>
				<?php endif; ?>
				<?php if ( $daily ) : ?>
					<li><span class="dashicons dashicons-calendar-alt" aria-hidden="true"></span>
						<?php
						/* translators: %s: date and time. */
						echo esc_html( sprintf( __( 'Next daily check: %s.', 'tracking-change-journal' ), trcj_time( $daily ) ) );
						?>
					</li>
				<?php endif; ?>
			</ul>
		</div>

		<?php trcj_overview( $state, $journal ); ?>
		<?php trcj_matrix( $state ); ?>

		<h2 class="trcj-h"><?php esc_html_e( 'Tags now', 'tracking-change-journal' ); ?></h2>
		<?php if ( ! $state['pages'] ) : ?>
			<div class="trcj-empty">
				<span class="dashicons dashicons-search" aria-hidden="true"></span>
				<p class="trcj-empty-title"><?php esc_html_e( 'No check yet.', 'tracking-change-journal' ); ?></p>
				<p><?php esc_html_e( 'Use "Check now" to load the pages and list the tags they contain. The first check is the baseline that later checks compare with.', 'tracking-change-journal' ); ?></p>
			</div>
		<?php endif; ?>
		<?php foreach ( TRCJ_Journal::group_pages( $state['pages'] ) as list( $paths, $page ) ) : ?>
			<section class="trcj-card trcj-page">
				<div class="trcj-page-head">
					<h3 class="trcj-path">
						<?php
						foreach ( $paths as $i => $path ) {
							echo ( $i ? ', ' : '' ) . '<a href="' . esc_url( TRCJ_Journal::origin() . $path ) . '">' . esc_html( $path ) . '</a>';
						}
						?>
					</h3>
					<?php trcj_status_badge( $page ); ?>
				</div>
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
									<td data-label="<?php esc_attr_e( 'Tag', 'tracking-change-journal' ); ?>">
										<strong><?php echo esc_html( $t['l'] ); ?></strong>
										<?php trcj_tag_badge( $t ); ?>
									</td>
									<td data-label="<?php esc_attr_e( 'ID', 'tracking-change-journal' ); ?>"><code><?php echo esc_html( $t['i'] ); ?></code></td>
									<td data-label="<?php esc_attr_e( 'How it is included', 'tracking-change-journal' ); ?>">
										<span class="trcj-chips">
										<?php foreach ( trcj_tag_notes( $t ) as $note ) : ?>
											<span class="trcj-chip <?php echo esc_attr( $note[1] ); ?>"><?php echo esc_html( $note[0] ); ?></span>
										<?php endforeach; ?>
										</span>
									</td>
								</tr>
							<?php endforeach; ?>
							</tbody>
						</table>
					</div>
				<?php endif; ?>
				<?php if ( TRCJ_Journal::ok( $page['status'] ) ) : ?>
					<div class="trcj-consent">
						<strong><?php esc_html_e( 'Consent mode defaults:', 'tracking-change-journal' ); ?></strong>
						<?php if ( ! $page['consent'] ) : ?>
							<span class="trcj-muted"><?php esc_html_e( 'none set in the page', 'tracking-change-journal' ); ?></span>
						<?php endif; ?>
						<?php foreach ( $page['consent'] as $set ) : ?>
							<span class="trcj-chips">
							<?php foreach ( trcj_consent_pairs( $set ) as $pair ) : ?>
								<span class="trcj-chip trcj-consent-<?php echo esc_attr( $pair[1] ); ?>"><?php echo esc_html( $pair[0] ); ?></span>
							<?php endforeach; ?>
							</span>
						<?php endforeach; ?>
					</div>
				<?php endif; ?>
			</section>
		<?php endforeach; ?>

		<div class="trcj-journal-head">
			<h2 class="trcj-h"><?php esc_html_e( 'Journal', 'tracking-change-journal' ); ?></h2>
			<?php if ( $journal ) : ?>
				<p class="trcj-actions">
					<button type="button" class="button" id="trcj-only-changed" aria-pressed="false" hidden><span class="dashicons dashicons-filter" aria-hidden="true"></span> <?php esc_html_e( 'Only entries with changes', 'tracking-change-journal' ); ?></button>
					<button type="button" class="button" id="trcj-csv"><span class="dashicons dashicons-download" aria-hidden="true"></span> <?php esc_html_e( 'Export as CSV', 'tracking-change-journal' ); ?></button>
				</p>
			<?php endif; ?>
		</div>
		<?php if ( ! $journal ) : ?>
			<div class="trcj-empty">
				<span class="dashicons dashicons-book-alt" aria-hidden="true"></span>
				<p class="trcj-empty-title"><?php esc_html_e( 'No entries yet.', 'tracking-change-journal' ); ?></p>
				<p><?php esc_html_e( 'Entries appear after checks: after updates, plugin and theme changes, and when a daily check finds a change.', 'tracking-change-journal' ); ?></p>
			</div>
		<?php else : ?>
			<div class="trcj-jtools" id="trcj-jtools" hidden>
				<span class="trcj-search-field">
					<label for="trcj-search" class="screen-reader-text"><?php esc_html_e( 'Search the journal', 'tracking-change-journal' ); ?></label>
					<span class="dashicons dashicons-search" aria-hidden="true"></span>
					<input type="search" id="trcj-search" class="regular-text" autocomplete="off" placeholder="<?php esc_attr_e( 'Search the journal: tag, ID, plugin …', 'tracking-change-journal' ); ?>" aria-describedby="trcj-search-key">
					<kbd id="trcj-search-key" title="<?php esc_attr_e( 'Press / to search', 'tracking-change-journal' ); ?>"><span aria-hidden="true">/</span><span class="screen-reader-text"><?php esc_html_e( 'Press / to search', 'tracking-change-journal' ); ?></span></kbd>
				</span>
				<span class="trcj-kinds" role="group" aria-label="<?php esc_attr_e( 'Kind of change', 'tracking-change-journal' ); ?>">
					<?php
					foreach ( array(
						'added'   => __( 'Added', 'tracking-change-journal' ),
						'removed' => __( 'Removed', 'tracking-change-journal' ),
						'consent' => __( 'Consent', 'tracking-change-journal' ),
						'bad'     => __( 'Problems', 'tracking-change-journal' ),
						'parts'   => __( 'Plugins and themes', 'tracking-change-journal' ),
					) as $kind_key => $kind_label ) :
						?>
						<button type="button" class="trcj-kind trcj-kind-<?php echo esc_attr( $kind_key ); ?>" data-kind="<?php echo esc_attr( $kind_key ); ?>" aria-pressed="false" hidden><?php echo esc_html( $kind_label ); ?> <span class="trcj-kind-n"></span></button>
					<?php endforeach; ?>
				</span>
			</div>
			<p id="trcj-journal-count" class="trcj-muted" aria-live="polite"></p>
			<ol class="trcj-journal" id="trcj-journal">
				<?php foreach ( array_slice( $journal, 0, 50 ) as $entry ) : ?>
					<li class="<?php echo esc_attr( $entry['changed'] ? 'trcj-changed' : 'trcj-unchanged' ); ?>" data-day="<?php echo esc_attr( wp_date( 'Y-m-d', (int) $entry['time'] ) ); ?>">
						<span class="trcj-dot dashicons dashicons-<?php echo esc_attr( trcj_reason_icon( $entry['reason'] ) ); ?>" aria-hidden="true"></span>
						<div class="trcj-when">
							<span class="trcj-when-main">
								<strong><?php echo esc_html( trcj_time( $entry['time'] ) ); ?></strong>
								<span class="trcj-reason"><?php echo esc_html( TRCJ_Journal::reason_label( $entry['reason'] ) ); ?></span>
								<?php if ( $entry['changed'] ) : ?>
									<span class="trcj-flag"><?php esc_html_e( 'Tracking changed', 'tracking-change-journal' ); ?></span>
								<?php endif; ?>
							</span>
							<button type="button" class="button-link trcj-copy" hidden><span class="dashicons dashicons-clipboard" aria-hidden="true"></span><span class="trcj-copy-label"><?php esc_html_e( 'Copy as text', 'tracking-change-journal' ); ?></span></button>
						</div>
						<?php if ( $entry['components'] ) : ?>
							<ul class="trcj-components">
								<?php foreach ( $entry['components'] as $c ) : ?>
									<li><span class="dashicons dashicons-<?php echo esc_attr( trcj_component_icon( $c ) ); ?>" aria-hidden="true"></span> <?php echo esc_html( TRCJ_Journal::describe_component( $c ) ); ?></li>
								<?php endforeach; ?>
							</ul>
						<?php endif; ?>
						<?php foreach ( TRCJ_Journal::group_pages( $entry['pages'] ) as list( $paths, $changes ) ) : ?>
							<div class="trcj-path"><?php echo esc_html( implode( ', ', $paths ) ); ?></div>
							<ul class="trcj-changes">
								<?php foreach ( $changes as $c ) : ?>
									<?php $kind = trcj_change_kind( $c ); ?>
									<li class="trcj-change-<?php echo esc_attr( $kind[0] ); ?>"><span class="dashicons dashicons-<?php echo esc_attr( $kind[1] ); ?>" aria-hidden="true"></span> <?php echo esc_html( TRCJ_Journal::describe_page( $c ) ); ?></li>
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

		<h2 class="trcj-h"><?php esc_html_e( 'Settings', 'tracking-change-journal' ); ?></h2>
		<form method="post" action="<?php echo esc_url( $action ); ?>" class="trcj-card trcj-settings">
			<input type="hidden" name="action" value="trcj_save">
			<?php wp_nonce_field( 'trcj_save' ); ?>
			<p>
				<label for="trcj-pages"><?php esc_html_e( 'Pages to check, one address or path of this site per line (up to ten). Add the pages that load different tags, for example a checkout or a landing page.', 'tracking-change-journal' ); ?></label><br>
				<textarea id="trcj-pages" name="trcj_pages" rows="5" class="large-text code" aria-describedby="trcj-pages-count"><?php echo esc_textarea( implode( "\n", $settings['pages'] ) ); ?></textarea>
				<span id="trcj-pages-count" class="trcj-counter" aria-live="polite"></span>
			</p>
			<p>
				<label>
					<input type="checkbox" name="trcj_email" value="1" <?php checked( $settings['email'] ); ?>>
					<?php
					/* translators: %s: email address. */
					echo esc_html( sprintf( __( 'Send an email to %s when a check finds a change on a checked page: tracking tags, consent defaults, or a page that stops loading or answers with another HTTP status.', 'tracking-change-journal' ), get_option( 'admin_email' ) ) );
					?>
				</label>
			</p>
			<?php submit_button( __( 'Save settings', 'tracking-change-journal' ), 'primary', 'submit', false ); ?>
		</form>

		<?php if ( $journal ) : ?>
			<div class="trcj-card trcj-danger">
				<div>
					<strong><?php esc_html_e( 'Delete journal', 'tracking-change-journal' ); ?></strong>
					<p class="trcj-muted"><?php esc_html_e( 'Removes all journal entries. The result of the last check stays, so the next check still compares with it.', 'tracking-change-journal' ); ?></p>
				</div>
				<form method="post" action="<?php echo esc_url( $action ); ?>" id="trcj-clear">
					<input type="hidden" name="action" value="trcj_clear">
					<?php wp_nonce_field( 'trcj_clear' ); ?>
					<?php submit_button( __( 'Delete journal', 'tracking-change-journal' ), 'delete', 'submit', false ); ?>
				</form>
			</div>
		<?php endif; ?>

		<details class="trcj-how trcj-limits">
			<summary><?php esc_html_e( 'Limits', 'tracking-change-journal' ); ?></summary>
			<p><?php esc_html_e( 'The check reads the HTML your server delivers. Tags that a Tag Manager container or another script adds later in the browser are not visible; for a container, its ID and how it is loaded are recorded. "Waits for consent or a loader script" means the code is in the page but marked so that it does not run on load, as consent tools and script delay features do.', 'tracking-change-journal' ); ?></p>
			<p><?php esc_html_e( 'Only tag names, IDs, loader host names, consent defaults, HTTP status codes and plugin, theme and WordPress versions are stored. No page content, cookies or visitor data. The plugin requests pages of this site only and follows redirects only within this site.', 'tracking-change-journal' ); ?></p>
		</details>
	</div>
	<?php
}

/**
 * Key figures of the last check and a chart of the journal over the last 30 days.
 *
 * @param array $state   State of the last check.
 * @param array $journal Journal entries, newest first.
 */
function trcj_overview( $state, $journal ) {
	if ( ! $state['pages'] && ! $journal ) {
		return;
	}
	$tags   = array();
	$held   = array();
	$dups   = array();
	$broken = 0;
	foreach ( $state['pages'] as $page ) {
		if ( ! TRCJ_Journal::ok( $page['status'] ) ) {
			++$broken;
		}
		foreach ( $page['tags'] as $key => $t ) {
			$tags[ $key ] = true;
			if ( ! TRCJ_Detector::active( $t ) ) {
				$held[ $key ] = true;
			}
			if ( TRCJ_Detector::duplicate( $t ) ) {
				$dups[ $key ] = true;
			}
		}
	}
	$tiles = array(
		array(
			count( $state['pages'] ),
			/* translators: %d: number of pages that did not load. */
			$broken ? sprintf( _n( 'Pages checked, %d not loaded', 'Pages checked, %d not loaded', $broken, 'tracking-change-journal' ), $broken ) : __( 'Pages checked', 'tracking-change-journal' ),
			$broken ? 'bad' : 'ok',
		),
		array( count( $tags ), __( 'Tracking tags', 'tracking-change-journal' ), 'info' ),
		array( count( $held ), __( 'Wait for consent or a loader script', 'tracking-change-journal' ), $held ? 'held' : 'ok' ),
		array( count( $dups ), __( 'Loaded or configured more than once', 'tracking-change-journal' ), $dups ? 'bad' : 'ok' ),
	);
	?>
	<div class="trcj-overview">
		<div class="trcj-tiles">
			<?php foreach ( $tiles as $tile ) : ?>
				<div class="trcj-tile trcj-tile-<?php echo esc_attr( $tile[2] ); ?>">
					<span class="trcj-tile-n" data-count="<?php echo esc_attr( $tile[0] ); ?>"><?php echo esc_html( number_format_i18n( $tile[0] ) ); ?></span>
					<span class="trcj-tile-l"><?php echo esc_html( $tile[1] ); ?></span>
				</div>
			<?php endforeach; ?>
		</div>
		<?php trcj_activity_chart( $journal ); ?>
	</div>
	<?php
}

/**
 * Bar chart of the journal entries per day over the last 30 days, drawn as inline SVG. The same numbers are in the
 * text summary and in the title of every bar.
 *
 * @param array $journal Journal entries, newest first.
 */
function trcj_activity_chart( $journal ) {
	$days  = 30;
	$bins  = array();
	for ( $i = $days - 1; $i >= 0; $i-- ) {
		$bins[ wp_date( 'Y-m-d', time() - $i * DAY_IN_SECONDS ) ] = array( 0, 0 );
	}
	foreach ( $journal as $entry ) {
		$day = wp_date( 'Y-m-d', (int) $entry['time'] );
		if ( isset( $bins[ $day ] ) ) {
			++$bins[ $day ][0];
			if ( $entry['changed'] ) {
				++$bins[ $day ][1];
			}
		}
	}
	$total   = array_sum( wp_list_pluck( $bins, 0 ) );
	$changed = array_sum( wp_list_pluck( $bins, 1 ) );
	$max     = max( 1, max( wp_list_pluck( $bins, 0 ) ) );
	$width   = 600;
	$height  = 110;
	$step    = $width / $days;
	$bar     = $step - 4;
	/* translators: 1: number of checks, 2: number of checks with changes. */
	$summary = sprintf( __( 'Last 30 days: %1$d journal entries, %2$d with tracking changes.', 'tracking-change-journal' ), $total, $changed );
	$recent  = array_slice( $bins, -7, 7, true );
	/* translators: 1: number of checks, 2: number of checks with changes. */
	$summary7 = sprintf( __( 'Last 7 days: %1$d journal entries, %2$d with tracking changes.', 'tracking-change-journal' ), array_sum( wp_list_pluck( $recent, 0 ) ), array_sum( wp_list_pluck( $recent, 1 ) ) );
	?>
	<figure class="trcj-card trcj-chart" data-summary30="<?php echo esc_attr( $summary ); ?>" data-summary7="<?php echo esc_attr( $summary7 ); ?>" data-width="<?php echo esc_attr( $width ); ?>" data-step="<?php echo esc_attr( round( $step, 4 ) ); ?>">
		<figcaption>
			<strong><?php esc_html_e( 'Journal activity', 'tracking-change-journal' ); ?></strong>
			<span class="trcj-muted trcj-chart-summary"><?php echo esc_html( $summary ); ?></span>
			<span class="trcj-range" role="group" aria-label="<?php esc_attr_e( 'Period', 'tracking-change-journal' ); ?>" hidden>
				<button type="button" class="trcj-range-button" data-days="7" aria-pressed="false"><?php esc_html_e( '7 days', 'tracking-change-journal' ); ?></button>
				<button type="button" class="trcj-range-button" data-days="30" aria-pressed="true"><?php esc_html_e( '30 days', 'tracking-change-journal' ); ?></button>
			</span>
		</figcaption>
		<svg viewBox="0 0 <?php echo esc_attr( $width ); ?> <?php echo esc_attr( $height + 18 ); ?>" role="img" aria-label="<?php echo esc_attr( $summary ); ?>" preserveAspectRatio="none" class="trcj-bars">
			<line x1="0" y1="<?php echo esc_attr( $height ); ?>" x2="<?php echo esc_attr( $width ); ?>" y2="<?php echo esc_attr( $height ); ?>" class="trcj-axis" />
			<?php
			$i = 0;
			foreach ( $bins as $day => $n ) :
				$x     = round( $i * $step + 2, 2 );
				$h_all = $n[0] ? max( 4, round( $n[0] / $max * ( $height - 8 ), 2 ) ) : 0;
				$h_chg = $n[1] ? max( 4, round( $n[1] / $max * ( $height - 8 ), 2 ) ) : 0;
				/* translators: 1: date, 2: number of journal entries, 3: number of entries with changes. */
				$label = sprintf( __( '%1$s: %2$d entries, %3$d with changes', 'tracking-change-journal' ), wp_date( get_option( 'date_format' ), strtotime( $day . ' 12:00:00' ) ), $n[0], $n[1] );
				?>
				<g class="trcj-day" data-day="<?php echo esc_attr( $day ); ?>" data-label="<?php echo esc_attr( $label ); ?>" data-date="<?php echo esc_attr( wp_date( get_option( 'date_format' ), strtotime( $day . ' 12:00:00' ) ) ); ?>" data-n="<?php echo esc_attr( $n[0] ); ?>">
					<title><?php echo esc_html( $label ); ?></title>
					<rect x="<?php echo esc_attr( $x ); ?>" y="0" width="<?php echo esc_attr( round( $bar, 2 ) ); ?>" height="<?php echo esc_attr( $height ); ?>" class="trcj-hit" />
					<?php if ( $h_all ) : ?>
						<rect x="<?php echo esc_attr( $x ); ?>" y="<?php echo esc_attr( $height - $h_all ); ?>" width="<?php echo esc_attr( round( $bar, 2 ) ); ?>" height="<?php echo esc_attr( $h_all ); ?>" rx="2" class="trcj-bar-all" />
					<?php endif; ?>
					<?php if ( $h_chg ) : ?>
						<rect x="<?php echo esc_attr( $x ); ?>" y="<?php echo esc_attr( $height - $h_chg ); ?>" width="<?php echo esc_attr( round( $bar, 2 ) ); ?>" height="<?php echo esc_attr( $h_chg ); ?>" rx="2" class="trcj-bar-changed" />
					<?php endif; ?>
				</g>
				<?php
				++$i;
			endforeach;
			?>
		</svg>
		<div class="trcj-chart-axis" aria-hidden="true">
			<span data-d30="<?php echo esc_attr( wp_date( 'j M', time() - ( $days - 1 ) * DAY_IN_SECONDS ) ); ?>" data-d7="<?php echo esc_attr( wp_date( 'j M', time() - 6 * DAY_IN_SECONDS ) ); ?>"><?php echo esc_html( wp_date( 'j M', time() - ( $days - 1 ) * DAY_IN_SECONDS ) ); ?></span>
			<span data-d30="<?php echo esc_attr( wp_date( 'j M', time() - 14 * DAY_IN_SECONDS ) ); ?>" data-d7="<?php echo esc_attr( wp_date( 'j M', time() - 3 * DAY_IN_SECONDS ) ); ?>"><?php echo esc_html( wp_date( 'j M', time() - 14 * DAY_IN_SECONDS ) ); ?></span>
			<span><?php esc_html_e( 'Today', 'tracking-change-journal' ); ?></span>
		</div>
		<ul class="trcj-legend" aria-hidden="true">
			<li><span class="trcj-swatch trcj-swatch-changed"></span><?php esc_html_e( 'With tracking changes', 'tracking-change-journal' ); ?></li>
			<li><span class="trcj-swatch trcj-swatch-all"></span><?php esc_html_e( 'Without tracking changes', 'tracking-change-journal' ); ?></li>
		</ul>
	</figure>
	<?php
}

/**
 * Tags per page: one row per tag, one column per checked page, the state in each cell.
 * A real table, so screen readers can move by row and column; colours repeat the words.
 *
 * @param array $state State of the last check.
 */
function trcj_matrix( $state ) {
	$pages = $state['pages'];
	$tags  = array();
	foreach ( $pages as $page ) {
		foreach ( $page['tags'] as $key => $t ) {
			if ( ! isset( $tags[ $key ] ) ) {
				$tags[ $key ] = $t;
			}
		}
	}
	if ( count( $pages ) < 2 || ! $tags ) {
		return;
	}
	$states = array(
		'ok'   => array( __( 'Active', 'tracking-change-journal' ), 'yes' ),
		'held' => array( __( 'Waiting', 'tracking-change-journal' ), 'lock' ),
		'bad'  => array( __( 'More than once', 'tracking-change-journal' ), 'warning' ),
		'none' => array( __( 'Not on this page', 'tracking-change-journal' ), 'minus' ),
		'fail' => array( __( 'Page not loaded', 'tracking-change-journal' ), 'dismiss' ),
	);
	?>
	<section class="trcj-card trcj-matrix-card" aria-labelledby="trcj-matrix-title">
		<h2 class="trcj-matrix-title" id="trcj-matrix-title"><span class="dashicons dashicons-grid-view" aria-hidden="true"></span> <?php esc_html_e( 'Tags per page', 'tracking-change-journal' ); ?></h2>
		<p class="trcj-muted"><?php esc_html_e( 'A gap in a row shows a page where a tag is missing, for example a checkout without the conversion tag.', 'tracking-change-journal' ); ?></p>
		<div class="trcj-matrix-scroll" tabindex="0" role="region" aria-labelledby="trcj-matrix-title">
			<table class="trcj-matrix">
				<thead>
					<tr>
						<th scope="col" class="trcj-matrix-corner"><?php esc_html_e( 'Tag', 'tracking-change-journal' ); ?></th>
						<?php foreach ( array_keys( $pages ) as $path ) : ?>
							<th scope="col" class="trcj-matrix-page" title="<?php echo esc_attr( $path ); ?>"><span><?php echo esc_html( $path ); ?></span></th>
						<?php endforeach; ?>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $tags as $key => $t ) : ?>
						<tr>
							<th scope="row"><strong><?php echo esc_html( $t['l'] ); ?></strong> <code><?php echo esc_html( $t['i'] ); ?></code></th>
							<?php foreach ( $pages as $path => $page ) : ?>
								<?php
								if ( ! TRCJ_Journal::ok( $page['status'] ) ) {
									$cell = 'fail';
								} elseif ( ! isset( $page['tags'][ $key ] ) ) {
									$cell = 'none';
								} elseif ( TRCJ_Detector::duplicate( $page['tags'][ $key ] ) ) {
									$cell = 'bad';
								} elseif ( TRCJ_Detector::active( $page['tags'][ $key ] ) ) {
									$cell = 'ok';
								} else {
									$cell = 'held';
								}
								?>
								<td class="trcj-cell trcj-cell-<?php echo esc_attr( $cell ); ?>" title="<?php echo esc_attr( $t['l'] . ' ' . $t['i'] . ' – ' . $path . ': ' . $states[ $cell ][0] ); ?>">
									<span class="trcj-cell-mark"><span class="dashicons dashicons-<?php echo esc_attr( $states[ $cell ][1] ); ?>" aria-hidden="true"></span></span>
									<span class="screen-reader-text"><?php echo esc_html( $states[ $cell ][0] ); ?></span>
								</td>
							<?php endforeach; ?>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<ul class="trcj-matrix-legend" aria-hidden="true">
			<?php foreach ( $states as $cell => $info ) : ?>
				<li><span class="trcj-cell-mark trcj-cell-<?php echo esc_attr( $cell ); ?>"><span class="dashicons dashicons-<?php echo esc_attr( $info[1] ); ?>"></span></span><?php echo esc_html( $info[0] ); ?></li>
			<?php endforeach; ?>
		</ul>
	</section>
	<?php
}

/**
 * Badge with the HTTP answer of a checked page.
 *
 * @param array $page Page result.
 */
function trcj_status_badge( $page ) {
	if ( TRCJ_Journal::ok( $page['status'] ) ) {
		/* translators: %d: HTTP status. */
		echo '<span class="trcj-pill trcj-pill-ok"><span class="dashicons dashicons-yes-alt" aria-hidden="true"></span>' . esc_html( sprintf( __( 'HTTP %d', 'tracking-change-journal' ), $page['status'] ) ) . '</span>';
		return;
	}
	$text = $page['status'] ? sprintf(
		/* translators: %d: HTTP status. */
		__( 'HTTP %d', 'tracking-change-journal' ),
		$page['status']
	) : __( 'Not loaded', 'tracking-change-journal' );
	echo '<span class="trcj-pill trcj-pill-bad"><span class="dashicons dashicons-warning" aria-hidden="true"></span>' . esc_html( $text ) . '</span>';
}

/**
 * Badge with the state of a tag: active, waiting, or more than once.
 *
 * @param array $t Tag entry.
 */
function trcj_tag_badge( $t ) {
	if ( TRCJ_Detector::duplicate( $t ) ) {
		echo '<span class="trcj-pill trcj-pill-bad">' . esc_html__( 'More than once', 'tracking-change-journal' ) . '</span>';
	} elseif ( TRCJ_Detector::active( $t ) ) {
		echo '<span class="trcj-pill trcj-pill-ok">' . esc_html__( 'Active', 'tracking-change-journal' ) . '</span>';
	} else {
		echo '<span class="trcj-pill trcj-pill-held">' . esc_html__( 'Waiting', 'tracking-change-journal' ) . '</span>';
	}
}

/**
 * One stored set of consent defaults as pairs with a state for colouring.
 *
 * @param string $set Stored defaults, for example "ad_storage=denied, analytics_storage=granted".
 * @return array List of [text, state]: granted, denied or other.
 */
function trcj_consent_pairs( $set ) {
	if ( '?' === $set ) {
		return array( array( TRCJ_Journal::consent_text( $set ), 'other' ) );
	}
	$out = array();
	foreach ( explode( ', ', $set ) as $pair ) {
		$value = substr( (string) strrchr( $pair, '=' ), 1 );
		$out[] = array( $pair, in_array( $value, array( 'granted', 'denied' ), true ) ? $value : 'other' );
	}
	return $out;
}

/**
 * Dashicon for the reason of a check.
 *
 * @param string $reason Reason key.
 * @return string
 */
function trcj_reason_icon( $reason ) {
	$icons = array(
		'manual'     => 'admin-users',
		'change'     => 'update',
		'activation' => 'controls-play',
		'settings'   => 'admin-generic',
	);
	return isset( $icons[ $reason ] ) ? $icons[ $reason ] : 'calendar-alt';
}

/**
 * Dashicon for a plugin, theme or core change.
 *
 * @param array $c Change.
 * @return string
 */
function trcj_component_icon( $c ) {
	if ( 'theme' === $c['t'] ) {
		return 'admin-appearance';
	}
	return 'core' === $c['t'] ? 'wordpress' : 'admin-plugins';
}

/**
 * Kind and dashicon of a page change, for colouring.
 *
 * @param array $c Change.
 * @return array [kind, dashicon].
 */
function trcj_change_kind( $c ) {
	switch ( $c['k'] ) {
		case 'added':
			return array( 'added', 'plus-alt2' );
		case 'removed':
			return array( 'removed', 'minus' );
		case 'held':
			return array( 'held', 'lock' );
		case 'released':
			return array( 'released', 'unlock' );
		case 'duplicate':
		case 'status':
		case 'error':
			return array( 'bad', 'warning' );
		case 'single':
			return array( 'good', 'yes' );
		case 'host':
			return array( 'info', 'randomize' );
		case 'first':
		case 'skipped':
			return array( 'info', 'info-outline' );
		default:
			return array( 'consent', 'shield' );
	}
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
	$email = isset( $_POST['trcj_email'] ) && is_string( $_POST['trcj_email'] ) && '1' === sanitize_text_field( wp_unslash( $_POST['trcj_email'] ) );
	TRCJ_Journal::save_settings( $pages, $email );
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
