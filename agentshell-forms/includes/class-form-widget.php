<?php
/**
 * The `WP_Widget` bridge to WordPress's widget areas.
 *
 * ## Why a widget at all
 *
 * A shortcode needs somebody to write a shortcode. A widget needs a theme with
 * a widget area — and every theme has one, including AgentShell's own
 * `wp_widget_area` block, which is what makes this the theme-independent
 * insertion path (§3.4 option C). The widget is a bridge to WordPress's widget
 * areas; it is **not** an entry in AgentShell's bilateral widget registry, and
 * it registers nothing there.
 *
 * ## One renderer
 *
 * The widget calls `Form_Renderer::render()`, exactly as the shortcode and
 * `agentshell_forms_render()` do, and renders the result unchanged. There is no
 * widget-specific markup to drift, and the widget inherits the published-only
 * rule, the escaping and the per-instance ids without repeating any of them.
 *
 * ## The picker lists forms a human can recognise
 *
 * The widget stores the form's **internal identifier**, because a slug can be
 * edited before first publication and an id cannot. The admin control shows the
 * name and the slug, and marks a form that has no published version: choosing
 * one is allowed (it is a legitimate thing to prepare) and the widget says, in
 * the editor, that it will not render until the form is published.
 *
 * @package AgentShell_Forms
 */

namespace AgentShell_Forms;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/*
 * `WP_Widget` is loaded by WordPress before plugins are, so this branch is
 * always taken on a real site. The guard is for the unit-test bootstrap and for
 * any host that loads the plugin file outside WordPress: declaring a subclass
 * of a class that does not exist is a fatal error, and a plugin that fatals on
 * load because a base class is missing is worse than a plugin that has no
 * widget on that host.
 */
