<?php
/**
 * Class ACF Field Inspector
 *
 * @package PostMigrator
 */

declare( strict_types = 1 );

namespace PostMigrator;

/**
 * Looks up the ACF field type behind a meta value or block field value, when ACF is available.
 *
 * ACF writes a sibling "_<meta_key>" meta row (or, inside an ACF block's own
 * attrs.data, a sibling "_<name>" entry) holding the field's key alongside
 * every value it stores. Resolving that key via acf_get_field() is what lets
 * this plugin tell "this value is an attachment/post reference" from "this
 * value is coincidentally numeric but is a plain text/number field" without
 * needing to read the active theme's acf-json field group files directly.
 */
class ACF_Field_Inspector {
	/**
	 * Get the ACF field type for a postmeta key, if it is an ACF field.
	 *
	 * @param int    $post_id  The post ID.
	 * @param string $meta_key The meta key.
	 * @return string|null The ACF field type (e.g. "image", "gallery", "post_object"), or null.
	 */
	public static function get_field_type_for_meta( int $post_id, string $meta_key ): ?string {
		$field = self::resolve_field( self::field_key_for_meta( $post_id, $meta_key ) );

		return isset( $field['type'] ) ? (string) $field['type'] : null;
	}

	/**
	 * Get the ACF field type for a field key found inside an ACF block's own
	 * attrs.data, using the same "_<name>" sibling-key convention.
	 *
	 * @param string $field_key The ACF field key.
	 * @return string|null
	 */
	public static function get_field_type_for_block_field( string $field_key ): ?string {
		$field = self::resolve_field( $field_key );

		return isset( $field['type'] ) ? (string) $field['type'] : null;
	}

	/**
	 * Get the taxonomy a "taxonomy"-type ACF postmeta field is bound to.
	 *
	 * @param int    $post_id  The post ID.
	 * @param string $meta_key The meta key.
	 * @return string|null
	 */
	public static function get_taxonomy_for_meta( int $post_id, string $meta_key ): ?string {
		$field = self::resolve_field( self::field_key_for_meta( $post_id, $meta_key ) );

		return isset( $field['taxonomy'] ) ? (string) $field['taxonomy'] : null;
	}

	/**
	 * Get the taxonomy a "taxonomy"-type ACF block field is bound to.
	 *
	 * @param string $field_key The ACF field key.
	 * @return string|null
	 */
	public static function get_taxonomy_for_block_field( string $field_key ): ?string {
		$field = self::resolve_field( $field_key );

		return isset( $field['taxonomy'] ) ? (string) $field['taxonomy'] : null;
	}

	/**
	 * Look up the ACF field key stored alongside a postmeta value, via the
	 * "_<meta_key>" sibling row ACF writes.
	 *
	 * @param int    $post_id  The post ID.
	 * @param string $meta_key The meta key.
	 * @return string
	 */
	private static function field_key_for_meta( int $post_id, string $meta_key ): string {
		$field_key = get_post_meta( $post_id, '_' . $meta_key, true );

		return is_string( $field_key ) ? $field_key : '';
	}

	/**
	 * Resolve an ACF field key to its registered field definition.
	 *
	 * @param string $field_key The ACF field key.
	 * @return array|null
	 */
	private static function resolve_field( string $field_key ): ?array {
		if ( '' === $field_key || ! function_exists( 'acf_get_field' ) ) {
			return null;
		}

		$field = acf_get_field( $field_key );

		return is_array( $field ) ? $field : null;
	}
}
