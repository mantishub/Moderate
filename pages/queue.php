<?php
/**
 * MantisBT - A PHP based bugtracking system
 *
 * MantisBT is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 2 of the License, or
 * (at your option) any later version.
 *
 * MantisBT is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with MantisBT.  If not, see <http://www.gnu.org/licenses/>.
 *
 * @copyright Copyright 2002  MantisBT Team - mantisbt-dev@lists.sourceforge.net
 */

require_api( 'access_api.php' );
require_api( 'bug_api.php' );
require_api( 'form_api.php' );
require_api( 'gpc_api.php' );
require_api( 'helper_api.php' );
require_api( 'html_api.php' );
require_api( 'print_api.php' );
require_api( 'project_api.php' );
require_api( 'string_api.php' );
require_api( 'user_api.php' );

use Mantis\Exceptions\ClientException;

# Load plugin API
plugin_require_api( 'files/moderate_api.php' );

# Auto-cleanup old moderated entries (30+ days)
moderate_queue_cleanup();

# Get the view parameter (pending or history)
$f_view = gpc_get_string( 'view', 'pending' );

# Get the moderated parameter (for pending view)
$f_show_moderated = gpc_get_int( 'moderated', 0 ) === 1;

# Get the count for pending view to display in title
# Note: moderate_queue_get_pending handles project access filtering internally
$t_queue_count = null;
if( $f_view === 'pending' ) {
	$t_result = moderate_queue_get_pending( null, $f_show_moderated );
	$t_queue_count = $t_result['total_count'];

	# If no items and count is 0, user may not have access - deny access
	if( $t_queue_count === 0 && empty( $t_result['items'] ) ) {
		# Check if this is due to lack of access vs. empty queue
		# by attempting to verify user has moderation access to at least one project
		$t_user_id = auth_get_current_user_id();
		$t_moderate_threshold = plugin_config_get( 'moderate_threshold' );
		$t_accessible_projects = access_project_array_filter( $t_moderate_threshold, null, $t_user_id );

		if( empty( $t_accessible_projects ) ) {
			access_denied();
		}
	}
}

layout_page_header( plugin_lang_get( 'queue_title' ) );
layout_page_begin();
?>

<div class="col-md-12 col-xs-12">
<div class="space-10"></div>

<!-- Tab navigation -->
<div class="widget-box widget-color-blue2">
<div class="widget-header widget-header-small">
	<h4 class="widget-title lighter">
		<?php print_icon( 'fa-check-circle', 'ace-icon' ); ?>
		<?php echo plugin_lang_get( 'queue_title' ) ?>
		<?php if( $t_queue_count !== null ): ?>
			<span class="badge"><?php echo $t_queue_count ?></span>
		<?php endif; ?>
	</h4>
	<?php if( $f_view === 'pending' ): ?>
	<div class="widget-toolbar">
		<?php
		$t_toggle_moderated = $f_show_moderated ? 0 : 1;
		$t_toggle_url = plugin_page( 'queue', true ) . '&view=pending&moderated=' . $t_toggle_moderated;
		$t_toggle_text = $f_show_moderated ? plugin_lang_get( 'hide_moderated' ) : plugin_lang_get( 'show_moderated' );
		?>
		<a href="<?php echo $t_toggle_url ?>" class="btn btn-xs btn-primary">
			<?php echo $t_toggle_text ?>
		</a>
	</div>
	<?php endif; ?>
</div>

<div class="widget-body">
<div class="widget-main">

<?php if( $f_view === 'pending' ): ?>
<!-- Pending Items View -->
<?php
# Reuse the result from earlier (already fetched for count)
$t_items = $t_result['items'];
$t_has_more = $t_result['has_more'];

