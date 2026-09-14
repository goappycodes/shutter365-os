<?php
/**
 * Shutters365 Business OS — view.
 *
 * Renders the standalone dashboard document from the payload built in data.php.
 * All output is escaped; sample sections are badged so nothing projected reads
 * as a confirmed figure.
 *
 * @package Shutters365\BusinessOS
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Format a money value with the store currency symbol, no decimals. */
function s365_bos_money( $n, $cur = '£' ) {
	return $cur . number_format( (float) $n, 0 );
}

/** A "Sample data" badge for projected sections. */
function s365_bos_badge( $is_sample ) {
	if ( ! $is_sample ) {
		return '';
	}
	return '<span class="badge badge-sample" title="Illustrative data — not yet captured in the system">Sample</span>';
}

/** A horizontal bar row. $value is absolute; $max scales the fill. */
function s365_bos_bar( $label, $value, $max, $display, $tone = 'brand' ) {
	$pct = $max > 0 ? max( 3, round( ( $value / $max ) * 100 ) ) : 0;
	return '<div class="bar-row">'
		. '<div class="bar-label" title="' . esc_attr( $label ) . '">' . esc_html( $label ) . '</div>'
		. '<div class="bar-track"><span class="bar-fill tone-' . esc_attr( $tone ) . '" style="width:' . esc_attr( $pct ) . '%"></span></div>'
		. '<div class="bar-val">' . esc_html( $display ) . '</div>'
		. '</div>';
}

/** An SVG donut from [ ['label'=>, 'count'=>], ... ]. */
function s365_bos_donut( $rows ) {
	$total = 0;
	foreach ( $rows as $r ) {
		$total += (float) $r['count'];
	}
	$total = $total ?: 1;
	$colors = array( '#c2261f', '#2c5b3f', '#385a6e', '#a5670a', '#8a5a83', '#6b7280' );
	$C      = 2 * M_PI * 42; // circumference for r=42
	$offset = 0;
	$segs   = '';
	$legend = '';
	foreach ( $rows as $i => $r ) {
		$frac  = (float) $r['count'] / $total;
		$len   = $frac * $C;
		$color = $colors[ $i % count( $colors ) ];
		$segs .= '<circle class="donut-seg" cx="60" cy="60" r="42" fill="none" stroke="' . esc_attr( $color ) . '" stroke-width="16"'
			. ' stroke-dasharray="' . esc_attr( round( $len, 2 ) . ' ' . round( $C - $len, 2 ) ) . '"'
			. ' stroke-dashoffset="' . esc_attr( round( -$offset, 2 ) ) . '" transform="rotate(-90 60 60)"></circle>';
		$offset += $len;
		$legend .= '<li><span class="dot" style="background:' . esc_attr( $color ) . '"></span>'
			. esc_html( $r['label'] ) . ' <b>' . esc_html( round( $frac * 100 ) ) . '%</b></li>';
	}
	return '<div class="donut-wrap"><svg viewBox="0 0 120 120" class="donut" role="img" aria-label="Share by category">'
		. '<circle cx="60" cy="60" r="42" fill="none" stroke="var(--line)" stroke-width="16"></circle>'
		. $segs . '</svg><ul class="donut-legend">' . $legend . '</ul></div>';
}

