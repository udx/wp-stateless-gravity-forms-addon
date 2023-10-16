<?php

namespace WPSL\GravityForms;

use PHPUnit\Framework\TestCase;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Brain\Monkey;
use Brain\Monkey\Actions;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use wpCloud\StatelessMedia\WPStatelessStub;
use WPSL\GravityForms\GravityForms;

/**
 * Class ClassGravityFormsTest
 */

class ClassGravityFormsTest extends TestCase {
  const TEST_URL = 'https://test.test';
  const UPLOADS_URL = self::TEST_URL . '/uploads';
  const TEST_FILE = 'gravity_forms/image.png';
  const SRC_URL = self::UPLOADS_URL . '/' . self::TEST_FILE;
  const DST_URL = WPStatelessStub::TEST_GS_HOST . '/' . self::TEST_FILE;
  const TEST_UPLOAD_DIR = [
    'baseurl' => self::UPLOADS_URL,
    'basedir' => '/var/www/uploads'
  ];

  // Adds Mockery expectations to the PHPUnit assertions count.
  use MockeryPHPUnitIntegration;

  public function setUp(): void {
		parent::setUp();
		Monkey\setUp();

    // WP mocks
    Functions\when('wp_upload_dir')->justReturn( self::TEST_UPLOAD_DIR );
    Functions\when('wp_normalize_path')->returnArg();
        
    // WP_Stateless mocks
    Filters\expectApplied('wp_stateless_file_name')
      ->andReturn( self::TEST_FILE );

    Filters\expectApplied('wp_stateless_handle_root_dir')
      ->andReturn( 'uploads' );

    Functions\when('ud_get_stateless_media')->justReturn( WPStatelessStub::instance() );
  }
	
  public function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

  public function testShouldInitHooks() {
    $gravityForms = new GravityForms();

    Actions\expectDone('sm:sync::register_dir')->once();

    $gravityForms->module_init([]);

    self::assertNotFalse( has_action('sm::synced::nonMediaFiles', [ $gravityForms, 'modify_db' ]) );
    self::assertNotFalse( has_action('gform_file_path_pre_delete_file', [ $gravityForms, 'gform_file_path_pre_delete_file' ]) );

    self::assertNotFalse( has_filter('gform_save_field_value', [ $gravityForms, 'gform_save_field_value' ]) );
    self::assertNotFalse( has_filter('stateless_skip_cache_busting', [ $gravityForms, 'skip_cache_busting' ]) );
  }

  public function testShouldSaveFieldValueFileUpload() {
    $gravityForms = new GravityForms();

    $gravityForms->gform_save_field_value(self::SRC_URL, ['id' => 15], new \GFFormsField(), null, null);

    Actions\expectDone('sm:sync::syncFile')->once();

    $this->assertEquals(
      self::DST_URL,
      $gravityForms->gform_save_field_value(self::SRC_URL, ['id' => 15], new \GFFormsField(), null, null)
    );
  }

  public function testShouldSaveFieldValuePostImage() {
    $gravityForms = new GravityForms();

    \GFFormsModel::set_input_type('post_image');

    $gravityForms->gform_save_field_value(self::SRC_URL, ['id' => 15], new \GFFormsField(), null, null);

    self::assertNotFalse( has_action('gform_after_create_post', 'function ($post_id, $lead, $form)' ) );
  }

  public function testShouldModifyDb() {
    /**
     * @TODO: tests fot modify_db method
     */
  }

  public function testShouldRemoveMedia() {
    $gravityForms = new GravityForms();

    $gravityForms->gform_file_path_pre_delete_file(self::DST_URL, self::SRC_URL);

    $this->assertEquals(
      self::TEST_FILE,
      WPStatelessStub::instance()->removed_file
    );
  }

  public function testShouldSkipCacheBusting() {
    $gravityForms = new GravityForms();

    $gravityForms->gform_file_path_pre_delete_file(self::DST_URL, self::SRC_URL);

    $this->assertEquals(
      self::DST_URL,
      $gravityForms->skip_cache_busting(self::SRC_URL, self::DST_URL)
    );
  }
}

function debug_backtrace($a, $b) {
  return [
    '7' => [
      'class' => 'GFExport',
      'function' => 'write_file',
    ],
  ];
}
