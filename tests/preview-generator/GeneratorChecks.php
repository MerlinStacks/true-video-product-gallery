<?php
/** Run separately: vendor/bin/phpunit --no-configuration --bootstrap tests/preview-generator/support.php tests/preview-generator/GeneratorChecks.php */
use PHPUnit\Framework\TestCase;

final class GeneratorChecks extends TestCase {
	private $url = 'https://example.test/uploads/video.mp4';
	private $file;
	private static $fixture = 0;

	protected function setUp(): void {
		$GLOBALS['preview_options'] = $GLOBALS['preview_jobs'] = array();
		$GLOBALS['preview_cache'] = $GLOBALS['preview_sql'] = array();
		$GLOBALS['preview_lookups'] = $GLOBALS['preview_scans'] = 0;
		unset( $GLOBALS['preview_cache_refresh'], $GLOBALS['preview_before_query'], $GLOBALS['preview_schedule_fail'], $GLOBALS['preview_before_schedule'] );
		TVPG_Preview_Generator::activate();
		$memo = new ReflectionProperty( TVPG_Preview_Generator::class, 'resolutions' );
		$memo->setAccessible( true ); $memo->setValue( null, array() );
		$GLOBALS['preview_types'] = array( 1 => 'product', 2 => 'product', 10 => 'attachment' );
		$GLOBALS['preview_mimes'] = array( 10 => 'video/mp4' );
		$GLOBALS['preview_urls'] = array( 10 => $this->url );
		$GLOBALS['preview_meta'] = array( 1 => array( '_tvpg_video_url' => $this->url ), 2 => array( '_tvpg_video_url' => $this->url ) );
		wp_mkdir_p( wp_upload_dir()['basedir'] );
		$this->file = wp_upload_dir()['basedir'] . '/video.mp4';
		if ( is_link( $this->file ) ) { unlink( $this->file ); }
		file_put_contents( $this->file, str_repeat( 'v', 4096 + ++self::$fixture ) );
		$GLOBALS['preview_files'] = array( 10 => $this->file );
		$this->invoke( 'source', $this->url, true ); // Product-save resolution, outside rendering.
	}

	private function invoke( $method, ...$args ) {
		$reflection = new ReflectionMethod( TVPG_Preview_Generator::class, $method );
		$reflection->setAccessible( true );
		return $reflection->invokeArgs( null, $args );
	}

	private function queue() {
		$this->assertSame( $this->url, TVPG_Preview_Generator::get_preview_url( 1, $this->url ) );
		return $GLOBALS['preview_jobs'][0]['args'][1];
	}

	private function process( $id, $key ): void {
		$state = get_option( TVPG_Preview_Generator::PREFIX . $key, array() );
		TVPG_Preview_Generator::process( $id, $key, $state['epoch'] ?? '' );
	}

	private function reset_request_memo(): void {
		$memo = new ReflectionProperty( TVPG_Preview_Generator::class, 'resolutions' );
		$memo->setAccessible( true ); $memo->setValue( null, array() );
	}

	public static function cancellation_methods(): array {
		return array( 'deactivate' => array( 'deactivate' ), 'uninstall' => array( 'uninstall' ) );
	}

	/** @dataProvider cancellation_methods */
	public function test_resolver_handoff_cannot_requeue_after_cleanup_cancels_lifecycle( $method ): void {
		delete_transient( TVPG_Preview_Generator::PREFIX . 'map_' . hash( 'sha256', $this->url ) );
		$this->reset_request_memo();
		$this->queue();
		$args = $GLOBALS['preview_jobs'][0]['args'];
		$GLOBALS['preview_cache_refresh'] = function () use ( $method ) {
			unset( $GLOBALS['preview_cache_refresh'] );
			TVPG_Preview_Generator::$method();
			$this->assertSame( array(), $GLOBALS['preview_jobs'] );
		};
		TVPG_Preview_Generator::process( ...$args );
		$this->assertSame( array(), $GLOBALS['preview_jobs'], 'The resolver must not enqueue after cancellation in clean_post_cache.' );
		$this->assertSame( array(), array_filter( array_keys( $GLOBALS['preview_options'] ), function ( $name ) { return (bool) preg_match( '/^tvpg_preview_[a-f0-9]{64}$/D', $name ); } ) );
		if ( 'uninstall' === $method ) {
			$this->assertSame( array(), $GLOBALS['preview_options'], 'An old resolver must not recreate uninstalled lifecycle data.' );
		} else {
			$this->assertFalse( get_option( TVPG_Preview_Generator::LIFECYCLE )['enabled'] );
		}
	}

	public function test_cancellation_after_handoff_check_is_atomic_with_claim_insertion(): void {
		delete_transient( TVPG_Preview_Generator::PREFIX . 'map_' . hash( 'sha256', $this->url ) );
		$this->reset_request_memo();
		$this->queue();
		$args = $GLOBALS['preview_jobs'][0]['args'];
		$GLOBALS['preview_cache_refresh'] = function () {
			unset( $GLOBALS['preview_cache_refresh'] );
			$GLOBALS['preview_before_query'] = function ( $sql ) {
				$this->assertStringContainsString( 'SELECT %s, %s', $sql );
				TVPG_Preview_Generator::deactivate();
			};
		};
		TVPG_Preview_Generator::process( ...$args );
		$this->assertSame( array(), $GLOBALS['preview_jobs'] );
		$source = $this->invoke( 'source', $this->url );
		$this->assertFalse( get_option( TVPG_Preview_Generator::PREFIX . $source['key'] ) );
	}

