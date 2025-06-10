<?php
/**
 * Plugin Name: Sprucely Prorated Renewals
 * Description: Enables prorated first renewal pricing for subscription products with a simple checkbox option. Adds clear price messaging: ", $X due today" for enabled products.
 * Version: 3.1.0
 * Author: Isaac Russell
 * Author URI: https://www.sprucely.net
 * License: GPL-3.0+
 * Requires Plugins: woocommerce, woocommerce-subscriptions
 */

defined( 'ABSPATH' ) || exit;

/**
 * Check if product has proration enabled via product meta
 *
 * @param int $product_id Product ID
 * @return bool
 */
function sprucely_is_prorate_product( $product_id ) {
	if ( ! is_numeric( $product_id ) || $product_id <= 0 ) {
		return false;
	}

	$cache_key  = 'sprucely_prorate_' . $product_id;
	$is_prorate = wp_cache_get( $cache_key );

	if ( false === $is_prorate ) {
		$is_prorate = get_post_meta( $product_id, '_sprucely_enable_proration', true ) === 'yes';
		wp_cache_set( $cache_key, $is_prorate, '', 3600 ); // Cache for 1 hour
	}

	return $is_prorate;
}

/**
 * Get days in billing period using WooCommerce Subscriptions' implementation
 *
 * Uses wcs_get_days_in_cycle() to ensure consistency with actual billing calculations.
 * This uses averages (month: 30.4375 days, year: 365.25 days) which matches
 * how WooCommerce calculates subscription billing.
 *
 * @param string $period Period (week, month, year)
 * @param int $interval Billing interval (default 1)
 * @return float Number of days in period
 */
function sprucely_get_days_in_period( $period, $interval = 1 ) {
	if ( ! in_array( $period, [ 'week', 'month', 'year' ], true ) ) {
		return 1;
	}

	// Validate interval
	if ( ! is_numeric( $interval ) || $interval <= 0 ) {
		return 1;
	}

	$cache_key = 'sprucely_days_' . $period . '_' . $interval;
	$days      = wp_cache_get( $cache_key );

	if ( false === $days ) {
		// Use WooCommerce's own implementation for consistency
		$days = wcs_get_days_in_cycle( $period, $interval );

		// Ensure we never return zero or negative days
		if ( ! is_numeric( $days ) || $days <= 0 ) {
			$days = 1;
		}

		wp_cache_set( $cache_key, $days, '', 3600 ); // Cache for 1 hour
	}

	return $days;
}

/**
 * Calculate prorated amount with WordPress object caching
 *
 * @param int $product_id Product ID
 * @param string $period Billing period
 * @param int $trial_days Days until first payment
 * @param float $regular_price Product price
 * @param int $interval Billing interval (default 1)
 * @return float Prorated amount
 */
function sprucely_get_prorated_amount( $product_id, $period, $trial_days, $regular_price, $interval = 1 ) {
	// Validate inputs
	if ( ! is_numeric( $trial_days ) || $trial_days <= 0 || ! is_numeric( $regular_price ) || $regular_price <= 0 ) {
		return 0.0;
	}

	if ( ! is_numeric( $interval ) || $interval <= 0 ) {
		return 0.0;
	}

	$cache_key       = 'sprucely_prorate_calc_' . $product_id . '_' . $period . '_' . $interval . '_' . $trial_days . '_' . number_format( $regular_price, 2 );
	$prorated_amount = wp_cache_get( $cache_key );

	if ( false === $prorated_amount ) {
		$days_in_period = sprucely_get_days_in_period( $period, $interval );

		// Prevent division by zero
		if ( $days_in_period <= 0 ) {
			return 0.0;
		}

		$proration_factor = min( 1, $trial_days / $days_in_period );
		$prorated_amount  = round( $regular_price * $proration_factor, wc_get_price_decimals() );
		$prorated_amount  = min( $prorated_amount, $regular_price );

		wp_cache_set( $cache_key, $prorated_amount, '', 1800 ); // Cache for 30 minutes
	}

	return $prorated_amount;
}

/**
 * Clear object cache when products are updated
 */
add_action( 'woocommerce_update_product', function ($product_id) {
	wp_cache_delete( 'sprucely_prorate_' . $product_id );
} );