if( empty( $t_items ) ) {
	echo '<div class="center">' . plugin_lang_get( 'queue_empty' ) . '</div>';
} else {
	if( $t_has_more ) {
		echo '<div class="alert alert-info">';
		echo '<i class="fa fa-info-circle"></i> ';
		echo plugin_lang_get( 'queue_limit_reached' );
		echo '</div>';
	}

	foreach( $t_items as $t_item ) {
		$t_data = $t_item['data'];
		$t_type = $t_item['type'];

		# Get title based on type
		if( $t_type === 'issue' ) {
			# Data is already in JSON/array format from IssueAddCommand
			$t_title = string_display_line( $t_data['summary'] );

			# Data is already in the right format for display
			$t_data_array = $t_data;
		} else {
			# Data is already in JSON/array format from IssueNoteAddCommand
			# For notes, get the parent bug and format title with hyperlinked issue id
			# Handle case where parent bug was deleted
			if( bug_exists( $t_item['bug_id'] ) ) {
				$t_bug = bug_get( $t_item['bug_id'] );
				$t_bug_id_padded = bug_format_id( $t_item['bug_id'] );
				$t_bug_url = string_get_bug_view_url( $t_item['bug_id'] );
				$t_title = '<a href="' . $t_bug_url . '">' . $t_bug_id_padded . '</a>: ' . string_display_line( $t_bug->summary );
			} else {
				# Parent bug was deleted - show issue ID without link
				$t_bug_id_padded = bug_format_id( $t_item['bug_id'] );
				$t_title = $t_bug_id_padded;
			}

			# Data is already in the right format for display
			$t_data_array = $t_data;
		}

		$t_json = json_encode( $t_data_array, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );

		# Get date format
		$t_date_format = config_get( 'normal_date_format' );

		# Check if item is moderated (not pending)
		$t_is_moderated = $t_item['status'] != MODERATE_STATUS_PENDING;

		# Check if approve button should be shown (not for already approved items)
		$t_show_approve = $t_item['status'] != MODERATE_STATUS_APPROVED;

		# Check if reject button should be shown (not for already approved or rejected items)
		$t_show_reject = $t_item['status'] != MODERATE_STATUS_APPROVED && $t_item['status'] != MODERATE_STATUS_REJECTED;

		# Check if user has manage users access for spam button
		$t_show_spam = access_has_global_level( config_get( 'manage_user_threshold' ) );
		# Don't show spam button for already approved items
		$t_show_spam = $t_show_spam && $t_item['status'] != MODERATE_STATUS_APPROVED;
?>
	<div class="widget-box widget-color-blue2" style="margin-bottom: 15px;">
		<div class="widget-header widget-header-small">
			<h4 class="widget-title lighter">
				<?php
					# Show appropriate icon based on type
					if( $t_type === 'issue' ) {
						print_icon( 'fa-bug', 'ace-icon' );
					} else {
						print_icon( 'fa-comment', 'ace-icon' );
					}
				?>
				<?php echo $t_title ?>
			</h4>
		</div>
		<div class="widget-body">
			<div class="widget-main">
				<div style="margin-bottom: 10px;">
					<strong><?php echo plugin_lang_get( 'queue_type' ) ?>:</strong>
					<span class="label label-info"><?php echo plugin_lang_get( 'queue_type_' . $t_type ) ?></span>
				</div>
				<div style="margin-bottom: 10px;">
					<strong><?php echo plugin_lang_get( 'queue_status' ) ?>:</strong>
					<span class="label label-<?php
						switch( $t_item['status'] ) {
							case MODERATE_STATUS_APPROVED:
								echo 'success';
								break;
							case MODERATE_STATUS_SPAM:
								echo 'warning';
								break;
							case MODERATE_STATUS_PENDING:
								echo 'info';
								break;
							default:
								echo 'danger';
								break;
						}
					?>"><?php echo moderate_queue_get_status_name( $t_item['status'] ) ?></span>
				</div>
				<div style="margin-bottom: 10px;">
					<strong><?php echo plugin_lang_get( 'queue_date_submitted' ) ?>:</strong>
					<?php echo date( $t_date_format, $t_item['date_submitted'] ) ?>
				</div>
				<div style="margin-bottom: 10px;">
					<strong><?php echo plugin_lang_get( 'queue_reporter' ) ?>:</strong>
					<?php echo prepare_user_name( $t_item['reporter_id'] ) ?>
				</div>
				<?php if( $t_is_moderated ): ?>
					<div style="margin-bottom: 10px;">
						<strong><?php echo plugin_lang_get( 'queue_moderator' ) ?>:</strong>
						<?php echo prepare_user_name( $t_item['moderator_id'] ) ?>
					</div>
					<div style="margin-bottom: 10px;">
						<strong><?php echo plugin_lang_get( 'queue_date_moderated' ) ?>:</strong>
						<?php echo date( $t_date_format, $t_item['date_moderated'] ) ?>
					</div>
				<?php endif; ?>
				<pre style="background-color: #f5f5f5; border: 1px solid #ddd; padding: 10px; border-radius: 4px; overflow-x: auto; max-height: 400px;"><?php echo htmlspecialchars( $t_json, ENT_QUOTES, 'UTF-8' ) ?></pre>
			</div>
			<div class="widget-toolbox padding-8 clearfix">
				<div class="pull-right">
				<?php if( $t_show_approve ): ?>
					<?php
						$t_action_args = [ 'id' => $t_item['id'] ];
						$t_approve_url = plugin_page( 'approve' );
						print_form_button( $t_approve_url, plugin_lang_get( 'approve' ), $t_action_args );
					?>
				<?php endif; ?>
				<?php if( $t_show_reject ): ?>
					<?php
						$t_action_args = [ 'id' => $t_item['id'] ];
						$t_reject_url = plugin_page( 'reject' );
						print_form_button( $t_reject_url, plugin_lang_get( 'reject' ), $t_action_args );
					?>
				<?php endif; ?>
				<?php if( $t_show_spam ): ?>
					<?php
						$t_action_args = [ 'id' => $t_item['id'] ];
						$t_spam_url = plugin_page( 'spam' );
						print_form_button( $t_spam_url, plugin_lang_get( 'spam' ), $t_action_args );
					?>
				<?php endif; ?>
					<?php
						$t_action_args = [ 'id' => $t_item['id'] ];
						$t_delete_url = plugin_page( 'delete' );
						print_form_button( $t_delete_url, plugin_lang_get( 'delete' ), $t_action_args );
					?>
				</div>
			</div>
		</div>
	</div>
<?php
	}
}
?>