	/** @dataProvider cancellation_methods */
	public function test_late_cron_insert_is_cancelled_after_lifecycle_cleanup( $method ): void {
		$source = $this->invoke( 'source', $this->url );
		$GLOBALS['preview_before_schedule'] = function () use ( $method ) {
			TVPG_Preview_Generator::$method();
			$this->assertSame( array(), $GLOBALS['preview_jobs'] );
		};
		TVPG_Preview_Generator::get_preview_url( 1, $this->url );
		$this->assertSame( array(), $GLOBALS['preview_jobs'], 'Even a scheduler insert after global unscheduling must be removed.' );
		$this->assertFalse( get_option( TVPG_Preview_Generator::PREFIX . $source['key'] ) );
		if ( 'uninstall' === $method ) {
			$this->assertSame( array(), $GLOBALS['preview_options'] );
			$this->assertSame( $this->url, TVPG_Preview_Generator::get_preview_url( 1, $this->url ) );
			$this->assertSame( array(), $GLOBALS['preview_options'], 'Public lookup must not bootstrap a removed lifecycle.' );
		}
	}

	public function test_init_bootstraps_existing_installations_but_cannot_reenable_deactivation(): void {
		delete_option( TVPG_Preview_Generator::LIFECYCLE );
		TVPG_Preview_Generator::init();
		$this->assertTrue( get_option( TVPG_Preview_Generator::LIFECYCLE )['enabled'] );
		$this->assertSame( 3, $GLOBALS['preview_hooks'][ TVPG_Preview_Generator::HOOK ]['accepted'] );
		TVPG_Preview_Generator::deactivate();
		TVPG_Preview_Generator::init();
		$this->assertFalse( get_option( TVPG_Preview_Generator::LIFECYCLE )['enabled'] );
		TVPG_Preview_Generator::get_preview_url( 1, $this->url );
		$this->assertSame( array(), $GLOBALS['preview_jobs'] );
		TVPG_Preview_Generator::activate();
		$this->queue();
		$this->assertCount( 1, $GLOBALS['preview_jobs'] );
	}

	public function test_untokened_legacy_action_cannot_adopt_a_current_claim(): void {
		$key = $this->queue();
		$name = TVPG_Preview_Generator::PREFIX . $key;
		$state = get_option( $name );
		TVPG_Preview_Generator::process( 1, $key );
		$this->assertSame( $state, get_option( $name ) );
	}

	public function test_old_scheduler_cleanup_cannot_cancel_work_from_reactivation(): void {
		$old_args = array();
		$GLOBALS['preview_before_schedule'] = function ( $args ) use ( &$old_args ) {
			$old_args = $args;
			TVPG_Preview_Generator::deactivate();
			TVPG_Preview_Generator::activate();
			TVPG_Preview_Generator::get_preview_url( 1, $this->url );
		};
		TVPG_Preview_Generator::get_preview_url( 1, $this->url );
		$this->assertCount( 1, $GLOBALS['preview_jobs'] );
		$new_args = array_values( $GLOBALS['preview_jobs'] )[0]['args'];
		$this->assertNotSame( $old_args[2], $new_args[2] );
		$name = TVPG_Preview_Generator::PREFIX . $new_args[1];
		$state = get_option( $name );
		$this->assertSame( $new_args[2], $state['epoch'] );
		TVPG_Preview_Generator::process( ...$old_args );
		$this->assertSame( $state, get_option( $name ), 'An old-token action must not take over the fresh claim.' );
		TVPG_Preview_Generator::process( ...$new_args );
		$this->assertSame( 'ready', get_option( $name )['state'] );
	}

	public function test_late_action_scheduler_insert_is_cancelled_by_exact_epoch(): void {
		$result = $this->subprocess(
			'function as_unschedule_all_actions($hook, $args = null, $group = "") { $GLOBALS["as_jobs"] = $args === null ? array() : array_filter($GLOBALS["as_jobs"], function($job) use ($args) { return $job !== $args; }); }'
			. 'function as_schedule_single_action($time, $hook, $args, $group) { TVPG_Preview_Generator::deactivate(); $GLOBALS["as_jobs"][] = $args; return 123; }'
			. '$GLOBALS["as_jobs"] = array(); TVPG_Preview_Generator::get_preview_url(1, $url); print json_encode(array_values($GLOBALS["as_jobs"]));'
		);
		$this->assertSame( array(), $result );
	}

