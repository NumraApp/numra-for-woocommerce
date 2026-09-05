<?php
/**
 * Static integrity check: every Numra_* class referenced anywhere must be
 * resolvable through the plugin autoloader, and every ClassName::method()
 * call must land on a method that actually exists.
 */
$ROOT = dirname( __DIR__ );
define( 'ABSPATH', $ROOT . '/' );
define( 'NUMRA_PLATFORM', 'woocommerce' );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );
define( 'NUMRA_PLUGIN_DIR', $ROOT . '/' );
define( 'NUMRA_PLUGIN_FILE', $ROOT . '/numra-for-woocommerce.php' );
define( 'NUMRA_PLUGIN_URL', 'http://example.test/' );
define( 'NUMRA_VERSION', '1.1.0' );
define( 'NUMRA_API_BASE', 'https://api.numra.ma' );

// Pull the autoload map straight out of the plugin file so we test the real one.
$main = file_get_contents( $ROOT . '/numra-for-woocommerce.php' );
preg_match_all( "/'(Numra_[A-Za-z_]+)'\s*=>\s*'([^']+)'/", $main, $mm, PREG_SET_ORDER );
$map = array();
foreach ( $mm as $m ) { $map[ $m[1] ] = $m[2]; }

echo "Autoload map (" . count( $map ) . " entries):\n";
$missing_files = array();
foreach ( $map as $cls => $rel ) {
    $ok = file_exists( $ROOT . '/' . $rel );
    printf( "  %-24s %s %s\n", $cls, $ok ? 'OK ' : 'MISSING FILE', $rel );
    if ( ! $ok ) { $missing_files[] = $cls; }
}

spl_autoload_register( function ( $class ) use ( $map ) {
    if ( isset( $map[ $class ] ) && file_exists( NUMRA_PLUGIN_DIR . $map[ $class ] ) ) {
        require_once NUMRA_PLUGIN_DIR . $map[ $class ];
    }
} );

// Load every mapped class plus every include, so reflection sees them all.
foreach ( glob( $ROOT . '/includes/*.php' ) as $f ) { require_once $f; }

// Collect every static call ClassName::method( in the codebase and verify it.
$files = array_merge( glob( $ROOT . '/includes/*.php' ), array( $ROOT . '/numra-for-woocommerce.php', $ROOT . '/uninstall.php' ) );
$bad = array();
$checked = 0;
foreach ( $files as $f ) {
    $src = file_get_contents( $f );
    // strip comments so commented-out code doesn't create false positives
    $stripped = '';
    foreach ( token_get_all( $src ) as $t ) {
        if ( is_array( $t ) && in_array( $t[0], array( T_COMMENT, T_DOC_COMMENT ), true ) ) { continue; }
        $stripped .= is_array( $t ) ? $t[1] : $t;
    }
    preg_match_all( '/\b(Numra_[A-Za-z_]+)::([a-zA-Z_][a-zA-Z0-9_]*)\s*\(/', $stripped, $calls, PREG_SET_ORDER );
    foreach ( $calls as $c ) {
        $checked++;
        list( , $cls, $method ) = $c;
        if ( ! class_exists( $cls ) )            { $bad[] = "$cls::$method()  -- CLASS NOT LOADABLE  (" . basename( $f ) . ")"; continue; }
        if ( ! method_exists( $cls, $method ) )  { $bad[] = "$cls::$method()  -- METHOD MISSING      (" . basename( $f ) . ")"; }
    }
}

// Verify every $this->method() inside each class resolves too.
foreach ( get_declared_classes() as $cls ) {
    if ( strpos( $cls, 'Numra_' ) !== 0 ) { continue; }
    $r = new ReflectionClass( $cls );
    $src = file_get_contents( $r->getFileName() );
    $stripped = '';
    foreach ( token_get_all( $src ) as $t ) {
        if ( is_array( $t ) && in_array( $t[0], array( T_COMMENT, T_DOC_COMMENT ), true ) ) { continue; }
        $stripped .= is_array( $t ) ? $t[1] : $t;
    }
    preg_match_all( '/\$this->([a-zA-Z_][a-zA-Z0-9_]*)\s*\(/', $stripped, $mcalls );
    foreach ( array_unique( $mcalls[1] ) as $method ) {
        $checked++;
        if ( ! method_exists( $cls, $method ) ) { $bad[] = "$cls->$method()  -- METHOD MISSING      (" . basename( $r->getFileName() ) . ")"; }
    }
}

echo "\nChecked $checked call sites.\n";

/* A check that finds nothing must fail, not pass. When this script moved into
   tests/ its __DIR__ paths silently pointed at an empty directory and it
   reported "all references resolve" having verified zero of them — a green
   result that meant nothing. Any real run of this plugin sees dozens. */
if ( $checked < 20 || count( $map ) < 5 ) {
	echo "\nFAILED: the check found almost nothing ($checked call sites, " . count( $map ) . " mapped classes).\n";
	echo "It is looking at the wrong directory, not at a healthy plugin.\n";
	exit( 1 );
}

if ( $missing_files ) { echo "\nMAPPED TO MISSING FILES: " . implode( ', ', $missing_files ) . "\n"; }
if ( $bad ) {
    echo "\nBROKEN CALLS (" . count( $bad ) . "):\n";
    foreach ( array_unique( $bad ) as $b ) { echo "  $b\n"; }
    exit( 1 );
}
echo "\nAll Numra_* class and method references resolve.\n";
