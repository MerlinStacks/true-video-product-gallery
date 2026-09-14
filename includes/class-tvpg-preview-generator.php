<?php
/**
 * Local-only, asynchronous archive video derivatives.
 *
 * @package TVPG
 */

// Native local descriptors, flock and atomic rename are required here; WP_Filesystem may use FTP.
// phpcs:disable WordPress.WP.AlternativeFunctions
defined( 'ABSPATH' ) || exit;

/** Generates and caches bounded local video previews outside the request rendering path. */
class TVPG_Preview_Generator {

	const HOOK       = 'tvpg_generate_preview';
	const GROUP      = 'tvpg-previews';
	const PROFILE    = 'mp4-h264-480-24-8-crf28-v1';
	const PREFIX     = 'tvpg_preview_';
	const DIRECTORY  = 'tvpg-previews';
	const MAX_INPUT  = 536870912;
	const MAX_OUTPUT = 8388608;
	const LIFECYCLE  = 'tvpg_preview_lifecycle';

	/**
	 * Request-local URL resolution records, scoped by uploads directory.
	 *
	 * @var array
	 */
	private static $resolutions = array();

	/** Register on admin, frontend and background requests alike. */
	public static function init() {
		// Bootstrap existing installations only; a disabled lifecycle requires explicit activation.
		if ( false === get_option( self::LIFECYCLE, false ) ) {
			self::change( self::LIFECYCLE, false, self::new_lifecycle( true ) );
		}
		add_action( self::HOOK, array( __CLASS__, 'process' ), 10, 3 );
		foreach ( array( 'added_post_meta', 'updated_post_meta', 'deleted_post_meta' ) as $hook ) {
			add_action( $hook, array( __CLASS__, 'meta_changed' ), 10, 4 );
		}
		add_action( 'delete_attachment', array( __CLASS__, 'delete_attachment' ) );
		add_action( 'woocommerce_update_product', array( __CLASS__, 'product_saved' ) );
	}

	/** Return a usable derivative or the original. Never executes a process or HTTP request. */
	public static function get_preview_url( $product_id, $original_url ): string {
		$lifecycle = get_option( self::LIFECYCLE, array() );
		return self::preview_url( $product_id, $original_url, ! empty( $lifecycle['enabled'] ) ? $lifecycle['epoch'] : '' );
	}

	/** Preserve the initiating lifecycle across a resolver-to-encoder handoff. */
	private static function preview_url( $product_id, $original_url, $epoch ): string {
		$original_url = is_string( $original_url ) ? $original_url : '';
		if ( ! $epoch || ! self::product( $product_id ) || get_post_meta( $product_id, '_tvpg_video_url', true ) !== $original_url ) {
			return $original_url;
		}
		$source = self::source( $original_url );
		if ( ! $source ) {
			$mapping = self::mapping( $original_url );
			if ( false !== self::relative( $original_url ) && ! $mapping ) {
				self::queue(
					$product_id,
					array(
						'key' => hash( 'sha256', 'resolve|' . $original_url ),
						'id'  => 0,
					),
					$epoch
				);
			}
			return $original_url;
		}
		$state = get_option( self::PREFIX . $source['key'], array() );
		if ( 'ready' === ( $state['state'] ?? '' ) ) {
			$file = self::output( $source );
			if ( $file && self::usable( $file, $source['size'] ) ) {
				return self::uploads()['baseurl'] . '/' . self::DIRECTORY . '/' . basename( $file );
			}
			self::forget( self::PREFIX . $source['key'], $state );
		}
		self::queue( $product_id, $source, $epoch );
		return $original_url;
	}

	/** Public admin API: array{state:string,message:string}, optionally attempts/next/key. */
	public static function get_status( $product_id ): array {
		$url    = get_post_meta( $product_id, '_tvpg_video_url', true );
		$source = self::source( $url );
		if ( ! $source ) {
			$pending = is_string( $url ) ? get_option( self::PREFIX . hash( 'sha256', 'resolve|' . $url ), array() ) : array();
			if ( $pending ) {
				return $pending;
			}
			return array(
				'state'   => 'original',
				'message' => __( 'Using the original video. Automatic previews require a supported local WordPress video attachment within uploads (maximum 512 MB).', 'true-video-product-gallery' ),
			);
		}
		return get_option(
			self::PREFIX . $source['key'],
			array(
				'state'   => 'idle',
				'message' => __( 'Preview will be queued when the product appears in a category or its video is saved.', 'true-video-product-gallery' ),
			)
		);
	}

	/** Enqueue after main video metadata changes; explicit saves also reset terminal failures. */
	public static function meta_changed( $meta_id, $product_id, $key, $value ) {
		unset( $meta_id, $value );
		if ( '_tvpg_video_url' === $key && self::product( $product_id ) ) {
			$source = self::source( get_post_meta( $product_id, $key, true ), true );
			if ( $source ) {
				$name  = self::PREFIX . $source['key'];
				$state = get_option( $name, array() );
				if ( in_array( $state['state'] ?? '', array( 'unavailable', 'failed' ), true ) ) {
					self::forget( $name, $state );
				}
			}
			self::get_preview_url( $product_id, get_post_meta( $product_id, $key, true ) );
		}
	}

