<?php
// Lightweight standalone unit tests (do not load WooCommerce).
define( 'ABSPATH', __DIR__ );
function wc_get_price_decimals() { return $GLOBALS['minor_digits']; }
function wc_get_price_decimal_separator() { return '.'; }
function wc_get_price_thousand_separator() { return ','; }
require dirname( __DIR__ ) . '/includes/class-wcp-storage.php';
$cases = array(
    array( 0, '۱٬۰۰۰٬۰۰۰', 1000000 ),
    array( 0, '12,345', 12345 ),
    array( 0, '١٢٣', 123 ),
    array( 0, '20.00', 20 ),
    array( 0, '20.50', 0 ),
    array( 2, '123.45', 12345 ),
    array( 2, '0.01', 1 ),
    array( 2, '۱,۲۰۰.۵', 120050 ),
    array( 2, '-100', 0 ),
    array( 2, '1e9', 0 ),
    array( 2, '999999999999999999999999999', 0 ),
);
foreach ( $cases as $test ) {
    $GLOBALS['minor_digits'] = $test[0];
    $actual = WCP_Storage::to_units( $test[1] );
    if ( $actual !== $test[2] ) { throw new RuntimeException( "to_units failed for {$test[1]}: {$actual} vs {$test[2]}" ); }
    if ( $actual > 0 && WCP_Storage::to_units( WCP_Storage::from_units( $actual ) ) !== $actual ) {
        throw new RuntimeException( 'Roundtrip money conversion failed.' );
    }
}
echo 'PASS: ' . count( $cases ) . " money conversion cases.\n";
