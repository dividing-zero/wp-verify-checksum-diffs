<?php

namespace WP_CLI\VerifyChecksumDiffs;

use WP_CLI;

if ( ! class_exists( '\WP_CLI' ) ) {
	return;
}

$wpcli_verify_checksum_diffs_autoloader = __DIR__ . '/vendor/autoload.php';

if ( file_exists( $wpcli_verify_checksum_diffs_autoloader ) ) {
	require_once $wpcli_verify_checksum_diffs_autoloader;
}

WP_CLI::add_command( 'verify-checksum-diffs', VerifyChecksumDiffs::class );
