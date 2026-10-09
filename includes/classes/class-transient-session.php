<?php
/**
 * Class Transient Session
 *
 * @package PostMigrator
 */

declare( strict_types = 1 );

namespace PostMigrator;

/**
 * Persists short-lived, user-scoped data between steps of a multi-page admin flow.
 *
 * Carrying the real data server-side (rather than round-tripping it through
 * hidden form fields) means the browser only ever carries an opaque token,
 * so a modified hidden field can't be used to smuggle in data the server
 * never actually computed.
 */
class Transient_Session {
	/**
	 * Default time-to-live, in seconds, for a session that doesn't specify one.
	 *
	 * @var int
	 */
	public const DEFAULT_TTL = 900;

	/**
	 * Start a new session, storing the given payload under a fresh opaque token.
	 *
	 * @param string $prefix  A namespace for this type of session (e.g. "push_batch").
	 * @param array  $payload The data to store.
	 * @param int    $ttl     How long, in seconds, the session should live.
	 * @return string The opaque token the session was stored under.
	 */
	public static function start( string $prefix, array $payload, int $ttl = self::DEFAULT_TTL ): string {
		$token = wp_generate_password( 32, false );

		set_transient( self::key( $prefix, $token ), self::with_owner( $payload ), $ttl );

		return $token;
	}

	/**
	 * Get a previously stored session's payload, if it exists and belongs to the current user.
	 *
	 * @param string $prefix The session's namespace.
	 * @param string $token  The session's opaque token.
	 * @return array|null
	 */
	public static function get( string $prefix, string $token ): ?array {
		if ( '' === $token ) {
			return null;
		}

		$stored = get_transient( self::key( $prefix, $token ) );

		if ( ! is_array( $stored ) || ! isset( $stored['user_id'] ) || get_current_user_id() !== (int) $stored['user_id'] ) {
			return null;
		}

		return $stored;
	}

	/**
	 * Replace a previously stored session's payload.
	 *
	 * @param string $prefix  The session's namespace.
	 * @param string $token   The session's opaque token.
	 * @param array  $payload The new data to store.
	 * @param int    $ttl     How long, in seconds, the session should live.
	 * @return void
	 */
	public static function update( string $prefix, string $token, array $payload, int $ttl = self::DEFAULT_TTL ) {
		set_transient( self::key( $prefix, $token ), self::with_owner( $payload ), $ttl );
	}

	/**
	 * Delete a previously stored session.
	 *
	 * @param string $prefix The session's namespace.
	 * @param string $token  The session's opaque token.
	 * @return void
	 */
	public static function delete( string $prefix, string $token ) {
		delete_transient( self::key( $prefix, $token ) );
	}

	/**
	 * Build the transient key for a given session namespace and token.
	 *
	 * @param string $prefix The session's namespace.
	 * @param string $token  The session's opaque token.
	 * @return string
	 */
	private static function key( string $prefix, string $token ): string {
		return 'post_migrator_' . $prefix . '_' . $token;
	}

	/**
	 * Attach the current user's ID to a payload, so ownership can be verified on read.
	 *
	 * @param array $payload The data to store.
	 * @return array
	 */
	private static function with_owner( array $payload ): array {
		$payload['user_id'] = get_current_user_id();

		return $payload;
	}
}
