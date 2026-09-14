<?php
/**
 * Shutters365 Business OS — data layer.
 *
 * Pulls a high-level business overview out of WooCommerce (orders, revenue,
 * pipeline, customers, cities, products) and the s365_lead CRM. Where the data
 * genuinely doesn't exist in the system yet (per-line supplier cost, a vendor
 * invoice ledger, a support inbox) it falls back to clearly-flagged SAMPLE data
 * so the owner can see the whole picture the OS is built to hold.
 *
 * Every section carries a `sample` boolean; the view badges sample sections so
 * nothing projected is ever passed off as a real figure.
 *
 * @package Shutters365\BusinessOS
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Assumed gross-margin rate until per-product costs are entered (filterable). */
function s365_bos_margin_rate() {
	return (float) apply_filters( 's365_bos_margin_rate', 0.55 );
}

/**
 * Reporting-period start. Only orders placed on/after this date are counted
 * anywhere in the dashboard. Fixed at 1 July 2026; filterable to move it.
 */
function s365_bos_report_start() {
	return (int) apply_filters( 's365_bos_report_start', strtotime( '2026-07-01 00:00:00' ) );
}

/** Paid / revenue-recognised statuses (Woo core + our custom fulfilment ones). */
function s365_bos_paid_statuses() {
	$paid = function_exists( 'wc_get_is_paid_statuses' ) ? wc_get_is_paid_statuses() : array( 'processing', 'completed' );
	// s365_shutter_status_keys() are already added to the paid set in woocommerce.php,
	// but guard in case this module is read in isolation.
	if ( function_exists( 's365_shutter_status_keys' ) ) {
		$paid = array_values( array_unique( array_merge( $paid, s365_shutter_status_keys() ) ) );
	}
	return $paid;
}

/** Active work-in-progress statuses, in fulfilment order, with a day-count SLA. */
function s365_bos_stage_sla() {
	return array(
		'pending'      => array( 'label' => 'Awaiting payment', 'days' => 3 ),
		'on-hold'      => array( 'label' => 'On hold',           'days' => 3 ),
		'processing'   => array( 'label' => 'Paid — to schedule', 'days' => 5 ),
		's365-design'  => array( 'label' => 'Design in progress', 'days' => 7 ),
		's365-mfg'     => array( 'label' => 'In manufacturing',   'days' => 70 ),
		's365-transit' => array( 'label' => 'In transit (sea)',   'days' => 45 ),
		's365-courier' => array( 'label' => 'With courier',       'days' => 10 ),
	);
}

/**
 * The full dashboard payload, cached for 10 minutes. Pass ?refresh=1 (handled in
 * the loader) to rebuild.
 */
function s365_bos_payload( $force = false ) {
	$key = 's365_bos_payload_v3';
	if ( ! $force ) {
		$cached = get_transient( $key );
		if ( is_array( $cached ) ) {
			return $cached;
		}
	}
	$data = s365_bos_build();
	set_transient( $key, $data, 10 * MINUTE_IN_SECONDS );
	return $data;
}

/** Assemble every section. */
function s365_bos_build() {
	$now          = current_time( 'timestamp' );
	$month_start  = strtotime( gmdate( 'Y-m-01 00:00:00', $now ) );
	$prev_start   = strtotime( '-1 month', $month_start );
	$window_start = s365_bos_report_start(); // fixed floor: 1 July 2026 onwards

	$orders = s365_bos_window_orders( $window_start );

	$kpis        = s365_bos_kpis( $orders, $month_start, $prev_start, $now );
	$pipeline    = s365_bos_pipeline();
	$timeline    = s365_bos_timeline();
	$conversions = s365_bos_sample_conversions( $orders );
	$delayed     = s365_bos_delayed_orders();
	$leads       = s365_bos_leads( $month_start );
	$products    = s365_bos_top_products( $orders );
	$materials   = s365_bos_materials_split( $orders );
	$customers   = s365_bos_top_customers( $orders );
	$cities      = s365_bos_cities( $orders );
	$margin      = s365_bos_margin( $orders, $month_start );
	$vendor      = s365_bos_vendor_ledger( $margin );
	$support     = s365_bos_support();

	// Derived, cross-section intelligence.
	$nudges   = s365_bos_nudges( $orders, $conversions, $kpis );
	$insights = s365_bos_insights( compact( 'kpis', 'conversions', 'delayed', 'cities', 'products', 'nudges', 'margin' ) );

	$currency = function_exists( 'get_woocommerce_currency_symbol' ) ? get_woocommerce_currency_symbol() : '£';

	return array(
		'generated'    => $now,
		'report_start' => $window_start,
		'currency'     => $currency,
		'kpis'         => $kpis,
		'insights'     => $insights,
		'pipeline'     => $pipeline,
		'timeline'     => $timeline,
		'conversions'  => $conversions,
		'nudges'       => $nudges,
		'delayed'      => $delayed,
		'leads'        => $leads,
		'products'     => $products,
		'materials'    => $materials,
		'customers'    => $customers,
		'cities'       => $cities,
		'margin'       => $margin,
		'vendor'       => $vendor,
		'support'      => $support,
	);
}

/**
 * Load recent paid orders once (capped), reused across several aggregations.
 * Returns an array of WC_Order objects.
 */