add_action( 'woocommerce_save_product_variation', function ($variation_id) {
	wp_cache_delete( 'sprucely_prorate_' . $variation_id );
} );

add_action( 'updated_post_meta', function ($meta_id, $object_id, $meta_key, $meta_value) {
	if ( '_sprucely_enable_proration' === $meta_key ) {
		wp_cache_delete( 'sprucely_prorate_' . $object_id );
	}
}, 10, 4 );

/**
 * Add proration checkbox to simple subscription product options
 */
add_action( 'woocommerce_subscriptions_product_options_pricing', 'sprucely_add_proration_checkbox' );
function sprucely_add_proration_checkbox() {
	global $post;

	woocommerce_wp_checkbox(
		array(
			'id'          => '_sprucely_enable_proration',
			'label'       => __( 'Enable prorated renewals', 'sprucely-prorated-renewals' ),
			'description' => __( 'Enable prorated pricing for the first renewal period. Customers will pay a prorated amount based on when they subscribe within the billing cycle.', 'sprucely-prorated-renewals' ),
			'desc_tip'    => true,
			'value'       => get_post_meta( $post->ID, '_sprucely_enable_proration', true ),
		)
	);
}

/**
 * Add proration checkbox to variable subscription product variations
 */
add_action( 'woocommerce_variable_subscription_pricing', 'sprucely_add_variation_proration_checkbox', 10, 3 );
function sprucely_add_variation_proration_checkbox( $loop, $variation_data, $variation ) {
	$variation_id = $variation->ID;

	echo '<div class="variable_subscription_proration show_if_variable-subscription" style="display: none">';

	woocommerce_wp_checkbox(
		array(
			'id'          => 'variable_sprucely_enable_proration[' . $loop . ']',
			'name'        => 'variable_sprucely_enable_proration[' . $loop . ']',
			'label'       => __( 'Enable prorated renewals', 'sprucely-prorated-renewals' ),
			'description' => __( 'Enable prorated pricing for this variation\'s first renewal period.', 'sprucely-prorated-renewals' ),
			'desc_tip'    => true,
			'value'       => get_post_meta( $variation_id, '_sprucely_enable_proration', true ),
			'wrapper_class' => 'form-row form-row-full',
		)
	);

	echo '</div>';
}

/**
 * Add admin styles for variable subscription proration checkbox
 */
add_action( 'admin_head', 'sprucely_admin_styles' );
function sprucely_admin_styles() {
	$screen = get_current_screen();
	if ( ! $screen || $screen->id !== 'product' ) {
		return;
	}
	?>
	<style>
	/* Variable subscription proration checkbox styling */
	.variable_subscription_proration {
		margin-top: 12px;
		padding: 12px 0;
		border-top: 1px solid #e0e0e0;
	}

	.variable_subscription_proration .form-row {
		margin-bottom: 0;
	}

	.variable_subscription_proration label {
		font-weight: 600;
	}
	</style>
	<?php
}

/**
 * Save proration checkbox value for simple subscription products
 */
add_action( 'woocommerce_process_product_meta_subscription', 'sprucely_save_proration_checkbox' );
add_action( 'woocommerce_process_product_meta_variable-subscription', 'sprucely_save_proration_checkbox' );
function sprucely_save_proration_checkbox( $product_id ) {
	$enable_proration = isset( $_POST['_sprucely_enable_proration'] ) ? 'yes' : 'no';
	update_post_meta( $product_id, '_sprucely_enable_proration', $enable_proration );
}

/**
 * Save proration checkbox value for variable subscription product variations
 */
add_action( 'woocommerce_save_product_variation', 'sprucely_save_variation_proration_checkbox', 10, 2 );
function sprucely_save_variation_proration_checkbox( $variation_id, $loop ) {
	if ( isset( $_POST['variable_sprucely_enable_proration'][ $loop ] ) ) {
		$enable_proration = $_POST['variable_sprucely_enable_proration'][ $loop ] ? 'yes' : 'no';
	} else {
		$enable_proration = 'no';
	}

	update_post_meta( $variation_id, '_sprucely_enable_proration', $enable_proration );
}

/**
 * Disable proration for products that are NOT tagged with 'prorate'.
 */
