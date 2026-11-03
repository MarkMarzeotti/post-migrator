<?php
/**
 * Class Site Profile
 *
 * @package PostMigrator
 */

declare( strict_types = 1 );

namespace PostMigrator;

/**
 * Describes this site's migration-relevant setup (plugin version, ACF,
 * browsable post types and their taxonomies), and compares two such
 * profiles to warn about mismatches that would make a migration silently
 * drop data or fail partway through.
 *
 * Direction-agnostic: callers always pass the side content comes from as
 * $source and the side it's written to as $destination.
 */
class Site_Profile {
	/**
	 * Describe this site's migration-relevant setup.
	 *
	 * @return array
	 */
	public static function describe(): array {
		$post_types = array();

		foreach ( array_keys( Content_Browser::get_browsable_post_types() ) as $slug ) {
			$taxonomies = get_object_taxonomies( $slug );
			sort( $taxonomies );

			$post_types[ $slug ] = array(
				'hierarchical' => is_post_type_hierarchical( $slug ),
				'taxonomies'   => array_values( $taxonomies ),
			);
		}

		return array(
			'version'    => POST_MIGRATOR_VERSION,
			'acf_active' => function_exists( 'acf_get_field' ),
			'post_types' => $post_types,
		);
	}

	/**
	 * Compare a source and destination profile for one post type.
	 *
	 * Either profile may have come from a remote site, so every field is
	 * read defensively.
	 *
	 * @param array  $source      The profile of the site content is read from.
	 * @param array  $destination The profile of the site content is written to.
	 * @param string $post_type   The post type being migrated.
	 * @return string[] Translated warnings; empty when no mismatch was found.
	 */
	public static function compare( array $source, array $destination, string $post_type ): array {
		$warnings = array();

		$source_version      = isset( $source['version'] ) && is_scalar( $source['version'] ) ? (string) $source['version'] : '';
		$destination_version = isset( $destination['version'] ) && is_scalar( $destination['version'] ) ? (string) $destination['version'] : '';

		if ( $source_version !== $destination_version ) {
			$warnings[] = sprintf(
				/* translators: 1: source site's plugin version, 2: destination site's plugin version */
				__( 'Post Migrator versions differ (source %1$s, destination %2$s). Some data may not migrate as expected.', 'post-migrator' ),
				'' !== $source_version ? $source_version : __( 'unknown', 'post-migrator' ),
				'' !== $destination_version ? $destination_version : __( 'unknown', 'post-migrator' )
			);
		}

		$source_types      = isset( $source['post_types'] ) && is_array( $source['post_types'] ) ? $source['post_types'] : array();
		$destination_types = isset( $destination['post_types'] ) && is_array( $destination['post_types'] ) ? $destination['post_types'] : array();
		$source_type       = isset( $source_types[ $post_type ] ) && is_array( $source_types[ $post_type ] ) ? $source_types[ $post_type ] : null;
		$destination_type  = isset( $destination_types[ $post_type ] ) && is_array( $destination_types[ $post_type ] ) ? $destination_types[ $post_type ] : null;

		if ( null === $destination_type ) {
			$warnings[] = sprintf(
				/* translators: %s: post type slug */
				__( 'The destination site has no "%s" post type. Migrating these posts will fail.', 'post-migrator' ),
				$post_type
			);
		} elseif ( null !== $source_type ) {
			if ( ! empty( $source_type['hierarchical'] ) !== ! empty( $destination_type['hierarchical'] ) ) {
				$warnings[] = sprintf(
					/* translators: %s: post type slug */
					__( 'The "%s" post type is hierarchical on only one of the sites. Parent pages will not carry over.', 'post-migrator' ),
					$post_type
				);
			}

			$source_taxonomies      = isset( $source_type['taxonomies'] ) && is_array( $source_type['taxonomies'] ) ? array_map( 'strval', $source_type['taxonomies'] ) : array();
			$destination_taxonomies = isset( $destination_type['taxonomies'] ) && is_array( $destination_type['taxonomies'] ) ? array_map( 'strval', $destination_type['taxonomies'] ) : array();
			$missing                = array_values( array_diff( $source_taxonomies, $destination_taxonomies ) );

			if ( ! empty( $missing ) ) {
				$warnings[] = sprintf(
					/* translators: 1: comma-separated taxonomy slugs, 2: post type slug */
					__( 'The destination site does not have these taxonomies for "%2$s": %1$s. Those terms will be skipped.', 'post-migrator' ),
					implode( ', ', $missing ),
					$post_type
				);
			}
		}

		// ACF field definitions are consulted on both sides: the source uses
		// them to find referenced attachments/posts worth sending, and the
		// destination uses them to remap those references to local IDs.
		if ( ! empty( $source['acf_active'] ) !== ! empty( $destination['acf_active'] ) ) {
			$warnings[] = __( 'ACF is active on only one of the sites. ACF field values will copy as raw meta, and image or post references inside them may not be remapped.', 'post-migrator' );
		}

		return $warnings;
	}
}
