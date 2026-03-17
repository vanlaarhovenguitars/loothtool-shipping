<?php
/**
 * Packing Slip & Gift Receipt — printable HTML pages.
 *
 * AJAX endpoint renders a standalone, print-optimized HTML page.
 * Two modes:
 *   - "standard" — full packing slip with prices
 *   - "gift"     — gift receipt, no financial data, shows gift message
 */

defined( 'ABSPATH' ) || exit;

class LT_Packing_Slip {

	public static function init() {
		add_action( 'wp_ajax_lt_packing_slip', [ __CLASS__, 'render' ] );
	}

	/**
	 * AJAX handler — outputs a full HTML page for printing.
	 */
	public static function render() {
		$order_id  = absint( $_GET['order_id'] ?? 0 );
		$vendor_id = absint( $_GET['vendor_id'] ?? 0 );
		$mode      = sanitize_key( $_GET['mode'] ?? 'standard' );

		if ( ! $order_id || ! $vendor_id ) {
			wp_die( 'Missing parameters.' );
		}

		// Nonce first, then authorization.
		check_ajax_referer( 'lt_packing_slip_' . $order_id, '_wpnonce' );

		$current_user_id = get_current_user_id();
		if ( $current_user_id !== $vendor_id && ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Unauthorized.' );
		}

		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			wp_die( 'Order not found.' );
		}

		// IDOR protection: verify this vendor owns at least one item in the order.
		if ( ! current_user_can( 'manage_options' ) ) {
			$has_items = false;
			foreach ( $order->get_items( 'line_item' ) as $item ) {
				$product = $item->get_product();
				if ( $product && (int) get_post_field( 'post_author', $product->get_id() ) === $vendor_id ) {
					$has_items = true;
					break;
				}
			}
			if ( ! $has_items ) {
				wp_die( 'Unauthorized.' );
			}
		}

		$is_gift = ( $mode === 'gift' );

		// Vendor store info for branding.
		$store_name = get_user_meta( $vendor_id, 'dokan_store_name', true )
			?: get_the_author_meta( 'display_name', $vendor_id );
		$logo_id  = get_user_meta( $vendor_id, 'dokan_gravatar', true );
		$logo_url = $logo_id
			? wp_get_attachment_image_url( (int) $logo_id, 'medium' )
			: get_avatar_url( $vendor_id, [ 'size' => 120 ] );

		// Shipping address.
		$ship_name    = trim( $order->get_shipping_first_name() . ' ' . $order->get_shipping_last_name() );
		$ship_address = array_filter( [
			$order->get_shipping_address_1(),
			$order->get_shipping_address_2(),
			trim( $order->get_shipping_city() . ', ' . $order->get_shipping_state() . ' ' . $order->get_shipping_postcode() ),
			$order->get_shipping_country(),
		] );

		// From address (vendor).
		$vendor_address_parts = [];
		$dokan_settings = get_user_meta( $vendor_id, 'dokan_profile_settings', true );
		if ( is_array( $dokan_settings ) && ! empty( $dokan_settings['address'] ) ) {
			$a = $dokan_settings['address'];
			$vendor_address_parts = array_filter( [
				$a['street_1'] ?? '',
				$a['street_2'] ?? '',
				trim( ( $a['city'] ?? '' ) . ', ' . ( $a['state'] ?? '' ) . ' ' . ( $a['zip'] ?? '' ) ),
			] );
		}

		// Order items — only items belonging to this vendor.
		$items = [];
		foreach ( $order->get_items( 'line_item' ) as $item ) {
			$product = $item->get_product();
			$author  = $product ? (int) get_post_field( 'post_author', $product->get_id() ) : 0;
			// In sub-orders all items belong to the vendor, but check anyway.
			if ( $author && $author !== $vendor_id ) {
				continue;
			}
			$items[] = [
				'name' => $item->get_name(),
				'sku'  => $product ? $product->get_sku() : '',
				'qty'  => $item->get_quantity(),
				'total' => $order->get_formatted_line_subtotal( $item ),
			];
		}

		// Gift message.
		$gift_message = $order->get_meta( '_lt_gift_message' ) ?: '';

		// Order date.
		$order_date = $order->get_date_created()
			? $order->get_date_created()->date_i18n( 'F j, Y' )
			: '';