	/** A same-value product save is also an explicit opportunity to retry after host repairs. */
	public static function product_saved( $product_id ) {
		self::meta_changed( 0, $product_id, '_tvpg_video_url', null );
	}

	/** Check the object type before creating background work. */
	private static function product( $id ) {
		return is_numeric( $id ) && $id > 0 && in_array( get_post_type( (int) $id ), array( 'product', 'product_variation' ), true );
	}

	/** Get uploads configuration without creating directories. */
	private static function uploads() {
		// No directory creation or remote/offloaded-media access on a page render.
		return wp_upload_dir( null, false );
	}

	/** Check URL locality without any attachment lookup. */
	private static function relative( $url ) {
		if ( ! is_string( $url ) || '' === $url || preg_match( '/[\x00-\x20\\\\]/', $url ) ) {
			return false;
		}
		$uploads = self::uploads();
		$base    = rtrim( $uploads['baseurl'], '/' ) . '/';
		if ( ! empty( $uploads['error'] ) || 0 !== strpos( $url, $base ) || preg_match( '/[?#]/', $url ) ) {
			return false;
		}
		$relative = rawurldecode( substr( $url, strlen( $base ) ) );
		if ( preg_match( '~(?:^|/)\.{1,2}(?:/|$)|[\x00-\x1f\\\\]~', $relative ) ) {
			return false;
		}
		return $relative;
	}

	/** Positive and negative records expire; only saves/workers perform URL SQL resolution. */
	private static function mapping( $url, $resolve = false ) {
		if ( false === self::relative( $url ) ) {
			return false;
		}
		$name = self::PREFIX . 'map_' . hash( 'sha256', $url );
		$memo = get_current_blog_id() . '|' . self::uploads()['basedir'] . '|' . $name;
		if ( ! isset( self::$resolutions[ $memo ] ) || $resolve ) {
			self::$resolutions[ $memo ] = get_transient( $name );
		}
		$record = self::$resolutions[ $memo ];
		if ( $resolve ) {
			$id     = (int) attachment_url_to_postid( $url );
			$record = array(
				'id'      => $id,
				'expires' => time() + ( $id ? 86400 : 3600 ),
			);
			set_transient( $name, $record, $id ? 86400 : 3600 );
			self::$resolutions[ $memo ] = $record;
			if ( $id ) {
				self::index( $id, $name );
			}
		}
		return ( $record['expires'] ?? 0 ) > time() ? $record : false;
	}

	/** Resolve only canonical URLs for existing local attachments. Stat, never hash, on frontend. */
	private static function source( $url, $resolve = false ) {
		$relative = self::relative( $url );
		if ( false === $relative ) {
			return false;
		}
		$uploads = self::uploads();
		$mapping = self::mapping( $url, $resolve );
		$id      = $mapping['id'] ?? 0;
		if ( ! $id || 'attachment' !== get_post_type( $id ) || 0 !== strpos( (string) get_post_mime_type( $id ), 'video/' ) ) {
			return false;
		}
		$attached = get_attached_file( $id, true );
		$root     = realpath( $uploads['basedir'] );
		$file     = $attached ? realpath( $attached ) : false;
		$expected = realpath( $uploads['basedir'] . '/' . $relative );
		if ( ! $root || ! $file || $file !== $expected || 0 !== strpos( $file, $root . DIRECTORY_SEPARATOR ) || ! is_file( $file ) || ! is_readable( $file ) ) {
			return false;
		}
		$formats   = array(
			'mp4'  => 'mov',
			'm4v'  => 'mov',
			'mov'  => 'mov',
			'webm' => 'matroska',
			'mkv'  => 'matroska',
			'avi'  => 'avi',
		);
		$extension = strtolower( pathinfo( $file, PATHINFO_EXTENSION ) );
		if ( ! isset( $formats[ $extension ] ) ) {
			return false;
		}
		clearstatcache( true, $file );
		$stat = stat( $file );
		if ( ! $stat || $stat['size'] <= 0 || $stat['size'] > self::MAX_INPUT ) {
			return false;
		}
		$key = hash( 'sha256', implode( '|', array( $id, $file, $stat['dev'], $stat['ino'], $stat['size'], $stat['mtime'], $stat['ctime'], self::PROFILE ) ) );
		return array(
			'id'     => $id,
			'file'   => $file,
			'size'   => $stat['size'],
			'key'    => $key,
			'format' => $formats[ $extension ],
			'stat'   => $stat,
		);
	}