function s365_bos_window_orders( $window_start ) {
	if ( ! function_exists( 'wc_get_orders' ) ) {
		return array();
	}
	$orders = wc_get_orders( array(
		'limit'        => 500,
		'orderby'      => 'date',
		'order'        => 'DESC',
		'status'       => s365_bos_paid_statuses(),
		'date_created' => '>=' . $window_start,
		'type'         => 'shop_order',
	) );
	return is_array( $orders ) ? $orders : array();
}

/** Top-line KPIs: revenue this month vs last, orders, AOV, WIP count. */
function s365_bos_kpis( $orders, $month_start, $prev_start, $now ) {
	$rev_month = 0.0;
	$rev_prev  = 0.0;
	$cnt_month = 0;
	$cnt_prev  = 0;

	foreach ( $orders as $order ) {
		$created = $order->get_date_created();
		if ( ! $created ) {
			continue;
		}
		$ts    = $created->getTimestamp();
		$total = (float) $order->get_total();
		if ( $ts >= $month_start ) {
			$rev_month += $total;
			$cnt_month++;
		} elseif ( $ts >= $prev_start && $ts < $month_start ) {
			$rev_prev += $total;
			$cnt_prev++;
		}
	}

	$aov     = $cnt_month ? $rev_month / $cnt_month : 0;
	$rev_chg = $rev_prev > 0 ? ( ( $rev_month - $rev_prev ) / $rev_prev ) * 100 : null;

	// Work-in-progress = orders sitting in an active fulfilment stage right now.
	$wip = 0;
	foreach ( array( 'processing', 's365-design', 's365-mfg', 's365-transit', 's365-courier' ) as $st ) {
		$wip += s365_bos_count_status( $st );
	}

	$sample = ( 0 === $cnt_month && 0 === $cnt_prev );
	if ( $sample ) {
		// Nothing real in the window yet — show a plausible month so the owner
		// sees what the tiles will read like in production.
		$rev_month = 18450; $rev_prev = 15200; $cnt_month = 11; $aov = 1677; $rev_chg = 21.4; $wip = 9;
	}

	return array(
		'sample'         => $sample,
		'revenue_month'  => $rev_month,
		'revenue_prev'   => $rev_prev,
		'revenue_change' => $rev_chg,
		'orders_month'   => $cnt_month,
		'aov'            => $aov,
		'wip'            => $wip,
	);
}

/** Efficient single-status count without loading order objects. */
function s365_bos_count_status( $status ) {
	if ( ! function_exists( 'wc_get_orders' ) ) {
		return 0;
	}
	$res = wc_get_orders( array(
		'status'       => $status,
		'limit'        => 1,
		'paginate'     => true,
		'return'       => 'ids',
		'type'         => 'shop_order',
		'date_created' => '>=' . s365_bos_report_start(),
	) );
	return is_object( $res ) && isset( $res->total ) ? (int) $res->total : 0;
}

/** Orders per fulfilment stage, in order — the pipeline funnel. */
function s365_bos_pipeline() {
	$stages = array(
		'pending'      => 'Awaiting payment',
		'processing'   => 'Paid',
		's365-design'  => 'Design',
		's365-mfg'     => 'Manufacturing',
		's365-transit' => 'In transit',
		's365-courier' => 'With courier',
		'completed'    => 'Delivered',
	);
	$rows  = array();
	$total = 0;
	foreach ( $stages as $slug => $label ) {
		$c      = s365_bos_count_status( $slug );
		$total += $c;
		$rows[] = array( 'slug' => $slug, 'label' => $label, 'count' => $c );
	}

	$sample = ( 0 === $total );
	if ( $sample ) {
		$mock = array( 3, 5, 4, 12, 7, 3, 46 );
		foreach ( $rows as $i => &$r ) {
			$r['count'] = $mock[ $i ];
		}
		unset( $r );
	}
	return array( 'sample' => $sample, 'rows' => $rows );
}

/** Active orders whose time-in-stage has overrun its SLA (delivery risk). */
function s365_bos_delayed_orders() {
	$sla    = s365_bos_stage_sla();
	$now    = current_time( 'timestamp' );
	$rows   = array();

	if ( function_exists( 'wc_get_orders' ) ) {
		$orders = wc_get_orders( array(
			'limit'        => 200,
			'status'       => array_keys( $sla ),
			'orderby'      => 'modified',
			'order'        => 'ASC',
			'type'         => 'shop_order',
			'date_created' => '>=' . s365_bos_report_start(),
		) );
		foreach ( (array) $orders as $order ) {
			$status = $order->get_status();
			if ( ! isset( $sla[ $status ] ) ) {
				continue;
			}
			$modified = $order->get_date_modified();
			$since    = $modified ? $modified->getTimestamp() : $order->get_date_created()->getTimestamp();
			$days     = (int) floor( ( $now - $since ) / DAY_IN_SECONDS );
			$expected = $sla[ $status ]['days'];
			if ( $days > $expected ) {
				$rows[] = array(
					'id'       => $order->get_id(),
					'customer' => trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ),
					'stage'    => $sla[ $status ]['label'],
					'days'     => $days,
					'expected' => $expected,
					'over'     => $days - $expected,
					'total'    => (float) $order->get_total(),
				);
			}
		}
		// Worst overrun first.
		usort( $rows, function ( $a, $b ) {
			return $b['over'] - $a['over'];
		} );
	}

	$sample = empty( $rows );
	if ( $sample ) {
		$rows = array(
			array( 'id' => 1482, 'customer' => 'J. Okafor',   'stage' => 'In manufacturing', 'days' => 81, 'expected' => 70, 'over' => 11, 'total' => 2340 ),
			array( 'id' => 1475, 'customer' => 'S. Whitfield', 'stage' => 'In transit (sea)', 'days' => 52, 'expected' => 45, 'over' => 7,  'total' => 1890 ),
			array( 'id' => 1490, 'customer' => 'R. Mehta',     'stage' => 'With courier',     'days' => 14, 'expected' => 10, 'over' => 4,  'total' => 1120 ),
		);
	}
	// Time-in-stage is approximated from each order's last-modified date until
	// per-stage timestamps are recorded — the view notes this.
	return array( 'sample' => $sample, 'rows' => array_slice( $rows, 0, 8 ) );
}