	public function test_cold_archive_resolution_runs_only_in_worker_and_is_shared(): void {
		delete_transient( TVPG_Preview_Generator::PREFIX . 'map_' . hash( 'sha256', $this->url ) );
		$this->reset_request_memo();
		$GLOBALS['preview_lookups'] = 0;
		for ( $i = 0; $i < 20; ++$i ) {
			TVPG_Preview_Generator::get_preview_url( 1 + ( $i % 2 ), $this->url );
		}
		$this->assertSame( 0, $GLOBALS['preview_lookups'] );
		$this->assertCount( 1, $GLOBALS['preview_jobs'] );
		TVPG_Preview_Generator::process( ...$GLOBALS['preview_jobs'][0]['args'] );
		$this->assertSame( 1, $GLOBALS['preview_lookups'] );
		$this->assertCount( 2, $GLOBALS['preview_jobs'] );
		TVPG_Preview_Generator::process( ...$GLOBALS['preview_jobs'][1]['args'] );
		$this->reset_request_memo();
		$this->assertStringContainsString( '/tvpg-previews/', TVPG_Preview_Generator::get_preview_url( 2, $this->url ) );
		$this->assertSame( 1, $GLOBALS['preview_lookups'], 'Persisted resolution must avoid lookup on later requests too.' );
		$GLOBALS['preview_files'][10] = $GLOBALS['preview_root'] . '/outside.mp4';
		file_put_contents( $GLOBALS['preview_files'][10], 'not an upload' );
		$this->assertSame( $this->url, TVPG_Preview_Generator::get_preview_url( 1, $this->url ), 'Cached attachment IDs still require fresh path validation.' );
	}

	public function test_negative_resolution_is_cached_and_expiry_allows_worker_retry(): void {
		$url = 'https://example.test/uploads/missing.mp4';
		$GLOBALS['preview_meta'][1]['_tvpg_video_url'] = $url;
		$GLOBALS['preview_lookups'] = 0;
		TVPG_Preview_Generator::get_preview_url( 1, $url );
		TVPG_Preview_Generator::process( ...$GLOBALS['preview_jobs'][0]['args'] );
		$this->reset_request_memo();
		for ( $i = 0; $i < 10; ++$i ) { TVPG_Preview_Generator::get_preview_url( 1, $url ); }
		$this->assertSame( 1, $GLOBALS['preview_lookups'] );
		$this->assertCount( 1, $GLOBALS['preview_jobs'] );
		$name = TVPG_Preview_Generator::PREFIX . 'map_' . hash( 'sha256', $url );
		set_transient( $name, array( 'id' => 0, 'expires' => time() - 1 ), 3600 );
		$this->reset_request_memo();
		TVPG_Preview_Generator::get_preview_url( 1, $url );
		$this->assertCount( 2, $GLOBALS['preview_jobs'] );
		$this->assertSame( 1, $GLOBALS['preview_lookups'] );
		TVPG_Preview_Generator::process( ...$GLOBALS['preview_jobs'][1]['args'] );
		$this->assertSame( 2, $GLOBALS['preview_lookups'] );
	}

	public function test_insert_claim_cannot_overwrite_racing_row_and_invalidates_negative_cache(): void {
		$source = $this->invoke( 'source', $this->url );
		$name = TVPG_Preview_Generator::PREFIX . $source['key'];
		$this->assertFalse( get_option( $name ) );
		$winner = array( 'state' => 'processing', 'next' => time() + 600, 'attempts' => 2 );
		$GLOBALS['preview_before_query'] = function ( $sql ) use ( $name, $winner ) {
			$this->assertStringStartsWith( 'INSERT IGNORE', $sql );
			$GLOBALS['preview_options'][ $name ] = $winner;
		};
		TVPG_Preview_Generator::get_preview_url( 1, $this->url );
		$this->assertSame( $winner, get_option( $name ) );
		$this->assertCount( 0, $GLOBALS['preview_jobs'] );
		$GLOBALS['preview_cache']['alloptions'][ $name ] = array( 'state' => 'stale' );
		$this->assertTrue( $this->invoke( 'change', $name, $winner, array( 'state' => 'ready' ) ) );
		$this->assertSame( array( 'state' => 'ready' ), get_option( $name ) );
		$this->assertFalse( $this->invoke( 'change', $name, $winner, array( 'state' => 'failed' ) ) );
	}

	public function test_lock_busy_cas_does_not_overwrite_a_new_processing_claim(): void {
		$key = $this->queue();
		$name = TVPG_Preview_Generator::PREFIX . $key;
		$winner = get_option( $name ); $winner['state'] = 'processing';
		$lock = $this->invoke( 'lock' );
		try {
			$GLOBALS['preview_before_query'] = function ( $sql ) use ( $name, $winner ) {
				$this->assertStringStartsWith( 'UPDATE', $sql );
				$GLOBALS['preview_options'][ $name ] = $winner;
			};
			$this->process( 1, $key );
			$this->assertSame( $winner, get_option( $name ) );
			$this->assertCount( 1, $GLOBALS['preview_jobs'] );
		} finally { flock( $lock, LOCK_UN ); fclose( $lock ); }
	}

