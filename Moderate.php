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
 * Moderate Plugin
 *
 * This plugin enables moderation of new issues and notes before they are
 * submitted as real issues/notes and trigger email notifications.
 */
class ModeratePlugin extends MantisPlugin {
	/**
	 * Plugin registration
	 * @return void
	 */
	function register() {
		$this->name = plugin_lang_get( 'title' );
		$this->description = plugin_lang_get( 'description' );
		$this->page = 'config';

		$this->version = '1.0.0';
		$this->requires = array(
			# TODO: update to proper release when available
			'MantisCore' => '2.28.0-dev',
		);

		$this->author = 'Victor Boctor';
		$this->contact = 'support@mantishub.com';
		$this->url = 'https://www.mantishub.com';
	}

	/**
	 * Plugin initialization
	 * @return void
	 */
	function init() {
		plugin_require_api( 'files/moderate_api.php' );
	}

	/**
	 * Default plugin configuration
	 * @return array
	 */
	function config() {
		return array(
			# Who can approve/reject moderated items (e.g., MANAGER or higher)
			'moderate_threshold' => MANAGER,

			# Users at or above this access level bypass moderation
			'moderate_bypass_threshold' => DEVELOPER,

			# Send email notification to reporter when their submission is rejected
			'notify_on_reject' => ON,

			# Send email notification to reporter when their submission is marked as spam
			'notify_on_spam' => OFF,

			# Include moderator name in rejection/spam email notifications
			'include_moderator_in_emails' => OFF,
		);
	}

