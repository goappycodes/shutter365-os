<?php
/**
 * Shutters365 Business OS — loader / router.
 *
 * A full-screen owner dashboard served at /admin/ (and /owner/ as a fallback in
 * case /admin is intercepted at the server level). Authentication is delegated
 * entirely to WordPress: a logged-out visitor is bounced to wp-login and back;
 * a logged-in user without the `manage_woocommerce` capability is refused. So the
 * only people who ever see it are the shop owner and administrators.
 *
 * It renders its own standalone HTML document (not the marketing theme chrome)
 * and exits, so nothing else on the site is affected.
 *
 * @package Shutters365\BusinessOS
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/data.php';
require_once __DIR__ . '/render.php';

/** The URL paths that open the dashboard. */
function s365_bos_paths() {
	return apply_filters( 's365_bos_paths', array( 'admin', 'owner', 'business-os' ) );
}

/** Canonical dashboard URL (used for login redirects). */
function s365_bos_url() {
	return home_url( '/admin/' );
}

/**
 * Intercept the dashboard path as early as possible on the front end, before
 * WordPress's canonical redirect can bounce an unknown URL elsewhere.
 */
add_action( 'template_redirect', 's365_bos_maybe_render', 0 );
function s365_bos_maybe_render() {
	$path = isset( $_SERVER['REQUEST_URI'] ) ? wp_parse_url( $_SERVER['REQUEST_URI'], PHP_URL_PATH ) : '';
	$path = trim( (string) $path, '/' );
	// Only the exact path (optionally with a sub-view segment we ignore for now).
	$first = explode( '/', $path )[0];
	if ( ! in_array( $first, s365_bos_paths(), true ) ) {
		return;
	}

	// --- Authentication: delegated to WordPress ---------------------------
	if ( ! is_user_logged_in() ) {
		wp_safe_redirect( wp_login_url( s365_bos_url() ) );
		exit;
	}
	if ( ! current_user_can( 'manage_woocommerce' ) && ! current_user_can( 'manage_options' ) ) {
		wp_die(
			esc_html__( 'Your account doesn’t have access to the Shutters365 business dashboard.', 'shutters365' ),
			esc_html__( 'No access', 'shutters365' ),
			array( 'response' => 403 )
		);
	}

	// Optional cache-buster for a fresh pull.
	$force = isset( $_GET['refresh'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	if ( $force ) {
		delete_transient( 's365_bos_payload_v1' );
	}

	$data = s365_bos_payload( $force );

	nocache_headers();
	status_header( 200 );
	header( 'Content-Type: text/html; charset=utf-8' );
	s365_bos_render_page( $data );
	exit;
}

/**
 * Add a "Business dashboard" shortcut to the wp-admin toolbar and menu so the
 * owner can always find it.
 */
add_action( 'admin_bar_menu', 's365_bos_toolbar', 90 );
function s365_bos_toolbar( $bar ) {
	if ( ! current_user_can( 'manage_woocommerce' ) && ! current_user_can( 'manage_options' ) ) {
		return;
	}
	$bar->add_node( array(
		'id'    => 's365-bos',
		'title' => '📊 Business OS',
		'href'  => s365_bos_url(),
		'meta'  => array( 'title' => __( 'Open the Shutters365 Business OS', 'shutters365' ) ),
	) );
}

add_action( 'admin_menu', 's365_bos_admin_menu' );
function s365_bos_admin_menu() {
	add_menu_page(
		__( 'Business OS', 'shutters365' ),
		__( 'Business OS', 'shutters365' ),
		'manage_woocommerce',
		's365-business-os',
		'__return_null',
		'dashicons-chart-area',
		2
	);
}

/** Point that menu item straight at the front-end /admin/ dashboard. */
add_action( 'admin_init', 's365_bos_redirect_menu' );
function s365_bos_redirect_menu() {
	if ( isset( $_GET['page'] ) && 's365-business-os' === $_GET['page'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		wp_safe_redirect( s365_bos_url() );
		exit;
	}
}