if ( class_exists( '\WP_Widget' ) ) {

	/**
	 * A widget that renders one published form.
	 */
	class Form_Widget extends \WP_Widget {

		/**
		 * Register the widget with WordPress.
		 *
		 * @return void
		 */
		public static function register() {
			if ( ! function_exists( 'register_widget' ) ) {
				return;
			}

			register_widget( __CLASS__ );
		}

		/**
		 * Constructor.
		 */
		public function __construct() {
			parent::__construct(
				'agentshell_form',
				__( 'AgentShell Form', 'agentshell-forms' ),
				array(
					'classname'                   => 'agentshell-forms-widget',
					'description'                 => __( 'Show one published AgentShell form.', 'agentshell-forms' ),
					'customize_selective_refresh' => true,
					'show_instance_in_rest'       => false,
				)
			);
		}

		/**
		 * The front end.
		 *
		 * @param array $args     Sidebar arguments supplied by the theme.
		 * @param array $instance This widget's saved settings.
		 * @return void
		 */
		public function widget( $args, $instance ) {
			$instance = is_array( $instance ) ? $instance : array();

			$form_id = isset( $instance['form_id'] ) && is_scalar( $instance['form_id'] )
				? (string) $instance['form_id']
				: '';

			if ( '' === $form_id ) {
				return;
			}

			// A widget whose form was deleted renders nothing at all — not an
			// empty box with the theme's `before_widget` furniture around it.
			$html = Form_Renderer::render(
				$form_id,
				array(
					'context'       => 'widget',
					'title'         => isset( $instance['title'] ) ? (string) $instance['title'] : 'show',
					'heading_level' => isset( $instance['heading_level'] ) ? (int) $instance['heading_level'] : 3,
					'class'         => isset( $instance['class'] ) ? (string) $instance['class'] : '',
				)
			);

			if ( '' === $html ) {
				return;
			}

			$before = isset( $args['before_widget'] ) ? (string) $args['before_widget'] : '';
			$after  = isset( $args['after_widget'] ) ? (string) $args['after_widget'] : '';

			// `$before_widget` / `$after_widget` are the theme's own markup and
			// are deliberately not escaped: WordPress's contract is that they
			// are already-safe HTML supplied by the theme. Escaping them would
			// break every theme's wrapper.
			echo $before . $html . $after; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}

		/**
		 * Save the widget's settings.
		 *
		 * @param array $new_instance Submitted values.
		 * @param array $old_instance Previously saved values.
		 * @return array
		 */
		public function update( $new_instance, $old_instance ) {
			unset( $old_instance );

			$new_instance = is_array( $new_instance ) ? $new_instance : array();

			$form_id = isset( $new_instance['form_id'] ) && is_scalar( $new_instance['form_id'] )
				? (string) $new_instance['form_id']
				: '';

			// An identifier that does not resolve is stored as '' rather than as
			// a stale string: a widget pointing at a form that no longer exists
			// is a widget whose "choose a form" state is honest about itself.
			if ( '' !== $form_id && Form_Definition::resolve_post_id( $form_id ) < 1 ) {
				$form_id = '';
			}

			$title = isset( $new_instance['title'] ) ? (string) $new_instance['title'] : 'show';
			$title = in_array( $title, array( 'show', 'hide' ), true ) ? $title : 'show';

			$heading = isset( $new_instance['heading_level'] ) ? (int) $new_instance['heading_level'] : 3;
			$heading = ( $heading >= 2 && $heading <= 6 ) ? $heading : 3;

			$class = isset( $new_instance['class'] ) && is_scalar( $new_instance['class'] )
				? sanitize_text_field( (string) $new_instance['class'] )
				: '';

			return array(
				'form_id'       => $form_id,
				'title'         => $title,
				'heading_level' => $heading,
				'class'         => $class,
			);
		}

		/**
		 * The editor's controls.
		 *
		 * @param array $instance This widget's saved settings.
		 * @return void
		 */
		public function form( $instance ) {
			$instance = is_array( $instance ) ? $instance : array();

			$form_id = isset( $instance['form_id'] ) ? (string) $instance['form_id'] : '';
			$title   = isset( $instance['title'] ) ? (string) $instance['title'] : 'show';
			$heading = isset( $instance['heading_level'] ) ? (int) $instance['heading_level'] : 3;
			$class   = isset( $instance['class'] ) ? (string) $instance['class'] : '';

			$forms = self::forms();

			echo '<p><label for="' . esc_attr( $this->get_field_id( 'form_id' ) ) . '">'
				. esc_html__( 'Form', 'agentshell-forms' )
				. '</label>';

			echo '<select class="widefat" id="' . esc_attr( $this->get_field_id( 'form_id' ) ) . '"'
				. ' name="' . esc_attr( $this->get_field_name( 'form_id' ) ) . '">';
			echo '<option value="">' . esc_html__( '— Select a form —', 'agentshell-forms' ) . '</option>';

			foreach ( $forms as $form ) {
				$label = $form['name'];
				if ( '' !== $form['slug'] ) {
					$label .= ' (' . $form['slug'] . ')';
				}
				if ( ! $form['published'] ) {
					$label .= ' — ' . __( 'not published yet', 'agentshell-forms' );
				}

				echo '<option value="' . esc_attr( $form['form_id'] ) . '"' . selected( $form['form_id'], $form_id, false ) . '>'
					. esc_html( $label )
					. '</option>';
			}

			echo '</select></p>';

			if ( empty( $forms ) ) {
				echo '<p class="description">'
					. esc_html__( 'No forms exist yet.', 'agentshell-forms' )
					. '</p>';
			} elseif ( '' !== $form_id && ! self::is_published( $form_id ) ) {
				echo '<p class="description">'
					. esc_html__( 'This form has no published version, so the widget renders nothing on the site yet.', 'agentshell-forms' )
					. '</p>';
			}

			echo '<p><label for="' . esc_attr( $this->get_field_id( 'title' ) ) . '">'
				. esc_html__( 'Form name', 'agentshell-forms' )
				. '</label>';
			echo '<select class="widefat" id="' . esc_attr( $this->get_field_id( 'title' ) ) . '"'
				. ' name="' . esc_attr( $this->get_field_name( 'title' ) ) . '">';
			echo '<option value="show"' . selected( $title, 'show', false ) . '>'
				. esc_html__( 'Show the form’s name as a heading', 'agentshell-forms' ) . '</option>';
			echo '<option value="hide"' . selected( $title, 'hide', false ) . '>'
				. esc_html__( 'Hide it — the widget area provides the heading', 'agentshell-forms' ) . '</option>';
			echo '</select></p>';

			echo '<p><label for="' . esc_attr( $this->get_field_id( 'heading_level' ) ) . '">'
				. esc_html__( 'Heading level', 'agentshell-forms' )
				. '</label>';
			echo '<select class="widefat" id="' . esc_attr( $this->get_field_id( 'heading_level' ) ) . '"'
				. ' name="' . esc_attr( $this->get_field_name( 'heading_level' ) ) . '">';
			for ( $level = 2; $level <= 6; $level++ ) {
				echo '<option value="' . esc_attr( (string) $level ) . '"' . selected( $heading, $level, false ) . '>'
					. esc_html( sprintf( 'H%d', $level ) )
					. '</option>';
			}
			echo '</select></p>';

			echo '<p><label for="' . esc_attr( $this->get_field_id( 'class' ) ) . '">'
				. esc_html__( 'Extra CSS classes', 'agentshell-forms' )
				. '</label>';
			echo '<input class="widefat" type="text" id="' . esc_attr( $this->get_field_id( 'class' ) ) . '"'
				. ' name="' . esc_attr( $this->get_field_name( 'class' ) ) . '"'
				. ' value="' . esc_attr( $class ) . '"></p>';
		}

		/**
		 * Every form a human may choose, in a stable order.
		 *
		 * Forms are posts of type `agentshell_form`; a form's own post status
		 * records whether it has ever been published, while the working/published
		 * split lives in meta (§5.1). Both are read, because the picker must
		 * show a draft form (so it can be prepared) while saying it will not
		 * render yet.
		 *
		 * @return array<int,array{form_id:string,slug:string,name:string,published:bool}>
		 */
		private static function forms() {
			if ( ! function_exists( 'get_posts' ) ) {
				return array();
			}

			$posts = get_posts(
				array(
					'post_type'              => Form_Post_Types::FORM,
					'post_status'            => array( 'publish', 'draft', 'private' ),
					'numberposts'            => 200,
					'orderby'                => 'title',
					'order'                  => 'ASC',
					'suppress_filters'       => false,
					'no_found_rows'          => true,
					'update_post_meta_cache' => true,
				)
			);

			$forms = array();

			foreach ( is_array( $posts ) ? $posts : array() as $post ) {
				if ( ! is_object( $post ) || ! isset( $post->ID ) ) {
					continue;
				}

				$form_id = (string) get_post_meta( (int) $post->ID, Form_Post_Types::META_FORM_ID, true );
				if ( '' === $form_id ) {
					continue;
				}

				$forms[] = array(
					'form_id'   => $form_id,
					'slug'      => (string) get_post_meta( (int) $post->ID, Form_Post_Types::META_SLUG, true ),
					'name'      => isset( $post->post_title ) && '' !== (string) $post->post_title
						? (string) $post->post_title
						: $form_id,
					'published' => self::is_published( $form_id ),
				);
			}

			return $forms;
		}

		/**
		 * Whether a form has a published definition.
		 *
		 * @param string $form_id Internal identifier.
		 * @return bool
		 */
		private static function is_published( $form_id ) {
			$post_id = Form_Definition::resolve_post_id( $form_id );
			if ( $post_id < 1 ) {
				return false;
			}

			$state = Form_Definition::get_by_post_id( $post_id );

			return ! is_wp_error( $state ) && is_array( $state['published'] );
		}
	}
}

/**
 * Register the widget.
 *
 * Called on `widgets_init`, where WordPress expects widgets to be registered.
 * Defined here, at file scope, so the callback is reachable even on a host where
 * `WP_Widget` was absent when this file loaded.
 *
 * @return void
 */
function agentshell_forms_register_widget() {
	$class = __NAMESPACE__ . '\\Form_Widget';

	if ( class_exists( $class ) && is_callable( array( $class, 'register' ) ) ) {
		call_user_func( array( $class, 'register' ) );
	}
}
