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

/**
 * Moderate API
 *
 * Provides functions for managing the moderation queue
 */

require_api( 'authentication_api.php' );
require_api( 'bug_api.php' );
require_api( 'bugnote_api.php' );
require_api( 'current_user_api.php' );
require_api( 'database_api.php' );
require_api( 'email_api.php' );

# Status constants - must match ModeratePlugin class constants
define( 'MODERATE_STATUS_PENDING', 0 );
define( 'MODERATE_STATUS_REJECTED', 1 );
define( 'MODERATE_STATUS_APPROVED', 2 );
define( 'MODERATE_STATUS_SPAM', 3 );

use Mantis\Exceptions\ClientException;

/**
 * Check if moderation should be bypassed for an issue
 *
 * @param integer $p_project_id Project ID
 * @param integer|null $p_user_id User ID (defaults to current user)
 * @return boolean True if moderation should be bypassed, false if item should be moderated
 */
function moderate_should_bypass_issue( $p_project_id, $p_user_id = null ) {
	# Get user ID if not specified
	if( $p_user_id === null ) {
		$p_user_id = auth_get_current_user_id();
	}

	# Check if user has bypass threshold
	if( access_has_project_level( plugin_config_get( 'moderate_bypass_threshold', null, false, null, 'Moderate' ), $p_project_id, $p_user_id ) ) {
		return true;
	}

	# User should be subject to moderation
	return false;
}

/**
 * Check if moderation should be bypassed for a note
 *
 * @param integer $p_issue_id Issue ID
 * @param integer|null $p_user_id User ID (defaults to current user)
 * @return boolean True if moderation should be bypassed, false if note should be moderated
 */
function moderate_should_bypass_note( $p_issue_id, $p_user_id = null ) {
	# Get user ID if not specified
	if( $p_user_id === null ) {
		$p_user_id = auth_get_current_user_id();
	}

	# Get project ID
	$t_project_id = bug_get_field( $p_issue_id, 'project_id' );

	# Check if user has bypass threshold
	if( access_has_project_level( plugin_config_get( 'moderate_bypass_threshold', null, false, null, 'Moderate' ), $t_project_id, $p_user_id ) ) {
		return true;
	}

	# Don't moderate notes if user is adding a note to their own issue
	$t_reporter_id = bug_get_field( $p_issue_id, 'reporter_id' );
	if( $t_reporter_id == $p_user_id ) {
		return true;
	}

	# User should be subject to moderation
	return false;
}

/**
 * Add an item to the moderation queue
 *
 * @param string $p_type Type of item ('issue' or 'note')
 * @param integer $p_bug_id Bug ID (for notes, the parent bug)
 * @param array $p_data Issue or note data array (command payload format)
 * @return integer Queue item ID
 */
