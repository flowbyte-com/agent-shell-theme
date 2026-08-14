<?php
namespace AgentShell_MCP;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Content primitives — intent-level wrappers over post/page CRUD for agents.
 *
 * Content is passed as raw HTML; WordPress auto-formatting (wpautop, kses)
 * is not applied because these tools run as administrators (unfiltered_html).
 * This matches the theme's REST API behaviour, where raw HTML is preserved.
 */
class Content_Primitives {
    const TYPES = array( 'post', 'page' );
    const STATUSES = array( 'draft', 'publish', 'pending', 'private', 'future' );

    /**
     * Create a post or page.
     *
     * @param string $type    'post' | 'page'
     * @param string $title   Post title
     * @param string $content Raw HTML content
     * @param string $status  draft|publish|pending|private|future
     * @param array  $extra   Optional: post_name, parent (pages), comment_status,
     *                        ping_status, post_date
     * @return array Created record { id, type, title, status, edit_url }
     * @throws \InvalidArgumentException
     */
    public static function create( $type, $title, $content, $status = 'draft', array $extra = array() ) {
        if ( ! in_array( $type, self::TYPES, true ) ) {
            throw new \InvalidArgumentException( "Unsupported type '{$type}'. Use post or page." );
        }
        if ( ! in_array( $status, self::STATUSES, true ) ) {
            throw new \InvalidArgumentException( "Unsupported status '{$status}'. Use " . implode( ', ', self::STATUSES ) . '.' );
        }

        $postarr = array(
            'post_type'    => $type,
            'post_status'  => $status,
            'post_title'   => sanitize_text_field( $title ),
            'post_content' => (string) $content,
        );

        foreach ( array( 'post_name', 'comment_status', 'ping_status', 'post_date' ) as $key ) {
            if ( isset( $extra[ $key ] ) ) {
                $postarr[ $key ] = $extra[ $key ];
            }
        }
        if ( $type === 'page' && ! empty( $extra['parent'] ) ) {
            $postarr['post_parent'] = (int) $extra['parent'];
        }

        $id = wp_insert_post( wp_slash( $postarr ), true );
        if ( is_wp_error( $id ) ) {
            throw new \InvalidArgumentException( 'Could not create ' . $type . ': ' . $id->get_error_message() );
        }
        if ( ! $id ) {
            throw new \InvalidArgumentException( 'Could not create ' . $type . '. WordPress returned no post ID.' );
        }

        return self::describe( $id, $type );
    }

    /**
     * Update a post or page (title / content / status / slug).
     *
     * @param int    $id      Post ID
     * @param string $type    'post' | 'page'
     * @param array  $changes Keys: title, content, status, slug, parent
     * @return array Updated record
     * @throws \InvalidArgumentException
     */
    public static function update( $id, $type, array $changes ) {
        $existing = self::require_post( $id, $type );

        $postarr = array( 'ID' => (int) $id );
        foreach ( $changes as $key => $value ) {
            switch ( $key ) {
                case 'title':
                    $postarr['post_title'] = sanitize_text_field( $value );
                    break;
                case 'content':
                    $postarr['post_content'] = (string) $value;
                    break;
                case 'status':
                    if ( ! in_array( $value, self::STATUSES, true ) ) {
                        throw new \InvalidArgumentException( "Unsupported status '{$value}'. Use " . implode( ', ', self::STATUSES ) . '.' );
                    }
                    $postarr['post_status'] = $value;
                    break;
                case 'slug':
                    $postarr['post_name'] = sanitize_title( $value );
                    break;
                case 'parent':
                    if ( $type !== 'page' ) {
                        throw new \InvalidArgumentException( 'parent only applies to pages.' );
                    }
                    $postarr['post_parent'] = (int) $value;
                    break;
                default:
                    throw new \InvalidArgumentException( "Unknown field '{$key}'. Supported: title, content, status, slug, parent." );
            }
        }

        $result = wp_update_post( wp_slash( $postarr ), true );
        if ( is_wp_error( $result ) ) {
            throw new \InvalidArgumentException( 'Could not update ' . $type . ' #' . $id . ': ' . $result->get_error_message() );
        }
        if ( ! $result ) {
            throw new \InvalidArgumentException( 'Could not update ' . $type . ' #' . $id . '. WordPress returned no post ID.' );
        }

        return self::describe( (int) $id, $type );
    }