	public function test_old_product_action_cannot_cancel_a_reassigned_source_claim(): void {
		$key = $this->queue();
		$name = TVPG_Preview_Generator::PREFIX . $key;
		$state = get_option( $name ); $state['next'] = time() - 1; update_option( $name, $state );
		TVPG_Preview_Generator::get_preview_url( 2, $this->url );
		$replacement = get_option( $name );
		$this->assertSame( 2, $replacement['product_id'] );
		$GLOBALS['preview_meta'][1]['_tvpg_video_url'] = 'https://remote.test/other.mp4';
		$this->process( 1, $key );
		$this->assertSame( $replacement, get_option( $name ) );
		$this->process( 2, $key );
		$this->assertSame( 'ready', get_option( $name )['state'] );
	}

	public function test_delayed_worker_refreshes_expired_mapping_without_a_render(): void {
		$key = $this->queue();
		$name = TVPG_Preview_Generator::PREFIX . 'map_' . hash( 'sha256', $this->url );
		set_transient( $name, array( 'id' => 10, 'expires' => time() - 1 ), 3600 );
		$this->reset_request_memo(); $GLOBALS['preview_lookups'] = 0;
		$this->process( 1, $key );
		$this->assertSame( 1, $GLOBALS['preview_lookups'] );
		$this->assertSame( 'ready', TVPG_Preview_Generator::get_status( 1 )['state'] );
	}

	public function test_pruning_cannot_delete_a_racing_new_queue_state(): void {
		$key = $this->queue();
		$this->process( 1, $key );
		$name = TVPG_Preview_Generator::PREFIX . $key;
		$replacement = get_option( $name ); $replacement['state'] = 'queued';
		$GLOBALS['preview_before_query'] = function ( $sql ) use ( $name, $replacement ) {
			$this->assertStringStartsWith( 'DELETE', $sql );
			$GLOBALS['preview_options'][ $name ] = $replacement;
		};
		$this->invoke( 'prune', 10, 'another-source' );
		$this->assertSame( $replacement, get_option( $name ) );
		$this->assertArrayHasKey( $name, get_option( TVPG_Preview_Generator::PREFIX . 'index_10' ) );
		$this->assertFileExists( wp_upload_dir()['basedir'] . '/tvpg-previews/10-' . $key . '.mp4' );
	}

	public function test_retry_lock_contention_resumes_on_scheduled_action_without_render(): void {
		$key = $this->queue();
		$name = TVPG_Preview_Generator::PREFIX . $key;
		$state = get_option( $name ); $state['state'] = 'retry'; $state['next'] = time() - 1; $state['attempts'] = 1;
		update_option( $name, $state );
		$lock = $this->invoke( 'lock' );
		$this->process( 1, $key );
		flock( $lock, LOCK_UN ); fclose( $lock );
		$state = get_option( $name );
		$this->assertSame( $GLOBALS['preview_jobs'][1]['time'], $state['next'] );
		TVPG_Preview_Generator::process( ...$GLOBALS['preview_jobs'][1]['args'] );
		$this->assertCount( 2, $GLOBALS['preview_jobs'], 'An early duplicate must not reschedule.' );
		$state['next'] = time() - 1; update_option( $name, $state );
		TVPG_Preview_Generator::process( ...$GLOBALS['preview_jobs'][1]['args'] );
		$this->assertSame( 'ready', get_option( $name )['state'] );
		$this->assertSame( 2, get_option( $name )['attempts'] );
	}

	public function test_scheduler_failure_is_visible_for_initial_queue_and_lock_retry(): void {
		$GLOBALS['preview_schedule_fail'] = true;
		TVPG_Preview_Generator::get_preview_url( 1, $this->url );
		$this->assertSame( 'failed', TVPG_Preview_Generator::get_status( 1 )['state'] );
		$GLOBALS['preview_schedule_fail'] = false;
		TVPG_Preview_Generator::product_saved( 1 );
		$key = $GLOBALS['preview_jobs'][0]['args'][1];
		$GLOBALS['preview_schedule_fail'] = true;
		$lock = $this->invoke( 'lock' );
		try { $this->process( 1, $key ); }
		finally { flock( $lock, LOCK_UN ); fclose( $lock ); }
		$this->assertSame( 'failed', TVPG_Preview_Generator::get_status( 1 )['state'] );
		$this->assertStringContainsString( 'scheduler', TVPG_Preview_Generator::get_status( 1 )['message'] );
	}

	public function test_no_fd_capability_is_explanatory_and_never_uses_unsafe_fallback(): void {
		file_put_contents( $this->file, 'NOFD' . str_repeat( 'v', 6000 ) );
		$key = $this->queue();
		$this->process( 1, $key );
		$this->assertSame( 'unavailable', TVPG_Preview_Generator::get_status( 1 )['state'] );
		$this->assertStringContainsString( 'fd input protocol', TVPG_Preview_Generator::get_status( 1 )['message'] );
		$this->assertFileDoesNotExist( wp_upload_dir()['basedir'] . '/tvpg-previews/10-' . $key . '.mp4' );
	}