	/** An atomic option insertion guards each source across all products using it. */
	private static function queue( $product_id, $source, $epoch ) {
		$name  = self::PREFIX . $source['key'];
		$state = get_option( $name, array() );
		if ( $state ) {
			if ( isset( $state['epoch'] ) && $state['epoch'] !== $epoch ) {
				return;
			}
			if ( in_array( $state['state'], array( 'ready', 'unavailable', 'failed', 'original' ), true ) || ( $state['next'] ?? PHP_INT_MAX ) > time() ) {
				return;
			}
			// Compare-and-delete prevents two expired-queue observers from removing a new claim.
			if ( ! self::forget( $name, $state ) ) {
				return;
			}
		}
		$state = array(
			'epoch'         => $epoch,
			'state'         => 'queued',
			'message'       => __( 'Automatic preview queued; using the original meanwhile.', 'true-video-product-gallery' ),
			'attempts'      => $state['attempts'] ?? 0,
			'next'          => time() + 600,
			'attachment_id' => $source['id'],
			'product_id'    => (int) $product_id,
		);
		if ( self::claim( $name, $state ) ) {
			if ( ! self::is_live( $epoch ) ) {
				self::forget( $name, $state );
				return;
			}
			if ( $source['id'] && ! self::index( $source['id'], $name ) ) {
				self::forget( $name, $state );
				return;
			}
			if ( ! self::schedule( $product_id, $source['key'], time() + 5, $epoch ) ) {
				if ( ! self::is_live( $epoch ) ) {
					self::forget( $name, $state );
				} else {
					self::save( $name, $state, 'failed', __( 'Using the original: the background scheduler could not queue the preview. Save the video to retry.', 'true-video-product-gallery' ), $state['attempts'] );
				}
			}
		}
	}

