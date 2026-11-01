<?php
/**
 * Small table gateway shared by the repositories: typed rows, insert/update/delete by id.
 *
 * @package Dev3\AiBooking
 */

namespace Dev3\AiBooking\Data;

defined( 'ABSPATH' ) || exit;

/**
 * Base repository. Subclasses set TABLE and FIELDS (column => type: int|float|bool|json|string).
 */
abstract class Repo {

	const TABLE = '';

	const FIELDS = array();

	/**
	 * Per-request row cache.
	 *
	 * @var array
	 */
	protected static $memo = array();

	/**
	 * Table name.
	 *
	 * @return string
	 */
	public static function table() {
		return Tables::name( static::TABLE );
	}

	/**
	 * Current UTC datetime string.
	 *
	 * @return string
	 */
	public static function now() {
		return gmdate( 'Y-m-d H:i:s', \Dev3\AiBooking\Availability\Clock::now() );
	}

	/**
	 * Casts a raw DB row.
	 *
	 * @param array|null $row Row.
	 * @return array|null
	 */
	public static function cast( $row ) {
		if ( ! $row ) {
			return null;
		}
		$out = array();
		foreach ( $row as $key => $value ) {
			$type = static::FIELDS[ $key ] ?? 'string';
			switch ( $type ) {
				case 'int':
					$out[ $key ] = (int) $value;
					break;
				case 'float':
					$out[ $key ] = round( (float) $value, 2 );
					break;
				case 'bool':
					$out[ $key ] = (bool) (int) $value;
					break;
				case 'json':
					$decoded     = is_string( $value ) && '' !== $value ? json_decode( $value, true ) : null;
					$out[ $key ] = is_array( $decoded ) ? $decoded : array();
					break;
				default:
					$out[ $key ] = null === $value ? null : (string) $value;
			}
		}
		return $out;
	}

	/**
	 * Prepares values for writing (JSON encodes arrays, keeps only known columns).
	 *
	 * @param array $data Data.
	 * @return array
	 */
	protected static function dehydrate( array $data ) {
		$out = array();
		foreach ( $data as $key => $value ) {
			if ( ! isset( static::FIELDS[ $key ] ) || 'id' === $key ) {
				continue;
			}
			$type = static::FIELDS[ $key ];
			if ( 'json' === $type ) {
				$value = wp_json_encode( is_array( $value ) ? $value : array() );
			} elseif ( 'bool' === $type ) {
				$value = $value ? 1 : 0;
			} elseif ( 'int' === $type ) {
				$value = (int) $value;
			} elseif ( 'float' === $type ) {
				$value = round( (float) $value, 2 );
			}
			$out[ $key ] = $value;
		}
		return $out;
	}

	/**
	 * Row by id.
	 *
	 * @param int $id Id.
	 * @return array|null
	 */
	public static function get( $id ) {
		$id = (int) $id;
		if ( $id <= 0 ) {
			return null;
		}
		$key = static::TABLE . ':' . $id;
		if ( ! array_key_exists( $key, self::$memo ) ) {
			global $wpdb;
			self::$memo[ $key ] = static::cast( $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . static::table() . ' WHERE id = %d', $id ), ARRAY_A ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		}
		return self::$memo[ $key ];
	}

	/**
	 * Inserts a row.
	 *
	 * @param array $data Data.
	 * @return int Id (0 on failure).
	 */
	public static function insert( array $data ) {
		global $wpdb;
		$now  = self::now();
		$data = static::dehydrate( $data );
		if ( isset( static::FIELDS['created_at'] ) && empty( $data['created_at'] ) ) {
			$data['created_at'] = $now;
		}
		if ( isset( static::FIELDS['updated_at'] ) ) {
			$data['updated_at'] = $now;
		}
		$ok = $wpdb->insert( static::table(), $data ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return $ok ? (int) $wpdb->insert_id : 0;
	}

	/**
	 * Updates a row.
	 *
	 * @param int   $id   Id.
	 * @param array $data Data.
	 * @return bool
	 */
	public static function update( $id, array $data ) {
		global $wpdb;
		$data = static::dehydrate( $data );
		if ( isset( static::FIELDS['updated_at'] ) ) {
			$data['updated_at'] = self::now();
		}
		unset( self::$memo[ static::TABLE . ':' . (int) $id ] );
		return false !== $wpdb->update( static::table(), $data, array( 'id' => (int) $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Deletes a row.
	 *
	 * @param int $id Id.
	 * @return bool
	 */
	public static function delete( $id ) {
		global $wpdb;
		unset( self::$memo[ static::TABLE . ':' . (int) $id ] );
		return (bool) $wpdb->delete( static::table(), array( 'id' => (int) $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Clears the row cache (tests, bulk changes).
	 */
	public static function forget() {
		self::$memo = array();
	}

	/**
	 * Rows for a prepared query.
	 *
	 * @param string $sql Prepared SQL.
	 * @return array
	 */
	protected static function rows( $sql ) {
		global $wpdb;
		$rows = $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		return array_map( array( static::class, 'cast' ), is_array( $rows ) ? $rows : array() );
	}
}