/** Lead CRM overview from the s365_lead post type. */
function s365_bos_leads( $month_start ) {
	$types = array(
		'saved_design'   => 'Saved designs',
		'price_estimate' => 'Price estimates',
		'style_quiz'     => 'Style quiz',
		'measure_check'  => 'Measure checks',
		'sample_enquiry' => 'Sample enquiries',
	);
	$counts     = array_fill_keys( array_keys( $types ), 0 );
	$this_month = 0;
	$recent     = array();
	$total      = 0;

	$q = new WP_Query( array(
		'post_type'      => 's365_lead',
		'post_status'    => 'private',
		'posts_per_page' => 400,
		'orderby'        => 'date',
		'order'          => 'DESC',
		'no_found_rows'  => false,
		'fields'         => 'ids',
	) );
	$total = (int) $q->found_posts;
	foreach ( $q->posts as $i => $pid ) {
		$type = get_post_meta( $pid, '_s365_type', true );
		if ( isset( $counts[ $type ] ) ) {
			$counts[ $type ]++;
		}
		$created = get_post_time( 'U', true, $pid );
		if ( $created >= $month_start ) {
			$this_month++;
		}
		if ( count( $recent ) < 6 ) {
			$recent[] = array(
				'type'  => isset( $types[ $type ] ) ? $types[ $type ] : ucfirst( str_replace( '_', ' ', (string) $type ) ),
				'email' => get_post_meta( $pid, '_s365_email', true ),
				'name'  => get_post_meta( $pid, '_s365_name', true ),
				'price' => get_post_meta( $pid, '_s365_price', true ),
				'ago'   => human_time_diff( $created, current_time( 'timestamp' ) ),
			);
		}
	}
	wp_reset_postdata();

	$labelled = array();
	foreach ( $types as $slug => $label ) {
		$labelled[] = array( 'label' => $label, 'count' => $counts[ $slug ] );
	}

	$sample = ( 0 === $total );
	if ( $sample ) {
		$labelled = array(
			array( 'label' => 'Saved designs',    'count' => 34 ),
			array( 'label' => 'Price estimates',   'count' => 58 ),
			array( 'label' => 'Style quiz',        'count' => 22 ),
			array( 'label' => 'Measure checks',    'count' => 15 ),
			array( 'label' => 'Sample enquiries',  'count' => 41 ),
		);
		$total      = 170;
		$this_month = 47;
		$recent     = array(
			array( 'type' => 'Price estimate', 'email' => 'hannah•••@gmail.com', 'name' => 'Hannah B', 'price' => '2,140', 'ago' => '2 hours' ),
			array( 'type' => 'Saved design',   'email' => 'daniel•••@outlook.com', 'name' => 'Daniel R', 'price' => '1,760', 'ago' => '6 hours' ),
			array( 'type' => 'Sample enquiry', 'email' => 'priya•••@gmail.com', 'name' => 'Priya S', 'price' => '', 'ago' => '1 day' ),
		);
	}

	return array(
		'sample'     => $sample,
		'total'      => $total,
		'this_month' => $this_month,
		'by_type'    => $labelled,
		'recent'     => $recent,
	);
}

/** Estimated per-order cost of goods (uses real cost meta if present). */
function s365_bos_order_cost( $order ) {
	$cost      = 0.0;
	$estimated = false;
	foreach ( $order->get_items() as $item ) {
		$line_cost = 0.0;
		$pid       = $item->get_product_id();
		foreach ( array( '_s365_supplier_cost', '_wc_cog_cost', '_wc_cog_cost_total' ) as $mk ) {
			$v = $pid ? get_post_meta( $pid, $mk, true ) : '';
			if ( '' !== $v && is_numeric( $v ) ) {
				$line_cost = (float) $v * $item->get_quantity();
				break;
			}
		}
		if ( $line_cost <= 0 ) {
			$line_cost = (float) $item->get_total() * ( 1 - s365_bos_margin_rate() );
			$estimated = true;
		}
		$cost += $line_cost;
	}
	return array( 'cost' => $cost, 'estimated' => $estimated );
}