	/** Claim insertion and the enabled-epoch predicate are one database statement. */
	private static function claim( $name, $state ) {
		global $wpdb;
		$lifecycle = array(
			'enabled' => true,
			'epoch'   => $state['epoch'],
		);
		$changed   = $wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) SELECT %s, %s, 'no' FROM {$wpdb->options} WHERE option_name = %s AND BINARY option_value = %s",
				$name,
				maybe_serialize( $state ),
				self::LIFECYCLE,
				maybe_serialize( $lifecycle )
			)
		);
		self::invalidate( $name );
		return 1 === $changed;
	}

	/** Explicit activation starts a new generation; old requests must never adopt it. */
	public static function activate() {
		update_option( self::LIFECYCLE, self::new_lifecycle( true ), false );
		self::invalidate( self::LIFECYCLE );
	}

	/** Random epochs also distinguish uninstall/reinstall from a previously missing option. */
	private static function new_lifecycle( $enabled ) {
		return array(
			'enabled' => $enabled,
			'epoch'   => bin2hex( random_bytes( 16 ) ),
		);
	}

	/** Bypass request-local option caches at lifecycle boundaries. */
	private static function is_live( $epoch ) {
		self::invalidate( self::LIFECYCLE );
		$lifecycle = get_option( self::LIFECYCLE, array() );
		return is_string( $epoch ) && '' !== $epoch && ! empty( $lifecycle['enabled'] ) && $epoch === $lifecycle['epoch'];
	}

	/** Compare-and-delete a source claim without racing a newer worker. */
	private static function forget( $name, $state ) {
		return self::change( $name, $state, false );
	}

	/** Invalidate positive, negative and legacy autoloaded WordPress option caches. */
	private static function invalidate( $name ) {
		wp_cache_delete( $name, 'options' );
		wp_cache_delete( 'notoptions', 'options' );
		wp_cache_delete( 'alloptions', 'options' );
	}

	/** INSERT IGNORE is insert-only; unlike add_option it cannot overwrite a racing claim. */
	private static function change( $name, $old, $replacement ) {
		global $wpdb;
		if ( false === $old ) {
			$sql = $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')", $name, maybe_serialize( $replacement ) );
		} elseif ( false === $replacement ) {
			$sql = $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s AND BINARY option_value = %s", $name, maybe_serialize( $old ) );
		} else {
			$sql = $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND BINARY option_value = %s", maybe_serialize( $replacement ), $name, maybe_serialize( $old ) );
		}
		$changed = $wpdb->query( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- All branches above are prepared.
		self::invalidate( $name );
		return 1 === $changed;
	}

	/** Bounded attachment-owned index; concurrent products merge it using CAS. */
	private static function index( $id, $entry ) {
		$name = self::PREFIX . 'index_' . (int) $id;
		for ( $attempt = 0; $attempt < 8; ++$attempt ) {
			self::invalidate( $name );
			$old  = get_option( $name, false );
			$next = $old ? $old : array();
			if ( isset( $next[ $entry ] ) ) {
				return true;
			}
			if ( count( $next ) >= 64 ) {
				return false;
			}
			$next[ $entry ] = true;
			if ( self::change( $name, $old, $next ) ) {
				return true;
			}
		}
		return false;
	}

	/** Schedule via WooCommerce's scheduler or core cron. */
	private static function schedule( $id, $key, $time, $epoch ) {
		if ( ! self::is_live( $epoch ) ) {
			return false;
		}
		$args = array( (int) $id, $key, $epoch );
		try {
			if ( function_exists( 'as_schedule_single_action' ) ) {
				$scheduled = as_schedule_single_action( $time, self::HOOK, $args, self::GROUP );
			} else {
				$scheduled = wp_next_scheduled( self::HOOK, $args ) || wp_schedule_single_event( $time, self::HOOK, $args );
			}
		} catch ( Throwable $error ) {
			$scheduled = false; // A broken scheduler must not break a category page.
		}
		// Cancellation may run inside/alongside the scheduler after its global unschedule pass.
		if ( ! self::is_live( $epoch ) ) {
			self::cancel_action( $args );
			return false;
		}
		return $scheduled;
	}

	/** Cancel only these exact arguments, so an old request cannot cancel reactivated work. */
	private static function cancel_action( $args ) {
		wp_clear_scheduled_hook( self::HOOK, $args );
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( self::HOOK, $args, self::GROUP );
		}
	}

	/** Worker only: one OS lock for the installation; kernel releases it after crashes. */
	private static function lock() {
		$path = rtrim( get_temp_dir(), '/\\' ) . '/tvpg-preview-' . hash( 'sha256', ABSPATH ) . '.lock';
		if ( is_link( $path ) ) {
			return false;
		}
		$handle = @fopen( $path, 'c' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- A non-writable temp directory is a handled lock failure.
		if ( ! $handle ) {
			return false;
		}
		$stat      = fstat( $handle );
		$path_stat = lstat( $path );
		if ( ! $path_stat || $stat['ino'] !== $path_stat['ino'] || 0100000 !== ( $path_stat['mode'] & 0170000 ) || ! flock( $handle, LOCK_EX | LOCK_NB ) ) {
			fclose( $handle );
			return false;
		}
		return $handle;
	}

	/**
	 * Execute a bounded, local-only transcode. Jobs contain IDs/fingerprints, never paths.
	 *
	 * @throws RuntimeException Internally caught and converted into bounded retry state.
	 */
	public static function process( $product_id, $key, $epoch = null ) {
		if ( ! self::product( $product_id ) || ! is_string( $key ) || ! preg_match( '/^[a-f0-9]{64}$/D', $key ) ) {
			return;
		}
		$name = self::PREFIX . $key;
		self::invalidate( $name );
		$state = get_option( $name, array() );
		if ( ( $state['epoch'] ?? '' ) !== $epoch || ! self::is_live( $epoch ) ) {
			return;
		}
		if ( ! in_array( $state['state'] ?? '', array( 'queued', 'retry' ), true ) ) {
			return;
		}
		if ( (int) ( $state['product_id'] ?? 0 ) !== (int) $product_id ) {
			return; // An expired source claim may now belong to another product's scheduled action.
		}
		if ( 'retry' === $state['state'] && ( $state['next'] ?? 0 ) > time() ) {
			return;
		}
		$url = get_post_meta( $product_id, '_tvpg_video_url', true );
		if ( ! is_string( $url ) ) {
			self::forget( $name, $state );
			return;
		}
		$resolving = empty( $state['attachment_id'] );
		$source    = $resolving ? false : self::source( $url, ! self::mapping( $url ) );
		if ( $resolving ? hash( 'sha256', 'resolve|' . $url ) !== $key : ( ! $source || $key !== $source['key'] ) ) {
			if ( $resolving ) {
				self::forget( $name, $state );
			} elseif ( self::remove_entry( (int) $state['attachment_id'], $name, $state ) ) {
				self::unindex( $state['attachment_id'], array( $name => true ) );
			}
			return;
		}
		$lock = self::lock();
		if ( ! $lock ) {
			$busy = (int) ( $state['busy'] ?? 0 ) + 1;
			if ( $busy >= 20 ) {
				self::save( $name, $state, 'failed', __( 'Using the original: preview worker remained busy or its global lock could not be opened. Save the video to retry.', 'true-video-product-gallery' ), (int) ( $state['attempts'] ?? 0 ) );
			} else {
				$next = array_merge(
					$state,
					array(
						'busy'  => $busy,
						'state' => 'retry',
						'next'  => time() + 60,
					)
				);
				if ( self::change( $name, $state, $next ) ) {
					$state = $next;
					self::reschedule( $name, $state, $product_id, $key );
				}
			}
			return;
		}
		$temp = false;
		try {
			// Re-read after acquiring the lock, including duplicate dispatched jobs.
			self::invalidate( $name );
			$state = get_option( $name, array() );
			if ( ! in_array( $state['state'] ?? '', array( 'queued', 'retry' ), true ) || (int) ( $state['product_id'] ?? 0 ) !== (int) $product_id || ( $state['epoch'] ?? '' ) !== $epoch || ! self::is_live( $epoch ) ) {
				return;
			}
			if ( 'retry' === $state['state'] && ( $state['next'] ?? 0 ) > time() ) {
				return;
			}
			$attempts = (int) ( $state['attempts'] ?? 0 );
			if ( $attempts >= 3 ) {
				self::save( $name, $state, 'failed', __( 'Using the original: preview generation exhausted its retry limit.', 'true-video-product-gallery' ), $attempts );
				return;
			}
			if ( ! self::save( $name, $state, 'processing', __( 'Generating the automatic preview; using the original meanwhile.', 'true-video-product-gallery' ), $attempts + 1, time() + 600 ) ) {
				return;
			}
			if ( $resolving ) {
				self::source( $url, true );
				if ( ! self::forget( $name, $state ) ) {
					return;
				}
				clean_post_cache( $product_id );
				if ( self::is_live( $epoch ) ) {
					self::preview_url( $product_id, get_post_meta( $product_id, '_tvpg_video_url', true ), $epoch );
				}
				return;
			}
			// Retain prior ready files until success, but do not accumulate failed source revisions.
			self::prune( $source['id'], $name, false );
			$binary = self::binary();
			if ( ! function_exists( 'proc_open' ) || ! $binary ) {
				self::save( $name, $state, 'unavailable', __( 'Using the original: FFmpeg or PHP proc_open is unavailable. Ask the host to enable them, then save the video again.', 'true-video-product-gallery' ), $attempts );
				return;
			}
			$capabilities = '';
			if ( ! self::execute( array( $binary, '-hide_banner', '-protocols' ), $source['file'], '', 3, $source['stat'], $lock, $capabilities, false ) || ! preg_match( '/^\s*fd\s*$/m', explode( 'Output:', $capabilities )[0] ) ) {
				self::save( $name, $state, 'unavailable', __( 'Using the original: this FFmpeg build lacks the secure seekable fd input protocol. Install an FFmpeg build with fd support, then save the video again.', 'true-video-product-gallery' ), $attempts );
				return;
			}
			$file = self::output( $source, true );
			if ( ! $file ) {
				throw new RuntimeException( 'Preview uploads directory is not writable.' );
			}
			self::clean_abandoned_temps( dirname( $file ) );
			$temp = tempnam( dirname( $file ), 'tvpg-tmp-' );
			if ( ! $temp || realpath( dirname( $temp ) ) !== realpath( dirname( $file ) ) ) {
				throw new RuntimeException( 'Cannot create a preview temporary file.' );
			}
			$digest = self::digest( $source );
			if ( ! $digest ) {
				throw new RuntimeException( 'Cannot fingerprint the source within resource limits.' );
			}
			$command = self::command( $binary, $source, $temp );
			if ( ! self::execute( $command, $source['file'], $temp, 45, $source['stat'], $lock ) || ! self::usable( $temp, $source['size'] ) ) {
				throw new RuntimeException( 'Encoder failed, exceeded limits, or did not produce a smaller MP4.' );
			}
			// Decode the entire bounded result, with no secondary-file/network protocol available.
			$validate = array( $binary, '-nostdin', '-v', 'error', '-progress', 'pipe:1', '-nostats', '-xerror', '-max_alloc', '67108864', '-threads', '1', '-protocol_whitelist', 'fd', '-format_whitelist', 'mov', '-f', 'mov', '-fd', '0', '-i', 'fd:', '-map', '0:v:0', '-an', '-threads', '1', '-f', 'null', '-' );
			if ( ! self::execute( $validate, $temp, $temp, 15, null, $lock ) ) {
				throw new RuntimeException( 'Generated MP4 did not pass decoding validation.' );
			}
			// Long-running workers must not compare against their request-local WP caches.
			clean_post_cache( $product_id );
			clean_post_cache( $source['id'] );
			self::invalidate( $name );
			$current_url = get_post_meta( $product_id, '_tvpg_video_url', true );
			$current     = self::source( $current_url, ! self::mapping( $current_url ) );
			if ( ! $current || $current['key'] !== $key || get_option( $name ) !== $state || ! self::is_live( $epoch ) || self::digest( $current ) !== $digest ) {
				self::forget( $name, $state );
				return;
			}
			if ( ! rename( $temp, $file ) ) {
				throw new RuntimeException( 'Cannot publish preview.' );
			}
			$temp = false;
			chmod( $file, 0644 );
			if ( self::save( $name, $state, 'ready', __( 'Automatic muted category preview is ready.', 'true-video-product-gallery' ), $attempts + 1 ) ) {
				if ( ! self::is_live( $epoch ) ) {
					self::forget( $name, $state );
					if ( is_file( $file ) ) {
						unlink( $file );
					}
					return;
				}
				self::prune( $source['id'], $name );
			} else {
				unlink( $file );
			}
		} catch ( Throwable $error ) {
			if ( 'ready' === ( $state['state'] ?? '' ) ) {
				return; // A cleanup failure must not invalidate an already published preview.
			}
			self::invalidate( $name );
			if ( get_option( $name ) !== $state ) {
				return; // Deactivation/deletion cancelled this worker while it was running.
			}
			$attempts = (int) ( $state['attempts'] ?? 1 );
			$next     = time() + 300 * ( 1 << min( $attempts, 3 ) );
			if ( self::save( $name, $state, $attempts < 3 ? 'retry' : 'failed', __( 'Using the original video. Preview generation failed: ', 'true-video-product-gallery' ) . $error->getMessage(), $attempts, $next ) && $attempts < 3 ) {
				self::reschedule( $name, $state, $product_id, $key );
			}
		} finally {
			if ( $temp && is_file( $temp ) ) {
				unlink( $temp );
			}
			flock( $lock, LOCK_UN );
			fclose( $lock );
		}
	}

	/** Persist human-readable status while retaining source ownership. */
	private static function save( $name, &$previous, $state, $message, $attempts, $next = 0 ) {
		$new = array_merge(
			$previous,
			array(
				'state'    => $state,
				'message'  => $message,
				'attempts' => $attempts,
				'next'     => $next,
			)
		);
		if ( ! self::change( $name, $previous, $new ) ) {
			return false;
		}
		$previous = $new;
		return true;
	}

	/** Retry deadlines and scheduled run times are identical; failures become visible terminal state. */
	private static function reschedule( $name, &$state, $id, $key ) {
		if ( ! self::schedule( $id, $key, $state['next'], $state['epoch'] ) ) {
			if ( ! self::is_live( $state['epoch'] ) ) {
				self::forget( $name, $state );
			} else {
				self::save( $name, $state, 'failed', __( 'Using the original: the background scheduler could not queue a retry. Save the video to retry.', 'true-video-product-gallery' ), $state['attempts'] );
			}
		}
	}

	/** Only trusted absolute executables; PATH entries writable by the web user are ignored. */
	private static function binary() {
		$candidates = defined( 'TVPG_FFMPEG_PATH' ) ? array( TVPG_FFMPEG_PATH ) : array( '/usr/bin/ffmpeg', '/usr/local/bin/ffmpeg' );
		if ( ! defined( 'TVPG_FFMPEG_PATH' ) ) {
			foreach ( explode( PATH_SEPARATOR, (string) getenv( 'PATH' ) ) as $directory ) {
				if ( '/' === substr( $directory, 0, 1 ) && ! is_writable( $directory ) ) {
					$candidates[] = rtrim( $directory, '/' ) . '/ffmpeg';
				}
			}
		}
		foreach ( $candidates as $candidate ) {
			if ( ! is_string( $candidate ) || '/' !== substr( $candidate, 0, 1 ) ) {
				continue;
			}
			$path = realpath( $candidate );
			if ( $path && is_file( $path ) && is_executable( $path ) && ( defined( 'TVPG_FFMPEG_PATH' ) || self::trusted_path( $path ) ) ) {
				return $path;
			}
		}
		return false;
	}

	/** Every ancestor must be protected against replacement by the web user. */
	private static function trusted_path( $path ) {
		do {
			if ( is_writable( $path ) ) {
				return false;
			}
			$parent = dirname( $path );
			if ( $parent === $path ) {
				return true;
			}
			$path = $parent;
		} while ( true );
	}

	/** Seekable fd-only input forbids both network protocols and secondary filesystem paths. */
	private static function command( $binary, $source, $temp ) {
		return array( $binary, '-nostdin', '-hide_banner', '-v', 'error', '-progress', 'pipe:1', '-nostats', '-y', '-xerror', '-max_alloc', '67108864', '-timelimit', '45', '-threads', '1', '-filter_threads', '1', '-probesize', '5242880', '-analyzeduration', '5000000', '-protocol_whitelist', 'fd', '-format_whitelist', $source['format'], '-f', $source['format'], '-fd', '0', '-i', 'fd:', '-map', '0:v:0', '-map_metadata', '-1', '-map_chapters', '-1', '-t', '8', '-an', '-sn', '-dn', '-vf', "scale=w='min(480,iw)':h='min(480,ih)':force_original_aspect_ratio=decrease:force_divisible_by=2", '-r', '24', '-c:v', 'libx264', '-preset', 'veryfast', '-crf', '28', '-threads', '1', '-pix_fmt', 'yuv420p', '-fs', (string) self::MAX_OUTPUT, '-movflags', '+faststart', '-protocol_whitelist', 'file', '-f', 'mp4', $temp );
	}

	/** Worker-only bounded content check catches even same-size, same-second source mutations. */
	private static function digest( $source ) {
		$stream = fopen( $source['file'], 'rb' );
		if ( ! $stream ) {
			return false;
		}
		try {
			$stat = fstat( $stream );
			foreach ( array( 'dev', 'ino', 'size', 'mtime', 'ctime' ) as $field ) {
				if ( $stat[ $field ] !== $source['stat'][ $field ] ) {
					return false;
				}
			}
			$context = hash_init( 'sha256' );
			$start   = microtime( true );
			$bytes   = 0;
			while ( ! feof( $stream ) ) {
				$data = fread( $stream, 1048576 );
				if ( false === $data || microtime( true ) - $start > 5 ) {
					return false;
				}
				$bytes += strlen( $data );
				if ( $bytes > self::MAX_INPUT ) {
					return false;
				}
				hash_update( $context, $data );
			}
			return $bytes === $source['size'] ? hash_final( $context ) : false;
		} finally {
			fclose( $stream );
		}
	}

	/** Array proc_open bypasses the shell. Stdin is an already-open verified source. */
	private static function execute( $command, $input, $output, $timeout, $expected = null, $lock = null, &$stdout = null, $require_frames = true ) {
		$expected = $expected ? $expected : stat( $input );
		$stream   = fopen( $input, 'rb' );
		if ( ! $stream ) {
			return false;
		}
		$actual = fstat( $stream );
		foreach ( array( 'dev', 'ino', 'size', 'mtime', 'ctime' ) as $field ) {
			if ( ! $expected || $actual[ $field ] !== $expected[ $field ] ) {
				fclose( $stream );
				return false;
			}
		}
		$pipes       = array();
		$descriptors = array(
			0 => $stream,
			1 => array( 'pipe', 'w' ),
			2 => array( 'pipe', 'w' ),
		);
		if ( $lock ) {
			$descriptors[3] = $lock; // Keep the global lock alive even if PHP is killed before its child.
		}
		$environment = array(
			'PATH' => '/usr/bin:/bin',
			'LANG' => 'C',
		);
		$process     = proc_open( $command, $descriptors, $pipes, null, $environment, array( 'bypass_shell' => true ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_proc_open -- Worker checks availability; argv bypasses the shell.
		fclose( $stream );
		if ( ! is_resource( $process ) ) {
			return false;
		}
		foreach ( $pipes as $pipe ) {
			stream_set_blocking( $pipe, false );
		}
		$start    = microtime( true );
		$ok       = false;
		$progress = '';
		try {
			do {
				foreach ( $pipes as $descriptor => $pipe ) {
					$data = fread( $pipe, 8192 );
					if ( 1 === $descriptor ) {
						$progress = substr( $progress . $data, -65536 );
					}
				}
				$status = proc_get_status( $process );
				clearstatcache( true, $output );
				if ( microtime( true ) - $start > $timeout || ( is_file( $output ) && filesize( $output ) > self::MAX_OUTPUT ) ) {
					proc_terminate( $process );
					usleep( 100000 );
					proc_terminate( $process, 9 );
					break;
				}
				if ( ! $status['running'] ) {
					$progress = substr( $progress . stream_get_contents( $pipes[1], 65536 ), -65536 );
					$ok       = 0 === $status['exitcode'];
					break;
				}
				usleep( 50000 );
			} while ( true );
		} finally {
			foreach ( $pipes as $pipe ) {
				fclose( $pipe );
			}
			proc_close( $process );
		}
		$stdout = $progress;
		return $ok && ( ! $require_frames || (bool) preg_match( '/(?:^|\n)frame=[1-9][0-9]*(?:\r?\n|$)/', $progress ) );
	}

	/** Derivative directory cannot be a symlink, nor can a destination be one. */
	private static function output( $source, $create = false ) {
		$uploads   = self::uploads();
		$root      = realpath( $uploads['basedir'] );
		$directory = $root ? $root . '/' . self::DIRECTORY : '';
		if ( ! $directory || is_link( $directory ) ) {
			return false;
		}
		if ( $create && ! is_dir( $directory ) ) {
			wp_mkdir_p( $directory );
		}
		if ( realpath( $directory ) !== $directory ) {
			return false;
		}
		$file = $directory . '/' . $source['id'] . '-' . $source['key'] . '.mp4';
		return is_link( $file ) ? false : $file;
	}

	/** Cheap cache check; new outputs additionally undergo a complete worker-side decode. */
	private static function usable( $file, $original_size ) {
		clearstatcache( true, $file );
		if ( ! is_file( $file ) || ! is_readable( $file ) || filesize( $file ) < 32 || filesize( $file ) >= $original_size || filesize( $file ) > self::MAX_OUTPUT ) {
			return false;
		}
		$handle = fopen( $file, 'rb' );
		$header = $handle ? fread( $handle, 12 ) : '';
		if ( $handle ) {
			fclose( $handle );
		}
		return 'ftyp' === substr( $header, 4, 4 );
	}

	/** Never recurse or follow links. Only our exact generated basename pattern is removed. */
	public static function delete_attachment( $attachment_id ) {
		$entries = get_option( self::PREFIX . 'index_' . (int) $attachment_id, array() );
		$removed = array();
		foreach ( $entries as $name => $unused ) {
			if ( self::remove_entry( (int) $attachment_id, $name ) ) {
				$removed[ $name ] = true;
			}
		}
		self::unindex( $attachment_id, $removed );
		self::$resolutions = array();
	}

	/** Prune superseded terminal derivatives while holding the global encoding lock. */
	private static function prune( $id, $keep, $superseded_ready = true ) {
		$entries = get_option( self::PREFIX . 'index_' . (int) $id, array() );
		$removed = array();
		foreach ( $entries as $name => $unused ) {
			if ( $name === $keep ) {
				continue;
			}
			if ( 0 === strpos( $name, self::PREFIX . 'map_' ) ) {
				if ( ! get_transient( $name ) ) {
					$removed[ $name ] = true;
				}
				continue;
			}
			$state    = get_option( $name, array() );
			$terminal = array( 'failed', 'unavailable', 'original' );
			if ( $superseded_ready ) {
				$terminal[] = 'ready';
			}
			if ( ! $state || in_array( $state['state'] ?? '', $terminal, true ) ) {
				if ( self::remove_entry( $id, $name, $state ) ) {
					$removed[ $name ] = true;
				}
			}
		}
		self::unindex( $id, $removed );
	}

	/** Remove exact indexed paths; no directory or options-table scan on attachment deletion. */
	private static function remove_entry( $id, $name, $expected = null ) {
		if ( preg_match( '/^tvpg_preview_map_[a-f0-9]{64}$/D', $name ) ) {
			$state = get_transient( $name );
			if ( (int) ( $state['id'] ?? 0 ) === $id ) {
				delete_transient( $name );
			}
			return true; // A URL reassigned to another attachment no longer belongs in this index.
		}
		$state = get_option( $name, array() );
		if ( null !== $expected && $expected !== $state ) {
			return false;
		}
		if ( ! preg_match( '/^tvpg_preview_([a-f0-9]{64})$/D', $name, $match ) || ( $state && (int) ( $state['attachment_id'] ?? 0 ) !== $id ) ) {
			return false;
		}
		if ( $state ) {
			$args = array( (int) $state['product_id'], $match[1] );
			if ( isset( $state['epoch'] ) ) {
				$args[] = $state['epoch'];
			}
			if ( ! self::forget( $name, $state ) ) {
				return false;
			}
			self::cancel_action( $args );
		}
		$file = self::output(
			array(
				'id'  => $id,
				'key' => $match[1],
			)
		);
		if ( $file && is_file( $file ) ) {
			unlink( $file );
		}
		return true;
	}

	/** CAS removal preserves entries concurrently added by another product save. */
	private static function unindex( $id, $removed ) {
		$name = self::PREFIX . 'index_' . (int) $id;
		for ( $attempt = 0; $removed && $attempt < 8; ++$attempt ) {
			self::invalidate( $name );
			$old = get_option( $name, false );
			if ( ! $old ) {
				return;
			}
			$next = array_diff_key( $old, $removed );
			if ( $next === $old || self::change( $name, $old, $next ? $next : false ) ) {
				return;
			}
		}
	}

	/** Remove abandoned worker temporary files after a conservative grace period. */
	private static function clean_abandoned_temps( $directory ) {
		// Called only under the global lock. A killed worker may have missed its finally block.
		foreach ( new DirectoryIterator( $directory ) as $entry ) {
			if ( ! $entry->isLink() && $entry->isFile() && preg_match( '/^tvpg-tmp-[A-Za-z0-9]+$/D', $entry->getFilename() ) && $entry->getMTime() < time() - 3600 ) {
				unlink( $entry->getPathname() );
			}
		}
	}

	/** Remove only strictly named derivatives belonging to this plugin. */
	private static function delete_files( $id = 0 ) {
		$root      = realpath( self::uploads()['basedir'] );
		$directory = $root . '/' . self::DIRECTORY;
		if ( ! $root || is_link( $directory ) || realpath( $directory ) !== $directory ) {
			return;
		}
		foreach ( new DirectoryIterator( $directory ) as $entry ) {
			if ( ! $id && ! $entry->isLink() && $entry->isFile() && preg_match( '/^tvpg-tmp-[A-Za-z0-9]+$/D', $entry->getFilename() ) ) {
				unlink( $entry->getPathname() );
				continue;
			}
			if ( $entry->isLink() || ! $entry->isFile() || ! preg_match( '/^([1-9][0-9]*)-([a-f0-9]{64})\.mp4$/D', $entry->getFilename(), $match ) || ( $id && (int) $match[1] !== $id ) ) {
				continue;
			}
			unlink( $entry->getPathname() );
			delete_option( self::PREFIX . $match[2] );
		}
	}

	/** Cancel jobs and discard statuses; keep successful derivatives on deactivation. */
	public static function deactivate() {
		// Disable before cancelling: claim INSERTs now fail, and late schedules self-cancel by epoch.
		update_option( self::LIFECYCLE, self::new_lifecycle( false ), false );
		self::invalidate( self::LIFECYCLE );
		wp_unschedule_hook( self::HOOK );
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( self::HOOK, null, self::GROUP );
		}
		global $wpdb;
		$names = $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( self::PREFIX ) . '%' ) );
		foreach ( $names as $name ) {
			if ( self::LIFECYCLE === $name || preg_match( '/^tvpg_preview_(?:index_|map_)/', $name ) ) {
				continue; // Retain lookup/index data alongside ready derivatives across reactivation.
			}
			$state = get_option( $name, array() );
			if ( 'ready' !== ( $state['state'] ?? '' ) ) {
				delete_option( $name );
			}
		}
	}

	/** Site-scoped uninstall; the caller handles multisite switching. */
	public static function uninstall() {
		self::deactivate();
		self::delete_files();
		global $wpdb;
		$names = $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( self::PREFIX ) . '%' ) );
		foreach ( $names as $name ) {
			if ( 0 === strpos( $name, self::PREFIX . 'index_' ) ) {
				foreach ( get_option( $name, array() ) as $entry => $unused ) {
					if ( 0 === strpos( $entry, self::PREFIX . 'map_' ) ) {
						delete_transient( $entry );
					}
				}
			}
			delete_option( $name );
		}
		// Include unindexed mappings in database storage. External-cache unindexed records expire within a day.
		foreach ( array( '_transient_', '_transient_timeout_' ) as $prefix ) {
			$names = $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( $prefix . self::PREFIX . 'map_' ) . '%' ) );
			foreach ( $names as $name ) {
				delete_option( $name );
			}
		}
		self::$resolutions = array();
	}
}