	public function test_success_prunes_old_attachment_derivatives_and_image_deletion_does_not_scan(): void {
		$old = $this->queue();
		$this->process( 1, $old );
		$directory = wp_upload_dir()['basedir'] . '/tvpg-previews/';
		file_put_contents( $directory . '11-' . $old . '.mp4', 'another attachment' );
		file_put_contents( $this->file, str_repeat( 'v', 7000 ) );
		$GLOBALS['preview_jobs'] = array();
		$new = $this->queue();
		$this->process( 1, $new );
		$this->assertFileDoesNotExist( $directory . '10-' . $old . '.mp4' );
		$this->assertFileExists( $directory . '10-' . $new . '.mp4' );
		$this->assertFileExists( $directory . '11-' . $old . '.mp4' );
		$this->assertFalse( get_option( TVPG_Preview_Generator::PREFIX . $old ) );
		$before = $GLOBALS['preview_options'];
		TVPG_Preview_Generator::delete_attachment( 999 );
		$this->assertSame( 0, $GLOBALS['preview_scans'] );
		$this->assertSame( $before, $GLOBALS['preview_options'] );
		TVPG_Preview_Generator::delete_attachment( 10 );
		$this->assertSame( 0, $GLOBALS['preview_scans'] );
		$this->assertFalse( get_option( TVPG_Preview_Generator::PREFIX . 'index_10' ) );
		$this->assertFalse( get_transient( TVPG_Preview_Generator::PREFIX . 'map_' . hash( 'sha256', $this->url ) ) );
	}

