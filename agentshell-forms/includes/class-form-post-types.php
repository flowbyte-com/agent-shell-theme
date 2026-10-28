<?php
/**
 * The two custom post types, their meta registration, and the meta keys.
 *
 * ## Why post types at all
 *
 * §3.2 and §3.3 of the design choose a custom post type over the AgentShell
 * configuration option and over a dedicated table. The decisive points:
 *
 *   - **Capabilities, trash, deletion and the privacy hooks come from
 *     WordPress.** A form is a record with an owner and a lifecycle; a table
 *     would mean re-implementing all of it.
 *   - **The generic AgentShell content tools are hard-limited to `post` and
 *     `page`**, so a form and a submission are invisible to
 *     `agentshell_search_content`, `agentshell_get_content` and
 *     `agentshell_trash_content` without changing any of them.
 *
 * ## The post type keys, and why they are short
 *
 * `register_post_type()` refuses a key longer than 20 characters, and
 * `wp_posts.post_type` is `varchar(20)` — so an over-long key would not merely
 * be refused, it would be **truncated in the database**. The obvious name for
 * the submission type, `agentshell_submission`, is 21 characters and is
 * therefore invalid. The submission type is `agentshell_entry` (16) and the
 * definition type is `agentshell_form` (15).
 *
 * ## The meta keys, and which of them are revisioned
 *
 * WordPress does **not** version arbitrary custom post meta. Two facts combine:
 * `wp_save_revisioned_meta_fields()` copies only the keys registered through
 * `register_meta()` with `revisions_enabled => true` (and the default is
 * `false`), and `wp_save_post_revision()` only takes a revision when
 * `post_title`, `post_content` or `post_excerpt` changed — which a definition
 * stored entirely in meta never does.
 *
 * So the three **definition** keys are registered with
 * `revisions_enabled => true`, and the plugin takes the revision itself at the
 * moments a snapshot is meaningful (after a publication, after a discard).
 *
 * The other keys are **not** revisioned, on purpose:
 *
 *   - `_agentshell_form_revision` is a **concurrency token**, not content. A
 *     revision carrying a stale counter would be misleading.
 *   - `_agentshell_form_version` labels a publication; it is bookkeeping.
 *   - `_agentshell_form_id` and `_agentshell_form_slug` are identity. A
 *     revision that could restore an old slug would be a rename by another
 *     name, and the slug is frozen after first publication.
 *
 * @package AgentShell_Forms
 */

namespace AgentShell_Forms;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Post type registration and the meta key vocabulary.
 */
class Form_Post_Types {

	/**
	 * The definition post type.
	 */
	const FORM = 'agentshell_form';

	/**
	 * The submission post type.
	 */
	const ENTRY = 'agentshell_entry';

	/**
	 * The working definition — what the builder and the agents edit.
	 */
	const META_WORKING = '_agentshell_form_working';

	/**
	 * The published definition — what visitors get and what submissions are
	 * validated against.
	 */
	const META_PUBLISHED = '_agentshell_form_published';

	/**
	 * The published definition as it was immediately before the last
	 * publication. A one-level undo.
	 */
	const META_PUBLISHED_PREVIOUS = '_agentshell_form_published_previous';

	/**
	 * The monotonic working-revision counter. A concurrency token, not a
	 * version, and deliberately not revisioned.
	 */
	const META_REVISION = '_agentshell_form_revision';

	/**
	 * The immutable internal identifier: 32 lowercase hex characters.
	 */
	const META_FORM_ID = '_agentshell_form_id';

	/**
	 * The optional human-readable slug.
	 */
	const META_SLUG = '_agentshell_form_slug';

	/**
	 * The published version label.
	 */
	const META_VERSION = '_agentshell_form_version';

	/**
	 * Miscellaneous bookkeeping (modified_by, modified_at, first_published_at).
	 */
	const META_META = '_agentshell_form_meta';

	/**
	 * The three definition keys that take a native revision.
	 *
	 * @return array<int,string>
	 */
	public static function revisioned_meta_keys() {
		return array( self::META_WORKING, self::META_PUBLISHED, self::META_PUBLISHED_PREVIOUS );
	}

	/**
	 * Every meta key the definition store owns.
	 *
	 * @return array<int,string>
	 */
	public static function all_meta_keys() {
		return array(
			self::META_WORKING,
			self::META_PUBLISHED,
			self::META_PUBLISHED_PREVIOUS,
			self::META_REVISION,
			self::META_FORM_ID,
			self::META_SLUG,
			self::META_VERSION,
			self::META_META,
		);
	}

