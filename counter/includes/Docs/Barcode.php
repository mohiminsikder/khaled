<?php
namespace Counter\Docs;

defined( 'ABSPATH' ) || exit;

/**
 * Inline SVG, server-side, no barcode font and no CDN — the whole point is
 * that a label still renders with the shop's internet down. Code 128 (subset
 * B — the printable-ASCII subset, which covers every character a SKU code
 * actually uses) for internal SKUs; EAN-13 where the product carries a real
 * GTIN.
 *
 * The Code 128 pattern table (CODE128_PATTERNS, indexed by symbol "value"
 * 0-106) is transcribed from Wikipedia's Code 128 article. test_barcode()
 * check 1 re-derives Wikipedia's own worked example ("PJJ123C", Start Code A,
 * published checksum value 54) from an independently-typed slice of this same
 * table, rather than trusting this file not to have a transcription error.
 * Subset A and subset B share identical values 0-95 — Start A is only ever
 * used by that self-test vector; the public code128_svg() below always emits
 * Start Code B.
 */
class Barcode {

	const CODE128_START_A = 103;
	const CODE128_START_B = 104;
	const CODE128_START_C = 105;
	const CODE128_STOP    = 106;

	const CODE128_PATTERNS = [
		'212222', '222122', '222221', '121223', '121322', '131222', '122213', '122312', '132212', '221213',
		'221312', '231212', '112232', '122132', '122231', '113222', '123122', '123221', '223211', '221132',
		'221231', '213212', '223112', '312131', '311222', '321122', '321221', '312212', '322112', '322211',
		'212123', '212321', '232121', '111323', '131123', '131321', '112313', '132113', '132311', '211313',
		'231113', '231311', '112133', '112331', '132131', '113123', '113321', '133121', '313121', '211331',
		'231131', '213113', '213311', '213131', '311123', '311321', '331121', '312113', '312311', '332111',
		'314111', '221411', '431111', '111224', '111422', '121124', '121421', '141122', '141221', '112214',
		'112412', '122114', '122411', '142112', '142211', '241211', '221114', '413111', '241112', '134111',
		'111242', '121142', '121241', '114212', '124112', '124211', '411212', '421112', '421211', '212141',
		'214121', '412121', '111143', '111341', '131141', '114113', '114311', '411113', '411311', '113141',
		'114131', '311141', '411131', '211412', '211214', '211232', '233111',
	];

	// EAN-13 module patterns, 7 modules each, indexed by digit 0-9.
	const EAN_L = [ '0001101', '0011001', '0010011', '0111101', '0100011', '0110001', '0101111', '0111011', '0110111', '0001011' ];
	const EAN_G = [ '0100111', '0110011', '0011011', '0100001', '0011101', '0111001', '0000101', '0010001', '0001001', '0010111' ];
	const EAN_R = [ '1110010', '1100110', '1101100', '1000010', '1011100', '1001110', '1010000', '1000100', '1001000', '1110100' ];

	// Which of L/G encodes each of the left 6 digits, keyed by the first (implicit) digit.
	const EAN_PARITY = [
		'LLLLLL', 'LLGLGG', 'LLGGLG', 'LLGGGL', 'LGLLGG',
		'LGGLLG', 'LGGGLL', 'LGLGLG', 'LGLGGL', 'LGGLGL',
	];

	/**
	 * Weighted mod-103 checksum used by every Code 128 subset: the start
	 * value carries weight 1, then each following data value carries a
	 * weight equal to its 1-based position among the data values. $values
	 * must begin with a start code (103/104/105) and hold only data values
	 * (0-102) after that — no checksum or stop appended yet.
	 */
	public static function code128_checksum( array $values ): int {
		$sum = $values[0];
		for ( $i = 1, $n = count( $values ); $i < $n; $i++ ) {
			$sum += $values[ $i ] * $i;
		}
		return $sum % 103;
	}

	/**
	 * Concatenates the module-width pattern for a full value sequence
	 * (start + data + checksum + stop), then appends the stop symbol's
	 * mandatory trailing 2-module bar — the stop pattern is 13 modules / 4
	 * bars, not the 11-module / 3-bar pattern the table alone encodes for
	 * every other symbol. Returns a flat list of module widths (ints); the
	 * colour of width[$i] is always bar when $i is even, space when odd —
	 * every symbol's own 6-digit pattern starts on a bar and ends on a
	 * space, so concatenation alone keeps that alternation correct across
	 * symbol boundaries with no extra bookkeeping.
	 */
	public static function code128_widths( array $full_values ): array {
		$widths = [];
		foreach ( $full_values as $v ) {
			foreach ( str_split( self::CODE128_PATTERNS[ $v ] ) as $w ) {
				$widths[] = (int) $w;
			}
		}
		$widths[] = 2; // stop symbol's extra trailing bar
		return $widths;
	}

	/**
	 * Public Code 128, subset B only (ASCII 32-126 — every character a SKU
	 * actually uses). Returns null for empty input or a character outside
	 * subset B, never a barcode encoding nothing or encoding garbage.
	 */
	public static function code128_svg( ?string $text, float $module_mm = 0.33, float $height_mm = 15.0 ): ?string {
		if ( null === $text || '' === $text ) {
			return null;
		}
		$values = [ self::CODE128_START_B ];
		foreach ( str_split( $text ) as $ch ) {
			$ord = ord( $ch );
			if ( $ord < 32 || $ord > 126 ) {
				return null; // outside Code 128 subset B
			}
			$values[] = $ord - 32;
		}
		$values[] = self::code128_checksum( $values );
		$values[] = self::CODE128_STOP;

		return self::widths_to_svg( self::code128_widths( $values ), $module_mm, $height_mm );
	}

