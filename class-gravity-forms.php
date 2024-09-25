<?php

namespace SLCA\GravityForms;

use wpCloud\StatelessMedia\Compatibility;
use wpCloud\StatelessMedia\Helper;
use wpCloud\StatelessMedia\Utility;

/**
 * Class GravityForms
 */
class GravityForms extends Compatibility {
  const GF_PATH = 'gravity_forms/';

  protected $id = 'gravity-form';
  protected $title = 'Gravity Forms';
  protected $constant = 'WP_STATELESS_COMPATIBILITY_GF';
  protected $description = 'Enables support for these Gravity Forms features: file upload field, post image field, custom file upload field type.';
  protected $plugin_file = 'gravityforms/gravityforms.php';
  protected $plugin_version;

  /**
   * @param $sm
   */
  public function module_init($sm) {
    if (class_exists('GFForms')) {
      $this->plugin_version = \GFForms::$version;
    }

    add_filter('gform_upload_path', array($this, 'gf_upload_path'), 10, 1);
    add_filter('gform_save_field_value', array($this, 'gform_save_field_value'), 10, 5);
    add_action('gform_file_path_pre_delete_file', array($this, 'gform_file_path_pre_delete_file'), 10, 2);

    add_filter('stateless_skip_cache_busting', array($this, 'skip_cache_busting'), 10, 2);

    add_action('sm::synced::nonMediaFiles', array($this, 'modify_db'), 10, 3);
    add_filter('sm:sync::nonMediaFiles', array($this, 'sync_non_media_files'), 20);
    add_filter('sm:sync::syncArgs', array($this, 'sync_args'), 10, 4);
  }

  /**
   * Get full gs:// path.
   * 
   * @return string
   */
  private function get_gs_path() {
    return 'gs://'  . ud_get_stateless_media()->get('sm.bucket');
  }

  /**
   * For 'stateless' mode we use upload path and URL without wildcards.
   * @param $upload_path
   * @return mixed
   */
  public function gf_upload_path($upload_path) {
    if ( !ud_get_stateless_media()->is_mode('stateless') ) {
      return $upload_path;
    }

    $dir = wp_upload_dir();

    $upload_path['path'] = ud_get_stateless_media()->get_gs_path() . str_replace($dir['basedir'], '', $upload_path['path']);
    $upload_path['url'] = ud_get_stateless_media()->get_gs_host() . str_replace($dir['baseurl'], '', $upload_path['url']);

    return $upload_path;
  }

  /**
   * On gform save field value sync file to GCS and alter the file url to GCS link.
   * @param $value
   * @param $lead
   * @param $field
   * @param $form
   * @param $input_id
   * @return array|false|mixed|string
   */
  public function gform_save_field_value($value, $lead, $field, $form, $input_id) {
    if (empty($value)) return $value;

    $is_stateless = ud_get_stateless_media()->get('sm.mode') === 'stateless';
    $type = \GFFormsModel::get_input_type($field);

    if ($type == 'fileupload') {
      $dir = wp_upload_dir();

      if ($field->multipleFiles) {
        $value = json_decode($value, true);
      } else {
        $value = array($value);
      }

      foreach ($value as $k => $v) {
        if ( empty($v) || !is_string($v) ) {
          continue;
        }

        $position = strpos($v, self::GF_PATH);

        if ($position !== false) {
          $name = substr($v, $position);
          $path = $is_stateless ? $this->get_gs_path() : $dir['basedir'];
          $absolutePath = $path . '/' .  $name;

          $name = apply_filters('wp_stateless_file_name', $name, 0);

          do_action( 'sm:sync::syncFile', $name, $absolutePath );

          $value[$k] = ud_get_stateless_media()->get_gs_host() . '/' . $name;
        }
      }

      if ($field->multipleFiles) {
        $value = wp_json_encode($value);
      } else {
        $value = array_pop($value);
      }
    } else if ($type == 'post_image') {
      add_action('gform_after_create_post', function ($post_id, $lead, $form) use ($value, $field, $is_stateless) {
        $dir = wp_upload_dir();

        $position = strpos($value, self::GF_PATH);
        $_name = substr($value, $position);
        $arr_name = explode('|:|', $_name);
        $name = rgar($arr_name, 0); // Removed |:| from end of the url.

        // doing sync
        $path = $is_stateless ? $this->get_gs_path() : $dir['basedir'];
        $absolutePath = $path . '/' .  $name;
        $name = apply_filters('wp_stateless_file_name', $name, 0);
        do_action( 'sm:sync::syncFile', $name, $absolutePath );

        $value = ud_get_stateless_media()->get_gs_host() . '/' . $name;

        gform_update_meta($lead['id'], $field['id'], $value, $form['id']);
      }, 10, 3);
    }
    return $value;
  }

  /**
   * Get relative filename for file values in gravity forms.
   * Converts: https://mysite.com/wp-content/uploads/gravity_forms/folder_hash/2024/03/photo-123.jpeg|:||:||:||:|
   * To: https://storage.googleapis.com/my_bucket/gravity_forms/folder_hash/2024/03/photo-123.jpeg
   * 
   * @param $filename
   * @return string|null
   */
  private function get_updated_filename($filename) {
    $position = strpos($filename, 'gravity_forms/');
    $name = substr($filename, $position); // gravity_forms/...
    // Removed |:| from end of the url.
    $arr_name = explode('|:|', $name);
    $name = rgar($arr_name, 0); 
    $name = apply_filters('wp_stateless_file_name', $name, 0);

    return ud_get_stateless_media()->get_gs_host() . '/' . $name;
  }