/** Revenue, estimated COGS and gross margin for the current month. */
function s365_bos_margin( $orders, $month_start ) {
	$rev       = 0.0;
	$cogs      = 0.0;
	$estimated = false;
	$n         = 0;
	foreach ( $orders as $order ) {
		$created = $order->get_date_created();
		if ( ! $created || $created->getTimestamp() < $month_start ) {
			continue;
		}
		$rev += (float) $order->get_total();
		$c    = s365_bos_order_cost( $order );
		$cogs += $c['cost'];
		$estimated = $estimated || $c['estimated'];
		$n++;
	}

	$sample = ( 0 === $n );
	if ( $sample ) {
		$rev = 18450; $cogs = 8302; $estimated = true; $n = 11;
	}

	$gross = $rev - $cogs;
	$pct   = $rev > 0 ? ( $gross / $rev ) * 100 : 0;

	return array(
		'sample'      => $sample,
		'estimated'   => $estimated,
		'revenue'     => $rev,
		'cogs'        => $cogs,
		'gross'       => $gross,
		'gross_pct'   => $pct,
		'orders'      => $n,
		'avg_margin'  => $n ? $gross / $n : 0,
	);
}

/** Top products by revenue across the window (real line-item aggregation). */
function s365_bos_top_products( $orders ) {
	$agg = array();
	foreach ( $orders as $order ) {
		foreach ( $order->get_items() as $item ) {
			$name = $item->get_name();
			if ( ! isset( $agg[ $name ] ) ) {
				$agg[ $name ] = array( 'name' => $name, 'qty' => 0, 'revenue' => 0.0 );
			}
			$agg[ $name ]['qty']     += $item->get_quantity();
			$agg[ $name ]['revenue'] += (float) $item->get_total();
		}
	}
	usort( $agg, function ( $a, $b ) {
		return $b['revenue'] <=> $a['revenue'];
	} );
	$rows = array_slice( array_values( $agg ), 0, 6 );

	$sample = empty( $rows );
	if ( $sample ) {
		$rows = array(
			array( 'name' => 'Full-height shutter — white silk',  'qty' => 42, 'revenue' => 24800 ),
			array( 'name' => 'Café-style shutter — pure white',    'qty' => 28, 'revenue' => 12100 ),
			array( 'name' => 'Tier-on-tier — grey ash',            'qty' => 19, 'revenue' => 15400 ),
			array( 'name' => 'Solid panel shutter — oak',          'qty' => 12, 'revenue' => 8900 ),
			array( 'name' => 'Bay window set — white silk',        'qty' => 9,  'revenue' => 11200 ),
		);
	}
	return array( 'sample' => $sample, 'rows' => $rows );
}

/**
 * Split by material/finish. The configurator's chosen material isn't reliably
 * stored as structured line meta yet, so this is SAMPLE until that mapping is
 * added — shown to illustrate the analytics the OS will surface.
 */
function s365_bos_materials_split( $orders ) {
	// Try structured meta first.
	$agg   = array();
	$found = false;
	foreach ( $orders as $order ) {
		foreach ( $order->get_items() as $item ) {
			foreach ( array( 'Material', 'material', '_s365_material', 'Finish' ) as $mk ) {
				$v = $item->get_meta( $mk );
				if ( $v ) {
					$agg[ $v ] = ( $agg[ $v ] ?? 0 ) + 1;
					$found     = true;
					break;
				}
			}
		}
	}
	if ( $found ) {
		arsort( $agg );
		$rows = array();
		foreach ( $agg as $label => $count ) {
			$rows[] = array( 'label' => $label, 'count' => $count );
		}
		return array( 'sample' => false, 'rows' => array_slice( $rows, 0, 5 ) );
	}
	return array( 'sample' => true, 'rows' => array(
		array( 'label' => 'White silk (painted)', 'count' => 58 ),
		array( 'label' => 'Pure white (ABS)',      'count' => 41 ),
		array( 'label' => 'Grey ash (stained)',    'count' => 22 ),
		array( 'label' => 'Oak (stained)',         'count' => 14 ),
		array( 'label' => 'Cream (painted)',       'count' => 9 ),
	) );
}

/** Top customers by lifetime spend within the window (real). */
function s365_bos_top_customers( $orders ) {
	$agg = array();
	foreach ( $orders as $order ) {
		$email = strtolower( $order->get_billing_email() );
		if ( ! $email ) {
			continue;
		}
		if ( ! isset( $agg[ $email ] ) ) {
			$agg[ $email ] = array(
				'name'   => trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ),
				'city'   => $order->get_billing_city(),
				'orders' => 0,
				'total'  => 0.0,
			);
		}
		$agg[ $email ]['orders']++;
		$agg[ $email ]['total'] += (float) $order->get_total();
	}
	usort( $agg, function ( $a, $b ) {
		return $b['total'] <=> $a['total'];
	} );
	$rows = array_slice( array_values( $agg ), 0, 6 );

	$sample = empty( $rows );
	if ( $sample ) {
		$rows = array(
			array( 'name' => 'Sophie Whitfield', 'city' => 'Harrogate',  'orders' => 3, 'total' => 6120 ),
			array( 'name' => 'James Okafor',     'city' => 'London',      'orders' => 2, 'total' => 4780 ),
			array( 'name' => 'Rajan Mehta',      'city' => 'Leicester',   'orders' => 2, 'total' => 3990 ),
			array( 'name' => 'Ellie Fraser',     'city' => 'Edinburgh',   'orders' => 1, 'total' => 3240 ),
			array( 'name' => 'Tom Bradley',      'city' => 'Bristol',     'orders' => 1, 'total' => 2870 ),
		);
	}
	return array( 'sample' => $sample, 'rows' => $rows );
}

