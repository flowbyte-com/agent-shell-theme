<?php
/**
 * agentshell_list_submissions — submission metadata, never submitted values.
 *
 * Returns one row per submission carrying identifiers, timestamps and
 * statuses: the entry id, the form's internal identifier, the form version,
 * the received-at time, the spam status, the notification status and the
 * field count. It carries no submitted value by construction, so it carries
 * no personal data: the form authority (`manage_agentshell_forms`) is the
 * only authority this tool needs, no personal-data authority is required
 * for it, and the listing stays available in every policy including the
 * default `off`.
 *
 * @package AgentShell_Forms
 */

namespace AgentShell_Forms\Tools;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * List submission metadata.
 */
class List_Submissions extends Base {
	public function get_name() { return 'agentshell_list_submissions'; }
	public function get_description() { return 'List submissions as metadata only (entry id, form id, form version, received-at, spam and notification status, field count). Never returns a submitted value; available in every personal-data policy.'; }
	public function get_input_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'form_id' => array( 'type' => 'string', 'description' => 'Optional: only submissions to this form (internal id or post id). Omit to list across all forms.' ),
				'status'  => array( 'type' => 'string', 'description' => 'Submission status: publish, trash or any. Defaults to publish.' ),
				'limit'   => array( 'type' => 'integer', 'description' => 'How many rows to return. Defaults to 100, capped at 500.' ),
				'offset'  => array( 'type' => 'integer', 'description' => 'How many rows to skip. Defaults to 0.' ),
			),
		);
	}

	public function execute( array $arguments ) {
		$refused = \AgentShell_Forms\Form_Tools::require_forms_capability();
		if ( null !== $refused ) {
			return $refused;
		}

		$status = isset( $arguments['status'] ) ? (string) $arguments['status'] : 'publish';
		if ( ! in_array( $status, array( 'publish', 'trash', 'any' ), true ) ) {
			return array(
				'ok'      => false,
				'code'    => 'forms_status_invalid',
				'message' => 'Status must be publish, trash or any.',
			);
		}

		$limit = isset( $arguments['limit'] ) ? (int) $arguments['limit'] : 100;
		$limit = max( 1, min( 500, $limit ) );

		$offset = isset( $arguments['offset'] ) ? max( 0, (int) $arguments['offset'] ) : 0;

		if ( isset( $arguments['form_id'] ) && '' !== (string) $arguments['form_id'] ) {
			$state = \AgentShell_Forms\Form_Tools::resolve_form( $arguments['form_id'] );

			if ( is_wp_error( $state ) ) {
				return \AgentShell_Forms\Form_Tools::error_result( $state );
			}

			$form_id = (string) $state['form_id'];
			$records = \AgentShell_Forms\Submission_Store::list_for_form(
				$form_id,
				array(
					'status' => $status,
					'limit'  => $limit,
					'offset' => $offset,
				)
			);
			$total   = \AgentShell_Forms\Submission_Store::count_for_form( $form_id, $status );
		} else {
			$statuses = 'any' === $status ? array( 'publish', 'trash' ) : array( $status );
			$posts    = get_posts(
				array(
					'post_type'      => \AgentShell_Forms\Form_Post_Types::ENTRY,
					'post_status'    => $statuses,
					'posts_per_page' => $limit,
					'offset'         => $offset,
					'orderby'        => 'date',
					'order'          => 'DESC',
				)
			);

			$records = array();
			foreach ( (array) $posts as $post ) {
				$record = \AgentShell_Forms\Submission_Store::get( (int) $post->ID, false );
				if ( ! is_wp_error( $record ) ) {
					$records[] = $record;
				}
			}

			$query = new \WP_Query(
				array(
					'post_type'      => \AgentShell_Forms\Form_Post_Types::ENTRY,
					'post_status'    => $statuses,
					'posts_per_page' => 1,
					'fields'         => 'ids',
					'no_found_rows'  => false,
				)
			);
			$total = (int) $query->found_posts;
		}

		$out = array();
		foreach ( (array) $records as $record ) {
			if ( ! is_array( $record ) ) {
				continue;
			}
			$out[] = \AgentShell_Forms\Form_Tools::submission_metadata( $record );
		}

		return array(
			'ok'          => true,
			'submissions' => $out,
			'count'       => count( $out ),
			'total'       => $total,
		);
	}
}