		self::output_html( [
			'is_gift'        => $is_gift,
			'order_number'   => $order->get_order_number(),
			'order_date'     => $order_date,
			'store_name'     => $store_name,
			'logo_url'       => $logo_url,
			'ship_name'      => $ship_name,
			'ship_address'   => $ship_address,
			'from_name'      => $store_name,
			'from_address'   => $vendor_address_parts,
			'items'          => $items,
			'order_total'    => $order->get_formatted_order_total(),
			'gift_message'   => $gift_message,
			'shipping_method'=> $order->get_shipping_method(),
		] );

		exit;
	}

	/**
	 * Output the full HTML page.
	 */
	private static function output_html( array $d ) {
		$title = $d['is_gift'] ? 'Gift Receipt' : 'Packing Slip';
		?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo esc_html( $title . ' — Order #' . $d['order_number'] ); ?></title>
<style>
	* { margin: 0; padding: 0; box-sizing: border-box; }
	body {
		font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
		font-size: 13px;
		color: #222;
		background: #f5f5f5;
		padding: 20px;
	}
	.slip {
		max-width: 700px;
		margin: 0 auto;
		background: #fff;
		border: 1px solid #ddd;
		border-radius: 8px;
		padding: 40px;
	}
	.slip-header {
		display: flex;
		justify-content: space-between;
		align-items: flex-start;
		margin-bottom: 28px;
		padding-bottom: 20px;
		border-bottom: 2px solid #eee;
	}
	.slip-brand {
		display: flex;
		align-items: center;
		gap: 14px;
	}
	.slip-logo {
		width: 60px;
		height: 60px;
		border-radius: 50%;
		object-fit: cover;
		border: 1px solid #eee;
	}
	.slip-store-name {
		font-size: 20px;
		font-weight: 700;
		color: #111;
	}
	.slip-title {
		font-size: 22px;
		font-weight: 700;
		text-transform: uppercase;
		letter-spacing: 2px;
		color: #333;
	}
	.slip-title--gift {
		color: #a42325;
	}
	.slip-meta {
		display: flex;
		justify-content: space-between;
		gap: 40px;
		margin-bottom: 24px;
	}
	.slip-meta-col h3 {
		font-size: 10px;
		text-transform: uppercase;
		letter-spacing: 1px;
		color: #888;
		margin-bottom: 6px;
	}
	.slip-meta-col p {
		line-height: 1.5;
	}
	.slip-order-info {
		text-align: right;
	}
	.slip-order-info span {
		display: block;
		color: #555;
		font-size: 12px;
	}
	.slip-order-info strong {
		font-size: 14px;
	}
	table {
		width: 100%;
		border-collapse: collapse;
		margin-bottom: 20px;
	}
	thead th {
		text-align: left;
		font-size: 10px;
		text-transform: uppercase;
		letter-spacing: 1px;
		color: #888;
		padding: 8px 10px;
		border-bottom: 2px solid #eee;
	}
	thead th.right { text-align: right; }
	tbody td {
		padding: 10px;
		border-bottom: 1px solid #f0f0f0;
		vertical-align: top;
	}
	tbody td.right { text-align: right; }
	.item-name { font-weight: 600; }
	.item-sku { color: #888; font-size: 11px; }
	.slip-total-row {
		text-align: right;
		padding: 12px 10px;
		font-size: 15px;
		font-weight: 700;
		border-top: 2px solid #eee;
	}
	.slip-gift-message {
		margin: 24px 0;
		padding: 20px;
		background: #fdf8f4;
		border: 1px solid #f0e0d0;
		border-radius: 8px;
	}
	.slip-gift-message h3 {
		font-size: 11px;
		text-transform: uppercase;
		letter-spacing: 1px;
		color: #a42325;
		margin-bottom: 8px;
	}
	.slip-gift-message p {
		font-size: 14px;
		line-height: 1.6;
		color: #333;
		white-space: pre-wrap;
	}
	.slip-footer {
		margin-top: 28px;
		padding-top: 16px;
		border-top: 1px solid #eee;
		text-align: center;
		color: #999;
		font-size: 11px;
	}
	.no-print { margin: 20px auto; max-width: 700px; text-align: center; }
	.no-print button {
		background: #333;
		color: #fff;
		border: none;
		padding: 10px 28px;
		border-radius: 6px;
		font-size: 14px;
		cursor: pointer;
		margin: 0 6px;
	}
	.no-print button:hover { background: #555; }
	@media print {
		body { background: #fff; padding: 0; }
		.slip { border: none; border-radius: 0; padding: 20px; box-shadow: none; }
		.no-print { display: none !important; }
	}
</style>
</head>
<body>

<div class="no-print">
	<button onclick="window.print()">Print</button>
	<button onclick="window.close()">Close</button>
</div>

<div class="slip">
	<!-- Header -->
	<div class="slip-header">
		<div class="slip-brand">
			<?php if ( $d['logo_url'] ) : ?>
			<img class="slip-logo" src="<?php echo esc_url( $d['logo_url'] ); ?>" alt="">
			<?php endif; ?>
			<div class="slip-store-name"><?php echo esc_html( $d['store_name'] ); ?></div>
		</div>
		<div class="slip-title<?php echo $d['is_gift'] ? ' slip-title--gift' : ''; ?>">
			<?php echo esc_html( $title ); ?>
		</div>
	</div>

	<!-- Addresses + Order info -->
	<div class="slip-meta">
		<?php if ( ! empty( $d['from_address'] ) ) : ?>
		<div class="slip-meta-col">
			<h3>From</h3>
			<p>
				<strong><?php echo esc_html( $d['from_name'] ); ?></strong><br>
				<?php echo esc_html( implode( "\n", $d['from_address'] ) ); ?>
			</p>
		</div>
		<?php endif; ?>
		<div class="slip-meta-col">
			<h3>Ship To</h3>
			<p>
				<strong><?php echo esc_html( $d['ship_name'] ); ?></strong><br>
				<?php echo esc_html( implode( "\n", $d['ship_address'] ) ); ?>
			</p>
		</div>
		<div class="slip-meta-col slip-order-info">
			<span>Order #<?php echo esc_html( $d['order_number'] ); ?></span>
			<span><?php echo esc_html( $d['order_date'] ); ?></span>
			<?php if ( $d['shipping_method'] && ! $d['is_gift'] ) : ?>
			<span style="margin-top:4px"><?php echo esc_html( $d['shipping_method'] ); ?></span>
			<?php endif; ?>
		</div>
	</div>

	<!-- Items table -->
	<table>
		<thead>
			<tr>
				<th>Item</th>
				<th style="width:60px">Qty</th>
				<?php if ( ! $d['is_gift'] ) : ?>
				<th class="right" style="width:100px">Price</th>
				<?php endif; ?>
			</tr>
		</thead>
		<tbody>
			<?php foreach ( $d['items'] as $item ) : ?>
			<tr>
				<td>
					<div class="item-name"><?php echo esc_html( $item['name'] ); ?></div>
					<?php if ( $item['sku'] ) : ?>
					<div class="item-sku">SKU: <?php echo esc_html( $item['sku'] ); ?></div>
					<?php endif; ?>
				</td>
				<td><?php echo esc_html( $item['qty'] ); ?></td>
				<?php if ( ! $d['is_gift'] ) : ?>
				<td class="right"><?php echo wp_kses_post( $item['total'] ); ?></td>
				<?php endif; ?>
			</tr>
			<?php endforeach; ?>
		</tbody>
	</table>

	<?php if ( ! $d['is_gift'] ) : ?>
	<div class="slip-total-row">
		Total: <?php echo wp_kses_post( $d['order_total'] ); ?>
	</div>
	<?php endif; ?>

	<?php if ( $d['is_gift'] && $d['gift_message'] ) : ?>
	<div class="slip-gift-message">
		<h3>Gift Message</h3>
		<p><?php echo esc_html( $d['gift_message'] ); ?></p>
	</div>
	<?php endif; ?>

	<div class="slip-footer">
		<?php if ( $d['is_gift'] ) : ?>
			This item was sent as a gift. We hope you enjoy it!
		<?php else : ?>
			Thank you for your order!
		<?php endif; ?>
	</div>
</div>

</body>
</html>
		<?php
	}
}

LT_Packing_Slip::init();
