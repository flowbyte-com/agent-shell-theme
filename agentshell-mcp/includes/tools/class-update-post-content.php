<?php
namespace AgentShell_MCP\Tools;

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Update_Post_Content extends Base_Tool {
    public function get_name() { return 'agentshell_update_post_content'; }
    public function get_description() { return 'Update a specific post/page HTML content via wp_update_post(). Style tags and inline style attributes are stripped for untrusted users (Unbreakable Grid). Admin users with unfiltered_html/manage_options preserve all tags including <style> and <script>.'; }
    public function get_input_schema() {
        return array(
            'type'       => 'object',
            'properties' => array(
                'post_id'     => array( 'type' => 'integer', 'description' => 'Target post/page ID' ),
                'html_content' => array( 'type' => 'string', 'description' => 'HTML content to set as post content (max 100000 chars)', 'maxLength' => 100000 ),
            ),
            'required'   => array( 'post_id', 'html_content' ),
        );
    }

    public function execute( array $arguments ) {
        $this->validate_required( $arguments, array( 'post_id', 'html_content' ) );

        $post_id = absint( $arguments['post_id'] );
        if ( $post_id === 0 ) {
            throw new \InvalidArgumentException( 'post_id must be a non-zero integer' );
        }

        $html = $arguments['html_content'];
        if ( strlen( $html ) > 100000 ) {
            throw new \InvalidArgumentException( 'html_content must be 100000 characters or less' );
        }

        // Check capability before any stripping. Trusted users (unfiltered_html or manage_options)
        // get full passthrough — no style stripping, no script stripping, no KSES.
        // This preserves custom Web Components that use customElements.define.
        $trusted = current_user_can( 'unfiltered_html' ) || current_user_can( 'manage_options' );

        if ( ! $trusted ) {
            // Security: Unbreakable Grid — strip <style> tags and inline style="" attributes.
            // These can break the fixed CSS Grid structure regardless of user capability.
            $html = preg_replace( '/<style\b[^>]*>.*?<\/style>/is', '', $html );
            $html = preg_replace( '/\s+style\s*=\s*["\'][^"\']*["\']/i', '', $html );

            // Strip scripts and run KSES sanitization for untrusted users.
            $html = preg_replace( '/<\/?script\b[^>]*>/i', '', $html );
            $html = wp_kses_post( $html );
        }

        $post = get_post( $post_id );
        if ( ! $post ) {
            throw new \InvalidArgumentException( "Post not found: {$post_id}" );
        }

        $result = wp_update_post( array(
            'ID'           => $post_id,
            'post_content' => $html,
        ), true );

        if ( is_wp_error( $result ) ) {
            throw new \RuntimeException( 'wp_update_post failed: ' . $result->get_error_message() );
        }

        return array(
            'post_id'      => $post_id,
            'post_title'   => get_the_title( $post_id ),
            'content_length' => strlen( $html ),
            'scripts_preserved' => $trusted,
        );
    }
}