	private function subprocess( $code, $disabled = '' ) {
		$bootstrap = 'require ' . var_export( __DIR__ . '/support.php', true ) . ';'
			. '$GLOBALS["preview_options"] = $GLOBALS["preview_jobs"] = array(); TVPG_Preview_Generator::activate();'
			. '$GLOBALS["preview_types"] = array(1 => "product", 10 => "attachment");'
			. '$GLOBALS["preview_mimes"] = array(10 => "video/mp4");'
			. '$url = "https://example.test/uploads/video.mp4";'
			. '$GLOBALS["preview_urls"] = array(10 => $url);'
			. '$GLOBALS["preview_meta"] = array(1 => array("_tvpg_video_url" => $url));'
			. 'wp_mkdir_p(wp_upload_dir()["basedir"]);'
			. '$file = wp_upload_dir()["basedir"] . "/video.mp4"; file_put_contents($file, str_repeat("v", 4096));'
			. '$GLOBALS["preview_files"] = array(10 => $file);'
			. '$method = new ReflectionMethod(TVPG_Preview_Generator::class, "source"); $method->setAccessible(true); $method->invoke(null, $url, true);';
		$process = proc_open( array( PHP_BINARY, '-d', 'disable_functions=' . $disabled, '-r', $bootstrap . $code ), array( 0 => array( 'pipe', 'r' ), 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes );
		fclose( $pipes[0] );
		$output = stream_get_contents( $pipes[1] );
		$error = stream_get_contents( $pipes[2] );
		fclose( $pipes[1] );
		fclose( $pipes[2] );
		$this->assertSame( 0, proc_close( $process ), $error );
		return json_decode( $output, true );
	}

	public function test_disabled_proc_open_falls_back_without_fatal_error(): void {
		$result = $this->subprocess( 'TVPG_Preview_Generator::get_preview_url(1, $url); TVPG_Preview_Generator::process(...$GLOBALS["preview_jobs"][0]["args"]); print json_encode(TVPG_Preview_Generator::get_status(1));', 'proc_open' );
		$this->assertSame( 'unavailable', $result['state'] );
		$this->assertStringContainsString( 'proc_open', $result['message'] );
	}

	public function test_action_scheduler_is_preferred_when_available(): void {
		$result = $this->subprocess( 'function as_schedule_single_action($time, $hook, $args, $group) { $GLOBALS["as_job"] = compact("time", "hook", "args", "group"); return 123; } TVPG_Preview_Generator::get_preview_url(1, $url); TVPG_Preview_Generator::get_preview_url(1, $url); print json_encode(array($GLOBALS["as_job"], $GLOBALS["preview_jobs"]));' );
		$this->assertSame( 'tvpg-previews', $result[0]['group'] );
		$this->assertSame( 'tvpg_generate_preview', $result[0]['hook'] );
		$this->assertSame( 1, $result[0]['args'][0] );
		$this->assertSame( array(), $result[1] );
	}

	public function test_invalid_and_remote_inputs_never_queue(): void {
		foreach ( array( '', 'https://youtube.com/watch?v=test', 'https://remote.test/video.mp4', 'https://example.test/uploads/%2e%2e/secret.mp4', $this->url . '?x=1', $this->url . "\0" ) as $url ) {
			$GLOBALS['preview_meta'][1]['_tvpg_video_url'] = $url;
			$this->assertSame( $url, TVPG_Preview_Generator::get_preview_url( 1, $url ) );
		}
		$this->assertSame( '', TVPG_Preview_Generator::get_preview_url( 1, array() ) );
		$GLOBALS['preview_meta'][1]['_tvpg_video_url'] = array( 'invalid' );
		$this->assertSame( 'original', TVPG_Preview_Generator::get_status( 1 )['state'] );
		$this->assertSame( $this->url, TVPG_Preview_Generator::get_preview_url( -1, $this->url ) );
		$this->assertSame( array(), $GLOBALS['preview_jobs'] );
	}

	public function test_attachment_and_physical_containment_are_required(): void {
		$GLOBALS['preview_mimes'][10] = 'image/jpeg';
		$this->assertFalse( $this->invoke( 'source', $this->url ) );
		$GLOBALS['preview_mimes'][10] = 'video/mp4';
		$outside = $GLOBALS['preview_root'] . '/outside.mp4';
		file_put_contents( $outside, 'private file' );
		unlink( $this->file );
		symlink( $outside, $this->file );
		$this->assertFalse( $this->invoke( 'source', $this->url ) );
		TVPG_Preview_Generator::get_preview_url( 1, $this->url );
		$this->assertSame( array(), $GLOBALS['preview_jobs'] );
	}

	public function test_duplicate_guard_and_shared_derivative_cache(): void {
		$key = $this->queue();
		TVPG_Preview_Generator::get_preview_url( 1, $this->url );
		TVPG_Preview_Generator::get_preview_url( 2, $this->url );
		$this->assertCount( 1, $GLOBALS['preview_jobs'] );
		$this->process( 1, $key );
		$this->assertSame( 'ready', TVPG_Preview_Generator::get_status( 1 )['state'] );
		$preview = TVPG_Preview_Generator::get_preview_url( 2, $this->url );
		$this->assertStringContainsString( '/tvpg-previews/10-' . $key . '.mp4', $preview );
		$this->assertSame( $this->url, get_post_meta( 1, '_tvpg_video_url', true ) );
		$this->assertCount( 1, $GLOBALS['preview_jobs'] );
	}

	public function test_archive_selection_ignores_manual_preview_and_uses_generated_url(): void {
		$GLOBALS['preview_meta'][1]['_tvpg_archive_video_url'] = 'https://remote.test/manual.mp4';
		$frontend = ( new ReflectionClass( TVPG_Frontend::class ) )->newInstanceWithoutConstructor();
		$method = new ReflectionMethod( TVPG_Frontend::class, 'get_archive_video_url' );
		$method->setAccessible( true );
		$product = new class() {
			public function get_id() { return 1; }
		};
		$this->assertSame( $this->url, $method->invoke( $frontend, $product ) );
		TVPG_Preview_Generator::process( ...$GLOBALS['preview_jobs'][0]['args'] );
		$this->assertStringContainsString( '/tvpg-previews/', $method->invoke( $frontend, $product ) );
		$this->assertSame( $this->url, get_post_meta( 1, '_tvpg_video_url' ) );
	}

	public function test_global_lock_is_atomic_and_released(): void {
		$key = $this->queue();
		$lock = $this->invoke( 'lock' );
		$this->assertIsResource( $lock );
		$this->assertFalse( $this->invoke( 'lock' ) );
		$this->process( 1, $key );
		$this->assertSame( 'retry', TVPG_Preview_Generator::get_status( 1 )['state'] );
		flock( $lock, LOCK_UN );
		fclose( $lock );
		$state = get_option( TVPG_Preview_Generator::PREFIX . $key );
		$this->assertSame( $state['next'], $GLOBALS['preview_jobs'][1]['time'] );
		$state['next'] = time() - 1;
		update_option( TVPG_Preview_Generator::PREFIX . $key, $state );
		$this->process( 1, $key );
		$this->assertSame( 'ready', TVPG_Preview_Generator::get_status( 1 )['state'] );
	}

	public function test_source_changes_before_and_during_job_cannot_publish_stale_output(): void {
		$key = $this->queue();
		$GLOBALS['preview_meta'][1]['_tvpg_video_url'] = 'https://remote.test/new.mp4';
		$this->process( 1, $key );
		$this->assertSame( 'original', TVPG_Preview_Generator::get_status( 1 )['state'] );
		$GLOBALS['preview_meta'][1]['_tvpg_video_url'] = $this->url;
		file_put_contents( $this->file, 'CHANGE:' . $this->file . "\n" . str_repeat( 'v', 5000 ) );
		$GLOBALS['preview_jobs'] = array();
		$key = $this->queue();
		$this->process( 1, $key );
		$this->assertNotSame( 'ready', get_option( TVPG_Preview_Generator::PREFIX . $key, array() )['state'] ?? '' );
		$this->assertFileDoesNotExist( wp_upload_dir()['basedir'] . '/tvpg-previews/10-' . $key . '.mp4' );
	}

	public function test_fresh_product_meta_is_checked_after_encoding(): void {
		$key = $this->queue();
		$GLOBALS['preview_cache_refresh'] = function ( $id ) {
			if ( 1 === $id ) { $GLOBALS['preview_meta'][1]['_tvpg_video_url'] = 'https://remote.test/replacement.mp4'; }
		};
		$this->process( 1, $key );
		$this->assertFalse( get_option( TVPG_Preview_Generator::PREFIX . $key ) );
		$this->assertFileDoesNotExist( wp_upload_dir()['basedir'] . '/tvpg-previews/10-' . $key . '.mp4' );
	}

	public function test_same_size_source_mutation_during_encoding_is_detected_by_digest(): void {
		file_put_contents( $this->file, 'SAME:' . $this->file . "\n" . str_repeat( 'v', 6000 ) );
		$key = $this->queue();
		$this->process( 1, $key );
		$this->assertFalse( get_option( TVPG_Preview_Generator::PREFIX . $key ) );
		$this->assertFileDoesNotExist( wp_upload_dir()['basedir'] . '/tvpg-previews/10-' . $key . '.mp4' );
	}

	public function test_expired_queue_recovery_and_compare_delete_guard(): void {
		$key = $this->queue();
		$name = TVPG_Preview_Generator::PREFIX . $key;
		$old = get_option( $name );
		$expired = $old;
		$expired['next'] = time() - 1;
		update_option( $name, $expired );
		$this->assertFalse( $this->invoke( 'forget', $name, $old ) );
		$this->assertSame( $expired, get_option( $name ) );
		TVPG_Preview_Generator::get_preview_url( 2, $this->url );
		$this->assertCount( 2, $GLOBALS['preview_jobs'] );
		$this->assertSame( 2, get_option( $name )['product_id'] );
	}

	public function test_failure_backoff_and_retry_cap(): void {
		file_put_contents( $this->file, 'FAIL' . str_repeat( 'x', 5000 ) );
		$key = $this->queue();
		$name = TVPG_Preview_Generator::PREFIX . $key;
		for ( $attempt = 1; $attempt <= 3; ++$attempt ) {
			$this->process( 1, $key );
			$state = get_option( $name );
			$this->assertSame( $attempt, $state['attempts'] );
			$this->process( 1, $key );
			$this->assertSame( $attempt, get_option( $name )['attempts'] );
			$state['next'] = time() - 1;
			update_option( $name, $state );
		}
		$this->assertSame( 'failed', get_option( $name )['state'] );
		$count = count( $GLOBALS['preview_jobs'] );
		TVPG_Preview_Generator::get_preview_url( 1, $this->url );
		$this->assertCount( $count, $GLOBALS['preview_jobs'] );
	}

	public function test_missing_binary_has_clear_status_and_original_fallback(): void {
		$key = $this->queue();
		rename( TVPG_FFMPEG_PATH, TVPG_FFMPEG_PATH . '.disabled' );
		try {
			$this->process( 1, $key );
			$this->assertSame( 'unavailable', TVPG_Preview_Generator::get_status( 1 )['state'] );
			$this->assertSame( $this->url, TVPG_Preview_Generator::get_preview_url( 1, $this->url ) );
		} finally {
			rename( TVPG_FFMPEG_PATH . '.disabled', TVPG_FFMPEG_PATH );
		}
	}

	public function test_timeout_terminates_child(): void {
		file_put_contents( $this->file, 'SLEEP' );
		$source = $this->invoke( 'source', $this->url );
		$output = $GLOBALS['preview_root'] . '/timeout.mp4';
		$start = microtime( true );
		$this->assertFalse( $this->invoke( 'execute', $this->invoke( 'command', TVPG_FFMPEG_PATH, $source, $output ), $this->file, $output, 0.1 ) );
		$this->assertLessThan( 2, microtime( true ) - $start );
	}

	public function test_larger_empty_and_invalid_outputs_are_never_published(): void {
		foreach ( array( str_repeat( 'x', 64 ), 'EMPTY' . str_repeat( 'x', 5000 ), 'INVALID' . str_repeat( 'x', 5000 ) ) as $input ) {
			file_put_contents( $this->file, $input );
			$GLOBALS['preview_jobs'] = array();
			$key = $this->queue();
			$this->process( 1, $key );
			$this->assertSame( 'retry', TVPG_Preview_Generator::get_status( 1 )['state'] );
			$this->assertFileDoesNotExist( wp_upload_dir()['basedir'] . '/tvpg-previews/10-' . $key . '.mp4' );
			$this->assertSame( array(), glob( wp_upload_dir()['basedir'] . '/tvpg-previews/tvpg-tmp-*' ) );
		}
	}

	public function test_open_descriptor_must_match_the_verified_source(): void {
		$source = $this->invoke( 'source', $this->url );
		$replacement = $GLOBALS['preview_root'] . '/replacement.mp4';
		file_put_contents( $replacement, str_repeat( 'r', $source['size'] ) );
		$output = $GLOBALS['preview_root'] . '/mismatch.mp4';
		$this->assertFalse( $this->invoke( 'execute', $this->invoke( 'command', TVPG_FFMPEG_PATH, $source, $output ), $replacement, $output, 1, $source['stat'] ) );
		$this->assertFileDoesNotExist( $output );
	}

	public function test_derivative_directory_symlink_is_rejected(): void {
		$source = $this->invoke( 'source', $this->url );
		$directory = wp_upload_dir()['basedir'] . '/tvpg-previews';
		wp_mkdir_p( $directory );
		rename( $directory, $directory . '.saved' );
		symlink( $GLOBALS['preview_root'], $directory );
		try {
			$this->assertFalse( $this->invoke( 'output', $source, true ) );
		} finally {
			unlink( $directory );
			rename( $directory . '.saved', $directory );
		}
	}

	public function test_cleanup_only_removes_owned_files_and_queue_state(): void {
		$key = $this->queue();
		$this->process( 1, $key );
		$directory = wp_upload_dir()['basedir'] . '/tvpg-previews';
		file_put_contents( $directory . '/keep.mp4', 'not ours' );
		TVPG_Preview_Generator::delete_attachment( 10 );
		$this->assertFileExists( $this->file );
		$this->assertFileExists( $directory . '/keep.mp4' );
		$this->assertFileDoesNotExist( $directory . '/10-' . $key . '.mp4' );
		$this->assertFalse( get_option( TVPG_Preview_Generator::PREFIX . $key ) );
		TVPG_Preview_Generator::uninstall();
		$this->assertSame( array(), $GLOBALS['preview_options'] );
		$this->assertSame( array(), $GLOBALS['preview_jobs'] );
	}

	public static function real_video_profiles(): array {
		return array( 'faststart' => array( true, 640, 360 ), 'end-moov' => array( false, 640, 360 ), 'small-no-upscale' => array( false, 160, 90 ) );
	}

	/** @dataProvider real_video_profiles */
	public function test_real_encoder_smoke_when_available( $faststart, $width, $height ): void {
		$binary = getenv( 'TVPG_TEST_REAL_FFMPEG' );
		if ( ! $binary || ! is_executable( $binary ) ) {
			$this->markTestSkipped( 'FFmpeg unavailable; set TVPG_TEST_REAL_FFMPEG to a trusted absolute binary to run the codec smoke test.' );
		}
		$process = proc_open( array( $binary, '-v', 'error', '-y', '-f', 'lavfi', '-i', "testsrc2=size={$width}x{$height}:rate=24", '-f', 'lavfi', '-i', 'sine=frequency=440', '-t', '10', '-c:v', 'libx264', '-c:a', 'aac', '-crf', '12', '-threads', '1', '-movflags', $faststart ? '+faststart' : '0', $this->file ), array( 0 => array( 'pipe', 'r' ), 1 => array( 'file', '/dev/null', 'w' ), 2 => array( 'file', '/dev/null', 'w' ) ), $pipes );
		fclose( $pipes[0] );
		$this->assertSame( 0, proc_close( $process ) );
		$input = file_get_contents( $this->file );
		$this->assertSame( $faststart, strpos( $input, 'moov' ) < strpos( $input, 'mdat' ), 'Fixture must actually have the requested atom order.' );
		$key = $this->queue();
		rename( TVPG_FFMPEG_PATH, TVPG_FFMPEG_PATH . '.fixture' );
		symlink( $binary, TVPG_FFMPEG_PATH );
		try {
			$this->process( 1, $key );
			$this->assertSame( 'ready', TVPG_Preview_Generator::get_status( 1 )['state'] );
			$this->assertNotSame( $this->url, TVPG_Preview_Generator::get_preview_url( 1, $this->url ) );
			$output = wp_upload_dir()['basedir'] . '/tvpg-previews/10-' . $key . '.mp4';
			$this->assertLessThan( strlen( $input ), filesize( $output ) );
			$bytes = file_get_contents( $output );
			$this->assertLessThan( strpos( $bytes, 'mdat' ), strpos( $bytes, 'moov' ) );
			$probe = dirname( $binary ) . '/ffprobe';
			$this->assertTrue( is_executable( $probe ), 'Real smoke verification requires ffprobe beside ffmpeg.' );
			$process = proc_open( array( $probe, '-v', 'error', '-show_streams', '-show_format', '-of', 'json', $output ), array( 0 => array( 'file', '/dev/null', 'r' ), 1 => array( 'pipe', 'w' ), 2 => array( 'file', '/dev/null', 'w' ) ), $pipes );
			$metadata = json_decode( stream_get_contents( $pipes[1] ), true );
			fclose( $pipes[1] );
			$this->assertSame( 0, proc_close( $process ) );
			$this->assertCount( 1, $metadata['streams'], 'Preview must contain video only, with no audio.' );
			$video = $metadata['streams'][0];
			$this->assertSame( 'h264', $video['codec_name'] );
			$this->assertSame( 'yuv420p', $video['pix_fmt'] );
			$this->assertSame( min( 480, $width ), $video['width'] );
			$this->assertSame( $width > 480 ? 270 : $height, $video['height'] );
			$this->assertSame( '24/1', $video['r_frame_rate'] );
			$this->assertEqualsWithDelta( 8.0, (float) $metadata['format']['duration'], 0.05 );
		} finally {
			unlink( TVPG_FFMPEG_PATH );
			rename( TVPG_FFMPEG_PATH . '.fixture', TVPG_FFMPEG_PATH );
		}
	}
}