	/**
	 * Plugin schema
	 * @return array
	 */
	function schema() {
		return array(
			# Create moderation queue table
			array( 'CreateTableSQL', array( plugin_table( 'queue' ), "
				id					I		NOTNULL UNSIGNED AUTOINCREMENT PRIMARY,
				type				C(10)	NOTNULL DEFAULT 'issue',
				project_id			I		NOTNULL UNSIGNED,
				reporter_id			I		NOTNULL UNSIGNED,
				bug_id				I		NOTNULL UNSIGNED DEFAULT '0',
				data				XL		NOTNULL,
				date_submitted		I		NOTNULL UNSIGNED DEFAULT '1',
				status				I		NOTNULL UNSIGNED DEFAULT '0',
				moderator_id		I		NOTNULL UNSIGNED DEFAULT '0',
				date_moderated		I		NOTNULL UNSIGNED DEFAULT '1'
			", array( 'mysql' => 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4', 'pgsql' => 'WITHOUT OIDS' ) ) ),

			# Create index on status for faster queries
			array( 'CreateIndexSQL', array(
				'idx_moderate_status',
				plugin_table( 'queue' ),
				'status'
			) ),

			# Create index on project_id for filtering
			array( 'CreateIndexSQL', array(
				'idx_moderate_project',
				plugin_table( 'queue' ),
				'project_id'
			) ),
		);
	}

	/**
	 * Plugin hooks
	 * @return array
	 */
	function hooks() {
		return array(
			'EVENT_REPORT_BUG_FORM' => 'report_bug_form',
			'EVENT_REPORT_BUG_MODERATE' => 'report_bug_moderate',
			'EVENT_REPORT_BUG_MODERATE_CHECK' => 'report_bug_moderate_check',
			'EVENT_BUGNOTE_ADD_MODERATE' => 'bugnote_add_moderate',
			'EVENT_BUGNOTE_ADD_MODERATE_CHECK' => 'bugnote_add_moderate_check',
			'EVENT_MENU_MANAGE' => 'menu_manage',
			'EVENT_REST_API_ROUTES' => 'rest_api_routes',
			'EVENT_MANAGE_PROJECT_DELETE' => 'project_delete',
			'EVENT_MANAGE_USER_DELETE' => 'user_delete',
		);
	}

	/**
	 * Hook for report bug form - check if moderation will be needed
	 * @param string $p_event Event name
	 * @param integer $p_project_id Project ID
	 * @return void
	 */
	function report_bug_form( $p_event, $p_project_id ) {
		if( !moderate_should_bypass_issue( $p_project_id ) ) {
			# Show a notice that the issue will be moderated
			echo '<div class="alert alert-warning">';
			echo '<i class="fa fa-info-circle"></i> ';
			echo plugin_lang_get( 'moderation_notice_issue' );
			echo '</div>';
		}
	}

	/**
	 * Hook for moderating bug reports - intercepts issue creation
	 * @param string $p_event Event name
	 * @param array $p_issue_data Issue data array from command payload
	 * @return boolean True if moderated, false to allow normal creation
	 */
	function report_bug_moderate( $p_event, $p_issue_data ) {
		# Check if moderation should be bypassed
		if( moderate_should_bypass_issue( $p_issue_data['project']['id'] ) ) {
			return false;
		}

		# Store JSON data in moderation queue
		moderate_queue_add( 'issue', 0, $p_issue_data );

		# Return true to indicate issue was moderated
		return true;
	}

	/**
	 * Hook for checking if issue will be moderated - used to prevent file uploads
	 * @param string $p_event Event name
	 * @return boolean True if issue will be moderated, false otherwise
	 */
	function report_bug_moderate_check( $p_event ) {
		$t_project_id = helper_get_current_project();

		# Return true if moderation is needed (i.e., bypass is false)
		return !moderate_should_bypass_issue( $t_project_id );
	}

	/**
	 * Hook for moderating bugnotes - intercepts note creation
	 * @param string $p_event Event name
	 * @param integer $p_issue_id Issue ID to add the note to
	 * @param array $p_note_data Note data array from command payload
	 * @return boolean True if moderated, false to allow normal creation
	 */
	function bugnote_add_moderate( $p_event, $p_issue_id, $p_note_data ) {
		# Check if moderation should be bypassed
		if( moderate_should_bypass_note( $p_issue_id ) ) {
			return false;
		}

		# Store JSON data in moderation queue
		moderate_queue_add( 'note', $p_issue_id, $p_note_data );

		# Return true to indicate note was moderated
		return true;
	}

	/**
	 * Hook for checking if note will be moderated - used to prevent file uploads
	 * @param string $p_event Event name
	 * @param integer $p_issue_id Issue ID
	 * @return boolean True if note will be moderated, false otherwise
	 */
	function bugnote_add_moderate_check( $p_event, $p_issue_id ) {
		# Return true if moderation is needed (i.e., bypass is false)
		return !moderate_should_bypass_note( $p_issue_id );
	}

	/**
	 * Add menu item to manage section
	 * @param string $p_event Event name
	 * @return array
	 */
	function menu_manage( $p_event ) {
		# Check if plugin schema is installed before accessing database
		#if( !db_table_exists( plugin_table( 'queue' ) ) ) {
		#	return array();
		#}

		# Only show to users who can approve
		$t_project_id = helper_get_current_project();
		$t_user_id = auth_get_current_user_id();

		if( $t_project_id == ALL_PROJECTS ) {
			if( !access_has_global_level( plugin_config_get( 'moderate_threshold' ), $t_user_id ) ) {
				return array();
			}
		} else {
			if( !access_has_project_level( plugin_config_get( 'moderate_threshold' ), $t_project_id, $t_user_id ) ) {
				return array();
			}
		}

		$t_page = plugin_page( 'queue' );
		$t_label = plugin_lang_get( 'menu_moderate' );

		# Count pending items
		$t_count = moderate_queue_count_pending();
		if( $t_count > 0 ) {
			$t_label .= ' (' . $t_count . ')';
		}

		return array(
			'<a href="' . $t_page . '">' . $t_label . '</a>',
		);
	}

	/**
	 * Hook to register REST API routes
	 * @param string $p_event Event name
	 * @param \Slim\App $p_app Slim application instance
	 * @return void
	 */
	function rest_api_routes( $p_event, $p_app ) {
		plugin_require_api( 'files/moderate_rest.php' );
		moderate_rest_routes( $p_app );
	}

	/**
	 * Hook for project deletion - clean up moderation entries
	 * @param string $p_event Event name
	 * @param integer $p_project_id Project ID being deleted
	 * @return void
	 */
	function project_delete( $p_event, $p_project_id ) {
		moderate_queue_delete_by_project( $p_project_id );
	}

	/**
	 * Hook for user deletion - clean up moderation entries
	 * @param string $p_event Event name
	 * @param integer $p_user_id User ID being deleted
	 * @return void
	 */
	function user_delete( $p_event, $p_user_id ) {
		moderate_queue_delete_by_user( $p_user_id );
	}
}