  /**
   * Modify value in database after sync from Sync tab.
   * @param $file_path
   * @param $fullsizepath
   * @param $media
   * @throws \Exception
   */
  public function modify_db($file_path, $fullsizepath, $media) {
    $position = strpos($file_path, self::GF_PATH);
    $is_index = strpos($file_path, 'index.html');

    if ($position === false || $is_index) {
      return;
    }

    $dir = wp_upload_dir();
    $root_dir = ud_get_stateless_media()->get('sm.root_dir');
    $root_dir = apply_filters("wp_stateless_handle_root_dir", $root_dir);

    $file_path = trim($file_path, '/');
    // Use base file name since the URL in the DB could be encoded with in an array
    $file_single = basename($file_path);

    // Get the entries with the file name
    $entries = \GFAPI::get_entries(
      0,
      array(
        'field_filters' => array(
          array(
            'key' => 'meta_value',
            'operator' => 'contains',
            'value' => $file_single
          )
        )
      )
    );

    foreach ( $entries as $entry ) {
      // Search entry for the field ID and value that contains our file name 
      foreach ( $entry as $field_id => $value ) {
        if ( strpos($value, $file_single) === false ) {
          continue;
        }

        $position = false;

        // Check if value is json encoded, if so, cycle through array and replace URLs.
        $result = json_decode($value);

        if ( json_last_error() === 0 ) {
          foreach ($result as $k => $v) {
            $position = strpos($v, $dir['baseurl']);

            if ($position !== false) {
              $result[$k] = $this->get_updated_filename($v);
            }
          }

          $result = wp_json_encode($value);
        } else {
          $position = strpos($value, $dir['baseurl']);

          if ($position !== false) {
            $result = $this->get_updated_filename($value);
          }
        }

        if ($position !== false) {
          gform_update_meta($entry['id'], $field_id, $result, $entry['form_id']);
        }
      }
    }
  }

  /**
   * Throw db error from last db query.
   * We need to throw db error instead of just printing,
   * so that we can catch them in ajax request.
   */
  function throw_db_error() {

    global $wpdb;
    $wpdb->show_errors();

    if ($wpdb->last_error !== '' && wp_doing_ajax()) :
      ob_start();
      $wpdb->print_error();
      $error = ob_get_clean();
      if ($error) {
        throw new \Exception( esc_html($error) );
      }
    endif;
  }

  /**
   * Delete file from GCS
   * @param $file_path
   * @param $url
   * @return string
   */
  public function gform_file_path_pre_delete_file($file_path, $url) {
    $file_path = wp_normalize_path($file_path);
    $gs_host = wp_normalize_path(ud_get_stateless_media()->get_gs_host());
    $dir = wp_upload_dir();
    $is_stateless = strpos($file_path, $gs_host);

    // If the url is a GCS link then remove it from GCS.
    if ($is_stateless !== false) {
      $gs_name = substr($file_path, strpos($file_path, '/' . self::GF_PATH));
      $file_path = $dir['basedir'] . $gs_name;
      $gs_name = apply_filters('wp_stateless_file_name', $gs_name, 0);

      $client = ud_get_stateless_media()->get_client();
      if (!is_wp_error($client)) {
        $client->remove_media(trim($gs_name, '/'), '', false);
      }
    }

    do_action('sm:sync::unregister_file', $url);
    
    return $file_path;
  }

  /**
   * Skip cache busting while exporting.
   * @param $return
   * @param $filename
   * @return mixed
   */
  public function skip_cache_busting($return, $filename) {
    $backtrace = debug_backtrace(false, 8);
    if (
      !empty($backtrace[7]['class']) &&
      $backtrace[7]['class'] == 'GFExport' &&
      ($backtrace[7]['function'] == 'write_file' ||
        $backtrace[7]['function'] == 'ajax_download_export')
    ) {
      return $filename;
    }
    return $return;
  }

  /**
   * Filter service files created by Gravity Forms for sync.
   * 
   * @param array $file_list
   * @return array
   */
  public function sync_non_media_files($file_list) {
    if ( !method_exists('\wpCloud\StatelessMedia\Utility', 'get_files') ) {
      Helper::log('WP-Stateless version too old, please update.');

      return $file_list;
    }

    $upload_dir = apply_filters('gform_upload_path', wp_upload_dir());
    $key = ud_get_stateless_media()->is_mode('stateless') ? 'path' : 'basedir';
    $basedir = $upload_dir[$key] ?? '';
    $dir = $basedir . '/' . self::GF_PATH;

    if (is_dir($dir)) {
      // Getting all the files from dir recursively.
      $files = Utility::get_files($dir);

      // validating and adding to the $files array.
      foreach ($files as $file) {
        if (!file_exists($file)) {
          continue;
        }

        // filter GF logs
        if (strpos($file, self::GF_PATH . 'logs/') !== false) {
          continue;
        }

        $basename = basename($file);

        // filter out index.html, .htaccess files
        if ( in_array($basename, array('index.html', '.htaccess')) ) {
          continue;
        }
    
        $file = str_replace( wp_normalize_path($basedir), '', wp_normalize_path($file) );
        $file = trim($file, '/');

        if (!in_array($file, $file_list)) {
          $file_list[] = $file;
        }
      }
    }
      
    return $file_list;
  }
      
  /**
   * Update args when uploading/syncing GF file to GCS.
   * 
   * @param array $args
   * @param string $name
   * @param string $file
   * @param bool $force
   * 
   * @return array
   */
  public function sync_args($args, $name, $file, $force) {
    if ( strpos($name, self::GF_PATH) !== 0 ) {
      return $args;
    }

    if ( ud_get_stateless_media()->is_mode('stateless') ) {
      $args['name_with_root'] = false;
    }

    $args['source'] = 'Gravity Forms';
    $args['source_version'] = $this->plugin_version;

    return $args;
  }
}
