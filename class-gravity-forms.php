<?php

namespace SLCA\GravityForms;

use wpCloud\StatelessMedia\Compatibility;
use wpCloud\StatelessMedia\Helper;

/**
 * Class GravityForms
 */
class GravityForms extends Compatibility {
  protected $id = 'gravity-form';
  protected $title = 'Gravity Forms';
  protected $constant = 'WP_STATELESS_COMPATIBILITY_GF';
  protected $description = 'Enables support for these Gravity Forms features: file upload field, post image field, custom file upload field type.';
  protected $plugin_file = 'gravityforms/gravityforms.php';
  protected $plugin_version;
  protected $non_library_sync = true;

  /**
   * @param $sm
   */
  public function module_init($sm) {
    if (class_exists('GFForms')) {
      $this->plugin_version = \GFForms::$version;
    }
    add_filter('gform_save_field_value', array($this, 'gform_save_field_value'), 10, 5);
    add_filter('stateless_skip_cache_busting', array($this, 'skip_cache_busting'), 10, 2);

    do_action('sm:sync::register_dir', '/gravity_forms/');
    add_action('sm::synced::nonMediaFiles', array($this, 'modify_db'), 10, 3);
    add_action('gform_file_path_pre_delete_file', array($this, 'gform_file_path_pre_delete_file'), 10, 2);
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

    if (empty($this->plugin_version) && class_exists('GFForms')) {
      $this->plugin_version = \GFForms::$version;
    }

    $type = \GFFormsModel::get_input_type($field);
    if ($type == 'fileupload') {
      $dir = wp_upload_dir();

      if ($field->multipleFiles) {
        $value = json_decode($value);
      } else {
        $value = array($value);
      }

      foreach ($value as $k => $v) {
        if (empty($v)) continue;
        $position = strpos($v, 'gravity_forms/');

        if ($position !== false) {
          $name = substr($v, $position);
          $absolutePath = $dir['basedir'] . '/' .  $name;
          $name = apply_filters('wp_stateless_file_name', $name, 0);

          do_action('sm:sync::syncFile', $name, $absolutePath);
          $value[$k] = ud_get_stateless_media()->get_gs_host() . '/' . $name;
        }
      }

      if ($field->multipleFiles) {
        $value = wp_json_encode($value);
      } else {
        $value = array_pop($value);
      }
    } else if ($type == 'post_image') {
      add_action('gform_after_create_post', function ($post_id, $lead, $form) use ($value, $field) {
        $dir = wp_upload_dir();

        $position = strpos($value, 'gravity_forms/');
        $_name = substr($value, $position); // gravity_forms/
        $arr_name = explode('|:|', $_name);
        $name = rgar($arr_name, 0); // Removed |:| from end of the url.

        // doing sync
        $absolutePath = $dir['basedir'] . '/' .  $name;
        $name = apply_filters('wp_stateless_file_name', $name, 0);
        do_action('sm:sync::syncFile', $name, $absolutePath);

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
    $position = strpos($file_path, 'gravity_forms/');
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
      $gs_name = substr($file_path, strpos($file_path, '/gravity_forms/'));
      $file_path = $dir['basedir'] . $gs_name;
      $gs_name = apply_filters('wp_stateless_file_name', $gs_name, 0);

      $client = ud_get_stateless_media()->get_client();
      if (!is_wp_error($client)) {
        $client->remove_media(trim($gs_name, '/'));
      }
    }

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
}
