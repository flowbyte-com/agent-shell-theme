<?php
/**
 * The builder's administration REST controller.
 *
 * ## Why a separate controller
 *
 * The builder talks to the server through REST, the way the configurator does,
 * and the shapes it needs (working definition, published definition, revision,
 * expected-revision save) are different from the public submission route's.
 * They live here, behind `manage_agentshell_forms`, and they reuse the Phase 1
 * store exactly: no second definition format, no second renderer.
 *
 * ## The optimistic-concurrency protocol
 *
 * Every write carries `expected_revision`. The store compares it with the
 * authoritative revision and refuses the write when they differ, naming the
 * other editor. The builder's `forms-model.js` holds the same revision and
 * recomputes the dirty state from the data, so a second tab's save is refused
 * rather than silently overwriting.
 *
 * @package AgentShell_Forms
 */

namespace AgentShell_Forms;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The builder REST routes.
 */
class Form_REST {

	/**
	 * The REST namespace, shared with the public routes.
	 */
	const REST_NAMESPACE = 'wp/v2/agentshell-forms/v1';

	/**
	 * Register the routes.
	 *
	 * @return void
	 */
	public static function register() {
		if ( ! function_exists( 'register_rest_route' ) ) {
			return;
		}

		$cap = 'manage_agentshell_forms';

		register_rest_route(
			self::REST_NAMESPACE,
			'/forms',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( __CLASS__, 'list_forms' ),
					'permission_callback' => function () use ( $cap ) {
						return current_user_can( $cap );
					},
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( __CLASS__, 'create_form' ),
					'permission_callback' => function () use ( $cap ) {
						return current_user_can( $cap );
					},
				),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/forms/(?P<id>[a-z0-9]+)',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( __CLASS__, 'get_form' ),
					'permission_callback' => function () use ( $cap ) {
						return current_user_can( $cap );
					},
					'args'                => array(
						'id' => array(
							'sanitize_callback' => 'sanitize_key',
						),
					),
				),
				array(
					'methods'             => 'DELETE',
					'callback'            => array( __CLASS__, 'trash_form' ),
					'permission_callback' => function () use ( $cap ) {
						return current_user_can( $cap );
					},
					'args'                => array(
						'id' => array(
							'sanitize_callback' => 'sanitize_key',
						),
					),
				),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/forms/(?P<id>[a-z0-9]+)/save',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'save_form' ),
				'permission_callback' => function () use ( $cap ) {
					return current_user_can( $cap );
				},
				'args'                => array(
					'id' => array(
						'sanitize_callback' => 'sanitize_key',
					),
				),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/forms/(?P<id>[a-z0-9]+)/publish',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'publish_form' ),
				'permission_callback' => function () use ( $cap ) {
					return current_user_can( $cap );
				},
				'args'                => array(
					'id' => array(
						'sanitize_callback' => 'sanitize_key',
					),
				),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/forms/(?P<id>[a-z0-9]+)/discard',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'discard_form' ),
				'permission_callback' => function () use ( $cap ) {
					return current_user_can( $cap );
				},
				'args'                => array(
					'id' => array(
						'sanitize_callback' => 'sanitize_key',
					),
				),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/forms/(?P<id>[a-z0-9]+)/preview',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'preview_form' ),
				'permission_callback' => function () use ( $cap ) {
					return current_user_can( $cap );
				},
				'args'                => array(
					'id' => array(
						'sanitize_callback' => 'sanitize_key',
					),
				),
			)
		);
	}

	/**
	 * List every form.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return \WP_REST_Response
	 */
	public static function list_forms( $request ) {
		unset( $request );

		$forms = array();

		foreach ( Form_Definition::list() as $post ) {
			$state = Form_Definition::get_by_post_id( (int) $post->ID );

			if ( ! is_array( $state ) ) {
				continue;
			}

			$forms[] = array(
				'form_id'    => (string) ( $state['form_id'] ?? '' ),
				'name'       => (string) ( $state['name'] ?? '' ),
				'slug'       => (string) ( $state['slug'] ?? '' ),
				'version'    => (int) ( $state['version'] ?? 0 ),
				'revision'   => (int) ( $state['revision'] ?? 0 ),
				'status'     => (string) ( $state['status'] ?? '' ),
				'field_count' => count( (array) ( $state['working']['fields'] ?? array() ) ),
				'entry_count' => self::entry_count( (string) ( $state['form_id'] ?? '' ) ),
				'updated_at' => (string) ( $state['updated_at'] ?? '' ),
			);
		}

		return new \WP_REST_Response( array( 'forms' => $forms ), 200 );
	}

	/**
	 * Read one form's working and published definitions.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function get_form( $request ) {
		$form_id = (string) $request->get_param( 'id' );
		$post_id = Form_Definition::resolve_post_id( $form_id );

		if ( $post_id < 1 ) {
			return new \WP_Error( 'form_not_found', __( 'No such form.', 'agentshell-forms' ), array( 'status' => 404 ) );
		}

		$state = Form_Definition::get_by_post_id( $post_id );

		if ( is_wp_error( $state ) ) {
			return $state;
		}

		return new \WP_REST_Response(
			array(
				'form_id'   => (string) ( $state['form_id'] ?? '' ),
				'name'      => (string) ( $state['name'] ?? '' ),
				'slug'      => (string) ( $state['slug'] ?? '' ),
				'version'   => (int) ( $state['version'] ?? 0 ),
				'revision'  => (int) ( $state['revision'] ?? 0 ),
				'status'    => (string) ( $state['status'] ?? '' ),
				'working'   => is_array( $state['working'] ) ? $state['working'] : null,
				'published' => is_array( $state['published'] ) ? $state['published'] : null,
			),
			200
		);
	}

	/**
	 * Create a form.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function create_form( $request ) {
		$body = self::json( $request );
		$name = isset( $body['name'] ) ? sanitize_text_field( (string) $body['name'] ) : '';

		if ( '' === $name ) {
			return new \WP_Error( 'form_name_required', __( 'A name is required.', 'agentshell-forms' ), array( 'status' => 400 ) );
		}

		$definition = Form_Schema::defaults( Form_Definition::new_form_id(), Form_Definition::sanitise_slug( $name ), $name );

		$created = Form_Definition::create(
			array(
				'name'       => $name,
				'slug'       => $definition['slug'],
				'definition' => $definition,
			),
			get_current_user_id()
		);

		if ( is_wp_error( $created ) ) {
			return $created;
		}

		return new \WP_REST_Response(
			array(
				'form_id'  => (string) ( $created['form_id'] ?? '' ),
				'revision' => (int) ( $created['revision'] ?? 0 ),
				'name'     => $name,
			),
			201
		);
	}

	/**
	 * Save the working definition.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function save_form( $request ) {
		$form_id = (string) $request->get_param( 'id' );
		$body    = self::json( $request );

		$definition = isset( $body['definition'] ) && is_array( $body['definition'] ) ? $body['definition'] : array();
		$expected   = isset( $body['expected_revision'] ) ? (int) $body['expected_revision'] : 0;

		if ( empty( $definition ) ) {
			return new \WP_Error( 'form_definition_required', __( 'A definition is required.', 'agentshell-forms' ), array( 'status' => 400 ) );
		}

		$result = Form_Definition::update_working( $form_id, $definition, $expected, get_current_user_id() );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return new \WP_REST_Response(
			array(
				'form_id'  => $form_id,
				'revision' => (int) ( $result['revision'] ?? 0 ),
				'updated'  => true,
			),
			200
		);
	}

	/**
	 * Publish the working definition.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function publish_form( $request ) {
		$form_id = (string) $request->get_param( 'id' );
		$body    = self::json( $request );

		$expected = isset( $body['expected_revision'] ) ? (int) $body['expected_revision'] : 0;

		$result = Form_Definition::publish( $form_id, $expected, get_current_user_id() );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return new \WP_REST_Response(
			array(
				'form_id'  => $form_id,
				'version'  => (int) ( $result['version'] ?? 0 ),
				'revision' => (int) ( $result['revision'] ?? 0 ),
				'published' => true,
			),
			200
		);
	}

	/**
	 * Discard the working definition.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function discard_form( $request ) {
		$form_id = (string) $request->get_param( 'id' );

		$result = Form_Definition::discard( $form_id, get_current_user_id() );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$state = Form_Definition::get_by_post_id( Form_Definition::resolve_post_id( $form_id ) );

		return new \WP_REST_Response(
			array(
				'form_id'  => $form_id,
				'revision' => (int) ( $state['revision'] ?? 0 ),
				'working'  => is_array( $state['working'] ) ? $state['working'] : null,
				'discarded' => true,
			),
			200
		);
	}

	/**
	 * Render the draft for preview. Nothing is written.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function preview_form( $request ) {
		$form_id = (string) $request->get_param( 'id' );
		$body    = self::json( $request );

		$definition = isset( $body['definition'] ) && is_array( $body['definition'] ) ? $body['definition'] : array();

		if ( empty( $definition ) ) {
			$state = Form_Definition::get_by_post_id( Form_Definition::resolve_post_id( $form_id ) );

			if ( is_array( $state ) && is_array( $state['working'] ) ) {
				$definition = $state['working'];
			}
		}

		$html = Form_Renderer::render_definition(
			$definition,
			array(
				'instance' => 1,
				'context'  => 'admin',
				'title'    => 'show',
			)
		);

		return new \WP_REST_Response(
			array(
				'form_id' => $form_id,
				'html'     => $html,
			),
			200
		);
	}

	/**
	 * Trash a form.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function trash_form( $request ) {
		$form_id = (string) $request->get_param( 'id' );
		$post_id = Form_Definition::resolve_post_id( $form_id );

		if ( $post_id < 1 ) {
			return new \WP_Error( 'form_not_found', __( 'No such form.', 'agentshell-forms' ), array( 'status' => 404 ) );
		}

		$trashed = wp_trash_post( $post_id );

		if ( ! $trashed ) {
			return new \WP_Error( 'form_trash_failed', __( 'The form could not be trashed.', 'agentshell-forms' ), array( 'status' => 500 ) );
		}

		return new \WP_REST_Response( array( 'trashed' => true ), 200 );
	}

	/**
	 * The number of submissions for a form.
	 *
	 * @param string $form_id Internal identifier.
	 * @return int
	 */
	private static function entry_count( $form_id ) {
		$posts = get_posts(
			array(
				'post_type'      => Form_Post_Types::ENTRY,
				'post_status'    => array( 'publish', 'draft', 'pending', 'private', 'trash' ),
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array(
						'key'   => Submission_Store::META_FORM_ID,
						'value' => (string) $form_id,
					),
				),
			)
		);

		return count( (array) $posts );
	}

	/**
	 * Read a JSON body.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return array
	 */
	private static function json( $request ) {
		$params = $request->get_json_params();

		return is_array( $params ) ? $params : array();
	}
}
EOF
echo written