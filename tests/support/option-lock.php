<?php
/** In-memory adapter for Mutation_Lock's atomic insert and compare-and-delete. */
if ( ! function_exists( 'get_option' ) ) { function get_option( $key, $default = false ) { return $GLOBALS['options'][ $key ] ?? $default; } }
if ( ! function_exists( 'add_option' ) ) { function add_option( $key, $value, ...$unused ) { if ( array_key_exists( $key, $GLOBALS['options'] ?? array() ) ) return false; $GLOBALS['options'][ $key ] = $value; return true; } }
if ( ! function_exists( 'wp_cache_delete' ) ) { function wp_cache_delete( $key, $group ) {} }
if ( ! function_exists( 'maybe_serialize' ) ) { function maybe_serialize( $value ) { return is_array( $value ) || is_object( $value ) ? serialize( $value ) : $value; } }
if ( ! isset( $GLOBALS['wpdb'] ) ) {
	$GLOBALS['wpdb'] = new class {
		public $options = 'wp_options';
		public function prepare( $sql, ...$args ) { return array( $sql, $args ); }
		public function query( $statement ) {
			list( $sql, $args ) = $statement;
			if ( 0 === strpos( $sql, 'INSERT IGNORE INTO wp_options ' ) ) {
				list( $key, $value ) = $args;
				if ( isset( $GLOBALS['before_lock'] ) && is_callable( $GLOBALS['before_lock'] ) ) {
					$hook = $GLOBALS['before_lock']; unset( $GLOBALS['before_lock'] ); $hook( $key );
				}
				if ( array_key_exists( $key, $GLOBALS['options'] ?? array() ) ) return 0;
				$GLOBALS['options'][ $key ] = unserialize( $value );
				return 1;
			}
			if ( 0 !== strpos( $sql, 'DELETE FROM wp_options WHERE option_name = ' ) ) throw new RuntimeException( 'Unexpected lock fixture query.' );
			list( $key, $expected ) = $args;
			if ( ! array_key_exists( $key, $GLOBALS['options'] ?? array() ) || maybe_serialize( $GLOBALS['options'][ $key ] ) !== $expected ) return 0;
			unset( $GLOBALS['options'][ $key ] );
			return 1;
		}
	};
}