function moderate_queue_add( $p_type, $p_bug_id, $p_data ) {
	# Check if user has exceeded moderation queue rate limit
	moderate_antispam_check();

	$t_queue_table = plugin_table( 'queue', 'Moderate' );

	# Encode data as JSON
	$t_data_json = json_encode( $p_data );

	# Get project ID
	if( $p_type === 'issue' ) {
		$t_project_id = $p_data['project']['id'];
	} else {
		$t_bug = bug_get( $p_bug_id );
		$t_project_id = $t_bug->project_id;
	}

	# Insert into queue
	$t_query = "INSERT INTO $t_queue_table
				(type, project_id, reporter_id, bug_id, data, date_submitted, status)
				VALUES
				(" . db_param() . ", " . db_param() . ", " . db_param() . ", " .
				db_param() . ", " . db_param() . ", " . db_param() . ", " . db_param() . ")";

	db_query(
		$t_query,
		array(
			$p_type,
			$t_project_id,
			auth_get_current_user_id(),
			$p_type === 'note' ? $p_bug_id : 0,
			$t_data_json,
			db_now(),
			MODERATE_STATUS_PENDING
		)
	);

	return db_insert_id( $t_queue_table );
}

/**
 * Get pending (and optionally moderated) items from the moderation queue
 * Automatically filters results based on user's moderation access per project
 *
 * @param integer|null $p_project_id Project ID filter (null for current project, ALL_PROJECTS for all accessible projects)
 * @param boolean $p_include_moderated Include moderated items (approved/rejected/spam)
 * @param integer|null $p_user_id User ID (defaults to current user)
 * @return array Array with 'items' and 'has_more' keys
 */
function moderate_queue_get_pending( $p_project_id = null, $p_include_moderated = false, $p_user_id = null ) {
	$t_queue_table = plugin_table( 'queue', 'Moderate' );
	$t_limit = 100;

	# Get user ID
	if( $p_user_id === null ) {
		$p_user_id = auth_get_current_user_id();
	}

	# Get moderation threshold
	$t_moderate_threshold = plugin_config_get( 'moderate_threshold', null, false, null, 'Moderate' );

	# Determine project filter based on selected project and user access
	if( $p_project_id === null ) {
		$p_project_id = helper_get_current_project();
	}

	# Build list of accessible project IDs
	if( $p_project_id == ALL_PROJECTS ) {
		# Get all projects user has moderation access to
		$t_project_ids = access_project_array_filter( $t_moderate_threshold, null, $p_user_id );

		# If user has no moderation access to any project, return empty
		if( empty( $t_project_ids ) ) {
			return array(
				'items' => array(),
				'has_more' => false,
				'total_count' => 0
			);
		}
	} else {
		# For specific project, check project-level access
		if( !access_has_project_level( $t_moderate_threshold, $p_project_id, $p_user_id ) ) {
			# User doesn't have access, return empty
			return array(
				'items' => array(),
				'has_more' => false,
				'total_count' => 0
			);
		}
		$t_project_ids = array( $p_project_id );
	}

	if( $p_include_moderated ) {
		# Get all items (pending and moderated)
		$t_where_clause = "WHERE 1=1";
		$t_params = array();
	} else {
		# Get only pending items
		$t_where_clause = "WHERE status = " . db_param();
		$t_params = array( MODERATE_STATUS_PENDING );
	}

	# Filter by accessible project IDs
	$t_where_clause .= " AND project_id IN (" . implode( ',', array_fill( 0, count( $t_project_ids ), db_param() ) ) . ")";
	$t_params = array_merge( $t_params, $t_project_ids );

	# Get total count first
	$t_count_query = "SELECT COUNT(*) as total FROM $t_queue_table $t_where_clause";
	$t_count_result = db_query( $t_count_query, $t_params );
	$t_total_count = db_result( $t_count_result );

	# Now get the limited items
	$t_query = "SELECT * FROM $t_queue_table $t_where_clause ORDER BY date_submitted DESC";
	$t_query .= " LIMIT " . ( $t_limit + 1 );

	$t_result = db_query( $t_query, $t_params );

	$t_items = array();
	$t_count = 0;
	while( $t_row = db_fetch_array( $t_result ) ) {
		$t_count++;
		# Only add up to the limit
		if( $t_count <= $t_limit ) {
			$t_row['data'] = json_decode( $t_row['data'], true );
			$t_items[] = $t_row;
		}
	}

	# If we got more than the limit, there are more items
	$t_has_more = $t_count > $t_limit;

	return array(
		'items' => $t_items,
		'has_more' => $t_has_more,
		'total_count' => $t_total_count
	);
}

/**
 * Count pending items in the moderation queue
 *
 * @param integer $p_project_id Project ID filter (null for all projects)
 * @return integer Count of pending items
 */
function moderate_queue_count_pending( $p_project_id = null, $p_user_id = null ) {
	$t_queue_table = plugin_table( 'queue', 'Moderate' );

	# Get user ID
	if( $p_user_id === null ) {
		$p_user_id = auth_get_current_user_id();
	}

	# Get moderation threshold
	$t_moderate_threshold = plugin_config_get( 'moderate_threshold', null, false, null, 'Moderate' );

	# Determine project filter based on selected project and user access
	if( $p_project_id === null ) {
		$p_project_id = helper_get_current_project();
	}

	# Build list of accessible project IDs
	if( $p_project_id == ALL_PROJECTS ) {
		# Get all projects user has moderation access to
		$t_project_ids = access_project_array_filter( $t_moderate_threshold, null, $p_user_id );

		# If user has no moderation access to any project, return 0
		if( empty( $t_project_ids ) ) {
			return 0;
		}
	} else {
		# For specific project, check project-level access
		if( !access_has_project_level( $t_moderate_threshold, $p_project_id, $p_user_id ) ) {
			# User doesn't have access, return 0
			return 0;
		}
		$t_project_ids = array( $p_project_id );
	}

	$t_query = "SELECT COUNT(*) FROM $t_queue_table WHERE status = " . db_param();
	$t_params = array( MODERATE_STATUS_PENDING );

	# Filter by accessible project IDs
	$t_query .= " AND project_id IN (" . implode( ',', array_fill( 0, count( $t_project_ids ), db_param() ) ) . ")";
	$t_params = array_merge( $t_params, $t_project_ids );

	$t_result = db_query( $t_query, $t_params );
	return db_result( $t_result );
}

/**
 * Get a queue item by ID
 *
 * @param integer $p_queue_id Queue item ID
 * @return array Queue item data
 */
function moderate_queue_get( $p_queue_id ) {
	$t_queue_table = plugin_table( 'queue', 'Moderate' );

	$t_query = "SELECT * FROM $t_queue_table WHERE id = " . db_param();
	$t_result = db_query( $t_query, array( $p_queue_id ) );

	if( !$t_row = db_fetch_array( $t_result ) ) {
		trigger_error( ERROR_PLUGIN_GENERIC, ERROR );
	}

	$t_row['data'] = json_decode( $t_row['data'], true );
	return $t_row;
}

/**
 * Approve a moderated item
 *
 * @param integer $p_queue_id Queue item ID
 * @return integer Created bug or note ID
 */
function moderate_queue_approve( $p_queue_id ) {
	$t_item = moderate_queue_get( $p_queue_id );

	# Validate that reporter still exists and is enabled
	if( !user_exists( $t_item['reporter_id'] ) || !user_is_enabled( $t_item['reporter_id'] ) ) {
		throw new ClientException(
			'Cannot approve: reporter no longer exists or is disabled.',
			ERROR_USER_BY_ID_NOT_FOUND,
			array( $t_item['reporter_id'] )
		);
	}

	# Validate that project still exists
	if( !project_exists( $t_item['project_id'] ) || !project_enabled( $t_item['project_id'] ) ) {
		throw new ClientException(
			'Cannot approve: project no longer exists or is disabled.',
			ERROR_PROJECT_NOT_FOUND,
			array( $t_item['project_id'] )
		);
	}

	# For notes, validate that parent bug still exists
	if( $t_item['type'] === 'note' ) {
		if( !bug_exists( $t_item['bug_id'] ) ) {
			throw new ClientException(
				'Cannot approve note: parent issue no longer exists',
				ERROR_BUG_NOT_FOUND,
				array( $t_item['bug_id'] )
			);
		}
	}

	# Impersonate the reporter for validation and creation
	# current_user_set() returns the old user ID
	$t_current_user = current_user_set( $t_item['reporter_id'] );

	try {
		if( $t_item['type'] === 'issue' ) {
			# Recreate the issue using IssueAddCommand
			# Data is already in command format (JSON from command payload)
			$t_command_data = array(
				'payload' => array(
					'issue' => $t_item['data']
				),
				'options' => array(
					'skip_moderation' => true,
				),
			);

			require_once( config_get_global( 'core_path' ) . 'commands/IssueAddCommand.php' );
			$t_command = new IssueAddCommand( $t_command_data );
			$t_result = $t_command->execute();
			$t_bug_id = $t_result['issue_id'];

			# Restore moderator user and clear bypass flag
			current_user_set( $t_current_user );

			# Update queue status with moderator info
			moderate_queue_update_status( $p_queue_id, MODERATE_STATUS_APPROVED );

			return $t_bug_id;
		} else {
			# Recreate the note using IssueNoteAddCommand
			# Data is already in command format (JSON from command payload)
			$t_bug_id = $t_item['bug_id'];

			$t_command_data = array(
				'query' => array(
					'issue_id' => $t_bug_id
				),
				'payload' => $t_item['data'],
				'options' => array(
					'skip_moderation' => true,
				),
			);

			require_once( config_get_global( 'core_path' ) . 'commands/IssueNoteAddCommand.php' );
			$t_command = new IssueNoteAddCommand( $t_command_data );
			$t_result = $t_command->execute();
			$t_note_id = $t_result['id'];

			# Restore moderator user and clear bypass flag
			current_user_set( $t_current_user );

			# Update queue status with moderator info
			moderate_queue_update_status( $p_queue_id, MODERATE_STATUS_APPROVED );

			return $t_note_id;
		}
	} catch( Exception $e ) {
		# Restore moderator user and clear bypass flag in case of error
		current_user_set( $t_current_user );
		throw $e;
	}
}

/**
 * Reject a moderated item
 *
 * @param integer $p_queue_id Queue item ID
 * @return void
 */
function moderate_queue_reject( $p_queue_id ) {
	# Get queue item before updating status
	$t_item = moderate_queue_get( $p_queue_id );

	# Update queue status with moderator info
	moderate_queue_update_status( $p_queue_id, MODERATE_STATUS_REJECTED );

	# Send notification to reporter if enabled
	if( plugin_config_get( 'notify_on_reject', null, false, null, 'Moderate' ) == ON ) {
		moderate_email_notify_rejection( $t_item );
	}
}

/**
 * Update the status of a queue item
 *
 * @param integer $p_queue_id Queue item ID
 * @param integer $p_status New status (MODERATE_STATUS_* constant)
 * @return void
 */
function moderate_queue_update_status( $p_queue_id, $p_status ) {
	$t_queue_table = plugin_table( 'queue', 'Moderate' );

	$t_query = "UPDATE $t_queue_table
				SET status = " . db_param() . ",
					moderator_id = " . db_param() . ",
					date_moderated = " . db_param() . "
				WHERE id = " . db_param();

	db_query( $t_query, array(
		$p_status,
		auth_get_current_user_id(),
		db_now(),
		$p_queue_id
	) );
}

/**
 * Get status name for display
 *
 * @param integer $p_status Status code
 * @return string Status name
 */
function moderate_queue_get_status_name( $p_status ) {
	switch( $p_status ) {
		case MODERATE_STATUS_PENDING:
			return plugin_lang_get( 'status_pending', 'Moderate' );
		case MODERATE_STATUS_REJECTED:
			return plugin_lang_get( 'status_rejected', 'Moderate' );
		case MODERATE_STATUS_APPROVED:
			return plugin_lang_get( 'status_approved', 'Moderate' );
		case MODERATE_STATUS_SPAM:
			return plugin_lang_get( 'status_spam', 'Moderate' );
		default:
			return plugin_lang_get( 'status_unknown', 'Moderate' );
	}
}

/**
 * Get moderation history (approved/rejected/spam items)
 * Automatically filters results based on user's moderation access per project
 *
 * @param integer|null $p_project_id Project ID filter (null for current project, ALL_PROJECTS for all accessible projects)
 * @param integer $p_limit Number of items to retrieve (default 50)
 * @param integer|null $p_user_id User ID (defaults to current user)
 * @return array Array of queue items
 */
function moderate_queue_get_history( $p_project_id = null, $p_limit = 50, $p_user_id = null ) {
	$t_queue_table = plugin_table( 'queue', 'Moderate' );

	# Get user ID
	if( $p_user_id === null ) {
		$p_user_id = auth_get_current_user_id();
	}

	# Get moderation threshold
	$t_moderate_threshold = plugin_config_get( 'moderate_threshold', null, false, null, 'Moderate' );

	# Determine project filter based on selected project and user access
	if( $p_project_id === null ) {
		$p_project_id = helper_get_current_project();
	}

	# Build list of accessible project IDs
	if( $p_project_id == ALL_PROJECTS ) {
		# Get all projects user has moderation access to
		$t_project_ids = access_project_array_filter( $t_moderate_threshold, null, $p_user_id );

		# If user has no moderation access to any project, return empty
		if( empty( $t_project_ids ) ) {
			return array();
		}
	} else {
		# For specific project, check project-level access
		if( !access_has_project_level( $t_moderate_threshold, $p_project_id, $p_user_id ) ) {
			# User doesn't have access, return empty
			return array();
		}
		$t_project_ids = array( $p_project_id );
	}

	$t_query = "SELECT * FROM $t_queue_table
				WHERE status IN (" . db_param() . ", " . db_param() . ", " . db_param() . ")";
	$t_params = array( MODERATE_STATUS_APPROVED, MODERATE_STATUS_REJECTED, MODERATE_STATUS_SPAM );

	# Filter by accessible project IDs
	$t_query .= " AND project_id IN (" . implode( ',', array_fill( 0, count( $t_project_ids ), db_param() ) ) . ")";
	$t_params = array_merge( $t_params, $t_project_ids );

	$t_query .= " ORDER BY date_moderated DESC LIMIT " . (int)$p_limit;

	$t_result = db_query( $t_query, $t_params );

	$t_items = array();
	while( $t_row = db_fetch_array( $t_result ) ) {
		$t_row['data'] = json_decode( $t_row['data'], true );
		$t_items[] = $t_row;
	}

	return $t_items;
}

/**
 * Mark a moderated item and all pending items from same user as spam
 * Also disables the reporter's user account
 *
 * @param integer $p_queue_id Queue item ID
 * @return integer Number of items marked as spam
 */
function moderate_queue_spam( $p_queue_id ) {
	# Get the queue item to find the reporter
	$t_item = moderate_queue_get( $p_queue_id );
	$t_reporter_id = $t_item['reporter_id'];

	$t_queue_table = plugin_table( 'queue', 'Moderate' );

	# Send notification before marking as spam (while user is still enabled)
	if( plugin_config_get( 'notify_on_spam', null, false, null, 'Moderate' ) == ON ) {
		moderate_email_notify_spam( $t_item );
	}

	# Mark all pending and non-approved items from this reporter as spam
	# This includes the current item regardless of its status (pending, rejected, or spam)
	$t_query = "UPDATE $t_queue_table
				SET status = " . db_param() . ",
					moderator_id = " . db_param() . ",
					date_moderated = " . db_param() . "
				WHERE reporter_id = " . db_param() . "
				AND status != " . db_param();

	db_query( $t_query, array(
		MODERATE_STATUS_SPAM,
		auth_get_current_user_id(),
		db_now(),
		$t_reporter_id,
		MODERATE_STATUS_APPROVED
	) );

	$t_spam_count = db_affected_rows();

	# Disable the reporter's user account so they can no longer sign in or report issues
	# Only if the user still exists
	if( user_exists( $t_reporter_id ) ) {
		user_set_field( $t_reporter_id, 'enabled', false );
	}

	# Return the number of items marked as spam
	return $t_spam_count;
}

/**
 * Delete a queue item
 *
 * @param integer $p_queue_id Queue item ID
 * @return void
 */
function moderate_queue_delete( $p_queue_id ) {
	$t_queue_table = plugin_table( 'queue', 'Moderate' );

	$t_query = "DELETE FROM $t_queue_table WHERE id = " . db_param();
	db_query( $t_query, array( $p_queue_id ) );
}

/**
 * Auto-delete old moderated entries (30+ days old)
 * Removes approved, rejected, and spam entries that were moderated 30+ days ago
 * Pending entries are never automatically deleted
 */
function moderate_queue_cleanup() {
	$t_queue_table = plugin_table( 'queue', 'Moderate' );

	# Calculate timestamp for 30 days ago
	$t_cutoff_date = db_now() - ( 30 * 24 * 60 * 60 );

	# Delete all moderated entries (approved, rejected, spam) that were moderated 30+ days ago
	# Pending entries are kept indefinitely
	$t_query = "DELETE FROM $t_queue_table
				WHERE status IN (" . db_param() . ", " . db_param() . ", " . db_param() . ")
				AND date_moderated < " . db_param();

	db_query( $t_query, array(
		MODERATE_STATUS_APPROVED,
		MODERATE_STATUS_REJECTED,
		MODERATE_STATUS_SPAM,
		$t_cutoff_date
	) );
}

/**
 * Delete all moderation entries for a deleted project
 * Called when a project is deleted to clean up orphaned moderation entries
 *
 * @param integer $p_project_id Project ID that was deleted
 */
function moderate_queue_delete_by_project( $p_project_id ) {
	$t_queue_table = plugin_table( 'queue', 'Moderate' );

	# Delete all moderation entries for this project
	$t_query = "DELETE FROM $t_queue_table WHERE project_id = " . db_param();
	db_query( $t_query, array( $p_project_id ) );
}

/**
 * Delete all moderation entries for a deleted user
 * Called when a user is deleted to clean up orphaned moderation entries
 *
 * @param integer $p_user_id User ID that was deleted
 */
function moderate_queue_delete_by_user( $p_user_id ) {
	$t_queue_table = plugin_table( 'queue', 'Moderate' );

	# Delete all moderation entries reported by this user
	$t_query = "DELETE FROM $t_queue_table WHERE reporter_id = " . db_param();
	db_query( $t_query, array( $p_user_id ) );
}

/**
 * Send email notification to reporter when their submission is rejected
 *
 * @param array $p_item Queue item data
 * @return void
 */
function moderate_email_notify_rejection( $p_item ) {
	# Check if email notifications are enabled globally
	if( OFF == config_get( 'enable_email_notification' ) ) {
		log_event( LOG_EMAIL_VERBOSE, 'email notifications disabled.' );
		return;
	}

	# Check if user is enabled
	if( !user_is_enabled( $p_item['reporter_id'] ) ) {
		log_event( LOG_EMAIL_VERBOSE, 'skipped rejection email for disabled user U' . $p_item['reporter_id'] );
		return;
	}

	# Get user email
	$t_email = user_get_email( $p_item['reporter_id'] );
	if( is_blank( $t_email ) ) {
		log_event( LOG_EMAIL_VERBOSE, 'skipped rejection email for U' . $p_item['reporter_id'] . ' (no email address).' );
		return;
	}

	# Push reporter's language
	lang_push( user_pref_get_language( $p_item['reporter_id'], $p_item['project_id'] ) );

	# Build email subject
	if( $p_item['type'] === 'issue' ) {
		$t_subject = plugin_lang_get( 'email_rejected_issue_subject', 'Moderate' );
		$t_body_intro = plugin_lang_get( 'email_rejected_issue_body', 'Moderate' );
	} else {
		$t_subject = plugin_lang_get( 'email_rejected_note_subject', 'Moderate' );
		$t_body_intro = plugin_lang_get( 'email_rejected_note_body', 'Moderate' );
	}

	# Build email body
	$t_date_format = config_get( 'normal_date_format' );
	$t_date = date( $t_date_format, $p_item['date_submitted'] );

	$t_body = $t_body_intro . "\n\n";
	$t_body .= lang_get( 'date_submitted' ) . ': ' . $t_date . "\n";

	# Include moderator name if configured
	if( plugin_config_get( 'include_moderator_in_emails', OFF ) ) {
		$t_moderator = user_get_name( auth_get_current_user_id() );
		$t_body .= plugin_lang_get( 'email_moderator', 'Moderate' ) . ': ' . $t_moderator . "\n";
	}

	$t_body .= "\n";

	# Add item details
	if( $p_item['type'] === 'issue' ) {
		$t_data = $p_item['data'];
		$t_body .= lang_get( 'summary' ) . ': ' . $t_data['summary'] . "\n\n";
		$t_body .= lang_get( 'description' ) . ":\n" . $t_data['description'] . "\n";
	} else {
		$t_data = $p_item['data'];
		$t_body .= plugin_lang_get( 'email_text', 'Moderate' ) . ":\n" . $t_data['text'] . "\n";
	}

	# Store email for sending
	$t_id = email_store( $t_email, $t_subject, $t_body );
	log_event( LOG_EMAIL_VERBOSE, 'queued rejection email ' . $t_id . ' for U' . $p_item['reporter_id'] );

	lang_pop();
}

/**
 * Send email notification to reporter when their submission is marked as spam
 *
 * @param array $p_item Queue item data
 * @return void
 */
function moderate_email_notify_spam( $p_item ) {
	# Check if email notifications are enabled globally
	if( OFF == config_get( 'enable_email_notification' ) ) {
		log_event( LOG_EMAIL_VERBOSE, 'email notifications disabled.' );
		return;
	}

	# Check if user is enabled (should be before we disable them)
	if( !user_is_enabled( $p_item['reporter_id'] ) ) {
		log_event( LOG_EMAIL_VERBOSE, 'skipped spam email for disabled user U' . $p_item['reporter_id'] );
		return;
	}

	# Get user email
	$t_email = user_get_email( $p_item['reporter_id'] );
	if( is_blank( $t_email ) ) {
		log_event( LOG_EMAIL_VERBOSE, 'skipped spam email for U' . $p_item['reporter_id'] . ' (no email address).' );
		return;
	}

	# Push reporter's language
	lang_push( user_pref_get_language( $p_item['reporter_id'], $p_item['project_id'] ) );

	# Build email subject
	if( $p_item['type'] === 'issue' ) {
		$t_subject = plugin_lang_get( 'email_spam_issue_subject', 'Moderate' );
	} else {
		$t_subject = plugin_lang_get( 'email_spam_note_subject', 'Moderate' );
	}

	# Build email body
	$t_date_format = config_get( 'normal_date_format' );
	$t_date = date( $t_date_format, $p_item['date_submitted'] );

	$t_body = plugin_lang_get( 'email_spam_body', 'Moderate' ) . "\n\n";
	$t_body .= lang_get( 'date_submitted' ) . ': ' . $t_date . "\n";

	# Include moderator name if configured
	if( plugin_config_get( 'include_moderator_in_emails', OFF ) ) {
		$t_moderator = user_get_name( auth_get_current_user_id() );
		$t_body .= plugin_lang_get( 'email_moderator', 'Moderate' ) . ': ' . $t_moderator . "\n";
	}

	# Store email for sending
	$t_id = email_store( $t_email, $t_subject, $t_body );
	log_event( LOG_EMAIL_VERBOSE, 'queued spam email ' . $t_id . ' for U' . $p_item['reporter_id'] );

	lang_pop();
}

/**
 * Check if user has exceeded moderation queue limits
 *
 * Similar to antispam_check() but checks pending moderation queue entries
 * instead of history events. Uses the same antispam configuration settings.
 *
 * @param integer|null $p_user_id User ID (defaults to current user)
 * @return void
 * @throws ClientException if user has exceeded the moderation queue limit
 */
function moderate_antispam_check( $p_user_id = null ) {
	# Get user ID if not specified
	if( $p_user_id === null ) {
		$p_user_id = auth_get_current_user_id();
	}

	# Get antispam configuration
	$t_antispam_max_event_count = config_get( 'antispam_max_event_count' );
	if( $t_antispam_max_event_count == 0 ) {
		return;
	}

	# Count pending moderation entries for this user
	$t_antispam_time_window_in_seconds = config_get( 'antispam_time_window_in_seconds' );
	$t_time_threshold = time() - $t_antispam_time_window_in_seconds;

	# Query to count pending moderation entries within time window
	$t_query = 'SELECT COUNT(*) FROM {plugin_Moderate_queue}
		WHERE reporter_id = ' . db_param() . '
		AND status = ' . db_param() . '
		AND date_submitted >= ' . db_param();
	$t_result = db_query( $t_query, array( $p_user_id, MODERATE_STATUS_PENDING, $t_time_threshold ) );
	$t_count = db_result( $t_result );

	# Allow one more entry before hitting the limit
	if( $t_count < $t_antispam_max_event_count ) {
		return;
	}

	throw new ClientException(
		"Hit moderation queue rate limit threshold",
		ERROR_SPAM_SUSPECTED,
		array( $t_antispam_max_event_count, $t_antispam_time_window_in_seconds )
	);
}
