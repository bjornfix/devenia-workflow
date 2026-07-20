<?php
/**
 * WordPress storage compatibility Adapter.
 *
 * @package Devenia_Workflow
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

trait Devenia_Workflow_WordPress_Storage_Adapter {
	/**
	 * Encode supplementary Unicode as HTML numeric references before values
	 * reach legacy utf8mb3 WordPress tables. The mapping is deterministic and
	 * browser-visible output remains unchanged.
	 *
	 * @param mixed $value A scalar or nested storage surface.
	 * @return mixed
	 */
	private static function wordpress_utf8mb3_safe_storage_value( $value ) {
		if ( is_array( $value ) ) {
			foreach ( $value as $key => $item ) {
				$value[ $key ] = self::wordpress_utf8mb3_safe_storage_value( $item );
			}
			return $value;
		}
		if ( ! is_string( $value ) || '' === $value ) {
			return $value;
		}
		$encoded = preg_replace_callback(
			'/[\x{10000}-\x{10FFFF}]/u',
			static function ( array $match ): string {
				$bytes = array_values( unpack( 'C*', $match[0] ) ?: array() );
				if ( 4 !== count( $bytes ) ) {
					return $match[0];
				}
				$codepoint = ( ( $bytes[0] & 0x07 ) << 18 )
					| ( ( $bytes[1] & 0x3F ) << 12 )
					| ( ( $bytes[2] & 0x3F ) << 6 )
					| ( $bytes[3] & 0x3F );
				return '&#x' . strtoupper( dechex( $codepoint ) ) . ';';
			},
			$value
		);
		return is_string( $encoded ) ? $encoded : $value;
	}
}
