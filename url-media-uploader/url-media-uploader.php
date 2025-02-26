
<?php
/**
 * Plugin Name: URL Media Uploader
 * Description: Easily upload media files to your WordPress media library by pasting URLs. Import images, videos, and other media directly from external links without manual download.
 * Version: 1.1.0
 * Author: apprhyme
 * License: GPL2
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: url-media-uploader
 */

 if ( ! defined( 'ABSPATH' ) ) {
     exit;
 }
 
 define( 'URL_MEDIA_UPLOADER_VERSION', '1.1.0' );
 define( 'URL_MEDIA_UPLOADER_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
 
 require_once  plugin_dir_path( __FILE__ ) . 'inc/class-url-media-uploader.php';
 
 function url_media_uploader_init() {
     $url_media_uploader = new URL_Media_Uploader();
 }
 add_action( 'plugins_loaded', 'url_media_uploader_init' ); 


?>