<?php
/** Product editor screen routing regressions. */
namespace Automattic\WooCommerce\Admin {
	class PageController {
		public static function is_admin_or_embed_page() { return $GLOBALS['tvpg_test_wc_admin'] ?? false; }
	}
}
namespace Automattic\WooCommerce\Utilities {
	class FeaturesUtil {
		public static function feature_is_enabled( $feature ) { return $GLOBALS['tvpg_test_product_editor_enabled'] ?? false; }
	}
}
namespace {
	require_once __DIR__ . '/performance-support.php';
	require_once TVPG_PATH . 'includes/class-tvpg-block-editor.php';
	function get_current_screen() { return $GLOBALS['tvpg_test_screen']; }
	function wp_enqueue_media() {}
	function wp_set_script_translations( $handle, $domain ) {}

	class TVPG_Block_Editor_Test extends \PHPUnit\Framework\TestCase {
		protected function setUp(): void {
			$GLOBALS['tvpg_test_scripts'] = (object) array( 'registered' => array() );
			$GLOBALS['tvpg_test_styles'] = (object) array( 'registered' => array() );
			$GLOBALS['tvpg_test_product_editor_enabled'] = true;
			$GLOBALS['tvpg_test_wc_admin'] = false;
			$GLOBALS['tvpg_test_screen'] = new class {
				public $post_type = '';
				public $block = false;
				public function is_block_editor() { return $this->block; }
			};
		}

		protected function tearDown(): void {
			unset( $GLOBALS['tvpg_test_screen'], $GLOBALS['tvpg_test_wc_admin'], $GLOBALS['tvpg_test_product_editor_enabled'] );
		}

		public function test_wc_admin_screen_without_post_type_loads_product_form_dependencies(): void {
			$GLOBALS['tvpg_test_wc_admin'] = true;
			(new \TVPG_Block_Editor())->enqueue_editor_assets();
			$deps = wp_scripts()->registered['tvpg-block-editor']->deps;
			$this->assertContains( 'wc-product-editor', $deps );
			$this->assertContains( 'wc-block-templates', $deps );
			$this->assertTrue( $GLOBALS['tvpg_test_localized']['tvpgEditorContext']['woocommerce'] );
		}

		public function test_wp_product_editor_keeps_sidebar_dependencies(): void {
			$GLOBALS['tvpg_test_screen']->post_type = 'product';
			$GLOBALS['tvpg_test_screen']->block = true;
			(new \TVPG_Block_Editor())->enqueue_editor_assets();
			$this->assertContains( 'wp-edit-post', wp_scripts()->registered['tvpg-block-editor']->deps );
			$this->assertFalse( $GLOBALS['tvpg_test_localized']['tvpgEditorContext']['woocommerce'] );
		}

		public function test_classic_product_screen_does_not_load_block_editor_assets(): void {
			$GLOBALS['tvpg_test_screen']->post_type = 'product';
			(new \TVPG_Block_Editor())->enqueue_editor_assets();
			$this->assertSame( array(), wp_scripts()->registered );
		}

		public function test_wc_admin_without_product_editor_feature_does_not_load_assets(): void {
			$GLOBALS['tvpg_test_wc_admin'] = true;
			$GLOBALS['tvpg_test_product_editor_enabled'] = false;
			(new \TVPG_Block_Editor())->enqueue_editor_assets();
			$this->assertSame( array(), wp_scripts()->registered );
		}
	}
}
