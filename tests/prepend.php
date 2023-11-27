<?php

namespace wpCloud\StatelessMedia {
  class Compatibility {
  }
  
  class WPStatelessStub {
  
    const TEST_GS_HOST = 'https://google.com'; 
  
    private static $instance = null;
  
    public static function instance() {
      return static::$instance ? static::$instance : static::$instance = new static;
    }

    public static function get_client() {
      return self::instance();
    }
  
    public $options = [
      'sm.root_dir' => 'uploads',
      'sm.mode' => 'cdn',
    ];
  
    public $removed_file = '';

    public function set($key, $value): void {
      $this->options[$key] = strval($value);
    }
  
    public function get($key): ?string {
      return $this->options[$key];
    }
  
    public function get_gs_host(): string {
      return self::TEST_GS_HOST;
    }

    public function remove_media($filename) {
      $this->removed_file = $filename;
    } 
  }  
}

namespace {
  class GFForms {
    public static $version = '2.5';
  }
  
  class GFFormsModel {
    private static $input_type = 'fileupload';
  
    public static function set_input_type($input_type) {
      self::$input_type = $input_type;
    }
  
    public static function get_input_type($f) {
      return self::$input_type;
    }
  }

  class GFFormsField {
    public $multipleFiles = false;
  }
}