/** Render the full page and echo it. */
function s365_bos_render_page( $data ) {
	$cur     = isset( $data['currency'] ) ? $data['currency'] : '£';
	$user    = wp_get_current_user();
	$name    = $user ? ( $user->first_name ? $user->first_name : $user->display_name ) : 'there';
	$updated = isset( $data['generated'] ) ? date_i18n( 'j M Y, H:i', $data['generated'] ) : '';
	$k       = $data['kpis'];

	// Delta rendering for revenue.
	$delta_html = '';
	if ( null !== $k['revenue_change'] ) {
		$up   = $k['revenue_change'] >= 0;
		$delta_html = '<span class="delta ' . ( $up ? 'up' : 'down' ) . '">' . ( $up ? '▲' : '▼' ) . ' '
			. esc_html( number_format( abs( $k['revenue_change'] ), 1 ) ) . '%</span>';
	}
	?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Business OS · Shutters365</title>
<style>
:root{
  --paper:#f3efe8; --card:#ffffff; --ink:#181511; --slate:#5b564e; --slate-2:#8b857a;
  --line:#e7dfd2; --line-2:#f0eae0; --brand:#c2261f; --brand-ink:#8f1a15;
  --green:#2c5b3f; --green-bg:#e9f1ec; --amber:#a5670a; --amber-bg:#f8efdb;
  --red:#a3201a; --red-bg:#f8e6e3; --steel:#385a6e;
  --shadow:0 1px 2px rgba(60,45,30,.05), 0 8px 24px -14px rgba(60,45,30,.22);
  --r:14px; --r-sm:10px;
}
*{box-sizing:border-box}
html,body{margin:0}
body{
  background:var(--paper); color:var(--ink);
  font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Inter,Helvetica,Arial,sans-serif;
  font-size:15px; line-height:1.5; -webkit-font-smoothing:antialiased;
}
.wrap{max-width:1200px;margin:0 auto;padding:0 20px}
h1,h2,h3{margin:0;letter-spacing:-.01em}
a{color:var(--brand)}
.tnum{font-variant-numeric:tabular-nums}

/* top bar */
.topbar{position:sticky;top:0;z-index:30;background:rgba(243,239,232,.92);backdrop-filter:saturate(1.2) blur(8px);border-bottom:1px solid var(--line)}
.topbar-in{display:flex;align-items:center;justify-content:space-between;gap:16px;padding:14px 0}
.brand{display:flex;align-items:center;gap:12px}
.brand .mark{width:30px;height:30px;border:2px solid var(--brand);border-radius:7px;position:relative;flex:0 0 auto}
.brand .mark::before,.brand .mark::after{content:"";position:absolute;background:var(--brand)}
.brand .mark::before{left:10px;top:2px;bottom:2px;width:2px}
.brand .mark::after{top:10px;left:2px;right:2px;height:2px}
.brand b{font-size:16px;font-weight:700}
.brand span{color:var(--slate-2);font-size:12px;letter-spacing:.14em;text-transform:uppercase;display:block;margin-top:-2px}
.topbar-actions{display:flex;align-items:center;gap:10px;font-size:13px;color:var(--slate)}
.btn{display:inline-flex;align-items:center;gap:7px;padding:8px 13px;border-radius:999px;border:1px solid var(--line);background:var(--card);color:var(--ink);text-decoration:none;font-size:13px;font-weight:600;box-shadow:var(--shadow)}
.btn:hover{border-color:var(--brand)}
.btn-ghost{box-shadow:none;background:transparent}

/* section nav */
.subnav{display:flex;gap:6px;overflow-x:auto;padding:12px 0 0}
.subnav a{white-space:nowrap;font-size:13px;color:var(--slate);text-decoration:none;padding:7px 12px;border-radius:999px;font-weight:600}
.subnav a:hover{background:var(--line-2);color:var(--ink)}

/* headings */
.section{padding:26px 0 4px}
.sec-h{display:flex;align-items:baseline;gap:10px;margin:0 0 14px}
.sec-h h2{font-size:15px;text-transform:uppercase;letter-spacing:.12em;color:var(--slate)}
.badge{font-size:10.5px;font-weight:700;letter-spacing:.05em;text-transform:uppercase;padding:3px 8px;border-radius:6px}
.badge-sample{background:var(--amber-bg);color:var(--amber)}

/* KPI tiles */
.kpis{display:grid;grid-template-columns:repeat(6,1fr);gap:14px}
.kpi{background:var(--card);border:1px solid var(--line);border-radius:var(--r);padding:16px 16px 15px;box-shadow:var(--shadow);min-height:104px;display:flex;flex-direction:column;justify-content:space-between}
.kpi .lab{font-size:11.5px;text-transform:uppercase;letter-spacing:.08em;color:var(--slate-2);font-weight:600}
.kpi .num{font-size:27px;font-weight:800;letter-spacing:-.02em;line-height:1.05}
.kpi .sub{font-size:12px;color:var(--slate)}
.kpi.hero{background:linear-gradient(180deg,#fff,#fdf7f6);border-color:#f0d7d4}
.kpi.hero .num{color:var(--brand-ink)}
.delta{font-size:12.5px;font-weight:700}
.delta.up{color:var(--green)} .delta.down{color:var(--red)}

/* cards + grid */
.grid{display:grid;gap:16px}
.g-2{grid-template-columns:1.4fr 1fr}
.g-2e{grid-template-columns:1fr 1fr}
.g-3{grid-template-columns:1fr 1fr 1fr}
.card{background:var(--card);border:1px solid var(--line);border-radius:var(--r);box-shadow:var(--shadow);padding:18px 18px}
.card h3{font-size:15px;font-weight:700;margin-bottom:3px}
.card .hint{font-size:12px;color:var(--slate-2);margin-bottom:14px}
.card-h{display:flex;align-items:center;justify-content:space-between;gap:10px;margin-bottom:12px}
.card-h h3{margin:0}

/* bars */
.bar-row{display:grid;grid-template-columns:150px 1fr auto;align-items:center;gap:12px;margin:9px 0}
.bar-label{font-size:13px;color:var(--slate);overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.bar-track{height:10px;background:var(--line-2);border-radius:999px;overflow:hidden}
.bar-fill{display:block;height:100%;border-radius:999px}
.tone-brand{background:linear-gradient(90deg,#d34b45,#c2261f)}
.tone-green{background:linear-gradient(90deg,#3f7a58,#2c5b3f)}
.tone-steel{background:linear-gradient(90deg,#4d7a92,#385a6e)}
.bar-val{font-size:13px;font-weight:700;color:var(--ink);font-variant-numeric:tabular-nums}

/* pipeline */
.pipe{display:flex;flex-direction:column;gap:2px}
.pipe-row{display:grid;grid-template-columns:130px 1fr 46px;align-items:center;gap:12px;padding:7px 0}
.pipe-row .st{font-size:13px;color:var(--ink);font-weight:600}
.pipe-track{height:22px;background:var(--line-2);border-radius:7px;overflow:hidden}
.pipe-fill{height:100%;border-radius:7px;background:linear-gradient(90deg,#e7b9b6,#c2261f);min-width:2px}
.pipe-row .c{text-align:right;font-weight:800;font-variant-numeric:tabular-nums}

/* tables */
table{width:100%;border-collapse:collapse}
th{text-align:left;font-size:11px;text-transform:uppercase;letter-spacing:.07em;color:var(--slate-2);font-weight:600;padding:8px 10px;border-bottom:1px solid var(--line)}
td{padding:10px 10px;border-bottom:1px solid var(--line-2);font-size:13.5px}
tr:last-child td{border-bottom:0}
td.num,th.num{text-align:right;font-variant-numeric:tabular-nums}
.tag{font-size:11px;font-weight:700;padding:3px 8px;border-radius:6px;white-space:nowrap}
.tag.due{background:var(--amber-bg);color:var(--amber)}
.tag.paid{background:var(--green-bg);color:var(--green)}
.tag.crit{background:var(--red-bg);color:var(--red)}
.pill-over{font-weight:800;color:var(--red)}

/* donut */
.donut-wrap{display:flex;align-items:center;gap:18px;flex-wrap:wrap}
.donut{width:130px;height:130px;flex:0 0 auto}
.donut-legend{list-style:none;margin:0;padding:0;display:flex;flex-direction:column;gap:7px;font-size:13px;color:var(--slate)}
.donut-legend li{display:flex;align-items:center;gap:8px}
.donut-legend .dot{width:10px;height:10px;border-radius:3px;flex:0 0 auto}
.donut-legend b{color:var(--ink);font-variant-numeric:tabular-nums}

/* misc lists */
.mini{display:flex;flex-direction:column;gap:10px}
.mini-row{display:flex;align-items:center;justify-content:space-between;gap:10px;font-size:13.5px}
.mini-row .who{color:var(--slate)}
.sev{width:8px;height:8px;border-radius:50%;flex:0 0 auto;display:inline-block;margin-right:8px}
.sev.warn{background:var(--amber)} .sev.info{background:var(--steel)} .sev.crit{background:var(--red)}
.stat-inline{display:flex;gap:22px;flex-wrap:wrap;margin-top:4px}
.stat-inline .s b{display:block;font-size:22px;font-weight:800;letter-spacing:-.01em}
.stat-inline .s span{font-size:12px;color:var(--slate-2);text-transform:uppercase;letter-spacing:.06em}

.note{font-size:12px;color:var(--slate-2);margin-top:10px;font-style:italic}
footer{padding:34px 0 50px;color:var(--slate-2);font-size:12.5px;text-align:center}

@media (max-width:1080px){ .kpis{grid-template-columns:repeat(3,1fr)} .g-2,.g-2e,.g-3{grid-template-columns:1fr} }
@media (max-width:640px){ .kpis{grid-template-columns:repeat(2,1fr)} .bar-row{grid-template-columns:110px 1fr auto} .pipe-row{grid-template-columns:96px 1fr 40px} }
</style>
</head>
<body>

<div class="topbar">
  <div class="wrap">
    <div class="topbar-in">
      <div class="brand">
        <span class="mark" aria-hidden="true"></span>
        <div><b>Shutters365</b><span>Business OS</span></div>
      </div>
      <div class="topbar-actions">
        <span>Hi <?php echo esc_html( $name ); ?> · <span class="tnum"><?php echo esc_html( $updated ); ?></span></span>
        <a class="btn" href="?refresh=1" title="Rebuild from live data">↻ Refresh</a>
        <a class="btn btn-ghost" href="<?php echo esc_url( admin_url() ); ?>">wp-admin</a>
      </div>
    </div>
    <nav class="subnav">
      <a href="#overview">Overview</a>
      <a href="#pipeline">Pipeline</a>
      <a href="#risk">Delivery risk</a>
      <a href="#leads">Leads</a>
      <a href="#analytics">Analytics</a>
      <a href="#margin">Margin</a>
      <a href="#vendor">Vendor</a>
      <a href="#support">Support</a>
    </nav>
  </div>
</div>

<div class="wrap">

  <!-- OVERVIEW -->
  <section class="section" id="overview">
    <div class="sec-h"><h2>This month at a glance</h2><?php echo s365_bos_badge( $k['sample'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
    <div class="kpis">
      <div class="kpi hero">
        <div class="lab">Revenue · this month</div>
        <div class="num tnum"><?php echo esc_html( s365_bos_money( $k['revenue_month'], $cur ) ); ?></div>
        <div class="sub"><?php echo $delta_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> vs <?php echo esc_html( s365_bos_money( $k['revenue_prev'], $cur ) ); ?> last month</div>
      </div>
      <div class="kpi">
        <div class="lab">Orders</div>
        <div class="num tnum"><?php echo esc_html( $k['orders_month'] ); ?></div>
        <div class="sub">this month</div>
      </div>
      <div class="kpi">
        <div class="lab">Avg order value</div>
        <div class="num tnum"><?php echo esc_html( s365_bos_money( $k['aov'], $cur ) ); ?></div>
        <div class="sub">per order</div>
      </div>
      <div class="kpi">
        <div class="lab">In production</div>
        <div class="num tnum"><?php echo esc_html( $k['wip'] ); ?></div>
        <div class="sub">active orders</div>
      </div>
      <div class="kpi">
        <div class="lab">New leads</div>
        <div class="num tnum"><?php echo esc_html( $data['leads']['this_month'] ); ?></div>
        <div class="sub">this month</div>
      </div>
      <div class="kpi">
        <div class="lab">Gross margin</div>
        <div class="num tnum"><?php echo esc_html( round( $data['margin']['gross_pct'] ) ); ?>%</div>
        <div class="sub"><?php echo $data['margin']['estimated'] ? 'estimated' : 'actual'; ?></div>
      </div>
    </div>
  </section>

  <!-- PIPELINE -->
  <section class="section" id="pipeline">
    <div class="grid g-2">
      <div class="card">
        <div class="card-h"><h3>Order pipeline</h3><?php echo s365_bos_badge( $data['pipeline']['sample'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
        <?php
        $max = 1;
        foreach ( $data['pipeline']['rows'] as $r ) {
			$max = max( $max, $r['count'] ); }
        echo '<div class="pipe">';
        foreach ( $data['pipeline']['rows'] as $r ) {
			$pct = round( ( $r['count'] / $max ) * 100 );
			echo '<div class="pipe-row"><span class="st">' . esc_html( $r['label'] ) . '</span>'
				. '<span class="pipe-track"><span class="pipe-fill" style="width:' . esc_attr( max( 2, $pct ) ) . '%"></span></span>'
				. '<span class="c tnum">' . esc_html( $r['count'] ) . '</span></div>';
		}
        echo '</div>';
        ?>
        <p class="note">Counts are live from WooCommerce order statuses — Design → Manufacturing → In transit → With courier → Delivered.</p>
      </div>
      <div class="card">
        <div class="card-h"><h3>Lead funnel</h3><?php echo s365_bos_badge( $data['leads']['sample'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
        <?php
        $lmax = 1;
        foreach ( $data['leads']['by_type'] as $r ) {
			$lmax = max( $lmax, $r['count'] ); }
        foreach ( $data['leads']['by_type'] as $r ) {
			echo s365_bos_bar( $r['label'], $r['count'], $lmax, (string) $r['count'], 'green' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
        ?>
        <div class="stat-inline">
          <div class="s"><b class="tnum"><?php echo esc_html( $data['leads']['total'] ); ?></b><span>total leads</span></div>
          <div class="s"><b class="tnum"><?php echo esc_html( $data['leads']['this_month'] ); ?></b><span>this month</span></div>
        </div>
      </div>
    </div>
  </section>

  <!-- DELIVERY RISK -->
  <section class="section" id="risk">
    <div class="card">
      <div class="card-h"><h3>Orders at delivery risk</h3><?php echo s365_bos_badge( $data['delayed']['sample'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
      <p class="hint">Active orders that have overrun the expected time for their stage. Worst overrun first.</p>
      <table>
        <thead><tr><th>Order</th><th>Customer</th><th>Stage</th><th class="num">Days in stage</th><th class="num">Over by</th><th class="num">Value</th></tr></thead>
        <tbody>
        <?php foreach ( $data['delayed']['rows'] as $r ) : ?>
          <tr>
            <td>#<?php echo esc_html( $r['id'] ); ?></td>
            <td><?php echo esc_html( $r['customer'] ? $r['customer'] : '—' ); ?></td>
            <td><?php echo esc_html( $r['stage'] ); ?></td>
            <td class="num tnum"><?php echo esc_html( $r['days'] ); ?> <span style="color:var(--slate-2)">/ <?php echo esc_html( $r['expected'] ); ?></span></td>
            <td class="num"><span class="pill-over">+<?php echo esc_html( $r['over'] ); ?>d</span></td>
            <td class="num tnum"><?php echo esc_html( s365_bos_money( $r['total'], $cur ) ); ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <p class="note">Time-in-stage is approximated from each order’s last-updated date until per-stage timestamps are recorded (Phase 2).</p>
    </div>
  </section>

  <!-- LEADS RECENT -->
  <section class="section" id="leads">
    <div class="card">
      <div class="card-h"><h3>Latest leads</h3><?php echo s365_bos_badge( $data['leads']['sample'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
      <table>
        <thead><tr><th>Source</th><th>Name</th><th>Email</th><th class="num">Est. quote</th><th class="num">When</th></tr></thead>
        <tbody>
        <?php foreach ( $data['leads']['recent'] as $r ) : ?>
          <tr>
            <td><?php echo esc_html( $r['type'] ); ?></td>
            <td><?php echo esc_html( $r['name'] ? $r['name'] : '—' ); ?></td>
            <td><?php echo esc_html( $r['email'] ); ?></td>
            <td class="num tnum"><?php echo $r['price'] ? esc_html( $cur . $r['price'] ) : '—'; ?></td>
            <td class="num"><?php echo esc_html( $r['ago'] ); ?> ago</td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </section>

  <!-- ANALYTICS -->
  <section class="section" id="analytics">
    <div class="sec-h"><h2>Analytics</h2></div>
    <div class="grid g-2e">
      <div class="card">
        <div class="card-h"><h3>Top-selling shutters</h3><?php echo s365_bos_badge( $data['products']['sample'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
        <?php
        $pmax = 1;
        foreach ( $data['products']['rows'] as $r ) {
			$pmax = max( $pmax, $r['revenue'] ); }
        foreach ( $data['products']['rows'] as $r ) {
			echo s365_bos_bar( $r['name'], $r['revenue'], $pmax, s365_bos_money( $r['revenue'], $cur ), 'brand' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
        ?>
      </div>
      <div class="card">
        <div class="card-h"><h3>Material &amp; finish split</h3><?php echo s365_bos_badge( $data['materials']['sample'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
        <?php echo s365_bos_donut( $data['materials']['rows'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
      </div>
    </div>
    <div class="grid g-2e" style="margin-top:16px">
      <div class="card">
        <div class="card-h"><h3>Top customers</h3><?php echo s365_bos_badge( $data['customers']['sample'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
        <table>
          <thead><tr><th>Customer</th><th>City</th><th class="num">Orders</th><th class="num">Spend</th></tr></thead>
          <tbody>
          <?php foreach ( $data['customers']['rows'] as $r ) : ?>
            <tr>
              <td><?php echo esc_html( $r['name'] ? $r['name'] : '—' ); ?></td>
              <td><?php echo esc_html( $r['city'] ); ?></td>
              <td class="num tnum"><?php echo esc_html( $r['orders'] ); ?></td>
              <td class="num tnum"><?php echo esc_html( s365_bos_money( $r['total'], $cur ) ); ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <div class="card">
        <div class="card-h"><h3>Orders by city</h3><?php echo s365_bos_badge( $data['cities']['sample'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
        <?php
        $cmax = 1;
        foreach ( $data['cities']['rows'] as $r ) {
			$cmax = max( $cmax, $r['revenue'] ); }
        foreach ( $data['cities']['rows'] as $r ) {
			echo s365_bos_bar( $r['label'], $r['revenue'], $cmax, $r['orders'] . ' · ' . s365_bos_money( $r['revenue'], $cur ), 'steel' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
        ?>
      </div>
    </div>
  </section>

  <!-- MARGIN -->
  <section class="section" id="margin">
    <div class="card">
      <div class="card-h"><h3>Margin this month</h3><?php echo s365_bos_badge( $data['margin']['estimated'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
      <div class="stat-inline" style="gap:34px">
        <div class="s"><b class="tnum"><?php echo esc_html( s365_bos_money( $data['margin']['revenue'], $cur ) ); ?></b><span>revenue</span></div>
        <div class="s"><b class="tnum" style="color:var(--amber)"><?php echo esc_html( s365_bos_money( $data['margin']['cogs'], $cur ) ); ?></b><span>cost of goods</span></div>
        <div class="s"><b class="tnum" style="color:var(--green)"><?php echo esc_html( s365_bos_money( $data['margin']['gross'], $cur ) ); ?></b><span>gross profit</span></div>
        <div class="s"><b class="tnum"><?php echo esc_html( round( $data['margin']['gross_pct'] ) ); ?>%</b><span>gross margin</span></div>
        <div class="s"><b class="tnum"><?php echo esc_html( s365_bos_money( $data['margin']['avg_margin'], $cur ) ); ?></b><span>avg profit / order</span></div>
      </div>
      <p class="note">Cost of goods is estimated at <?php echo esc_html( round( s365_bos_margin_rate() * 100 ) ); ?>% margin until per-product supplier costs are entered — then this becomes exact, per sale.</p>
    </div>
  </section>

  <!-- VENDOR -->
  <section class="section" id="vendor">
    <div class="grid g-2">
      <div class="card">
        <div class="card-h"><h3>Vendor purchase orders</h3><?php echo s365_bos_badge( $data['vendor']['sample'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
        <table>
          <thead><tr><th>PO</th><th>Order</th><th class="num">Vendor cost</th><th class="num">Status</th></tr></thead>
          <tbody>
          <?php foreach ( $data['vendor']['rows'] as $r ) : ?>
            <tr>
              <td><?php echo esc_html( $r['po'] ); ?></td>
              <td>#<?php echo esc_html( $r['order'] ); ?></td>
              <td class="num tnum"><?php echo esc_html( s365_bos_money( $r['cost'], $cur ) ); ?></td>
              <td class="num"><span class="tag <?php echo 'Paid' === $r['status'] ? 'paid' : 'due'; ?>"><?php echo esc_html( $r['status'] ); ?></span></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <div class="card">
        <h3>Vendor payments</h3>
        <p class="hint">What the maker is owed vs settled.</p>
        <div class="stat-inline">
          <div class="s"><b class="tnum" style="color:var(--amber)"><?php echo esc_html( s365_bos_money( $data['vendor']['outstanding'], $cur ) ); ?></b><span>outstanding</span></div>
          <div class="s"><b class="tnum" style="color:var(--green)"><?php echo esc_html( s365_bos_money( $data['vendor']['paid'], $cur ) ); ?></b><span>paid</span></div>
        </div>
        <p class="note">Projected from each in-production order’s estimated cost. Becomes a live owed-vs-invoiced ledger once vendor invoices are entered (Phase 3).</p>
      </div>
    </div>
  </section>

  <!-- SUPPORT -->
  <section class="section" id="support">
    <div class="card">
      <div class="card-h"><h3>Support &amp; customer requests</h3><?php echo s365_bos_badge( $data['support']['sample'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
      <div class="mini">
      <?php foreach ( $data['support']['rows'] as $r ) : ?>
        <div class="mini-row">
          <span><span class="sev <?php echo esc_attr( $r['sev'] ); ?>"></span><?php echo esc_html( $r['subject'] ); ?></span>
          <span class="who"><?php echo esc_html( $r['who'] ); ?> · <?php echo esc_html( $r['age'] ); ?></span>
        </div>
      <?php endforeach; ?>
      </div>
      <p class="note">Where info@ threads, day-2 fitting check-ins and escalations will land once the support inbox is connected (Phase 3).</p>
    </div>
  </section>

  <footer>
    Shutters365 Business OS · data from WooCommerce + the lead CRM on this server ·
    sections marked <span class="badge badge-sample">Sample</span> are illustrative until that data is captured.
  </footer>

</div>
</body>
</html>
	<?php
}
