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
 * Register REST API routes for Moderate plugin
 *
 * @param \Slim\App $p_app Slim application
 * @return void
 */
function moderate_rest_routes( \Slim\App $p_app ) {
	$p_app->group( '/moderate', function() {
		/**
		 * Get pending moderation queue items
		 *
		 * GET /moderate/queue
		 *
		 * @return array List of pending items
		 */
		$this->get( '/queue', function( $request, $response ) {
			# Check access
			$t_approve_threshold = plugin_config_get( 'moderate_threshold', null, false, null, 'Moderate' );
			access_ensure_global_level( $t_approve_threshold );

			# Get optional project filter
			$t_project_id = $request->getParam( 'project_id', null );

			# Get pending items
			$t_items = moderate_queue_get_pending( $t_project_id );

			# Format response
			$t_result = array(
				'items' => array()
			);

			foreach( $t_items as $t_item ) {
				# Don't include serialized data in response
				unset( $t_item['data'] );
				$t_item['status_name'] = moderate_queue_get_status_name( $t_item['status'] );
				$t_result['items'][] = $t_item;
			}

			return $response->withStatus( HTTP_STATUS_SUCCESS )
				->withJson( $t_result );
		});

		/**
		 * Get moderation history
		 *
		 * GET /moderate/history
		 *
		 * @return array List of historical items
		 */
		$this->get( '/history', function( $request, $response ) {
			# Check access
			$t_approve_threshold = plugin_config_get( 'moderate_threshold', null, false, null, 'Moderate' );
			access_ensure_global_level( $t_approve_threshold );

			# Get optional parameters
			$t_project_id = $request->getParam( 'project_id', null );
			$t_limit = (int)$request->getParam( 'limit', 50 );

			# Get history
			$t_items = moderate_queue_get_history( $t_project_id, $t_limit );

			# Format response
			$t_result = array(
				'items' => array()
			);

			foreach( $t_items as $t_item ) {
				# Don't include serialized data in response
				unset( $t_item['data'] );
				$t_item['status_name'] = moderate_queue_get_status_name( $t_item['status'] );
				$t_result['items'][] = $t_item;
			}

			return $response->withStatus( HTTP_STATUS_SUCCESS )
				->withJson( $t_result );
		});

		/**
		 * Approve a moderated item
		 *
		 * POST /moderate/approve/{queue_id}
		 *
		 * @param integer $queue_id Queue item ID
		 * @return array Approval result
		 */
		$this->post( '/approve/{queue_id}', function( $request, $response, $args ) {
			$t_queue_id = (int)$args['queue_id'];

			# Build command data
			$t_data = array(
				'query' => array(
					'queue_id' => $t_queue_id
				)
			);

			# Execute command
			plugin_require_api( 'files/commands/ModerateApproveCommand.php', 'Moderate' );
			$t_command = new ModerateApproveCommand( $t_data );
			$t_result = $t_command->execute();

			return $response->withStatus( HTTP_STATUS_SUCCESS )
				->withJson( $t_result );
		});

		/**
		 * Reject a moderated item
		 *
		 * POST /moderate/reject/{queue_id}
		 *
		 * @param integer $queue_id Queue item ID
		 * @return array Rejection result
		 */
		$this->post( '/reject/{queue_id}', function( $request, $response, $args ) {
			$t_queue_id = (int)$args['queue_id'];

			# Get optional reason from body
			$t_body = $request->getParsedBody();
			$t_reason = isset( $t_body['reason'] ) ? $t_body['reason'] : '';

			# Build command data
			$t_data = array(
				'query' => array(
					'queue_id' => $t_queue_id
				),
				'payload' => array(
					'reason' => $t_reason
				)
			);

			# Execute command
			plugin_require_api( 'files/commands/ModerateRejectCommand.php', 'Moderate' );
			$t_command = new ModerateRejectCommand( $t_data );
			$t_result = $t_command->execute();

			return $response->withStatus( HTTP_STATUS_SUCCESS )
				->withJson( $t_result );
		});

		/**
		 * Mark a queue item and all pending items from same user as spam
		 *
		 * POST /moderate/spam/{queue_id}
		 *
		 * @return array Result with spam count
		 */
		$this->post( '/spam/{queue_id}', function( $request, $response, $args ) {
			$t_queue_id = isset( $args['queue_id'] ) ? (int)$args['queue_id'] : 0;

			# Build command data
			$t_data = array(
				'query' => array(
					'queue_id' => $t_queue_id
				)
			);

			# Execute command
			plugin_require_api( 'files/commands/ModerateSpamCommand.php', 'Moderate' );
			$t_command = new ModerateSpamCommand( $t_data );
			$t_result = $t_command->execute();

			return $response->withStatus( HTTP_STATUS_SUCCESS )
				->withJson( $t_result );
		});

		/**
		 * Delete a moderated queue item
		 *
		 * DELETE /moderate/{queue_id}
		 *
		 * @return array Result
		 */
		$this->delete( '/{queue_id}', function( $request, $response, $args ) {
			$t_queue_id = isset( $args['queue_id'] ) ? (int)$args['queue_id'] : 0;

			# Build command data
			$t_data = array(
				'query' => array(
					'queue_id' => $t_queue_id
				)
			);

			# Execute command
			plugin_require_api( 'files/commands/ModerateDeleteCommand.php', 'Moderate' );
			$t_command = new ModerateDeleteCommand( $t_data );
			$t_result = $t_command->execute();

			return $response->withStatus( HTTP_STATUS_SUCCESS )
				->withJson( $t_result );
		});

		/**
		 * Get queue item statistics
		 *
		 * GET /moderate/stats
		 *
		 * @return array Statistics
		 */
		$this->get( '/stats', function( $request, $response ) {
			# Check access
			$t_approve_threshold = plugin_config_get( 'moderate_threshold', null, false, null, 'Moderate' );
			access_ensure_global_level( $t_approve_threshold );

			# Get optional project filter
			$t_project_id = $request->getParam( 'project_id', null );

			# Count pending items
			$t_pending_count = moderate_queue_count_pending( $t_project_id );

			return $response->withStatus( HTTP_STATUS_SUCCESS )
				->withJson( array(
					'pending_count' => $t_pending_count
				) );
		});
	});
}