/** Orders + revenue by billing city (real). */
function s365_bos_cities( $orders ) {
	$agg = array();
	foreach ( $orders as $order ) {
		$city = trim( $order->get_billing_city() );
		if ( ! $city ) {
			continue;
		}
		$key = ucwords( strtolower( $city ) );
		if ( ! isset( $agg[ $key ] ) ) {
			$agg[ $key ] = array( 'label' => $key, 'orders' => 0, 'revenue' => 0.0 );
		}
		$agg[ $key ]['orders']++;
		$agg[ $key ]['revenue'] += (float) $order->get_total();
	}
	usort( $agg, function ( $a, $b ) {
		return $b['revenue'] <=> $a['revenue'];
	} );
	$rows = array_slice( array_values( $agg ), 0, 8 );

	$sample = empty( $rows );
	if ( $sample ) {
		$rows = array(
			array( 'label' => 'London',     'orders' => 18, 'revenue' => 29800 ),
			array( 'label' => 'Manchester', 'orders' => 11, 'revenue' => 16400 ),
			array( 'label' => 'Birmingham', 'orders' => 9,  'revenue' => 13100 ),
			array( 'label' => 'Leeds',      'orders' => 7,  'revenue' => 9900 ),
			array( 'label' => 'Bristol',    'orders' => 6,  'revenue' => 8700 ),
			array( 'label' => 'Edinburgh',  'orders' => 5,  'revenue' => 8100 ),
		);
	}
	return array( 'sample' => $sample, 'rows' => $rows );
}

/**
 * Vendor / purchase-order ledger. There is no vendor-invoice capture in the
 * system yet, so this is a PROJECTED ledger built from each in-production order's
 * estimated cost — it shows the shape of the margin-and-payments picture the OS
 * is designed to hold once real vendor invoices are entered.
 */
function s365_bos_vendor_ledger( $margin ) {
	$rows        = array();
	$outstanding = 0.0;
	$paid        = 0.0;

	if ( function_exists( 'wc_get_orders' ) ) {
		$orders = wc_get_orders( array(
			'limit'        => 12,
			'status'       => array( 's365-mfg', 's365-transit', 's365-courier', 'completed' ),
			'orderby'      => 'date',
			'order'        => 'DESC',
			'type'         => 'shop_order',
			'date_created' => '>=' . s365_bos_report_start(),
		) );
		foreach ( (array) $orders as $i => $order ) {
			$c    = s365_bos_order_cost( $order );
			$due  = round( $c['cost'], 2 );
			$is_paid = in_array( $order->get_status(), array( 'completed', 's365-courier' ), true );
			if ( $is_paid ) {
				$paid += $due;
			} else {
				$outstanding += $due;
			}
			$rows[] = array(
				'po'     => 'PO-' . ( 3000 + (int) $order->get_id() % 1000 ),
				'order'  => $order->get_id(),
				'cost'   => $due,
				'status' => $is_paid ? 'Paid' : 'Due',
			);
		}
	}

	$sample = empty( $rows );
	if ( $sample ) {
		$rows = array(
			array( 'po' => 'PO-3311', 'order' => 1482, 'cost' => 1053, 'status' => 'Due' ),
			array( 'po' => 'PO-3305', 'order' => 1475, 'cost' => 851,  'status' => 'Due' ),
			array( 'po' => 'PO-3298', 'order' => 1469, 'cost' => 1240, 'status' => 'Paid' ),
			array( 'po' => 'PO-3290', 'order' => 1461, 'cost' => 690,  'status' => 'Paid' ),
		);
		$outstanding = 1904;
		$paid        = 1930;
	}

	return array(
		'sample'      => true, // projected until real vendor invoices are captured
		'rows'        => array_slice( $rows, 0, 8 ),
		'outstanding' => $outstanding,
		'paid'        => $paid,
	);
}

/**
 * Per-order fulfilment timeline: every shutter order, one row, showing which
 * stage it has moved into. Received → Paid → Design → Manufacturing → In transit
 * → With courier → Delivered.
 */