<?php else: ?>
<!-- History View -->
<table class="table table-striped table-bordered table-condensed">
<thead>
	<tr>
		<th><?php echo plugin_lang_get( 'queue_type' ) ?></th>
		<th><?php echo plugin_lang_get( 'queue_project' ) ?></th>
		<th><?php echo plugin_lang_get( 'queue_reporter' ) ?></th>
		<th><?php echo plugin_lang_get( 'queue_summary' ) ?></th>
		<th><?php echo plugin_lang_get( 'queue_status' ) ?></th>
		<th><?php echo plugin_lang_get( 'queue_moderator' ) ?></th>
		<th><?php echo plugin_lang_get( 'queue_date_moderated' ) ?></th>
	</tr>
</thead>
<tbody>
<?php
# Note: moderate_queue_get_history handles project access filtering internally
$t_items = moderate_queue_get_history();

if( empty( $t_items ) ) {
	echo '<tr><td colspan="7" class="center">' . plugin_lang_get( 'history_empty' ) . '</td></tr>';
} else {
	foreach( $t_items as $t_item ) {
		$t_data = $t_item['data'];
		$t_type = $t_item['type'];

		# Get summary
		if( $t_type === 'issue' ) {
			# Data is now in JSON/array format
			$t_summary = string_display_line( $t_data['summary'] );
		} else {
			$t_summary = plugin_lang_get( 'queue_note_on_issue' ) . ' #' . $t_item['bug_id'];
		}

		# Status styling
		switch( $t_item['status'] ) {
			case MODERATE_STATUS_APPROVED:
				$t_status_class = 'success';
				break;
			case MODERATE_STATUS_SPAM:
				$t_status_class = 'warning';
				break;
			default:
				$t_status_class = 'danger';
				break;
		}

		# Get project name, handling deleted projects
		if( project_exists( $t_item['project_id'] ) ) {
			$t_project_name = project_get_name( $t_item['project_id'] );
		} else {
			$t_project_name = '@' . $t_item['project_id'];
		}

		echo '<tr>';
		echo '<td>' . plugin_lang_get( 'queue_type_' . $t_type ) . '</td>';
		echo '<td>' . string_display_line( $t_project_name ) . '</td>';
		echo '<td>' . string_display_line( user_get_name( $t_item['reporter_id'] ) ) . '</td>';
		echo '<td><strong>' . $t_summary . '</strong></td>';
		echo '<td><span class="label label-' . $t_status_class . '">' .
			 moderate_queue_get_status_name( $t_item['status'] ) . '</span></td>';
		echo '<td>' . string_display_line( user_get_name( $t_item['moderator_id'] ) ) . '</td>';
		echo '<td>' . date( config_get( 'normal_date_format' ), $t_item['date_moderated'] ) . '</td>';
		echo '</tr>';
	}
}
?>
</tbody>
</table>
<?php endif; ?>

</div>
</div>
</div>
</div>

<?php
layout_page_end();