    /**
     * Set a post's status (publish/unpublish/draft).
     *
     * @return array Updated record
     */
    public static function set_status( $id, $type, $status ) {
        return self::update( $id, $type, array( 'status' => $status ) );
    }

    /**
     * Search posts/pages. Returns compact records.
     *
     * @param string $query  Search term (optional; empty lists recent)
     * @param string $type   post|page|both
     * @param string $status any|draft|publish|pending|private|future
     * @param int    $limit  1-50
     * @return array { total, items: [ { id, title, type, status, date, modified, excerpt } ] }
     */
    public static function search( $query, $type = 'both', $status = 'any', $limit = 10 ) {
        $post_type = $type === 'both' ? array( 'post', 'page' ) : $type;
        if ( ! in_array( $type, array( 'both', 'post', 'page' ), true ) ) {
            throw new \InvalidArgumentException( "Unsupported type '{$type}'. Use post, page or both." );
        }
        $args = array(
            'post_type'      => $post_type,
            'post_status'    => $status,
            'posts_per_page' => max( 1, min( 50, (int) $limit ) ),
            'no_found_rows'  => false,
            'orderby'        => 'modified',
            'order'          => 'DESC',
        );
        if ( $query !== '' ) {
            $args['s'] = $query;
        }

        $query_obj = new \WP_Query( $args );
        $items     = array();
        foreach ( $query_obj->posts as $post ) {
            $items[] = self::compact( $post );
        }
        wp_reset_postdata();

        return array(
            'total' => (int) $query_obj->found_posts,
            'items' => $items,
        );
    }

    /**
     * Fetch one post/page.
     *
     * @return array Record incl. raw content (rendered and raw variants)
     * @throws \InvalidArgumentException
     */
    public static function get( $id, $type ) {
        $post = self::require_post( $id, $type );
        return array_merge( self::compact( $post ), array(
            'content' => array(
                'raw'     => $post->post_content,
                'rendered' => apply_filters( 'the_content', $post->post_content ),
            ),
            'slug'    => $post->post_name,
            'link'    => get_permalink( $post ),
        ) );
    }

    /**
     * Full record for create/update responses.
     */
    private static function describe( $id, $type ) {
        $post = self::require_post( $id, $type );
        $record = self::compact( $post );
        $record['content'] = array(
            'raw'       => $post->post_content,
            'length'    => strlen( $post->post_content ),
        );
        $record['link'] = get_permalink( $post );
        $record['edit_url'] = admin_url( 'post.php?post=' . $id . '&action=edit' );
        return $record;
    }

    private static function require_post( $id, $type ) {
        if ( ! in_array( $type, self::TYPES, true ) ) {
            throw new \InvalidArgumentException( "Unsupported type '{$type}'. Use post or page." );
        }
        $post = get_post( (int) $id );
        if ( ! $post || $post->post_type !== $type ) {
            throw new \InvalidArgumentException( $type . ' #' . (int) $id . ' not found.' );
        }
        return $post;
    }

    private static function compact( $post ) {
        return array(
            'id'       => (int) $post->ID,
            'title'    => get_the_title( $post ),
            'type'     => $post->post_type,
            'status'   => $post->post_status,
            'date'     => get_the_date( 'Y-m-d H:i:s', $post ),
            'modified' => get_the_modified_date( 'Y-m-d H:i:s', $post ),
            'excerpt'  => trim( wp_strip_all_tags( get_the_excerpt( $post ) ) ),
        );
    }
}