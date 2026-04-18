<?php
namespace AgentShell_MCP;

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Auth {
    public function authenticate( \WP_REST_Request $request ) {
        $auth_header = $request->get_header( 'authorization' );
        if ( ! $auth_header || ! str_starts_with( strtolower( $auth_header ), 'basic ' ) ) {
            return new \WP_Error( 'no_auth', 'Missing Basic Auth header' );
        }

        $creds = base64_decode( substr( $auth_header, 6 ), true );
        if ( ! $creds || ! str_contains( $creds, ':' ) ) {
            return new \WP_Error( 'bad_creds', 'Invalid Basic Auth format' );
        }

        $parts        = explode( ':', $creds, 2 );
        $username     = trim( $parts[0] );
        $app_password = $parts[1];

        $user = get_user_by( 'login', $username )
             ?: get_user_by( 'email', $username );

        if ( ! $user ) {
            return new \WP_Error( 'user_not_found', 'User not found' );
        }

        $stored = \WP_Application_Passwords::get_user_application_passwords( $user->ID );
        foreach ( $stored as $item ) {
            if ( \WP_Application_Passwords::check_password( $app_password, $item['password'] ) ) {
                if ( ! user_can( $user, 'manage_options' ) ) {
                    return new \WP_Error( 'forbidden', 'User must have manage_options capability' );
                }
                wp_set_current_user( $user->ID );
                return array( 'user_id' => $user->ID );
            }
        }

        return new \WP_Error( 'invalid_password', 'Invalid application password' );
    }
}