	/**
	 * Register both post types and their meta.
	 *
	 * Idempotent: `register_post_type()` overwrites, and this is called once
	 * per request from `init`.
	 *
	 * @return void
	 */
	public static function register() {
		self::register_form_type();
		self::register_entry_type();
		self::register_meta();
		self::register_entry_meta();
	}

	/**
	 * The definition post type.
	 *
	 * `show_ui` is false: the plugin provides its own screens (Phase 4), and a
	 * half-finished default editor screen that could write an arbitrary
	 * `post_content` into a form would be a way to corrupt a definition
	 * through a surface that does not validate.
	 *
	 * @return void
	 */
	private static function register_form_type() {
		register_post_type(
			self::FORM,
			array(
				'labels'              => array(
					'name'          => __( 'Forms', 'agentshell-forms' ),
					'singular_name' => __( 'Form', 'agentshell-forms' ),
				),
				'description'         => __( 'AgentShell form definitions.', 'agentshell-forms' ),
				'public'              => false,
				'publicly_queryable'  => false,
				'show_ui'             => false,
				'show_in_menu'        => false,
				'show_in_nav_menus'   => false,
				'show_in_admin_bar'   => false,
				'show_in_rest'        => false,
				'exclude_from_search' => true,
				'query_var'           => false,
				'rewrite'             => false,
				'hierarchical'        => false,
				'can_export'          => true,
				'delete_with_user'    => false,
				'supports'            => array( 'title', 'revisions' ),
				'capability_type'     => array( self::FORM, self::FORM . 's' ),
				'map_meta_cap'        => true,
				'capabilities'        => self::capability_map( Form_Capabilities::CAP_FORMS, self::FORM ),
			)
		);
	}

	/**
	 * The submission post type.
	 *
	 * A submission is immutable: `title` only, no editor, no revisions, no
	 * comments. `can_export` is false because an entry is personal data with
	 * its own retention rule (§6.5) — a generic content export is not the
	 * erasure or subject-access path, and letting one carry personal data out
	 * of the site by accident is exactly the kind of quiet disclosure the
	 * design's data-minimisation rule exists to prevent.
	 *
	 * @return void
	 */
	private static function register_entry_type() {
		register_post_type(
			self::ENTRY,
			array(
				'labels'              => array(
					'name'          => __( 'Form submissions', 'agentshell-forms' ),
					'singular_name' => __( 'Form submission', 'agentshell-forms' ),
				),
				'description'         => __( 'AgentShell form submissions.', 'agentshell-forms' ),
				'public'              => false,
				'publicly_queryable'  => false,
				'show_ui'             => false,
				'show_in_menu'        => false,
				'show_in_nav_menus'   => false,
				'show_in_admin_bar'   => false,
				'show_in_rest'        => false,
				'exclude_from_search' => true,
				'query_var'           => false,
				'rewrite'             => false,
				'hierarchical'        => false,
				'can_export'          => false,
				'delete_with_user'    => false,
				'supports'            => array( 'title' ),
				'capability_type'     => array( self::ENTRY, self::ENTRY . 's' ),
				'map_meta_cap'        => true,
				'capabilities'        => self::capability_map( Form_Capabilities::CAP_SUBMISSIONS, self::ENTRY ),
			)
		);
	}