add_filter( 'wcs_calculated_prorated_trial_length', 'sprucely_disable_proration_for_non_tagged', 10, 5 );
function sprucely_disable_proration_for_non_tagged( $trial_length, $product, $sync_date, $start_date, $period ) {
	if ( ! sprucely_is_prorate_product( $product->get_id() ) ) {
		return 0;
	}
	return $trial_length;
}

/**
 * Ensure non-tagged products get the full interval before their first renewal (mimics 'recurring').
 */
add_filter( 'woocommerce_subscriptions_synced_first_payment_date', 'sprucely_delay_first_payment_for_non_tagged', 10, 5 );
function sprucely_delay_first_payment_for_non_tagged( $first_payment_date, $product, $type, $from_date, $from_date_param ) {
	if ( ! sprucely_is_prorate_product( $product->get_id() ) ) {
		$interval  = WC_Subscriptions_Product::get_interval( $product );
		$period    = WC_Subscriptions_Product::get_period( $product );
		$timestamp = wcs_add_time( $interval, $period, wcs_date_to_time( $from_date ) );

		return ( $type === 'mysql' ) ? gmdate( 'Y-m-d H:i:s', $timestamp ) : $timestamp;
	}

	return $first_payment_date;
}

/**
 * Append ", $X due today" for products tagged 'prorate'
 */
add_filter( 'woocommerce_subscriptions_product_price_string', 'sprucely_append_due_today_amount', 20, 3 );
function sprucely_append_due_today_amount( $price_string, $product, $include ) {
	// Validate product object and type
	if ( ! $product instanceof WC_Product ) {
		return $price_string;
	}

	// Validate that this is a subscription product
	if ( ! WC_Subscriptions_Product::is_subscription( $product ) ) {
		return $price_string;
	}

	$product_id = $product->get_id();

	// Validate product ID
	if ( ! $product_id || ! is_numeric( $product_id ) ) {
		return $price_string;
	}

	if ( ! sprucely_is_prorate_product( $product_id ) ) {
		return $price_string;
	}

	$period = WC_Subscriptions_Product::get_period( $product );
	if ( ! in_array( $period, [ 'week', 'month', 'year' ], true ) ) {
		return $price_string;
	}

	// Simple request-level caching
	static $calculation_cache = [];
	$cache_key = $product_id . '_' . $period;

	if ( isset( $calculation_cache[ $cache_key ] ) ) {
		$cached_data = $calculation_cache[ $cache_key ];
		if ( $cached_data['trial_days'] > 0 ) {
			$price_string .= ', ' . wc_price( $cached_data['prorated_amount'] ) . ' due today';
		}
		return $price_string;
	}

	$now        = current_time( 'timestamp' );
	$start_date = gmdate( 'Y-m-d H:i:s', $now );
	$sync_date  = WC_Subscriptions_Synchroniser::calculate_first_payment_date( $product, 'mysql', $start_date );

	if ( empty( $sync_date ) || ! is_string( $sync_date ) ) {
		$calculation_cache[ $cache_key ] = [ 'trial_days' => 0, 'prorated_amount' => 0 ];
		return $price_string;
	}

	$sync_ts    = wcs_date_to_time( $sync_date );
	$trial_days = ceil( ( $sync_ts - $now ) / DAY_IN_SECONDS );

	if ( $trial_days > 0 ) {
		$regular_price = (float) $product->get_price();

		// Validate price
		if ( $regular_price <= 0 ) {
			$calculation_cache[ $cache_key ] = [ 'trial_days' => 0, 'prorated_amount' => 0 ];
			return $price_string;
		}

		$interval        = WC_Subscriptions_Product::get_interval( $product );
		$prorated_amount = sprucely_get_prorated_amount( $product_id, $period, $trial_days, $regular_price, $interval );

		$calculation_cache[ $cache_key ] = [
			'trial_days'      => $trial_days,
			'prorated_amount' => $prorated_amount
		];

		$price_string .= ', ' . wc_price( $prorated_amount ) . ' due today';
	} else {
		$calculation_cache[ $cache_key ] = [ 'trial_days' => 0, 'prorated_amount' => 0 ];
	}
	return $price_string;
}
