<?php
/** Durable queue storage for deferred WordPress mail. */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class AS_SMTP2GO_Queue {
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'as_smtp2go_queue';
	}

	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$table = self::table();
		$sql = "CREATE TABLE $table (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			status varchar(20) NOT NULL DEFAULT 'queued',
			payload longtext NOT NULL,
			attempts int(10) unsigned NOT NULL DEFAULT 0,
			last_error text NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY status_id (status,id)
		) {$wpdb->get_charset_collate()};";
		dbDelta( $sql );
		add_option( 'as_smtp2go_monitor_state', 'armed', '', false );
	}

	/** Store all mail arguments, including file contents, before reporting acceptance. */
	public static function enqueue( $mail ) {
		global $wpdb;
		$payload = self::pack( $mail );
		if ( is_wp_error( $payload ) ) {
			return $payload;
		}
		$now = current_time( 'mysql', true );
		$json = wp_json_encode( $payload );
		if ( false === $json ) {
			return new WP_Error( 'queue_encode_failed', __( 'The email could not be encoded for the queue.', 'as-smtp2go-monitor' ) );
		}
		$ok = $wpdb->insert(
			self::table(),
			array(
				'status' => 'queued',
				'payload' => $json,
				'created_at' => $now,
				'updated_at' => $now,
			),
			array( '%s', '%s', '%s', '%s' )
		);
		return $ok ? (int) $wpdb->insert_id : new WP_Error( 'queue_write_failed', __( 'The email could not be saved to the queue.', 'as-smtp2go-monitor' ) );
	}

	private static function pack( $mail ) {
		$packed = array();
		foreach ( array( 'to', 'subject', 'message', 'headers' ) as $key ) {
			$packed[ $key ] = isset( $mail[ $key ] ) ? $mail[ $key ] : '';
		}
		foreach ( array( 'attachments', 'embeds' ) as $key ) {
			$packed[ $key ] = array();
			$paths = isset( $mail[ $key ] ) ? $mail[ $key ] : array();
			if ( is_string( $paths ) ) {
				$paths = array_filter( explode( "\n", str_replace( "\r\n", "\n", $paths ) ) );
			}
			if ( ! is_array( $paths ) ) {
				return new WP_Error( 'invalid_files', __( 'Email file arguments are invalid.', 'as-smtp2go-monitor' ) );
			}
			foreach ( $paths as $name => $path ) {
				if ( ! is_string( $path ) || ! is_file( $path ) || ! is_readable( $path ) ) {
					return new WP_Error( 'missing_file', __( 'An email attachment is missing or unreadable.', 'as-smtp2go-monitor' ) );
				}
				$contents = file_get_contents( $path );
				if ( false === $contents ) {
					return new WP_Error( 'unreadable_file', __( 'An email attachment could not be read.', 'as-smtp2go-monitor' ) );
				}
				$packed[ $key ][] = array(
					'name' => $name,
					'filename' => basename( $path ),
					'data' => base64_encode( $contents ),
				);
			}
		}
		return $packed;
	}

	public static function unpack( $json ) {
		$packed = json_decode( $json, true );
		if ( ! is_array( $packed ) ) {
			return new WP_Error( 'invalid_payload', __( 'The queued email could not be decoded.', 'as-smtp2go-monitor' ) );
		}
		$temporary = array();
		foreach ( array( 'attachments', 'embeds' ) as $key ) {
			$files = array();
			foreach ( isset( $packed[ $key ] ) && is_array( $packed[ $key ] ) ? $packed[ $key ] : array() as $file ) {
				if ( ! isset( $file['data'], $file['filename'], $file['name'] ) ) {
					self::cleanup( $temporary );
					return new WP_Error( 'invalid_payload', __( 'The queued attachment is invalid.', 'as-smtp2go-monitor' ) );
				}
				$data = base64_decode( $file['data'], true );
				$directory = trailingslashit( sys_get_temp_dir() ) . 'as-smtp2go-' . wp_generate_uuid4();
				$path = $directory . '/' . sanitize_file_name( $file['filename'] );
				if ( false === $data || ! mkdir( $directory, 0700 ) || strlen( $data ) !== file_put_contents( $path, $data ) ) {
					self::cleanup( $temporary );
					if ( is_dir( $directory ) ) {
						rmdir( $directory );
					}
					return new WP_Error( 'file_restore_failed', __( 'A queued attachment could not be restored.', 'as-smtp2go-monitor' ) );
				}
				$temporary[] = $path;
				$files[ $file['name'] ] = $path;
			}
			$packed[ $key ] = $files;
		}
		return array( $packed, $temporary );
	}

	public static function cleanup( $files ) {
		foreach ( $files as $file ) {
			if ( is_string( $file ) && is_file( $file ) ) {
				unlink( $file );
				$directory = dirname( $file );
				if ( 0 === strpos( basename( $directory ), 'as-smtp2go-' ) ) {
					rmdir( $directory );
				}
			}
		}
	}

	public static function count() {
		global $wpdb;
		$table = self::table();
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table WHERE status = 'queued' OR status = 'sending'" );
	}

	public static function next() {
		global $wpdb;
		$table = self::table();
		return $wpdb->get_row( "SELECT * FROM $table WHERE status = 'queued' ORDER BY id ASC LIMIT 1" );
	}

	public static function has_sending() {
		global $wpdb;
		$table = self::table();
		return (bool) $wpdb->get_var( "SELECT id FROM $table WHERE status = 'sending' LIMIT 1" );
	}

	public static function claim( $id ) {
		global $wpdb;
		return 1 === $wpdb->update(
			self::table(),
			array( 'status' => 'sending', 'updated_at' => current_time( 'mysql', true ) ),
			array( 'id' => $id, 'status' => 'queued' ),
			array( '%s', '%s' ),
			array( '%d', '%s' )
		);
	}

	public static function finish( $id, $success, $error = '' ) {
		global $wpdb;
		if ( $success ) {
			$wpdb->delete( self::table(), array( 'id' => $id, 'status' => 'sending' ), array( '%d', '%s' ) );
			return;
		}
		$wpdb->update(
			self::table(),
			array( 'status' => 'queued', 'attempts' => (int) $wpdb->get_var( $wpdb->prepare( 'SELECT attempts FROM ' . self::table() . ' WHERE id = %d', $id ) ) + 1, 'last_error' => $error, 'updated_at' => current_time( 'mysql', true ) ),
			array( 'id' => $id, 'status' => 'sending' ),
			array( '%s', '%d', '%s', '%s' ),
			array( '%d', '%s' )
		);
	}
}
