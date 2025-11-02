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
require_api( 'authentication_api.php' );
require_api( 'helper_api.php' );

# Load plugin API for constants and functions
plugin_require_api( 'files/moderate_api.php', 'Moderate' );

use Mantis\Exceptions\ClientException;

/**
 * Command to reject a moderated item
 *
 * Sample data:
 * {
 *   "query": {
 *     "queue_id": 123
 *   }
 * }
 */
class ModerateRejectCommand extends Command {
	/**
	 * Queue item ID
	 * @var integer
	 */
	private $queue_id;

	/**
	 * The queue item data
	 * @var array
	 */
	private $queue_item;

	/**
	 * Constructor
	 *
	 * @param array $p_data The command data
	 */
	function __construct( array $p_data ) {
		parent::__construct( $p_data );
	}

	/**
	 * Validate the command
	 *
	 * @throws ClientException
	 */
	protected function validate() {
		# Get queue ID from query parameter
		$this->queue_id = (int)$this->query( 'queue_id' );

		if( $this->queue_id <= 0 ) {
			throw new ClientException(
				'Queue ID not specified or invalid',
				ERROR_INVALID_FIELD_VALUE,
				array( 'queue_id' )
			);
		}

		# Check if user has permission to reject
		$t_approve_threshold = plugin_config_get( 'moderate_threshold', null, false, null, 'Moderate' );
		if( !access_has_global_level( $t_approve_threshold ) ) {
			throw new ClientException(
				'Access denied to reject moderated items',
				ERROR_ACCESS_DENIED
			);
		}

		# Verify the queue item exists
		$this->queue_item = moderate_queue_get( $this->queue_id );
	}

	/**
	 * Process the command
	 *
	 * @return array Command response data
	 */
	protected function process() {
		# Reject the item
		moderate_queue_reject( $this->queue_id );

		return array(
			'queue_id' => $this->queue_id,
			'status' => 'rejected',
			'type' => $this->queue_item['type'],
		);
	}
}
