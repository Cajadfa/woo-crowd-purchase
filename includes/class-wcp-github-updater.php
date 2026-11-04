<?php
/** GitHub Updates for Woo Crowd Purchase (public and fine-grained PAT private repos). */
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class WCP_GitHub_Updater {
    const REPO = 'Cajadfa/woo-crowd-purchase';
    const SLUG = 'woo-crowd-purchase';
    const UPDATE_URI = 'https://github.com/Cajadfa/woo-crowd-purchase';
    const ZIP_URL = 'https://api.github.com/repos/Cajadfa/woo-crowd-purchase/zipball/main';
    const TOKEN_OPTION = 'wcp_github_pat';
    const CACHE_KEY = 'wcp_github_latest';

    public static function init() {
        add_filter( 'update_plugins_github.com', array( __CLASS__, 'check_update' ), 10, 4 );
        add_filter( 'plugins_api', array( __CLASS__, 'plugin_information' ), 20, 3 );
        add_filter( 'upgrader_pre_download', array( __CLASS__, 'download_private_archive' ), 10, 4 );
        add_filter( 'upgrader_source_selection', array( __CLASS__, 'normalize_archive_directory' ), 10, 4 );
    }

    public static function has_token() {
        return (bool) get_option( self::TOKEN_OPTION, '' );
    }

    /** Keep the PAT out of filesystem files, the HTML response and autoloaded options. */
    public static function store_token( $token ) {
        $token = trim( (string) $token );
        if ( ! preg_match( '/^github_pat_[A-Za-z0-9_]{20,}$/D', $token ) ) {
            return new WP_Error( 'wcp_invalid_pat', 'توکن Fine-grained معتبر نیست؛ توکن باید با github_pat_ شروع شود.' );
        }
        if ( function_exists( 'sodium_crypto_secretbox' ) ) {
            $nonce = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
            $key = hash( 'sha256', wp_salt( 'auth' ), true );
            $sealed = 's1:' . base64_encode( $nonce . sodium_crypto_secretbox( $token, $nonce, $key ) );
        } elseif ( function_exists( 'openssl_encrypt' ) ) {
            $nonce = random_bytes( 12 );
            $tag = '';
            $cipher = openssl_encrypt( $token, 'aes-256-gcm', hash( 'sha256', wp_salt( 'auth' ), true ), OPENSSL_RAW_DATA, $nonce, $tag );
            if ( false === $cipher ) {
                return new WP_Error( 'wcp_encryption_error', 'رمزگذاری توکن امکان‌پذیر نیست.' );
            }
            $sealed = 'g1:' . base64_encode( $nonce . $tag . $cipher );
        } else {
            return new WP_Error( 'wcp_encryption_unavailable', 'برای ذخیره امن توکن، افزونه PHP Sodium یا OpenSSL باید فعال باشد.' );
        }
        update_option( self::TOKEN_OPTION, $sealed, false );
        self::clear_cache();
        return true;
    }

    public static function delete_token() {
        delete_option( self::TOKEN_OPTION );
        self::clear_cache();
    }

    private static function token() {
        $stored = get_option( self::TOKEN_OPTION, '' );
        if ( ! is_string( $stored ) || ! $stored ) { return ''; }
        $encoded = substr( $stored, 3 );
        $payload = base64_decode( $encoded, true );
        if ( false === $payload ) { return ''; }
        $key = hash( 'sha256', wp_salt( 'auth' ), true );
        if ( 0 === strpos( $stored, 's1:' ) && function_exists( 'sodium_crypto_secretbox_open' ) ) {
            $nonce_len = SODIUM_CRYPTO_SECRETBOX_NONCEBYTES;
            if ( strlen( $payload ) <= $nonce_len ) { return ''; }
            $value = sodium_crypto_secretbox_open( substr( $payload, $nonce_len ), substr( $payload, 0, $nonce_len ), $key );
            return is_string( $value ) ? $value : '';
        }
        if ( 0 === strpos( $stored, 'g1:' ) && function_exists( 'openssl_decrypt' ) ) {
            if ( strlen( $payload ) <= 28 ) { return ''; }
            $value = openssl_decrypt( substr( $payload, 28 ), 'aes-256-gcm', $key, OPENSSL_RAW_DATA, substr( $payload, 0, 12 ), substr( $payload, 12, 16 ) );
            return is_string( $value ) ? $value : '';
        }
        return '';
    }

    public static function clear_cache() {
        delete_transient( self::CACHE_KEY );
        delete_site_transient( 'update_plugins' );
    }

    private static function headers( $with_token = true ) {
        $headers = array(
            'Accept' => 'application/vnd.github+json',
            'User-Agent' => 'Woo-Crowd-Purchase-Updater/' . WCP_VERSION,
            'X-GitHub-Api-Version' => '2022-11-28',
        );
        if ( $with_token ) {
            $token = self::token();
            if ( $token ) { $headers['Authorization'] = 'Bearer ' . $token; }
        }
        return $headers;
    }

    /** Read the version from the remote plugin header. No release/tag creation required. */
    public static function remote_version( $force = false ) {
        if ( ! $force ) {
            $cached = get_transient( self::CACHE_KEY );
            if ( is_array( $cached ) && ! empty( $cached['version'] ) ) { return $cached; }
        }
        $url = 'https://api.github.com/repos/' . self::REPO . '/contents/woo-crowd-purchase.php?ref=main';
        $response = wp_safe_remote_get( $url, array( 'timeout' => 16, 'redirection' => 0, 'headers' => self::headers() ) );
        if ( is_wp_error( $response ) ) {
            return new WP_Error( 'wcp_github_network', 'اتصال امن به GitHub برقرار نشد.' );
        }
        $code = (int) wp_remote_retrieve_response_code( $response );
        if ( 200 !== $code ) {
            return new WP_Error( 'wcp_github_http', sprintf( 'GitHub پاسخ HTTP %d داد. اگر ریپو خصوصی است، دسترسی Contents: Read توکن را بررسی کنید.', $code ) );
        }
        $json = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( ! is_array( $json ) || ! isset( $json['content'], $json['encoding'] ) || 'base64' !== $json['encoding'] ) {
            return new WP_Error( 'wcp_github_format', 'اطلاعات نسخه افزونه از GitHub قابل خواندن نیست.' );
        }
        $header = base64_decode( preg_replace( '/\s+/', '', $json['content'] ), true );
        if ( ! is_string( $header ) || strlen( $header ) > 100000 || ! preg_match( '/^\s*\*\s*Version:\s*([0-9]+(?:\.[0-9]+){1,3}(?:[-.][0-9A-Za-z.-]+)?)/mi', $header, $match ) ) {
            return new WP_Error( 'wcp_github_version', 'شماره نسخه معتبر در فایل اصلی GitHub پیدا نشد.' );
        }
        $info = array( 'version' => $match[1] );
        set_transient( self::CACHE_KEY, $info, 20 * MINUTE_IN_SECONDS );
        return $info;
    }

    public static function check_update( $update, $plugin_data, $plugin_file, $locales ) {
        if ( plugin_basename( WCP_FILE ) !== $plugin_file || self::UPDATE_URI !== $plugin_data['UpdateURI'] ) { return $update; }
        $remote = self::remote_version();
        if ( is_wp_error( $remote ) ) { return false; }
        return array(
            'slug' => self::SLUG,
            'version' => $remote['version'],
            'url' => self::UPDATE_URI,
            'package' => self::ZIP_URL,
            'requires_php' => '7.4',
        );
    }

    public static function plugin_information( $result, $action, $args ) {
        if ( 'plugin_information' !== $action || ! is_object( $args ) || empty( $args->slug ) || self::SLUG !== $args->slug ) { return $result; }
        $remote = self::remote_version();
        if ( is_wp_error( $remote ) ) { return $result; }
        return (object) array(
            'name' => 'Woo Crowd Purchase',
            'slug' => self::SLUG,
            'version' => $remote['version'],
            'author' => 'Sajad',
            'homepage' => self::UPDATE_URI,
            'requires' => '6.2',
            'requires_php' => '7.4',
            'download_link' => self::ZIP_URL,
            'sections' => array( 'description' => 'افزونه مشارکت در خرید ووکامرس. منبع به‌روزرسانی: GitHub.' ),
        );
    }

    private static function is_our_upgrade( $hook_extra ) {
        return is_array( $hook_extra ) && isset( $hook_extra['plugin'] ) && plugin_basename( WCP_FILE ) === $hook_extra['plugin'];
    }

    /** GitHub API redirects to an expiring archive URL. Never forward the PAT to that host. */
    public static function download_private_archive( $reply, $package, $upgrader, $hook_extra ) {
        if ( false !== $reply || self::ZIP_URL !== $package || ! self::is_our_upgrade( $hook_extra ) || ! self::has_token() ) { return $reply; }
        if ( ! self::token() ) { return new WP_Error( 'wcp_github_token', 'توکن GitHub ذخیره‌شده قابل رمزگشایی نیست؛ آن را دوباره ثبت کنید.' ); }
        $temp = wp_tempnam( 'wcp-github.zip' );
        if ( ! $temp ) { return new WP_Error( 'wcp_github_temp', 'فایل موقت دانلود قابل ایجاد نیست.' ); }
        $response = wp_safe_remote_get( self::ZIP_URL, array(
            'timeout' => 90, 'redirection' => 0, 'stream' => true, 'filename' => $temp, 'headers' => self::headers(),
        ) );
        if ( is_wp_error( $response ) ) {
            @unlink( $temp );
            return new WP_Error( 'wcp_github_download', 'خطا در دریافت بسته از GitHub.' );
        }
        $code = (int) wp_remote_retrieve_response_code( $response );
        if ( in_array( $code, array( 301, 302, 303, 307, 308 ), true ) ) {
            $location = wp_remote_retrieve_header( $response, 'location' );
            $host = is_string( $location ) ? wp_parse_url( $location, PHP_URL_HOST ) : '';
            if ( ! is_string( $location ) || 'https' !== wp_parse_url( $location, PHP_URL_SCHEME ) || ! in_array( strtolower( (string) $host ), array( 'codeload.github.com', 'objects.githubusercontent.com' ), true ) || wp_parse_url( $location, PHP_URL_USER ) ) {
                @unlink( $temp );
                return new WP_Error( 'wcp_github_redirect', 'آدرس دانلود GitHub مورد تأیید نیست.' );
            }
            // A signed archive URL is requested with NO Authorization header.
            $response = wp_safe_remote_get( $location, array( 'timeout' => 120, 'redirection' => 0, 'stream' => true, 'filename' => $temp ) );
            $code = is_wp_error( $response ) ? 0 : (int) wp_remote_retrieve_response_code( $response );
        }
        if ( 200 !== $code || ! is_file( $temp ) || filesize( $temp ) < 100 ) {
            @unlink( $temp );
            return new WP_Error( 'wcp_github_archive', 'فایل ZIP از GitHub دریافت نشد؛ دسترسی توکن به ریپو را بررسی کنید.' );
        }
        $file = fopen( $temp, 'rb' );
        $magic = $file ? fread( $file, 2 ) : '';
        if ( $file ) { fclose( $file ); }
        if ( 'PK' !== $magic ) {
            @unlink( $temp );
            return new WP_Error( 'wcp_github_bad_zip', 'GitHub فایل ZIP معتبر ارسال نکرد.' );
        }
        return $temp;
    }

    /** GitHub zipball uses owner-repo-hash; WooCommerce plugin must retain its original directory. */
    public static function normalize_archive_directory( $source, $remote_source, $upgrader, $hook_extra ) {
        if ( is_wp_error( $source ) || ! self::is_our_upgrade( $hook_extra ) ) { return $source; }
        global $wp_filesystem;
        $source = trailingslashit( $source );
        if ( ! $wp_filesystem || ! $wp_filesystem->exists( $source . 'woo-crowd-purchase.php' ) ) {
            return new WP_Error( 'wcp_github_missing_plugin', 'فایل اصلی افزونه در بسته آپدیت پیدا نشد.' );
        }
        if ( self::SLUG === basename( untrailingslashit( $source ) ) ) { return $source; }
        $destination = trailingslashit( dirname( untrailingslashit( $source ) ) ) . self::SLUG;
        if ( $wp_filesystem->exists( $destination ) || ! $wp_filesystem->move( untrailingslashit( $source ), $destination, false ) ) {
            return new WP_Error( 'wcp_github_directory', 'تغییر نام پوشه بسته GitHub ممکن نشد؛ آپدیت متوقف شد.' );
        }
        return trailingslashit( $destination );
    }
}
