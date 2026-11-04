<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Payment ledger, funding rounds, FIFO allocations and settlement. */
final class WCP_Storage {
    public static function table( $name ) {
        global $wpdb;
        return $wpdb->prefix . 'wcp_' . $name;
    }

    public static function install() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $collate = $wpdb->get_charset_collate();
        $contributions = self::table( 'contributions' );
        $cycles = self::table( 'cycles' );
        $allocations = self::table( 'allocations' );
        dbDelta( "CREATE TABLE {$contributions} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            order_id bigint(20) unsigned NOT NULL,
            order_item_id bigint(20) unsigned NOT NULL,
            product_id bigint(20) unsigned NOT NULL,
            customer_id bigint(20) unsigned NOT NULL DEFAULT 0,
            buyer_name varchar(190) NOT NULL DEFAULT '',
            amount bigint(20) unsigned NOT NULL,
            refunded bigint(20) unsigned NOT NULL DEFAULT 0,
            allocated bigint(20) unsigned NOT NULL DEFAULT 0,
            review_units bigint(20) unsigned NOT NULL DEFAULT 0,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY order_item_id (order_item_id),
            KEY product_id (product_id,id),
            KEY order_id (order_id)
        ) ENGINE=InnoDB {$collate};" );
        dbDelta( "CREATE TABLE {$cycles} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            product_id bigint(20) unsigned NOT NULL,
            sequence int(11) unsigned NOT NULL,
            target bigint(20) unsigned NOT NULL,
            allocated bigint(20) unsigned NOT NULL DEFAULT 0,
            status varchar(20) NOT NULL DEFAULT 'open',
            order_id bigint(20) unsigned NOT NULL DEFAULT 0,
            created_at datetime NOT NULL,
            completed_at datetime DEFAULT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY product_sequence (product_id,sequence),
            KEY product_status (product_id,status)
        ) ENGINE=InnoDB {$collate};" );
        dbDelta( "CREATE TABLE {$allocations} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            cycle_id bigint(20) unsigned NOT NULL,
            contribution_id bigint(20) unsigned NOT NULL,
            amount bigint(20) unsigned NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY cycle_contribution (cycle_id,contribution_id),
            KEY contribution_id (contribution_id)
        ) ENGINE=InnoDB {$collate};" );
        update_option( 'wcp_db_version', WCP_VERSION, false );
    }

    /** Converts a localized decimal amount to minor units using string operations, not floats. */
    public static function to_units( $raw ) {
        if ( ! is_scalar( $raw ) ) { return 0; }
        $value = strtr( (string) $raw, array(
            '۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9',
            '٠'=>'0','١'=>'1','٢'=>'2','٣'=>'3','٤'=>'4','٥'=>'5','٦'=>'6','٧'=>'7','٨'=>'8','٩'=>'9',
            '٫'=>'.','٬'=>'', '،'=>'',
        ) );
        $value = str_replace( array( ' ', "\xC2\xA0", "\xE2\x80\xAF" ), '', trim( $value ) );
        $decimal_sep = function_exists( 'wc_get_price_decimal_separator' ) ? wc_get_price_decimal_separator() : '.';
        $thousand_sep = function_exists( 'wc_get_price_thousand_separator' ) ? wc_get_price_thousand_separator() : ',';
        if ( $thousand_sep && $thousand_sep !== $decimal_sep ) {
            $value = str_replace( $thousand_sep, '', $value );
        }
        if ( $decimal_sep && $decimal_sep !== '.' ) {
            $value = str_replace( $decimal_sep, '.', $value );
        }
        // Commas are accepted as grouping separators in Persian/English entry.
        $value = str_replace( ',', '', $value );
        if ( ! preg_match( '/^\+?(\d+)(?:\.(\d+))?$/D', $value, $matches ) ) { return 0; }
        $decimals = function_exists( 'wc_get_price_decimals' ) ? max( 0, min( 6, (int) wc_get_price_decimals() ) ) : 2;
        $fraction = isset( $matches[2] ) ? $matches[2] : '';
        if ( strlen( $fraction ) > $decimals ) {
            if ( trim( substr( $fraction, $decimals ), '0' ) !== '' ) { return 0; }
            $fraction = substr( $fraction, 0, $decimals );
        }
        $whole = ltrim( $matches[1], '0' );
        $whole = $whole === '' ? '0' : $whole;
        $digits = ltrim( $whole . str_pad( $fraction, $decimals, '0' ), '0' );
        if ( $digits === '' ) { return 0; }
        $max = (string) PHP_INT_MAX;
        if ( strlen( $digits ) > strlen( $max ) || ( strlen( $digits ) === strlen( $max ) && strcmp( $digits, $max ) > 0 ) ) { return 0; }
        return (int) $digits;
    }

    public static function from_units( $units ) {
        $decimals = function_exists( 'wc_get_price_decimals' ) ? max( 0, min( 6, (int) wc_get_price_decimals() ) ) : 2;
        $units = max( 0, (int) $units );
        if ( 0 === $decimals ) { return (string) $units; }
        $number = str_pad( (string) $units, $decimals + 1, '0', STR_PAD_LEFT );
        return substr( $number, 0, -$decimals ) . '.' . substr( $number, -$decimals );
    }

    public static function money_html( $units ) {
        return wc_price( self::from_units( $units ) );
    }

    public static function active_cycle( $product_id ) {
        global $wpdb;
        $table = self::table( 'cycles' );
        return $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$table} WHERE product_id = %d AND status IN ('open','ready') ORDER BY id ASC LIMIT 1",
            $product_id
        ) );
    }

    public static function progress( $product_id, $current_price ) {
        $cycle = self::active_cycle( $product_id );
        $target = $cycle ? (int) $cycle->target : self::to_units( $current_price );
        $paid = $cycle ? (int) $cycle->allocated : 0;
        $percentage = $target > 0 ? min( 100, round( 100 * ( $paid / $target ), 2 ) ) : 0;
        return array( 'target' => $target, 'paid' => $paid, 'percent' => $percentage, 'cycle' => $cycle );
    }

    private static function acquire( $product_id ) {
        global $wpdb;
        $name = 'wcp_' . get_current_blog_id() . '_' . (int) $product_id;
        return 1 === (int) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 8)', $name ) );
    }

    private static function release( $product_id ) {
        global $wpdb;
        $name = 'wcp_' . get_current_blog_id() . '_' . (int) $product_id;
        $wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $name ) );
    }

    public static function defer_order( $order_id ) {
        if ( ! wp_next_scheduled( 'wcp_retry_order', array( (int) $order_id ) ) ) {
            wp_schedule_single_event( time() + 90, 'wcp_retry_order', array( (int) $order_id ) );
        }
    }

    /** Record each paid checkout line exactly once and subsequently settle the product queue. */
    public static function record_payment( $order_id ) {
        global $wpdb;
        $order = wc_get_order( $order_id );
        if ( ! $order || 'yes' !== $order->get_meta( '_wcp_payment', true ) || ! in_array( $order->get_status(), array( 'processing', 'completed' ), true ) ) { return; }
        $name = trim( $order->get_formatted_billing_full_name() );
        if ( ! $name && $order->get_customer_id() ) {
            $person = get_userdata( $order->get_customer_id() );
            if ( $person ) { $name = $person->display_name; }
        }
        if ( ! $name ) { $name = 'مشارکت‌کننده مهمان'; }
        $name = sanitize_text_field( $name );
        foreach ( $order->get_items( 'line_item' ) as $item_id => $item ) {
            $product_id = absint( $item->get_meta( '_wcp_target_id', true ) );
            if ( ! $product_id ) { continue; }
            $amount = self::to_units( $item->get_total() );
            if ( ! $amount ) { continue; }
            if ( ! self::acquire( $product_id ) ) { self::defer_order( $order_id ); continue; }
            try {
                $table = self::table( 'contributions' );
                $wpdb->query( $wpdb->prepare(
                    "INSERT IGNORE INTO {$table} (order_id,order_item_id,product_id,customer_id,buyer_name,amount,created_at) VALUES (%d,%d,%d,%d,%s,%d,%s)",
                    $order_id, $item_id, $product_id, $order->get_customer_id(), $name, $amount, current_time( 'mysql', true )
                ) );
                self::process_queue_locked( $product_id );
            } catch ( Throwable $e ) {
                error_log( 'WCP contribution processing: ' . $e->getMessage() );
                self::defer_order( $order_id );
            } finally {
                self::release( $product_id );
            }
        }
    }

    /** Resume all processing from the recorded ledger, oldest credits first. Must hold product lock. */
    private static function process_queue_locked( $product_id ) {
        global $wpdb;
        $contributions = self::table( 'contributions' );
        $cycles = self::table( 'cycles' );
        $allocations = self::table( 'allocations' );
        $product = wc_get_product( $product_id );
        if ( ! $product || ! $product->is_type( 'simple' ) ) { return; }
        for ( $n = 0; $n < 60; $n++ ) {
            $cycle = self::active_cycle( $product_id );
            if ( $cycle && 'ready' === $cycle->status ) {
                if ( ! self::finalize_locked( $cycle, $product ) ) { return; }
                continue;
            }
            $row = $wpdb->get_row( $wpdb->prepare(
                "SELECT * FROM {$contributions} WHERE product_id=%d AND amount > (allocated + refunded) ORDER BY id ASC LIMIT 1",
                $product_id
            ) );
            if ( ! $row ) { return; }
            if ( ! $cycle ) {
                $price = self::to_units( $product->get_price() );
                if ( $price < 1 ) { return; }
                $sequence = 1 + (int) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(MAX(sequence),0) FROM {$cycles} WHERE product_id=%d", $product_id ) );
                $created = $wpdb->insert( $cycles, array( 'product_id' => $product_id, 'sequence' => $sequence, 'target' => $price, 'allocated' => 0, 'status' => 'open', 'created_at' => current_time( 'mysql', true ) ), array( '%d','%d','%d','%d','%s','%s' ) );
                if ( ! $created ) { return; }
                $cycle = self::active_cycle( $product_id );
            }
            if ( ! $cycle || $cycle->target < 1 ) { return; }
            $remaining = (int) $cycle->target - (int) $cycle->allocated;
            $available = (int) $row->amount - (int) $row->allocated - (int) $row->refunded;
            if ( $remaining <= 0 ) {
                $wpdb->update( $cycles, array( 'status' => 'ready' ), array( 'id' => $cycle->id ), array( '%s' ), array( '%d' ) );
                continue;
            }
            $chunk = min( $remaining, $available );
            if ( $chunk < 1 ) { return; }
            // The named MySQL lock serializes writers per product. Atomic updates keep ledger consistent.
            $wpdb->query( 'START TRANSACTION' );
            try {
                $inserted = $wpdb->query( $wpdb->prepare(
                    "INSERT INTO {$allocations} (cycle_id,contribution_id,amount) VALUES (%d,%d,%d) ON DUPLICATE KEY UPDATE amount = amount + VALUES(amount)",
                    $cycle->id, $row->id, $chunk
                ) );
                $increased = $wpdb->query( $wpdb->prepare( "UPDATE {$contributions} SET allocated=allocated+%d WHERE id=%d", $chunk, $row->id ) );
                $new_total = (int) $cycle->allocated + $chunk;
                $updated = $wpdb->query( $wpdb->prepare(
                    "UPDATE {$cycles} SET allocated=%d, status=%s WHERE id=%d",
                    $new_total, $new_total >= (int) $cycle->target ? 'ready' : 'open', $cycle->id
                ) );
                if ( false === $inserted || false === $increased || false === $updated ) { throw new RuntimeException( 'Allocation database write failed' ); }
                $wpdb->query( 'COMMIT' );
            } catch ( Throwable $e ) {
                $wpdb->query( 'ROLLBACK' );
                throw $e;
            }
            // Iteration will settle a ready round or consume the next credit.
        }
        // Guard against excessive per-request work after one very large payment.
        $pending = $wpdb->get_var( $wpdb->prepare( "SELECT order_id FROM {$contributions} WHERE product_id=%d AND amount > (allocated+refunded) LIMIT 1", $product_id ) );
        if ( $pending ) { self::defer_order( (int) $pending ); }
    }

    /** Create zero-total *fulfillment* order. Funds are already counted by payment orders. */
    private static function finalize_locked( $cycle, $product ) {
        global $wpdb;
        $cycles = self::table( 'cycles' );
        $contributions = self::table( 'contributions' );
        $allocations = self::table( 'allocations' );
        if ( (int) $cycle->allocated < (int) $cycle->target ) { return false; }
        $order = (int) $cycle->order_id ? wc_get_order( (int) $cycle->order_id ) : false;
        if ( ! $order ) {
            $matches = wc_get_orders( array( 'limit' => 1, 'return' => 'ids', 'meta_key' => '_wcp_cycle_id', 'meta_value' => (string) $cycle->id ) );
            if ( ! empty( $matches ) ) { $order = wc_get_order( $matches[0] ); }
        }
        if ( ! $order && ( ! $product->is_in_stock() || ( $product->managing_stock() && (int) $product->get_stock_quantity() < 1 ) ) ) {
            // Money is kept and exposed as 100%-funded, pending an administrator's stock decision.
            return false;
        }
        $participants = $wpdb->get_results( $wpdb->prepare(
            "SELECT c.order_id, c.customer_id, c.buyer_name, a.amount FROM {$allocations} a JOIN {$contributions} c ON c.id=a.contribution_id WHERE a.cycle_id=%d ORDER BY a.id ASC",
            $cycle->id
        ), ARRAY_A );
        if ( ! $order ) {
            $order = wc_create_order( array( 'status' => 'pending', 'customer_id' => 0 ) );
            if ( is_wp_error( $order ) || ! $order ) { return false; }
            $order->set_created_via( 'woo-crowd-purchase' );
            $order->set_currency( get_woocommerce_currency() );
            $order->set_payment_method( 'wcp_funded' );
            $order->set_payment_method_title( 'تسویه از محل مشارکت‌ها (بدون تراکنش جدید)' );
            $item_id = $order->add_product( $product, 1, array( 'subtotal' => 0, 'total' => 0 ) );
            if ( ! $item_id ) { $order->delete( true ); return false; }
            $order->update_meta_data( '_wcp_funded_order', 'yes' );
            $order->update_meta_data( '_wcp_cycle_id', (string) $cycle->id );
            $order->update_meta_data( '_wcp_product_id', (string) $product->get_id() );
            $order->update_meta_data( '_wcp_funded_units', (string) $cycle->target );
            $order->update_meta_data( '_wcp_participants', $participants );
            $order->calculate_totals( false ); // Intentionally zero to prevent duplicate revenue.
            $order->save();
            $order->add_order_note( sprintf( 'دور مشارکت شماره %d تکمیل شد. کل مبلغ %s در سفارش‌های پرداخت مشارکت‌کنندگان دریافت شده است؛ جمع این سفارش صفر است تا فروش مالی دوبار ثبت نشود.', (int) $cycle->sequence, wp_strip_all_tags( self::money_html( $cycle->target ) ) ) );
            $wpdb->update( $cycles, array( 'order_id' => $order->get_id() ), array( 'id' => $cycle->id ), array( '%d' ), array( '%d' ) );
        } elseif ( (int) $cycle->order_id !== (int) $order->get_id() ) {
            $wpdb->update( $cycles, array( 'order_id' => $order->get_id() ), array( 'id' => $cycle->id ), array( '%d' ), array( '%d' ) );
        }
        if ( 'completed' !== $order->get_status() ) {
            $order->set_date_paid( time() );
            $order->save();
            $order->update_status( 'completed', 'خرید این محصول با مشارکت جمعی تکمیل شد.', true );
        }
        if ( 'completed' !== $order->get_status() ) { return false; }
        $wpdb->update( $cycles, array( 'status' => 'completed', 'completed_at' => current_time( 'mysql', true ) ), array( 'id' => $cycle->id ), array( '%s','%s' ), array( '%d' ) );
        return true;
    }

    public static function sync_refund( $order_id ) {
        global $wpdb;
        $order = wc_get_order( $order_id );
        if ( ! $order || 'yes' !== $order->get_meta( '_wcp_payment', true ) ) { return; }
        $contributions = self::table( 'contributions' );
        $allocations = self::table( 'allocations' );
        $cycles = self::table( 'cycles' );
        $rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$contributions} WHERE order_id=%d ORDER BY id", $order_id ) );
        $item_refunds = array();
        $item_refund_total = 0;
        foreach ( $rows as $refund_row ) {
            $line_refund = min( (int) $refund_row->amount, self::to_units( abs( (float) $order->get_total_refunded_for_item( (int) $refund_row->order_item_id ) ) ) );
            $item_refunds[ $refund_row->id ] = $line_refund;
            $item_refund_total += $line_refund;
        }
        // Manually entered amount-only refunds have no line item. Distribute their unassigned
        // refunded amount FIFO across contributions in the same payment order.
        $unassigned_refund = max( 0, self::to_units( abs( (float) $order->get_total_refunded() ) ) - $item_refund_total );
        foreach ( $rows as $row ) {
            $explicit = isset( $item_refunds[ $row->id ] ) ? $item_refunds[ $row->id ] : 0;
            $extra = min( $unassigned_refund, max( 0, (int) $row->amount - $explicit ) );
            $unassigned_refund -= $extra;
            $refunded = min( (int) $row->amount, $explicit + $extra );
            if ( $refunded <= (int) $row->refunded ) { continue; }
            if ( ! self::acquire( $row->product_id ) ) { self::defer_order( $order_id ); continue; }
            try {
                $fresh = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$contributions} WHERE id=%d", $row->id ) );
                if ( ! $fresh ) { continue; }
                $delta = $refunded - (int) $fresh->refunded;
                if ( $delta <= 0 ) { continue; }
                $free_before = max( 0, (int) $fresh->amount - (int) $fresh->refunded - (int) $fresh->allocated );
                $unwind = max( 0, $delta - $free_before );
                $wpdb->query( 'START TRANSACTION' );
                try {
                    $open_allocations = $wpdb->get_results( $wpdb->prepare(
                        "SELECT a.id,a.cycle_id,a.amount FROM {$allocations} a JOIN {$cycles} cy ON cy.id=a.cycle_id WHERE a.contribution_id=%d AND cy.status='open' ORDER BY a.id DESC",
                        $fresh->id
                    ) );
                    foreach ( $open_allocations as $a ) {
                        if ( ! $unwind ) { break; }
                        $removed = min( $unwind, (int) $a->amount );
                        if ( $removed === (int) $a->amount ) {
                            $wpdb->delete( $allocations, array( 'id' => $a->id ), array( '%d' ) );
                        } else {
                            $wpdb->query( $wpdb->prepare( "UPDATE {$allocations} SET amount=amount-%d WHERE id=%d", $removed, $a->id ) );
                        }
                        $wpdb->query( $wpdb->prepare( "UPDATE {$cycles} SET allocated=allocated-%d WHERE id=%d", $removed, $a->cycle_id ) );
                        $wpdb->query( $wpdb->prepare( "UPDATE {$contributions} SET allocated=allocated-%d WHERE id=%d", $removed, $fresh->id ) );
                        $unwind -= $removed;
                    }
                    $wpdb->query( $wpdb->prepare(
                        "UPDATE {$contributions} SET refunded=%d, review_units=review_units+%d WHERE id=%d",
                        $refunded, $unwind, $fresh->id
                    ) );
                    $wpdb->query( 'COMMIT' );
                } catch ( Throwable $e ) {
                    $wpdb->query( 'ROLLBACK' );
                    throw $e;
                }
                if ( $unwind > 0 ) {
                    $order->add_order_note( 'هشدار مشارکت: بخشی از وجه برگشت داده شده قبلاً در یک خرید تکمیل‌شده مصرف شده است. رسیدگی دستی و تطبیق حساب الزامی است.' );
                }
                self::process_queue_locked( (int) $row->product_id );
            } catch ( Throwable $e ) {
                error_log( 'WCP refund sync: ' . $e->getMessage() );
                self::defer_order( $order_id );
            } finally {
                self::release( $row->product_id );
            }
        }
    }

    public static function retry_pending_products() {
        global $wpdb;
        $contributions = self::table( 'contributions' );
        $cycles = self::table( 'cycles' );
        $ids = $wpdb->get_col( "SELECT DISTINCT product_id FROM {$cycles} WHERE status='ready' LIMIT 50" );
        foreach ( $ids as $product_id ) {
            if ( self::acquire( $product_id ) ) {
                try { self::process_queue_locked( (int) $product_id ); }
                finally { self::release( $product_id ); }
            }
        }
        $ids = $wpdb->get_col( "SELECT DISTINCT product_id FROM {$contributions} WHERE amount > (allocated+refunded) LIMIT 50" );
        foreach ( $ids as $product_id ) {
            if ( self::acquire( $product_id ) ) {
                try { self::process_queue_locked( (int) $product_id ); }
                finally { self::release( $product_id ); }
            }
        }
    }
}