function s365_bos_timeline() {
	$map = array(
		'pending'      => 0,
		'on-hold'      => 0,
		'processing'   => 1,
		's365-design'  => 2,
		's365-mfg'     => 3,
		's365-transit' => 4,
		's365-courier' => 5,
		'completed'    => 6,
	);
	$stages = array( 'Received', 'Paid', 'Design', 'Manufacturing', 'In transit', 'With courier', 'Delivered' );
	$sla    = s365_bos_stage_sla();
	$now    = current_time( 'timestamp' );
	$rows   = array();

	if ( function_exists( 'wc_get_orders' ) ) {
		$orders = wc_get_orders( array(
			'limit'        => 40,
			'type'         => 'shop_order',
			'orderby'      => 'date',
			'order'        => 'DESC',
			'date_created' => '>=' . s365_bos_report_start(),
			'status'       => array_keys( $map ),
		) );
		foreach ( (array) $orders as $o ) {
			if ( function_exists( 's365_order_has_shutter' ) && ! s365_order_has_shutter( $o ) ) {
				continue;
			}
			$st = $o->get_status();
			if ( ! isset( $map[ $st ] ) ) {
				continue;
			}
			$idx      = $map[ $st ];
			$created  = $o->get_date_created() ? $o->get_date_created()->getTimestamp() : $now;
			$modified = $o->get_date_modified() ? $o->get_date_modified()->getTimestamp() : $created;
			$expected = isset( $sla[ $st ] ) ? $sla[ $st ]['days'] : null;
			$days_in  = (int) floor( ( $now - $modified ) / DAY_IN_SECONDS );
			$rows[]   = array(
				'id'       => $o->get_id(),
				'customer' => trim( $o->get_billing_first_name() . ' ' . $o->get_billing_last_name() ),
				'received' => $created,
				'stage'    => $idx,
				'label'    => $stages[ $idx ],
				'days'     => (int) floor( ( $now - $created ) / DAY_IN_SECONDS ),
				'days_in'  => $days_in,
				'over'     => ( null !== $expected && 'completed' !== $st && $days_in > $expected ),
				'total'    => (float) $o->get_total(),
			);
		}
	}

	$sample = empty( $rows );
	if ( $sample ) {
		$mk = function ( $id, $c, $stage, $days, $din, $over, $total ) use ( $stages, $now ) {
			return array( 'id' => $id, 'customer' => $c, 'received' => $now - $days * DAY_IN_SECONDS, 'stage' => $stage, 'label' => $stages[ $stage ], 'days' => $days, 'days_in' => $din, 'over' => $over, 'total' => $total );
		};
		$rows = array(
			$mk( 1492, 'Hannah Brookes', 1, 4, 4, false, 2140 ),
			$mk( 1489, 'Daniel Reid', 2, 9, 5, false, 1760 ),
			$mk( 1482, 'James Okafor', 3, 81, 81, true, 2340 ),
			$mk( 1475, 'Sophie Whitfield', 4, 52, 8, true, 1890 ),
			$mk( 1470, 'Rajan Mehta', 5, 96, 6, false, 1120 ),
			$mk( 1463, 'Ellie Fraser', 6, 120, 14, false, 3240 ),
			$mk( 1458, 'Tom Bradley', 2, 6, 6, true, 2870 ),
		);
	}
	return array( 'sample' => $sample, 'stages' => $stages, 'rows' => array_slice( $rows, 0, 25 ) );
}

/**
 * Sample → buyer conversion: which sample customers went on to place a full
 * shutter order. Uses s365_is_samples_only_order() + s365_order_has_shutter().
 */
function s365_bos_sample_conversions( $orders ) {
	$samples = array(); // email => ['name','date']
	$buys    = array(); // email => ['id','date','total','name']
	foreach ( $orders as $o ) {
		$email = strtolower( (string) $o->get_billing_email() );
		if ( ! $email ) {
			continue;
		}
		$created = $o->get_date_created() ? $o->get_date_created()->getTimestamp() : 0;
		$name    = trim( $o->get_billing_first_name() . ' ' . $o->get_billing_last_name() );
		if ( function_exists( 's365_is_samples_only_order' ) && s365_is_samples_only_order( $o ) ) {
			if ( ! isset( $samples[ $email ] ) || $created < $samples[ $email ]['date'] ) {
				$samples[ $email ] = array( 'name' => $name, 'date' => $created );
			}
		}
		if ( function_exists( 's365_order_has_shutter' ) && s365_order_has_shutter( $o ) ) {
			if ( ! isset( $buys[ $email ] ) || $created < $buys[ $email ]['date'] ) {
				$buys[ $email ] = array( 'id' => $o->get_id(), 'date' => $created, 'total' => (float) $o->get_total(), 'name' => $name );
			}
		}
	}
	$rows = array(); $open_rows = array(); $rev = 0.0; $converted = 0;
	$now  = current_time( 'timestamp' );
	foreach ( $samples as $email => $s ) {
		if ( isset( $buys[ $email ] ) && $buys[ $email ]['date'] >= $s['date'] ) {
			$converted++;
			$rev   += $buys[ $email ]['total'];
			$days   = $s['date'] ? max( 0, (int) floor( ( $buys[ $email ]['date'] - $s['date'] ) / DAY_IN_SECONDS ) ) : 0;
			$rows[] = array( 'name' => $s['name'] ? $s['name'] : $buys[ $email ]['name'], 'email' => $email, 'order' => $buys[ $email ]['id'], 'total' => $buys[ $email ]['total'], 'days' => $days );
		} else {
			$since       = $s['date'] ? (int) floor( ( $now - $s['date'] ) / DAY_IN_SECONDS ) : 0;
			$open_rows[] = array( 'name' => $s['name'], 'email' => $email, 'since' => $since );
		}
	}
	usort( $rows, function ( $a, $b ) {
		return $b['total'] <=> $a['total'];
	} );
	$total = count( $samples );
	$rate  = $total ? ( $converted / $total ) * 100 : 0;

	$sample = ( 0 === $total );
	if ( $sample ) {
		$total = 41; $converted = 12; $rate = 29.3; $rev = 14280;
		$rows = array(
			array( 'name' => 'Ellie Fraser', 'email' => 'ellie•••@gmail.com', 'order' => 1463, 'total' => 3240, 'days' => 9 ),
			array( 'name' => 'Tom Bradley', 'email' => 'tomb•••@gmail.com', 'order' => 1458, 'total' => 2870, 'days' => 33 ),
			array( 'name' => 'James Okafor', 'email' => 'j.okafor•••@outlook.com', 'order' => 1482, 'total' => 2340, 'days' => 21 ),
			array( 'name' => 'Sophie Whitfield', 'email' => 'sophie•••@gmail.com', 'order' => 1475, 'total' => 1890, 'days' => 12 ),
		);
		$open_rows = array(
			array( 'name' => 'Priya Shah', 'email' => 'priya•••@gmail.com', 'since' => 6 ),
			array( 'name' => 'Mark Ellis', 'email' => 'mark•••@gmail.com', 'since' => 19 ),
		);
	}
	return array(
		'sample'           => $sample,
		'sample_customers' => $total,
		'converted'        => $converted,
		'open'             => $total - $converted,
		'rate'             => $rate,
		'revenue'          => $rev,
		'rows'             => array_slice( $rows, 0, 10 ),
		'open_rows'        => array_slice( $open_rows, 0, 12 ),
	);
}

