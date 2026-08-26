<?php
namespace Counter\Orders;

use Counter\Stock\Locations;

defined( 'ABSPATH' ) || exit;

/**
 * A POS sale is taxed where the shop is, not where the customer lives.
 * Confirmed live against the installed WooCommerce (docs/verified-api.md item
 * 8): the filter name is woocommerce_order_get_tax_location, applied inside
 * the protected get_tax_location() — which the public get_taxable_location()
 * wrapper (since WC 7.6.0) also goes through, so hooking the filter here
 * covers both callers.
 */
class Tax {

	public static function init(): void {
		add_filter( 'woocommerce_order_get_tax_location', [ self::class, 'pos_tax_location' ], 10, 2 );
	}

	public static function pos_tax_location( array $location, \WC_Order $order ): array {
		if ( 'pos' !== $order->get_meta( '_cntr_channel' ) ) {
			return $location;
		}

		$location_id = (int) $order->get_meta( '_cntr_location_id' );
		$loc          = $location_id ? Locations::get( $location_id ) : null;
		$address      = ( $loc && $loc['address_json'] ) ? json_decode( $loc['address_json'], true ) : [];

		return [
			'country'  => $address['country'] ?? \WC()->countries->get_base_country(),
			'state'    => $address['state'] ?? \WC()->countries->get_base_state(),
			'postcode' => $address['postcode'] ?? '',
			'city'     => $address['city'] ?? '',
		];
	}
}
