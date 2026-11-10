<?php
/** Tiny dbDelta stand-in for the multisite setup test. */
function dbDelta( $sql ) {
	global $wpdb;
	if ( preg_match( '/CREATE TABLE ([a-zA-Z0-9_]+)/', $sql, $match ) ) {
		$wpdb->tables[ $match[1] ] = true;
	}
}
