<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class WCP_Plugin {
    private static $instance;
    private $adding_contribution = false;

    public static function instance() {
        if ( ! self::$instance ) { self::$instance = new self(); }
        return self::$instance;
    }

    private function __construct() {
        add_action( 'init', array( $this, 'ensure_placeholder' ), 40 );
        add_action( 'admin_init', array( $this, 'maybe_upgrade' ) );
        add_action( 'woocommerce_product_options_general_product_data', array( $this, 'product_option' ) );
        add_action( 'woocommerce_admin_process_product_object', array( $this, 'save_product_option' ) );
        add_action( 'woocommerce_before_single_product', array( $this, 'disable_standard_button' ), 2 );
        add_action( 'woocommerce_after_single_product_summary', array( $this, 'render_contribution_panel' ), 9 );
        add_filter( 'woocommerce_product_tabs', array( $this, 'add_product_tab' ), 30 );
        add_filter( 'woocommerce_loop_add_to_cart_link', array( $this, 'catalog_button' ), 20, 2 );
        add_action( 'template_redirect', array( $this, 'handle_frontend_form' ), 1 );
        add_filter( 'woocommerce_add_to_cart_validation', array( $this, 'validate_add_to_cart' ), 10, 3 );
        add_action( 'woocommerce_before_calculate_totals', array( $this, 'set_cart_prices' ), 1000 );
        add_filter( 'woocommerce_cart_item_name', array( $this, 'cart_item_name' ), 10, 3 );
        add_filter( 'woocommerce_cart_item_quantity', array( $this, 'cart_item_quantity' ), 10, 3 );
        add_filter( 'woocommerce_get_item_data', array( $this, 'cart_item_details' ), 10, 2 );
        add_filter( 'woocommerce_coupons_enabled', array( $this, 'disable_funding_coupons' ) );
        add_action( 'woocommerce_check_cart_items', array( $this, 'check_cart' ) );
        add_action( 'woocommerce_checkout_create_order', array( $this, 'tag_payment_order' ), 10, 2 );
        add_action( 'woocommerce_checkout_create_order_line_item', array( $this, 'tag_payment_item' ), 10, 4 );
        add_action( 'woocommerce_store_api_checkout_order_processed', array( $this, 'tag_store_api_payment' ), 10, 1 );
        add_action( 'woocommerce_payment_complete', array( 'WCP_Storage', 'record_payment' ), 10, 1 );
        add_action( 'woocommerce_order_status_processing', array( 'WCP_Storage', 'record_payment' ), 20, 1 );
        add_action( 'woocommerce_order_status_completed', array( 'WCP_Storage', 'record_payment' ), 20, 1 );
        add_action( 'woocommerce_order_refunded', array( $this, 'payment_refunded' ), 10, 2 );
        add_action( 'woocommerce_order_status_refunded', array( 'WCP_Storage', 'sync_refund' ), 20, 1 );
        add_action( 'wcp_retry_order', array( $this, 'retry_payment_order' ) );
        add_action( 'admin_menu', array( $this, 'admin_menu' ) );
        add_action( 'woocommerce_admin_order_data_after_order_details', array( $this, 'admin_order_information' ) );
        add_filter( 'woocommerce_email_enabled_customer_completed_order', array( $this, 'prevent_synthetic_email' ), 10, 2 );
        add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_styles' ) );
    }

    public function maybe_upgrade() {
        if ( current_user_can( 'manage_woocommerce' ) && get_option( 'wcp_db_version' ) !== WCP_VERSION ) {
            WCP_Storage::install();
        }
    }

    public static function enabled( $product ) {
        return $product instanceof WC_Product && $product->is_type( 'simple' ) && 'yes' === $product->get_meta( '_wcp_enabled', true );
    }

    /** An invisible virtual product guarantees that a partial payment never buys or reserves the target SKU. */
    public function ensure_placeholder() {
        if ( ! function_exists( 'wc_get_product' ) ) { return 0; }
        $id = absint( get_option( 'wcp_placeholder_id' ) );
        $product = $id ? wc_get_product( $id ) : false;
        if ( $product && $product->is_type( 'simple' ) ) { return $id; }
        $product = new WC_Product_Simple();
        $product->set_name( 'پرداخت مشارکت در خرید' );
        $product->set_status( 'publish' );
        $product->set_catalog_visibility( 'hidden' );
        $product->set_virtual( true );
        $product->set_tax_status( 'none' );
        $product->set_manage_stock( false );
        $product->set_sold_individually( false );
        $product->set_regular_price( '1' );
        $product->set_price( '1' );
        $product->set_description( 'محصول فنی داخلی افزونه مشارکت در خرید؛ حذف یا ویرایش نشود.' );
        $product->update_meta_data( '_wcp_internal', 'yes' );
        $id = $product->save();
        update_option( 'wcp_placeholder_id', absint( $id ), false );
        return absint( $id );
    }

    public function product_option() {
        echo '<div class="options_group show_if_simple">';
        woocommerce_wp_checkbox( array(
            'id' => '_wcp_enabled',
            'label' => 'مشارکت در خرید',
            'description' => 'خریداران می‌توانند هر مبلغی را برای تأمین قیمت این محصول پرداخت کنند. خرید مستقیم محصول تا پایان فعال‌بودن این گزینه غیرفعال است.',
            'desc_tip' => true,
        ) );
        echo '</div>';
    }

    public function save_product_option( $product ) {
        if ( $product->is_type( 'simple' ) ) {
            $product->update_meta_data( '_wcp_enabled', isset( $_POST['_wcp_enabled'] ) ? 'yes' : 'no' ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce product save handler verifies the nonce.
        }
    }

    public function disable_standard_button() {
        global $product;
        if ( self::enabled( $product ) ) {
            remove_action( 'woocommerce_single_product_summary', 'woocommerce_template_single_add_to_cart', 30 );
        }
    }

    public function catalog_button( $html, $product ) {
        if ( self::enabled( $product ) ) {
            return '<a class="button" href="' . esc_url( get_permalink( $product->get_id() ) ) . '">مشارکت در خرید</a>';
        }
        return $html;
    }

    public function enqueue_styles() {
        if ( function_exists( 'is_product' ) && is_product() ) {
            wp_enqueue_style( 'wcp-frontend', plugins_url( 'assets/frontend.css', WCP_FILE ), array(), WCP_VERSION );
            $hex = sanitize_hex_color( get_option( 'wcp_accent', '#7855d9' ) );
            if ( ! $hex ) { $hex = '#7855d9'; }
            wp_add_inline_style( 'wcp-frontend', ':root{--wcp-accent:' . $hex . ';}' );
        }
    }

    public function render_contribution_panel() {
        global $product;
        if ( ! self::enabled( $product ) ) { return; }
        $progress = WCP_Storage::progress( $product->get_id(), $product->get_price() );
        $target = (int) $progress['target'];
        $paid = (int) $progress['paid'];
        $remaining = max( 0, $target - $paid );
        $disabled = ! $product->is_in_stock() || ! $product->is_purchasable() || ! $target;
        $percentage = number_format( $progress['percent'], 2, '.', '' );
        $button = sanitize_text_field( (string) get_option( 'wcp_button_label', 'مشارکت در خرید' ) );
        echo '<section class="wcp-panel" dir="rtl" aria-label="مشارکت در خرید">';
        echo '<div class="wcp-panel-top"><div><span class="wcp-eyebrow">خرید جمعی</span><h3>با هم، یک خرید کامل!</h3></div><strong>' . esc_html( WCP_Storage::from_units( $paid ) ) . ' / ' . esc_html( WCP_Storage::from_units( $target ) ) . '</strong></div>';
        echo '<div class="wcp-progress-meta"><span>مبلغ جمع‌شده: ' . wp_kses_post( WCP_Storage::money_html( $paid ) ) . '</span><span>' . esc_html( number_format_i18n( $progress['percent'], 1 ) ) . '٪</span></div>';
        echo '<div class="wcp-progress" role="progressbar" aria-valuenow="' . esc_attr( $percentage ) . '" aria-valuemin="0" aria-valuemax="100"><div class="wcp-progress-fill" style="width:' . esc_attr( $percentage ) . '%"></div></div>';
        echo '<div class="wcp-under-progress"><span>باقی‌مانده تا تکمیل خرید: <b>' . wp_kses_post( WCP_Storage::money_html( $remaining ) ) . '</b></span><span>هر مشارکت، یک قدم نزدیک‌تر</span></div>';
        if ( $disabled ) {
            echo '<p class="wcp-warning">فعلاً امکان ثبت مشارکت جدید برای این محصول وجود ندارد.</p>';
        } else {
            echo '<form class="wcp-form" method="post" action="' . esc_url( get_permalink( $product->get_id() ) ) . '">';
            wp_nonce_field( 'wcp_contribute_' . $product->get_id(), 'wcp_nonce' );
            echo '<input type="hidden" name="wcp_product_id" value="' . esc_attr( $product->get_id() ) . '">';
            echo '<label for="wcp-amount-' . esc_attr( $product->get_id() ) . '">مبلغ دلخواه مشارکت (' . esc_html( get_woocommerce_currency_symbol() ) . ')</label>';
            echo '<div class="wcp-form-controls"><input id="wcp-amount-' . esc_attr( $product->get_id() ) . '" name="wcp_amount" type="text" inputmode="decimal" autocomplete="off" placeholder="مثلاً ۵۰۰٬۰۰۰" required><button type="submit" name="wcp_submit" value="1">' . esc_html( $button ) . '</button></div>';
            echo '<p class="wcp-help">بعد از پرداخت، مبلغ به اعتبار این محصول اضافه می‌شود. مازادِ تکمیل یک خرید، به دور بعد می‌رود؛ تا قبل از تکمیل دور، از موجودی کالا کم نمی‌شود.</p>';
            echo '</form>';
        }
        echo '</section>';
    }

    public function add_product_tab( $tabs ) {
        global $product;
        if ( self::enabled( $product ) ) {
            $tabs['wcp_funded'] = array( 'title' => 'خریدهای مشارکتی', 'priority' => 6, 'callback' => array( $this, 'render_product_tab' ) );
        }
        return $tabs;
    }

    public function render_product_tab() {
        global $product, $wpdb;
        if ( ! self::enabled( $product ) ) { return; }
        $table = WCP_Storage::table( 'cycles' );
        $allocs = WCP_Storage::table( 'allocations' );
        $rounds = $wpdb->get_results( $wpdb->prepare(
            "SELECT cy.*, (SELECT COUNT(*) FROM {$allocs} a WHERE a.cycle_id=cy.id) AS donors FROM {$table} cy WHERE cy.product_id=%d AND cy.status='completed' ORDER BY cy.id DESC LIMIT 20",
            $product->get_id()
        ) );
        echo '<div class="wcp-rounds" dir="rtl"><h3>خریدهایی که با کمک هم تکمیل شده‌اند</h3>';
        if ( ! $rounds ) { echo '<p>هنوز خرید مشارکتی تکمیل‌شده‌ای نداریم. می‌تونی اولین نفر باشی!</p>'; }
        foreach ( $rounds as $round ) {
            $date = $round->completed_at ? wp_date( get_option( 'date_format' ), strtotime( $round->completed_at . ' UTC' ) ) : '—';
            echo '<div class="wcp-round"><span><b>دور ' . esc_html( $round->sequence ) . '</b><small>تکمیل در ' . esc_html( $date ) . '</small></span><span><b>' . wp_kses_post( WCP_Storage::money_html( $round->target ) ) . '</b><small>' . esc_html( $round->donors ) . ' پرداخت مشارکتی</small></span><span class="wcp-done">تکمیل‌شده ✓</span></div>';
        }
        echo '<p class="wcp-privacy">برای حفظ حریم خصوصی، اطلاعات هویتی مشارکت‌کنندگان فقط در مدیریت سفارش قابل مشاهده است.</p></div>';
    }

    public function handle_frontend_form() {
        if ( ! isset( $_POST['wcp_submit'] ) ) { return; }
        if ( ! isset( $_POST['wcp_product_id'], $_POST['wcp_nonce'] ) ) { wc_add_notice( 'درخواست نامعتبر است.', 'error' ); return; }
        $product_id = absint( wp_unslash( $_POST['wcp_product_id'] ) );
        $nonce = sanitize_text_field( wp_unslash( $_POST['wcp_nonce'] ) );
        if ( ! wp_verify_nonce( $nonce, 'wcp_contribute_' . $product_id ) ) {
            wc_add_notice( 'اعتبار درخواست به پایان رسیده؛ لطفاً دوباره تلاش کن.', 'error' ); return;
        }
        $product = wc_get_product( $product_id );
        if ( ! self::enabled( $product ) || ! $product->is_purchasable() || ! $product->is_in_stock() || WCP_Storage::to_units( $product->get_price() ) < 1 ) {
            wc_add_notice( 'این محصول در حال حاضر مشارکت فعال ندارد.', 'error' ); return;
        }
        $raw = isset( $_POST['wcp_amount'] ) ? wp_unslash( $_POST['wcp_amount'] ) : '';
        $units = WCP_Storage::to_units( $raw );
        if ( $units < 1 ) { wc_add_notice( 'یک مبلغ معتبر و بزرگ‌تر از صفر وارد کن.', 'error' ); return; }
        if ( ! WC()->cart ) { wc_add_notice( 'سبد خرید آماده نیست؛ صفحه را دوباره باز کن.', 'error' ); return; }
        foreach ( WC()->cart->get_cart() as $cart_item ) {
            if ( empty( $cart_item['wcp_product_id'] ) ) {
                wc_add_notice( 'برای مشارکت، ابتدا خریدهای عادی را جداگانه تسویه کن.', 'error' ); return;
            }
        }
        WC()->cart->remove_coupons();
        $placeholder = $this->ensure_placeholder();
        if ( ! $placeholder ) { wc_add_notice( 'خطا در راه‌اندازی پرداخت مشارکتی.', 'error' ); return; }
        $this->adding_contribution = true;
        try {
            $key = WC()->cart->add_to_cart( $placeholder, 1, 0, array(), array(
                'wcp_product_id' => $product_id,
                'wcp_amount_units' => $units,
                'wcp_unique' => wp_generate_uuid4(),
            ) );
        } finally {
            $this->adding_contribution = false;
        }
        if ( $key ) {
            wp_safe_redirect( wc_get_cart_url() );
            exit;
        }
        wc_add_notice( 'مشارکت به سبد اضافه نشد؛ دوباره تلاش کن.', 'error' );
    }

    public function validate_add_to_cart( $passed, $product_id, $quantity ) {
        $placeholder_id = absint( get_option( 'wcp_placeholder_id' ) );
        if ( $product_id === $placeholder_id ) {
            if ( ! $this->adding_contribution || (int) $quantity !== 1 ) {
                wc_add_notice( 'این آیتم فقط از فرم امن مشارکت قابل اضافه‌شدن است.', 'error' ); return false;
            }
            return $passed;
        }
        $product = wc_get_product( $product_id );
        if ( self::enabled( $product ) ) {
            wc_add_notice( 'برای این محصول از بخش «مشارکت در خرید» استفاده کن.', 'error' ); return false;
        }
        if ( WC()->cart ) {
            foreach ( WC()->cart->get_cart() as $item ) {
                if ( ! empty( $item['wcp_product_id'] ) ) {
                    wc_add_notice( 'ابتدا مشارکت‌های سبد فعلی را تسویه کن و سپس خرید عادی انجام بده.', 'error' ); return false;
                }
            }
        }
        return $passed;
    }

    public function set_cart_prices( $cart ) {
        if ( is_admin() && ! wp_doing_ajax() ) { return; }
        if ( ! $cart instanceof WC_Cart ) { return; }
        foreach ( $cart->get_cart() as $key => $item ) {
            if ( ! empty( $item['wcp_product_id'] ) && ! empty( $item['wcp_amount_units'] ) && isset( $item['data'] ) ) {
                // Each cart row needs its own product object because all rows share one placeholder ID.
                $cart_product = clone $item['data'];
                $cart_product->set_price( WCP_Storage::from_units( $item['wcp_amount_units'] ) );
                $cart_product->set_virtual( true );
                $cart_product->set_tax_status( 'none' );
                $cart->cart_contents[ $key ]['data'] = $cart_product;
            }
        }
    }

    public function cart_item_name( $name, $item, $key ) {
        if ( ! empty( $item['wcp_product_id'] ) ) {
            $title = get_the_title( absint( $item['wcp_product_id'] ) );
            return '<strong>مشارکت در خرید:</strong> ' . esc_html( $title );
        }
        return $name;
    }

    public function cart_item_quantity( $quantity, $cart_item_key, $cart_item ) {
        if ( ! empty( $cart_item['wcp_product_id'] ) ) { return '<span class="wcp-cart-qty">۱</span>'; }
        return $quantity;
    }

    public function cart_item_details( $data, $item ) {
        if ( ! empty( $item['wcp_product_id'] ) ) {
            $data[] = array( 'name' => 'مبلغ مشارکت', 'value' => wp_strip_all_tags( WCP_Storage::money_html( $item['wcp_amount_units'] ) ) );
        }
        return $data;
    }

    private function cart_has_funding() {
        if ( ! WC()->cart ) { return false; }
        foreach ( WC()->cart->get_cart() as $item ) {
            if ( ! empty( $item['wcp_product_id'] ) ) { return true; }
        }
        return false;
    }

    public function disable_funding_coupons( $enabled ) {
        return $this->cart_has_funding() ? false : $enabled;
    }

    public function check_cart() {
        if ( ! WC()->cart ) { return; }
        $has_funding = false;
        $has_normal = false;
        $placeholder = absint( get_option( 'wcp_placeholder_id' ) );
        foreach ( WC()->cart->get_cart() as $item ) {
            if ( ! empty( $item['wcp_product_id'] ) && (int) $item['product_id'] === $placeholder ) {
                $has_funding = true;
                $target = wc_get_product( absint( $item['wcp_product_id'] ) );
                if ( 1 !== (int) $item['quantity'] || ! self::enabled( $target ) || ! $target->is_in_stock() || ! $target->is_purchasable() ) {
                    wc_add_notice( 'یک مشارکت در سبد دیگر معتبر یا موجود نیست؛ لطفاً آن را حذف کن.', 'error' );
                }
            } else { $has_normal = true; }
        }
        if ( $has_funding && WC()->cart->get_applied_coupons() ) {
            wc_add_notice( 'برای پرداخت مشارکتی امکان استفاده از کد تخفیف نیست؛ کد تخفیف را حذف کن.', 'error' );
        }
        if ( $has_funding && $has_normal ) { wc_add_notice( 'امکان تسویه هم‌زمان مشارکت و خرید عادی نیست؛ لطفاً سبدها را جدا کن.', 'error' ); }
    }

    public function tag_payment_order( $order, $data ) {
        if ( $this->cart_has_funding() ) {
            $order->update_meta_data( '_wcp_payment', 'yes' );
            $order->set_created_via( 'woo-crowd-purchase-payment' );
        }
    }

    public function tag_payment_item( $item, $cart_item_key, $values, $order ) {
        if ( empty( $values['wcp_product_id'] ) ) { return; }
        $product_id = absint( $values['wcp_product_id'] );
        $item->set_name( 'مشارکت در خرید: ' . get_the_title( $product_id ) );
        $item->add_meta_data( '_wcp_target_id', (string) $product_id, true );
        $item->add_meta_data( '_wcp_amount_units', (string) absint( $values['wcp_amount_units'] ), true );
        $item->add_meta_data( 'نوع پرداخت', 'اعتبار مشارکت در خرید', true );
    }

    /** Checkout Blocks use a Store API action rather than checkout_create_order. */
    public function tag_store_api_payment( $order ) {
        if ( ! $order instanceof WC_Order ) { return; }
        foreach ( $order->get_items( 'line_item' ) as $item ) {
            if ( absint( $item->get_meta( '_wcp_target_id', true ) ) ) {
                $order->update_meta_data( '_wcp_payment', 'yes' );
                $order->save();
                return;
            }
        }
    }

    public function payment_refunded( $order_id, $refund_id ) {
        WCP_Storage::sync_refund( $order_id );
    }

    public function retry_payment_order( $order_id ) {
        WCP_Storage::record_payment( $order_id );
        WCP_Storage::sync_refund( $order_id );
    }

    public function prevent_synthetic_email( $enabled, $order ) {
        return $order instanceof WC_Order && 'yes' === $order->get_meta( '_wcp_funded_order', true ) ? false : $enabled;
    }

    public function admin_menu() {
        add_submenu_page( 'woocommerce', 'مشارکت در خرید', 'مشارکت در خرید', 'manage_woocommerce', 'wcp-dashboard', array( $this, 'admin_page' ) );
    }

    public function admin_page() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) { return; }
        global $wpdb;
        if ( 'POST' === $_SERVER['REQUEST_METHOD'] && isset( $_POST['wcp_save_settings'] ) ) {
            check_admin_referer( 'wcp_save_settings' );
            $color = isset( $_POST['wcp_accent'] ) ? sanitize_hex_color( wp_unslash( $_POST['wcp_accent'] ) ) : '';
            update_option( 'wcp_accent', $color ? $color : '#7855d9' );
            $label = isset( $_POST['wcp_button_label'] ) ? sanitize_text_field( wp_unslash( $_POST['wcp_button_label'] ) ) : 'مشارکت در خرید';
            update_option( 'wcp_button_label', $label ? $label : 'مشارکت در خرید' );
            $token_error = null;
            if ( ! empty( $_POST['wcp_github_remove_token'] ) ) {
                WCP_GitHub_Updater::delete_token();
            } elseif ( isset( $_POST['wcp_github_token'] ) && '' !== trim( (string) wp_unslash( $_POST['wcp_github_token'] ) ) ) {
                $token_result = WCP_GitHub_Updater::store_token( wp_unslash( $_POST['wcp_github_token'] ) );
                if ( is_wp_error( $token_result ) ) { $token_error = $token_result->get_error_message(); }
            }
            if ( $token_error ) {
                echo '<div class="notice notice-error"><p>' . esc_html( $token_error ) . '</p></div>';
            } else {
                echo '<div class="notice notice-success"><p>تنظیمات ذخیره شد.</p></div>';
            }
        }
        if ( 'POST' === $_SERVER['REQUEST_METHOD'] && isset( $_POST['wcp_check_github'] ) ) {
            check_admin_referer( 'wcp_check_github' );
            WCP_GitHub_Updater::clear_cache();
            $latest = WCP_GitHub_Updater::remote_version( true );
            if ( is_wp_error( $latest ) ) {
                echo '<div class="notice notice-error"><p>' . esc_html( $latest->get_error_message() ) . '</p></div>';
            } else {
                if ( function_exists( 'wp_update_plugins' ) ) { wp_update_plugins(); }
                $message = version_compare( $latest['version'], WCP_VERSION, '>' )
                    ? 'نسخه جدید ' . $latest['version'] . ' در GitHub موجود است. می‌توانید از صفحه افزونه‌های وردپرس آن را نصب کنید.'
                    : 'اتصال به GitHub موفق بود؛ افزونه به‌روز است (نسخه ' . $latest['version'] . ').';
                echo '<div class="notice notice-success"><p>' . esc_html( $message ) . '</p></div>';
            }
        }
        if ( 'POST' === $_SERVER['REQUEST_METHOD'] && isset( $_POST['wcp_retry'] ) ) {
            check_admin_referer( 'wcp_retry' );
            WCP_Storage::retry_pending_products();
            echo '<div class="notice notice-info"><p>تلاش مجدد برای تکمیل دورهای آماده انجام شد.</p></div>';
        }
        $c = WCP_Storage::table( 'contributions' );
        $cy = WCP_Storage::table( 'cycles' );
        $count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$c}" );
        $funded_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$cy} WHERE status='completed'" );
        $review_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$c} WHERE review_units>0" );
        $ready_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$cy} WHERE status='ready'" );
        $page = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
        $per = 25;
        $recent = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$c} ORDER BY id DESC LIMIT %d OFFSET %d", $per, ( $page - 1 ) * $per ) );
        $rounds = $wpdb->get_results( "SELECT * FROM {$cy} ORDER BY id DESC LIMIT 25" );
        echo '<div class="wrap" dir="rtl"><h1>مشارکت در خرید ووکامرس</h1>';
        echo '<p>واریزها به سفارش‌های پرداختی ووکامرس متصل‌اند، اما صرفاً تکمیل هر دور یک سفارش واقعیِ محصول با تعداد فروش +۱ می‌سازد.</p>';
        echo '<div style="display:flex;gap:12px;flex-wrap:wrap">';
        foreach ( array( 'پرداخت‌های ثبت‌شده' => $count, 'خریدهای تکمیل‌شده' => $funded_count, 'دورهای آماده بررسی' => $ready_count, 'مغایرت استرداد وجه' => $review_count ) as $title => $value ) {
            echo '<div style="padding:15px 24px;background:#fff;border:1px solid #dcdcde;border-radius:9px;min-width:135px"><b>' . esc_html( $title ) . '</b><div style="font-size:26px;margin-top:8px">' . esc_html( $value ) . '</div></div>';
        }
        echo '</div>';
        if ( $review_count > 0 ) {
            echo '<div class="notice notice-warning inline"><p>اخطار: استرداد وجه برای برخی مشارکت‌هایی رخ داده که قبلاً در سفارش تکمیل‌شده مصرف شده‌اند. در پایین، ردیف‌های «نیازمند تطبیق» را بررسی کنید؛ سیستم سفارش تحویل‌شده را خودکار باطل نمی‌کند.</p></div>';
        }
        echo '<h2>تنظیمات ظاهری</h2><form method="post">';
        wp_nonce_field( 'wcp_save_settings' );
        echo '<table class="form-table"><tr><th><label for="wcp_accent">رنگ نوار پیشرفت و دکمه</label></th><td><input type="color" id="wcp_accent" name="wcp_accent" value="' . esc_attr( get_option( 'wcp_accent', '#7855d9' ) ) . '"></td></tr>';
        echo '<tr><th><label for="wcp_button_label">متن دکمه</label></th><td><input class="regular-text" id="wcp_button_label" type="text" name="wcp_button_label" value="' . esc_attr( get_option( 'wcp_button_label', 'مشارکت در خرید' ) ) . '"></td></tr></table>';
        echo '<h2>به‌روزرسانی خودکار از GitHub</h2>';
        echo '<p>منبع: <a href="' . esc_url( WCP_GitHub_Updater::UPDATE_URI ) . '" target="_blank" rel="noopener noreferrer">Cajadfa/woo-crowd-purchase</a> | نسخه نصب‌شده: <code>' . esc_html( WCP_VERSION ) . '</code></p>';
        echo '<p>ریپوی عمومی بدون توکن کار می‌کند. اگر خصوصی شد، Fine-grained PAT با مجوز <code>Contents: Read-only</code> برای همین ریپو ثبت کنید. توکن فقط رمزگذاری‌شده در دیتابیس نگه‌داری می‌شود.</p>';
        echo '<table class="form-table"><tr><th><label for="wcp_github_token">Fine-grained Personal Access Token</label></th><td>';
        echo '<input class="regular-text" id="wcp_github_token" type="password" name="wcp_github_token" value="" autocomplete="new-password" spellcheck="false" placeholder="github_pat_…">';
        echo '<p class="description">' . ( WCP_GitHub_Updater::has_token() ? 'توکن قبلاً ثبت شده است. برای نگه‌داشتن آن، فیلد را خالی بگذارید.' : 'توکنی ثبت نشده؛ برای ریپوی عمومی نیازی به توکن نیست.' ) . '</p>';
        if ( WCP_GitHub_Updater::has_token() ) {
            echo '<label><input type="checkbox" name="wcp_github_remove_token" value="1"> حذف توکن ذخیره‌شده</label>';
        }
        echo '</td></tr></table>';
        submit_button( 'ذخیره تنظیمات', 'primary', 'wcp_save_settings' );
        echo '</form>';
        echo '<form method="post" style="margin:12px 0 24px">';
        wp_nonce_field( 'wcp_check_github' );
        submit_button( 'بررسی ارتباط و نسخه GitHub', 'secondary', 'wcp_check_github', false );
        echo '<p class="description">برای نصب خودکار آپدیت‌ها در آینده، گزینه «فعال‌سازی به‌روزرسانی خودکار» این افزونه را در فهرست افزونه‌های وردپرس روشن کنید.</p></form>';
        echo '<h2>دورهای خرید</h2><form method="post">';
        wp_nonce_field( 'wcp_retry' );
        submit_button( 'تلاش مجدد برای نهایی‌کردن دورهای آماده', 'secondary', 'wcp_retry', false );
        echo '</form><table class="widefat striped" style="margin-top:12px;text-align:right"><thead><tr><th>محصول</th><th>دور</th><th>هدف</th><th>تأمین‌شده</th><th>وضعیت</th><th>سفارش نهایی</th></tr></thead><tbody>';
        foreach ( $rounds as $round ) {
            $edit = get_edit_post_link( $round->product_id );
            $status = 'completed' === $round->status ? 'تکمیل' : ( 'ready' === $round->status ? '۱۰۰٪ تأمین‌شده / در انتظار نهایی‌سازی' : 'در حال جمع‌آوری' );
            $link = $round->order_id && function_exists( 'wc_get_order' ) ? wc_get_order( $round->order_id ) : false;
            echo '<tr><td><a href="' . esc_url( $edit ? $edit : '#' ) . '">' . esc_html( get_the_title( $round->product_id ) ) . '</a></td><td>' . esc_html( $round->sequence ) . '</td><td>' . wp_kses_post( WCP_Storage::money_html( $round->target ) ) . '</td><td>' . wp_kses_post( WCP_Storage::money_html( $round->allocated ) ) . '</td><td>' . esc_html( $status ) . '</td><td>';
            if ( $link ) { echo '<a href="' . esc_url( $link->get_edit_order_url() ) . '">#' . esc_html( $round->order_id ) . '</a>'; } else { echo '—'; }
            echo '</td></tr>';
        }
        if ( ! $rounds ) { echo '<tr><td colspan="6">هنوز دوری ساخته نشده.</td></tr>'; }
        echo '</tbody></table>';
        echo '<h2>پرداخت‌های مشارکت‌کنندگان</h2><table class="widefat striped" style="text-align:right"><thead><tr><th>مشارکت‌کننده</th><th>محصول</th><th>مبلغ پرداختی</th><th>استرداد</th><th>مصرف‌شده</th><th>مانده</th><th>وضعیت</th><th>سفارش پرداخت</th></tr></thead><tbody>';
        foreach ( $recent as $row ) {
            $payment = wc_get_order( $row->order_id );
            $available = max( 0, (int) $row->amount - (int) $row->refunded - (int) $row->allocated );
            echo '<tr><td>' . esc_html( $row->buyer_name ) . '</td><td>' . esc_html( get_the_title( $row->product_id ) ) . '</td><td>' . wp_kses_post( WCP_Storage::money_html( $row->amount ) ) . '</td><td>' . wp_kses_post( WCP_Storage::money_html( $row->refunded ) ) . '</td><td>' . wp_kses_post( WCP_Storage::money_html( $row->allocated ) ) . '</td><td>' . wp_kses_post( WCP_Storage::money_html( $available ) ) . '</td><td>' . ( $row->review_units > 0 ? '<b style="color:#b32d2e">نیازمند تطبیق دستی</b>' : 'ثبت‌شده' ) . '</td><td>';
            if ( $payment ) { echo '<a href="' . esc_url( $payment->get_edit_order_url() ) . '">#' . esc_html( $row->order_id ) . '</a>'; } else { echo '#' . esc_html( $row->order_id ); }
            echo '</td></tr>';
        }
        if ( ! $recent ) { echo '<tr><td colspan="8">هنوز پرداخت موفقی ثبت نشده.</td></tr>'; }
        echo '</tbody></table>';
        $pages = (int) ceil( $count / $per );
        if ( $pages > 1 ) {
            echo '<div class="tablenav"><div class="tablenav-pages">' . wp_kses_post( paginate_links( array( 'base' => add_query_arg( 'paged', '%#%' ), 'format' => '', 'current' => $page, 'total' => $pages ) ) ) . '</div></div>';
        }
        echo '<p><b>یادآوری:</b> سفارش‌های پرداختی در ووکامرس برای اتصال به درگاه و استرداد وجه باقی می‌مانند. سفارش نهایی محصول مجموع صفر دارد تا درآمد دوبار محاسبه نشود؛ مبلغ واقعی در جزئیات سفارش و گزارش مشارکت درج شده است.</p></div>';
    }

    public function admin_order_information( $order ) {
        if ( ! $order instanceof WC_Order ) { return; }
        if ( 'yes' === $order->get_meta( '_wcp_payment', true ) ) {
            echo '<p><strong>نوع سفارش:</strong> پرداخت مشارکت در خرید (خرید مستقیم محصول نیست؛ موجودی محصول اصلی تغییر نمی‌کند).</p>';
        }
        if ( 'yes' !== $order->get_meta( '_wcp_funded_order', true ) ) { return; }
        $participants = $order->get_meta( '_wcp_participants', true );
        echo '<div style="clear:both;padding:14px 0"><h3>مشارکت‌کنندگان این خرید کامل</h3>';
        echo '<p><strong>مبلغ کل تأمین‌شده:</strong> ' . wp_kses_post( WCP_Storage::money_html( $order->get_meta( '_wcp_funded_units', true ) ) ) . '. <strong>مبلغ سفارش نهایی:</strong> صفر؛ چون هزینه قبلاً دریافت شده است.</p>';
        echo '<table class="widefat striped"><thead><tr><th>نام</th><th>مبلغ تخصیص‌یافته</th><th>سفارش پرداخت</th></tr></thead><tbody>';
        if ( is_array( $participants ) ) {
            foreach ( $participants as $row ) {
                $payment = wc_get_order( absint( $row['order_id'] ) );
                echo '<tr><td>' . esc_html( $row['buyer_name'] ) . '</td><td>' . wp_kses_post( WCP_Storage::money_html( $row['amount'] ) ) . '</td><td>';
                if ( $payment ) { echo '<a href="' . esc_url( $payment->get_edit_order_url() ) . '">#' . esc_html( $row['order_id'] ) . '</a>'; }
                else { echo esc_html( $row['order_id'] ); }
                echo '</td></tr>';
            }
        }
        echo '</tbody></table></div>';
    }
}