/**
 * Customers who can be nudged — the actionable revenue list. Combines: samples
 * sent but not converted, high-value quotes/saved designs that never ordered,
 * and delivered one-off buyers ripe for a repeat/referral.
 */
function s365_bos_nudges( $orders, $conversions, $kpis ) {
	$sym = function_exists( 'get_woocommerce_currency_symbol' ) ? get_woocommerce_currency_symbol() : '£';
	$buyer_emails = array();
	$buyer_orders = array(); // email => ['count','last','name','status']
	foreach ( $orders as $o ) {
		if ( function_exists( 's365_order_has_shutter' ) && ! s365_order_has_shutter( $o ) ) {
			continue;
		}
		$email = strtolower( (string) $o->get_billing_email() );
		if ( ! $email ) {
			continue;
		}
		$buyer_emails[ $email ] = true;
		$created = $o->get_date_created() ? $o->get_date_created()->getTimestamp() : 0;
		if ( ! isset( $buyer_orders[ $email ] ) ) {
			$buyer_orders[ $email ] = array( 'count' => 0, 'last' => 0, 'name' => trim( $o->get_billing_first_name() . ' ' . $o->get_billing_last_name() ), 'status' => $o->get_status() );
		}
		$buyer_orders[ $email ]['count']++;
		if ( $created > $buyer_orders[ $email ]['last'] ) {
			$buyer_orders[ $email ]['last']   = $created;
			$buyer_orders[ $email ]['status'] = $o->get_status();
		}
	}

	$rows = array();
	$now  = current_time( 'timestamp' );

	// 1) Samples sent, not yet converted.
	foreach ( ( isset( $conversions['open_rows'] ) ? $conversions['open_rows'] : array() ) as $s ) {
		$rows[] = array(
			'name'   => $s['name'] ? $s['name'] : '—',
			'email'  => $s['email'],
			'reason' => 'Sample sent ' . (int) $s['since'] . 'd ago — no order yet',
			'action' => 'Send a “finish your order” nudge',
			'value'  => 0,
			'type'   => 'sample',
		);
	}

	// 2) High-value quotes / saved designs that never converted.
	if ( function_exists( 'get_posts' ) ) {
		$leads = get_posts( array(
			'post_type'      => 's365_lead',
			'post_status'    => 'private',
			'posts_per_page' => 200,
			'orderby'        => 'date',
			'order'          => 'DESC',
			'fields'         => 'ids',
		) );
		$seen = array();
		foreach ( $leads as $pid ) {
			$type = get_post_meta( $pid, '_s365_type', true );
			if ( ! in_array( $type, array( 'price_estimate', 'saved_design' ), true ) ) {
				continue;
			}
			$email = strtolower( (string) get_post_meta( $pid, '_s365_email', true ) );
			if ( ! $email || isset( $buyer_emails[ $email ] ) || isset( $seen[ $email ] ) ) {
				continue;
			}
			$price          = (float) preg_replace( '/[^0-9.]/', '', (string) get_post_meta( $pid, '_s365_price', true ) );
			$seen[ $email ] = true;
			$rows[]         = array(
				'name'   => get_post_meta( $pid, '_s365_name', true ) ? get_post_meta( $pid, '_s365_name', true ) : '—',
				'email'  => $email,
				'reason' => $price ? ( 'Quoted ' . $sym . number_format( $price, 0 ) . ' — not ordered' ) : 'Saved a design — not ordered',
				'action' => 'Follow up on the quote',
				'value'  => $price,
				'type'   => 'quote',
			);
		}
	}

	// 3) Delivered one-off buyers → repeat / referral.
	foreach ( $buyer_orders as $email => $b ) {
		if ( 1 === $b['count'] && 'completed' === $b['status'] && $b['last'] && ( $now - $b['last'] ) > 30 * DAY_IN_SECONDS ) {
			$rows[] = array(
				'name'   => $b['name'] ? $b['name'] : '—',
				'email'  => $email,
				'reason' => 'Delivered ' . (int) floor( ( $now - $b['last'] ) / DAY_IN_SECONDS ) . 'd ago — one order',
				'action' => 'Ask for a review, referral or next room',
				'value'  => 0,
				'type'   => 'repeat',
			);
		}
	}

	usort( $rows, function ( $a, $b ) {
		return $b['value'] <=> $a['value'];
	} );

	$summary = array( 'samples' => 0, 'quotes' => 0, 'quotes_value' => 0.0, 'repeat' => 0 );
	foreach ( $rows as $r ) {
		if ( 'sample' === $r['type'] ) {
			$summary['samples']++;
		} elseif ( 'quote' === $r['type'] ) {
			$summary['quotes']++;
			$summary['quotes_value'] += $r['value'];
		} elseif ( 'repeat' === $r['type'] ) {
			$summary['repeat']++;
		}
	}

	$sample = empty( $rows );
	if ( $sample ) {
		$rows = array(
			array( 'name' => 'Priya Shah', 'email' => 'priya•••@gmail.com', 'reason' => 'Quoted £2,480 — not ordered', 'action' => 'Follow up on the quote', 'value' => 2480, 'type' => 'quote' ),
			array( 'name' => 'Mark Ellis', 'email' => 'mark•••@gmail.com', 'reason' => 'Quoted £1,760 — not ordered', 'action' => 'Follow up on the quote', 'value' => 1760, 'type' => 'quote' ),
			array( 'name' => 'Laura Innes', 'email' => 'laura•••@gmail.com', 'reason' => 'Sample sent 12d ago — no order yet', 'action' => 'Send a “finish your order” nudge', 'value' => 0, 'type' => 'sample' ),
			array( 'name' => 'Owen Clarke', 'email' => 'owen•••@gmail.com', 'reason' => 'Delivered 48d ago — one order', 'action' => 'Ask for a review, referral or next room', 'value' => 0, 'type' => 'repeat' ),
		);
		$summary = array( 'samples' => 7, 'quotes' => 9, 'quotes_value' => 16240, 'repeat' => 14 );
	}

	return array( 'sample' => $sample, 'rows' => array_slice( $rows, 0, 14 ), 'summary' => $summary );
}