	/**
	 * Map a type's capability vocabulary onto one plugin capability.
	 *
	 * ## Why the three singular meta capabilities keep their generated names
	 *
	 * `register_post_type( ..., 'map_meta_cap' => true )` records the *value*
	 * of `edit_post`, `read_post` and `delete_post` in a **global** table,
	 * `$post_type_meta_caps`, keyed by that value. `map_meta_cap()` consults
	 * that table for any capability name it does not recognise, and rewrites it
	 * into the singular meta capability it found there.
	 *
	 * So mapping all three onto `manage_agentshell_forms` has a consequence
	 * that is invisible until it is measured: `manage_agentshell_forms` becomes
	 * a **meta** capability, `map_meta_cap( 'manage_agentshell_forms' )` yields
	 * `delete_post` (whichever of the three was registered last), and a bare
	 * `user_can( $user, 'manage_agentshell_forms' )` — with no post to check
	 * against — returns `do_not_allow`. The plugin's own permission check would
	 * then be false for every user, forever.
	 *
	 * Keeping the three meta names distinct per type avoids that entirely,
	 * while every *primitive* capability still points at the one capability the
	 * plugin defines:
	 *
	 *   - a bare `manage_agentshell_forms` check is a plain primitive check;
	 *   - `current_user_can( 'edit_post', $id )` resolves through
	 *     `map_meta_cap()` to `edit_posts` / `edit_others_posts` /
	 *     `edit_published_posts`, all of which are `manage_agentshell_forms`;
	 *   - `current_user_can( 'edit_agentshell_form', $id )` resolves through
	 *     `$post_type_meta_caps` to `edit_post`, and then to the same primitive.
	 *
	 * `read` is mapped too, because core resolves a public post status through
	 * `cap->read` and leaving it at the generic `read` would let every
	 * logged-in user read a published form.
	 *
	 * @param string $capability      The plugin capability (primitive).
	 * @param string $meta_singular   The type's singular name, for the meta
	 *                                capability names.
	 * @return array<string,string>
	 */
	private static function capability_map( $capability, $meta_singular ) {
		return array(
			'edit_post'              => 'edit_' . $meta_singular,
			'read_post'              => 'read_' . $meta_singular,
			'delete_post'            => 'delete_' . $meta_singular,
			'read'                   => $capability,
			'edit_posts'             => $capability,
			'edit_others_posts'      => $capability,
			'edit_published_posts'   => $capability,
			'edit_private_posts'     => $capability,
			'publish_posts'          => $capability,
			'read_private_posts'     => $capability,
			'delete_posts'           => $capability,
			'delete_others_posts'    => $capability,
			'delete_published_posts' => $capability,
			'delete_private_posts'   => $capability,
		);
	}

	/**
	 * Register the definition meta.
	 *
	 * All keys are `show_in_rest => false` and carry an `auth_callback` that
	 * refuses, so a definition is not reachable through the post REST API. The
	 * plugin's own routes are the only way in.
	 *
	 * `type` is `string` for every key, including the two integer counters.
	 * The counters are written and read as raw bytes by `Form_DB` (the
	 * compare-and-swap compares the stored value), so registering them as
	 * integers would invite a sanitiser to change the bytes between the read
	 * and the guard. They are numbers by convention and strings by storage, and
	 * that is deliberate.
	 *
	 * @return void
	 */
	private static function register_meta() {
		$revisioned = self::revisioned_meta_keys();

		foreach ( self::all_meta_keys() as $key ) {
			$args = array(
				'type'          => 'string',
				'single'        => true,
				'show_in_rest'  => false,
				'auth_callback' => array( __CLASS__, 'auth_callback' ),
			);

			if ( in_array( $key, $revisioned, true ) ) {
				$args['revisions_enabled'] = true;
			}

			register_post_meta( self::FORM, $key, $args );
		}
	}

	/**
	 * Register the submission meta.
	 *
	 * Every key is `show_in_rest => false` and refuses the generic meta API for
	 * the same reason the definition keys do: the store writes exact bytes and
	 * the notification claim compares them. `auth_callback` is the REST
	 * authorisation hook, so a submission's values are not reachable through
	 * the post meta REST surface either.
	 *
	 * Registered from `Submission_Store::meta_keys()` so the vocabulary has one
	 * definition; the store is loaded before `init` runs.
	 *
	 * @return void
	 */
	private static function register_entry_meta() {
		if ( ! class_exists( 'AgentShell_Forms\\Submission_Store' ) ) {
			return;
		}

		foreach ( Submission_Store::meta_keys() as $key ) {
			register_post_meta(
				self::ENTRY,
				$key,
				array(
					'type'          => 'string',
					'single'        => true,
					'show_in_rest'  => false,
					'auth_callback' => array( __CLASS__, 'auth_callback' ),
				)
			);
		}
	}

	/**
	 * Refuse every direct meta write through the meta API.
	 *
	 * The definition rows are written only by `Form_DB`, which counts rows and
	 * compare-and-swaps on `meta_id`. `update_post_meta()` would bypass both:
	 * it updates *every* matching row and has no guard. Refusing the generic
	 * path is what keeps the invariants from being sidestepped by a future
	 * caller that reaches for the convenient function.
	 *
	 * @return bool
	 */
	public static function auth_callback() {
		return false;
	}
}
