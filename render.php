<?php
/**
 * Shutters365 Business OS — view.
 *
 * Renders the standalone dashboard document from the payload built in data.php.
 * The functions are split across tabs (Overview, Delivery risk, Leads,
 * Analytics, Margin, Vendor, Support); only one panel shows at a time. All
 * output is escaped; sample sections are badged so nothing projected reads as a
 * confirmed figure.
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

/** A horizontal stage rail: nodes filled up to (and including) $index. */
function s365_bos_stage_rail( $stages, $index, $over = false ) {
	$out = '<div class="rail">';
	foreach ( $stages as $i => $label ) {
		$cls = $i < $index ? 'done' : ( $i === $index ? 'current' : 'todo' );
		if ( $i === $index && $over ) {
			$cls .= ' over';
		}
		$out .= '<div class="node ' . $cls . '">';
		if ( $i > 0 ) {
			$out .= '<span class="seg ' . ( $i <= $index ? 'fill' : '' ) . '"></span>';
		}
		$out .= '<span class="dot"></span><span class="lbl">' . esc_html( $label ) . '</span></div>';
	}
	$out .= '</div>';
	return $out;
}

/** Render the full page and echo it. */
function s365_bos_render_page( $data ) {
	$cur     = isset( $data['currency'] ) ? $data['currency'] : '£';
	$user    = wp_get_current_user();
	$name    = $user ? ( $user->first_name ? $user->first_name : $user->display_name ) : 'there';
	$updated = isset( $data['generated'] ) ? date_i18n( 'j M Y, H:i', $data['generated'] ) : '';
	$since   = isset( $data['report_start'] ) ? date_i18n( 'j M Y', $data['report_start'] ) : '';
	$k       = $data['kpis'];

	$delta_html = '';
	if ( null !== $k['revenue_change'] ) {
		$up   = $k['revenue_change'] >= 0;
		$delta_html = '<span class="delta ' . ( $up ? 'up' : 'down' ) . '">' . ( $up ? '▲' : '▼' ) . ' '
			. esc_html( number_format( abs( $k['revenue_change'] ), 1 ) ) . '%</span>';
	}

	$tabs = array(
		'overview'  => 'Overview',
		'timeline'  => 'Timeline',
		'risk'      => 'Delivery risk',
		'leads'     => 'Leads',
		'samples'   => 'Samples → sales',
		'growth'    => 'Growth',
		'analytics' => 'Analytics',
		'margin'    => 'Margin',
		'vendor'    => 'Vendor',
		'support'   => 'Support',
	);
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
[hidden]{display:none!important}

/* top bar */
.topbar{position:sticky;top:0;z-index:30;background:rgba(243,239,232,.94);backdrop-filter:saturate(1.2) blur(8px);border-bottom:1px solid var(--line)}
.topbar-in{display:flex;align-items:center;justify-content:space-between;gap:16px;padding:14px 0}
.brand{display:flex;align-items:center;gap:12px}
.brand .mark{width:30px;height:30px;border:2px solid var(--brand);border-radius:7px;position:relative;flex:0 0 auto}
.brand .mark::before,.brand .mark::after{content:"";position:absolute;background:var(--brand)}
.brand .mark::before{left:10px;top:2px;bottom:2px;width:2px}
.brand .mark::after{top:10px;left:2px;right:2px;height:2px}
.brand b{font-size:16px;font-weight:700}
.brand span{color:var(--slate-2);font-size:12px;letter-spacing:.14em;text-transform:uppercase;display:block;margin-top:-2px}
.topbar-actions{display:flex;align-items:center;gap:10px;font-size:13px;color:var(--slate);flex-wrap:wrap;justify-content:flex-end}
.period{font-size:11.5px;font-weight:600;color:var(--brand-ink);background:#fbeceb;border:1px solid #f0d7d4;padding:4px 10px;border-radius:999px;white-space:nowrap}
.btn{display:inline-flex;align-items:center;gap:7px;padding:8px 13px;border-radius:999px;border:1px solid var(--line);background:var(--card);color:var(--ink);text-decoration:none;font-size:13px;font-weight:600;box-shadow:var(--shadow)}
.btn:hover{border-color:var(--brand)}
.btn-ghost{box-shadow:none;background:transparent}

/* tabs */
.tabs{display:flex;gap:2px;overflow-x:auto;padding:4px 0 0;scrollbar-width:thin}
.tab{white-space:nowrap;font-size:13.5px;color:var(--slate);background:transparent;border:0;border-bottom:2px solid transparent;padding:10px 15px;font-weight:600;cursor:pointer;font-family:inherit}
.tab:hover{color:var(--ink)}
.tab.active{color:var(--brand);border-bottom-color:var(--brand)}
.tab:focus-visible{outline:2px solid var(--brand);outline-offset:-2px;border-radius:6px}

/* panels */
.panel{padding:24px 0 4px}
.panel-h{display:flex;align-items:baseline;gap:10px;margin:0 0 16px}
.panel-h h2{font-size:18px;font-weight:800;letter-spacing:-.01em}
.panel-h .sub{font-size:13px;color:var(--slate-2)}
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
.mt{margin-top:16px}
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
.pill-over{font-weight:800;color:var(--red)}

/* donut */
.donut-wrap{display:flex;align-items:center;gap:18px;flex-wrap:wrap}
.donut{width:130px;height:130px;flex:0 0 auto}
.donut-legend{list-style:none;margin:0;padding:0;display:flex;flex-direction:column;gap:7px;font-size:13px;color:var(--slate)}
.donut-legend li{display:flex;align-items:center;gap:8px}
.donut-legend .dot{width:10px;height:10px;border-radius:3px;flex:0 0 auto}
.donut-legend b{color:var(--ink);font-variant-numeric:tabular-nums}

/* misc */
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

/* insights strip */
.insights{display:grid;grid-template-columns:repeat(3,1fr);gap:12px;margin-top:18px}
.insight{background:var(--card);border:1px solid var(--line);border-left:3px solid var(--slate-2);border-radius:var(--r-sm);padding:13px 15px;box-shadow:var(--shadow);font-size:13.5px;color:var(--ink);display:flex;gap:10px;align-items:flex-start}
.insight .ic{font-size:15px;flex:0 0 auto}
.insight.good{border-left-color:var(--green)} .insight.bad{border-left-color:var(--red)}
.insight.warn{border-left-color:var(--amber)} .insight.info{border-left-color:var(--steel)}
.insight.opp{border-left-color:var(--brand);background:#fdf7f6}

/* stage rail (timeline) */
.rail{display:flex;align-items:flex-start}
.rail .node{flex:1;display:flex;flex-direction:column;align-items:center;gap:6px;position:relative;min-width:0}
.rail .seg{position:absolute;top:6px;left:-50%;width:100%;height:2px;background:var(--line);z-index:0}
.rail .seg.fill{background:var(--green)}
.rail .dot{width:14px;height:14px;border-radius:50%;background:#fff;border:2px solid var(--line);z-index:1}
.rail .node.done .dot{background:var(--green);border-color:var(--green)}
.rail .node.current .dot{width:16px;height:16px;background:var(--brand);border-color:var(--brand);box-shadow:0 0 0 4px rgba(194,38,31,.15)}
.rail .node.current.over .dot{background:var(--red);border-color:var(--red);box-shadow:0 0 0 4px rgba(163,32,26,.18)}
.rail .lbl{font-size:9.5px;line-height:1.15;text-align:center;color:var(--slate-2);white-space:nowrap}
.rail .node.done .lbl,.rail .node.current .lbl{color:var(--ink);font-weight:600}
.tl-row{display:grid;grid-template-columns:210px 1fr;gap:18px;align-items:center;padding:15px 4px;border-bottom:1px solid var(--line-2)}
.tl-row:last-child{border-bottom:0}
.tl-meta .oid{font-weight:700;font-size:14px}
.tl-meta .oid .v{color:var(--slate-2);font-weight:600;font-size:12px;margin-left:6px}
.tl-meta .cust{font-size:13px;color:var(--slate)}
.tl-meta .rcv{font-size:11.5px;color:var(--slate-2)}
.tl-cap{font-size:11.5px;color:var(--slate-2);margin-top:8px;text-align:center}
.tl-cap .over{color:var(--red);font-weight:700}

/* conversion stats */
.conv-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:14px;margin-bottom:16px}
.conv{background:var(--card);border:1px solid var(--line);border-radius:var(--r);padding:16px;box-shadow:var(--shadow)}
.conv b{display:block;font-size:26px;font-weight:800;letter-spacing:-.02em}
.conv span{font-size:12px;color:var(--slate-2);text-transform:uppercase;letter-spacing:.05em}
.conv.hi b{color:var(--green)}

/* growth / nudges */
.opp-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:14px;margin-bottom:18px}
.opp-card{background:var(--card);border:1px solid var(--line);border-radius:var(--r);padding:16px 17px;box-shadow:var(--shadow);border-top:3px solid var(--brand)}
.opp-card.q{border-top-color:var(--steel)} .opp-card.s{border-top-color:var(--amber)} .opp-card.r{border-top-color:var(--green)}
.opp-card b{display:block;font-size:24px;font-weight:800;letter-spacing:-.01em}
.opp-card .t{font-size:13px;color:var(--ink);font-weight:600;margin-top:2px}
.opp-card .d{font-size:12px;color:var(--slate-2);margin-top:4px}
.ntype{font-size:10.5px;font-weight:700;text-transform:uppercase;letter-spacing:.04em;padding:3px 8px;border-radius:6px;white-space:nowrap}
.ntype.sample{background:var(--amber-bg);color:var(--amber)}
.ntype.quote{background:#e8eff3;color:var(--steel)}
.ntype.repeat{background:var(--green-bg);color:var(--green)}
.act{font-size:13px;color:var(--slate)}

@media (max-width:1080px){ .kpis{grid-template-columns:repeat(3,1fr)} .g-2,.g-2e{grid-template-columns:1fr} .insights,.opp-grid{grid-template-columns:1fr} .conv-grid{grid-template-columns:repeat(2,1fr)} }
@media (max-width:640px){ .kpis{grid-template-columns:repeat(2,1fr)} .bar-row{grid-template-columns:110px 1fr auto} .pipe-row{grid-template-columns:96px 1fr 40px} .tl-row{grid-template-columns:1fr;gap:10px} .rail .lbl{font-size:8px} }
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
        <?php if ( $since ) : ?><span class="period">Orders since <?php echo esc_html( $since ); ?></span><?php endif; ?>
        <span>Hi <?php echo esc_html( $name ); ?> · <span class="tnum"><?php echo esc_html( $updated ); ?></span></span>
        <a class="btn" href="?refresh=1" title="Rebuild from live data">↻ Refresh</a>
        <a class="btn btn-ghost" href="<?php echo esc_url( admin_url() ); ?>">wp-admin</a>
      </div>
    </div>
    <div class="tabs" role="tablist">
      <?php foreach ( $tabs as $id => $label ) : ?>
        <button class="tab<?php echo 'overview' === $id ? ' active' : ''; ?>" role="tab" data-tab="<?php echo esc_attr( $id ); ?>" aria-selected="<?php echo 'overview' === $id ? 'true' : 'false'; ?>"><?php echo esc_html( $label ); ?></button>
      <?php endforeach; ?>
    </div>
  </div>
</div>

<div class="wrap">

  <!-- OVERVIEW -->
  <section class="panel" id="tab-overview" role="tabpanel">
    <div class="panel-h"><h2>This month at a glance</h2><?php echo s365_bos_badge( $k['sample'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
    <div class="kpis">
      <div class="kpi hero">
        <div class="lab">Revenue · this month</div>
        <div class="num tnum"><?php echo esc_html( s365_bos_money( $k['revenue_month'], $cur ) ); ?></div>
        <div class="sub"><?php echo $delta_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> vs <?php echo esc_html( s365_bos_money( $k['revenue_prev'], $cur ) ); ?> last month</div>
      </div>
      <div class="kpi"><div class="lab">Orders</div><div class="num tnum"><?php echo esc_html( $k['orders_month'] ); ?></div><div class="sub">this month</div></div>
      <div class="kpi"><div class="lab">Avg order value</div><div class="num tnum"><?php echo esc_html( s365_bos_money( $k['aov'], $cur ) ); ?></div><div class="sub">per order</div></div>
      <div class="kpi"><div class="lab">In production</div><div class="num tnum"><?php echo esc_html( $k['wip'] ); ?></div><div class="sub">active orders</div></div>
      <div class="kpi"><div class="lab">New leads</div><div class="num tnum"><?php echo esc_html( $data['leads']['this_month'] ); ?></div><div class="sub">this month</div></div>
      <div class="kpi"><div class="lab">Gross margin</div><div class="num tnum"><?php echo esc_html( round( $data['margin']['gross_pct'] ) ); ?>%</div><div class="sub"><?php echo $data['margin']['estimated'] ? 'estimated' : 'actual'; ?></div></div>
    </div>
    <?php if ( ! empty( $data['insights']['rows'] ) ) : ?>
    <div class="insights">
      <?php
      $ins_icons = array( 'good' => '▲', 'bad' => '▼', 'warn' => '⚠', 'info' => '›', 'opp' => '★' );
      foreach ( $data['insights']['rows'] as $ins ) :
        $tone = isset( $ins['tone'] ) ? $ins['tone'] : 'info';
      ?>
        <div class="insight <?php echo esc_attr( $tone ); ?>"><span class="ic"><?php echo esc_html( isset( $ins_icons[ $tone ] ) ? $ins_icons[ $tone ] : '›' ); ?></span><span><?php echo esc_html( $ins['text'] ); ?></span></div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
    <div class="card mt">
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
      <p class="note">Live from WooCommerce order statuses — Design → Manufacturing → In transit → With courier → Delivered.</p>
    </div>
  </section>

  <!-- TIMELINE -->
  <section class="panel" id="tab-timeline" role="tabpanel" hidden>
    <div class="panel-h"><h2>Order timeline</h2><span class="sub">every shutter order and the stage it’s moved into</span><?php echo s365_bos_badge( $data['timeline']['sample'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
    <div class="card">
      <?php foreach ( $data['timeline']['rows'] as $r ) : ?>
        <div class="tl-row">
          <div class="tl-meta">
            <div class="oid">#<?php echo esc_html( $r['id'] ); ?><span class="v"><?php echo esc_html( s365_bos_money( $r['total'], $cur ) ); ?></span></div>
            <div class="cust"><?php echo esc_html( $r['customer'] ? $r['customer'] : '—' ); ?></div>
            <div class="rcv">received <?php echo esc_html( date_i18n( 'j M', $r['received'] ) ); ?> · day <?php echo esc_html( $r['days'] ); ?></div>
          </div>
          <div>
            <?php echo s365_bos_stage_rail( $data['timeline']['stages'], (int) $r['stage'], ! empty( $r['over'] ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
            <div class="tl-cap">
              <?php if ( (int) $r['stage'] >= 6 ) : ?>Delivered<?php else : ?>In <b><?php echo esc_html( $r['label'] ); ?></b> for <?php echo esc_html( $r['days_in'] ); ?>d<?php if ( ! empty( $r['over'] ) ) : ?> · <span class="over">overdue</span><?php endif; ?><?php endif; ?>
            </div>
          </div>
        </div>
      <?php endforeach; ?>
      <p class="note">Stage is live from each order’s WooCommerce status; exact per-stage dates arrive with the Phase 2 stage timestamps.</p>
    </div>
  </section>

  <!-- DELIVERY RISK -->
  <section class="panel" id="tab-risk" role="tabpanel" hidden>
    <div class="panel-h"><h2>Orders at delivery risk</h2><span class="sub">active orders past the expected time for their stage</span><?php echo s365_bos_badge( $data['delayed']['sample'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
    <div class="card">
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

  <!-- LEADS -->
  <section class="panel" id="tab-leads" role="tabpanel" hidden>
    <div class="panel-h"><h2>Leads</h2><?php echo s365_bos_badge( $data['leads']['sample'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
    <div class="grid g-2e">
      <div class="card">
        <div class="card-h"><h3>Lead funnel by source</h3></div>
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
      <div class="card">
        <div class="card-h"><h3>Latest leads</h3></div>
        <table>
          <thead><tr><th>Source</th><th>Name</th><th class="num">Est. quote</th><th class="num">When</th></tr></thead>
          <tbody>
          <?php foreach ( $data['leads']['recent'] as $r ) : ?>
            <tr>
              <td><?php echo esc_html( $r['type'] ); ?></td>
              <td><?php echo esc_html( $r['name'] ? $r['name'] : '—' ); ?></td>
              <td class="num tnum"><?php echo $r['price'] ? esc_html( $cur . $r['price'] ) : '—'; ?></td>
              <td class="num"><?php echo esc_html( $r['ago'] ); ?> ago</td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </section>

  <!-- SAMPLES -> SALES -->
  <section class="panel" id="tab-samples" role="tabpanel" hidden>
    <div class="panel-h"><h2>Samples → sales</h2><span class="sub">which sample customers went on to buy</span><?php echo s365_bos_badge( $data['conversions']['sample'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
    <div class="conv-grid">
      <div class="conv"><b class="tnum"><?php echo esc_html( $data['conversions']['sample_customers'] ); ?></b><span>sample customers</span></div>
      <div class="conv hi"><b class="tnum"><?php echo esc_html( $data['conversions']['converted'] ); ?></b><span>converted to buyers</span></div>
      <div class="conv"><b class="tnum"><?php echo esc_html( round( $data['conversions']['rate'] ) ); ?>%</b><span>conversion rate</span></div>
      <div class="conv"><b class="tnum"><?php echo esc_html( s365_bos_money( $data['conversions']['revenue'], $cur ) ); ?></b><span>revenue from converts</span></div>
    </div>
    <div class="card">
      <div class="card-h"><h3>Sample customers who became buyers</h3></div>
      <table>
        <thead><tr><th>Customer</th><th>Email</th><th>Full order</th><th class="num">Order value</th><th class="num">Days to buy</th></tr></thead>
        <tbody>
        <?php foreach ( $data['conversions']['rows'] as $r ) : ?>
          <tr>
            <td><?php echo esc_html( $r['name'] ? $r['name'] : '—' ); ?></td>
            <td><?php echo esc_html( $r['email'] ); ?></td>
            <td>#<?php echo esc_html( $r['order'] ); ?></td>
            <td class="num tnum"><?php echo esc_html( s365_bos_money( $r['total'], $cur ) ); ?></td>
            <td class="num tnum"><?php echo esc_html( $r['days'] ); ?>d</td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <p class="note"><?php echo esc_html( $data['conversions']['open'] ); ?> sample customers haven’t ordered yet — they’re in the Growth tab as nudge targets.</p>
    </div>
  </section>

  <!-- GROWTH -->
  <section class="panel" id="tab-growth" role="tabpanel" hidden>
    <div class="panel-h"><h2>Growth &amp; nudges</h2><span class="sub">upsell opportunities and customers worth a nudge</span><?php echo s365_bos_badge( $data['nudges']['sample'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
    <?php $ns = $data['nudges']['summary']; ?>
    <div class="opp-grid">
      <div class="opp-card q"><b class="tnum"><?php echo esc_html( s365_bos_money( $ns['quotes_value'], $cur ) ); ?></b><div class="t"><?php echo esc_html( $ns['quotes'] ); ?> quotes not yet ordered</div><div class="d">Follow up before they go cold.</div></div>
      <div class="opp-card s"><b class="tnum"><?php echo esc_html( $ns['samples'] ); ?></b><div class="t">samples awaiting purchase</div><div class="d">Nudge to finish the order.</div></div>
      <div class="opp-card r"><b class="tnum"><?php echo esc_html( $ns['repeat'] ); ?></b><div class="t">past customers for repeat / referral</div><div class="d">Ask for the next room or a review.</div></div>
    </div>
    <div class="card">
      <div class="card-h"><h3>Customers to nudge</h3></div>
      <table>
        <thead><tr><th>Customer</th><th>Email</th><th>Why</th><th>Suggested action</th><th class="num">Type</th></tr></thead>
        <tbody>
        <?php foreach ( $data['nudges']['rows'] as $r ) : ?>
          <tr>
            <td><?php echo esc_html( $r['name'] ); ?></td>
            <td><?php echo esc_html( $r['email'] ); ?></td>
            <td><?php echo esc_html( $r['reason'] ); ?></td>
            <td class="act"><?php echo esc_html( $r['action'] ); ?></td>
            <td class="num"><span class="ntype <?php echo esc_attr( $r['type'] ); ?>"><?php echo esc_html( $r['type'] ); ?></span></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <p class="note">Built from unconverted quotes, sample requests and delivered one-off buyers. One-click email will come with the Phase 3 follow-up sequences.</p>
    </div>
  </section>

  <!-- ANALYTICS -->
  <section class="panel" id="tab-analytics" role="tabpanel" hidden>
    <div class="panel-h"><h2>Analytics</h2><span class="sub">orders since <?php echo esc_html( $since ); ?></span></div>
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
    <div class="grid g-2e mt">
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
  <section class="panel" id="tab-margin" role="tabpanel" hidden>
    <div class="panel-h"><h2>Margin this month</h2><?php echo s365_bos_badge( $data['margin']['estimated'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
    <div class="card">
      <div class="stat-inline" style="gap:34px">
        <div class="s"><b class="tnum"><?php echo esc_html( s365_bos_money( $data['margin']['revenue'], $cur ) ); ?></b><span>revenue</span></div>
        <div class="s"><b class="tnum" style="color:var(--amber)"><?php echo esc_html( s365_bos_money( $data['margin']['cogs'], $cur ) ); ?></b><span>cost of goods</span></div>
        <div class="s"><b class="tnum" style="color:var(--green)"><?php echo esc_html( s365_bos_money( $data['margin']['gross'], $cur ) ); ?></b><span>gross profit</span></div>
        <div class="s"><b class="tnum"><?php echo esc_html( round( $data['margin']['gross_pct'] ) ); ?>%</b><span>gross margin</span></div>
        <div class="s"><b class="tnum"><?php echo esc_html( s365_bos_money( $data['margin']['avg_margin'], $cur ) ); ?></b><span>avg profit / order</span></div>
      </div>
      <p class="note">Cost of goods is estimated at <?php echo esc_html( round( ( 1 - s365_bos_margin_rate() ) * 100 ) ); ?>% of price (<?php echo esc_html( round( s365_bos_margin_rate() * 100 ) ); ?>% margin) until per-product supplier costs are entered — then this becomes exact, per sale.</p>
    </div>
  </section>

  <!-- VENDOR -->
  <section class="panel" id="tab-vendor" role="tabpanel" hidden>
    <div class="panel-h"><h2>Vendor &amp; payments</h2><?php echo s365_bos_badge( $data['vendor']['sample'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
    <div class="grid g-2">
      <div class="card">
        <div class="card-h"><h3>Purchase orders</h3></div>
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
  <section class="panel" id="tab-support" role="tabpanel" hidden>
    <div class="panel-h"><h2>Support &amp; customer requests</h2><?php echo s365_bos_badge( $data['support']['sample'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
    <div class="card">
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
    Shutters365 Business OS · orders placed on/after <?php echo esc_html( $since ); ?> · live WooCommerce + lead CRM data ·
    sections marked <span class="badge badge-sample">Sample</span> are illustrative until that data is captured.
  </footer>

</div>

<script>
(function(){
  var tabs = Array.prototype.slice.call(document.querySelectorAll('[data-tab]'));
  var panels = Array.prototype.slice.call(document.querySelectorAll('.panel'));
  function show(id){
    var found = false;
    panels.forEach(function(p){ var on = (p.id === 'tab-' + id); p.hidden = !on; if(on){found=true;} });
    tabs.forEach(function(t){ var on = (t.getAttribute('data-tab') === id); t.classList.toggle('active', on); t.setAttribute('aria-selected', on ? 'true' : 'false'); });
    return found;
  }
  tabs.forEach(function(t){
    t.addEventListener('click', function(){
      var id = t.getAttribute('data-tab');
      show(id);
      try { history.replaceState(null, '', '#' + id); } catch(e){}
    });
  });
  var initial = (location.hash || '').replace('#','');
  if(!initial || !show(initial)){ show('overview'); }
})();
</script>
</body>
</html>
	<?php
}