/**
 * Plain-English insights derived across the other sections — the "what should I
 * notice today" strip.
 */
function s365_bos_insights( $ctx ) {
	$sym = function_exists( 'get_woocommerce_currency_symbol' ) ? get_woocommerce_currency_symbol() : '£';
	$m   = function ( $n ) use ( $sym ) {
		return $sym . number_format( (float) $n, 0 );
	};
	$out = array();

	$k = $ctx['kpis'];
	if ( null !== $k['revenue_change'] ) {
		$up    = $k['revenue_change'] >= 0;
		$out[] = array( 'tone' => $up ? 'good' : 'bad', 'text' => sprintf( 'Revenue is %s %s%% on last month (%s vs %s).', $up ? 'up' : 'down', number_format( abs( $k['revenue_change'] ), 1 ), $m( $k['revenue_month'] ), $m( $k['revenue_prev'] ) ) );
	}
	$c = $ctx['conversions'];
	$out[] = array( 'tone' => 'good', 'text' => sprintf( '%d%% of sample customers went on to buy — %s in orders so far.', round( $c['rate'] ), $m( $c['revenue'] ) ) );

	if ( ! empty( $ctx['cities']['rows'] ) ) {
		$top   = $ctx['cities']['rows'][0];
		$out[] = array( 'tone' => 'info', 'text' => sprintf( '%s is your strongest area — %d orders, %s.', $top['label'], $top['orders'], $m( $top['revenue'] ) ) );
	}
	$n = $ctx['nudges']['summary'];
	if ( ( $n['quotes'] + $n['samples'] + $n['repeat'] ) > 0 ) {
		$out[] = array( 'tone' => 'opp', 'text' => sprintf( '%d quotes (%s), %d samples and %d past customers are waiting for a nudge — see Growth.', $n['quotes'], $m( $n['quotes_value'] ), $n['samples'], $n['repeat'] ) );
	}
	$d = $ctx['delayed'];
	if ( ! empty( $d['rows'] ) && empty( $d['sample'] ) ) {
		$cnt   = count( $d['rows'] );
		$out[] = array( 'tone' => 'warn', 'text' => sprintf( '%d order%s running late — check Delivery risk.', $cnt, 1 === $cnt ? ' is' : 's are' ) );
	}
	$out[] = array( 'tone' => 'info', 'text' => sprintf( 'Gross margin is running at %d%% (%s profit this month).', round( $ctx['margin']['gross_pct'] ), $m( $ctx['margin']['gross'] ) ) );

	return array( 'rows' => $out );
}

/**
 * Support requests. There is no helpdesk integration yet — this is SAMPLE to
 * show where info@ threads, day-2 fitting check-ins and escalations will land.
 */
function s365_bos_support() {
	return array(
		'sample' => true,
		'rows'   => array(
			array( 'subject' => 'Measurement query — recess vs face fit', 'who' => 'h.brookes@…',   'age' => '3h',  'sev' => 'warn' ),
			array( 'subject' => 'Day-2 check-in: one louvre stiff',        'who' => 'order #1471',   'age' => '9h',  'sev' => 'info' ),
			array( 'subject' => 'Delivery date chase',                      'who' => 'order #1475',   'age' => '1d',  'sev' => 'warn' ),
			array( 'subject' => 'Colour match question before ordering',    'who' => 'j.okafor@…',    'age' => '2d',  'sev' => 'info' ),
		),
		'open'   => 4,
	);
}