	/**
	 * The check digit: alternating weights 1,3,1,3,... left to right over
	 * the first 12 digits, mod 10, then (10 - remainder) mod 10. $digits12
	 * must be exactly 12 digit characters.
	 */
	public static function ean13_check_digit( string $digits12 ): int {
		$sum = 0;
		foreach ( str_split( $digits12 ) as $i => $d ) {
			$sum += (int) $d * ( 0 === $i % 2 ? 1 : 3 );
		}
		return ( 10 - ( $sum % 10 ) ) % 10;
	}

	/**
	 * True only for a 13-digit numeric string whose 13th digit matches the
	 * check digit computed from the first 12.
	 */
	public static function ean13_is_valid( ?string $code ): bool {
		if ( null === $code || 13 !== strlen( $code ) || 1 !== preg_match( '/^\d{13}$/', $code ) ) {
			return false;
		}
		return (int) $code[12] === self::ean13_check_digit( substr( $code, 0, 12 ) );
	}

	/**
	 * EAN-13 as inline SVG. $code must be a full, valid 13-digit GTIN
	 * (check digit included) — an invalid or malformed code returns null
	 * rather than rendering something a scanner would reject anyway.
	 */
	public static function ean13_svg( ?string $code, float $module_mm = 0.33, float $height_mm = 18.0 ): ?string {
		if ( null === $code || '' === $code || ! self::ean13_is_valid( $code ) ) {
			return null;
		}

		$first  = (int) $code[0];
		$parity = self::EAN_PARITY[ $first ];
		$left   = substr( $code, 1, 6 );
		$right  = substr( $code, 7, 6 );

		$bits = '101'; // start guard
		foreach ( str_split( $left ) as $i => $d ) {
			$bits .= 'L' === $parity[ $i ] ? self::EAN_L[ (int) $d ] : self::EAN_G[ (int) $d ];
		}
		$bits .= '01010'; // centre guard
		foreach ( str_split( $right ) as $d ) {
			$bits .= self::EAN_R[ (int) $d ];
		}
		$bits .= '101'; // end guard

		// Each bit is exactly 1 module wide, alternating starting on bar
		// only where the bit is '1' — unlike Code 128, EAN-13 encodes
		// colour directly per bit rather than run-length widths, so build
		// widths as runs of equal consecutive bits instead.
		$widths  = [];
		$current = null;
		$run     = 0;
		foreach ( str_split( $bits ) as $bit ) {
			if ( $bit === $current ) {
				$run++;
			} else {
				if ( null !== $current ) {
					$widths[] = [ $current, $run ];
				}
				$current = $bit;
				$run     = 1;
			}
		}
		$widths[] = [ $current, $run ];

		return self::widths_to_svg_bits( $widths, $module_mm, $height_mm );
	}

	/**
	 * Code 128 renderer: $widths is a flat list of module widths, colour
	 * alternating bar/space starting on bar.
	 */
	private static function widths_to_svg( array $widths, float $module_mm, float $height_mm ): string {
		$total_modules = array_sum( $widths );
		$total_mm      = $total_modules * $module_mm;

		$svg  = sprintf(
			'<svg xmlns="http://www.w3.org/2000/svg" width="%smm" height="%smm" viewBox="0 0 %d 100" preserveAspectRatio="none">',
			self::fmt( $total_mm ),
			self::fmt( $height_mm ),
			$total_modules
		);
		$svg .= '<rect x="0" y="0" width="' . $total_modules . '" height="100" fill="#fff"/>';

		$pos = 0;
		foreach ( $widths as $i => $w ) {
			if ( 0 === $i % 2 ) {
				$svg .= '<rect x="' . $pos . '" y="0" width="' . $w . '" height="100" fill="#000"/>';
			}
			$pos += $w;
		}
		$svg .= '</svg>';
		return $svg;
	}

	/**
	 * EAN-13 renderer: $runs is a list of [bit_char, run_length] pairs,
	 * '1' meaning bar.
	 */
	private static function widths_to_svg_bits( array $runs, float $module_mm, float $height_mm ): string {
		$total_modules = 0;
		foreach ( $runs as $r ) {
			$total_modules += $r[1];
		}
		$total_mm = $total_modules * $module_mm;

		$svg  = sprintf(
			'<svg xmlns="http://www.w3.org/2000/svg" width="%smm" height="%smm" viewBox="0 0 %d 100" preserveAspectRatio="none">',
			self::fmt( $total_mm ),
			self::fmt( $height_mm ),
			$total_modules
		);
		$svg .= '<rect x="0" y="0" width="' . $total_modules . '" height="100" fill="#fff"/>';

		$pos = 0;
		foreach ( $runs as $r ) {
			list( $bit, $w ) = $r;
			if ( '1' === $bit ) {
				$svg .= '<rect x="' . $pos . '" y="0" width="' . $w . '" height="100" fill="#000"/>';
			}
			$pos += $w;
		}
		$svg .= '</svg>';
		return $svg;
	}

	private static function fmt( float $n ): string {
		return rtrim( rtrim( number_format( $n, 3, '.', '' ), '0' ), '.' );
	}
}
