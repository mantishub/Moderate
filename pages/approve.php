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

require_api( 'form_api.php' );
require_api( 'gpc_api.php' );
require_api( 'helper_api.php' );
require_api( 'print_api.php' );

# Get parameters
$f_queue_id = gpc_get_int( 'id' );

# Verify form token
form_security_validate( 'plugin_Moderate_approve' );

# Build command data
$t_data = array(
	'query' => array(
		'queue_id' => $f_queue_id
	)
);

# Execute command
plugin_require_api( 'files/commands/ModerateApproveCommand.php' );
$t_command = new ModerateApproveCommand( $t_data );
$t_result = $t_command->execute();

# Clear form token
form_security_purge( 'plugin_Moderate_approve' );

# Redirect based on item type
if( $t_result['type'] === 'issue' ) {
	# Redirect to the newly created issue
	print_header_redirect( string_get_bug_view_url( $t_result['result_id'] ) );
} else {
	# Redirect to the issue with the note bookmark
	$t_redirect_url = string_get_bug_view_url( $t_result['bug_id'] ) . '#c' . $t_result['result_id'];
	print_header_redirect( $t_redirect_url );
}
