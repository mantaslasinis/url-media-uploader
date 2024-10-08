<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class URL_Media_Uploader {

    public function __construct() {
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_scripts' ) );

        // Handle Elementor
        if ( has_action( 'elementor/editor/before_enqueue_scripts' ) ) {
            add_action( 'elementor/editor/before_enqueue_scripts', function() {
                $this->enqueue_scripts( 'skip' );
            } );
        }

        // Handle Divi
        add_action( 'wp_enqueue_scripts', function() {
            if ( function_exists( 'et_core_is_fb_enabled' ) && et_core_is_fb_enabled() ) {
                $this->enqueue_scripts( 'skip' );
            }
        });

        add_action( 'wp_ajax_url_media_uploader_url_upload', array( $this, 'ajax_handler' ) );
    }

    public function enqueue_scripts( $hook ) {
        if ( 'post.php' !== $hook && 
             'post-new.php' !== $hook && 
             'upload.php' !== $hook && 
             'skip' !== $hook 
        ) {
            return;
        }

        wp_enqueue_script(
            'url-media-uploader-js',
            URL_MEDIA_UPLOADER_PLUGIN_URL . '/js/url-media-uploader.js',
            array( 'jquery' ),
            URL_MEDIA_UPLOADER_VERSION,
            true
        );

        wp_enqueue_style(
            'url-media-uploader-css',
            URL_MEDIA_UPLOADER_PLUGIN_URL . '/css/url-media-uploader.css',
            array(),
            URL_MEDIA_UPLOADER_VERSION
        );

        wp_localize_script( 'url-media-uploader-js', 'urlMediaUploader', array(
            'ajax_url' => admin_url( 'admin-ajax.php' ),
            'nonce'    => wp_create_nonce( 'url_media_uploader_nonce' ),
        ) );
    }
    

    public function ajax_handler() {
        if ( ! check_ajax_referer( 'url_media_uploader_nonce', 'nonce', false ) ) {
            wp_send_json_error( array( 'message' => 'Nonce verification failed' ) );
        }

        $url = '';
        $attachment_id = false;

        if ( isset( $_POST['url'] ) ) {
            if ( str_starts_with( $_POST['url'], 'data:image/' ) ) {
                $attachment_id = $this->validate_and_process_base64_image( $_POST['url'] );

                if ( is_wp_error( $attachment_id ) ) {
                    wp_send_json_error( array( 'message' => $attachment_id->get_error_message() ) );
                } else {
                    wp_send_json_success( array( 'message' => 'Media uploaded successfully!', 'attachment_id' => $attachment_id ) );
                }

            } else {
                $url = esc_url_raw( wp_unslash( $_POST['url'] ) );
            }
        }

        if ( strpos( $url, site_url() ) !== false ) {
            $attachment_id = attachment_url_to_postid( $url );
            if ( $attachment_id ) {
                wp_send_json_success( array( 'message' => 'Media already exists!', 'attachment_id' => $attachment_id ) );
            }
        }

        $result = $this->download_and_attach_media_from_url( $url );

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( array( 'message' => $result->get_error_message() ) );
        } else {
            wp_send_json_success( array( 'message' => 'Media uploaded successfully!', 'attachment_id' => $result ) );
        }

    }

    public function download_and_attach_media_from_url( $url ) {
        if ( empty( $url ) ) {
            return new WP_Error( 'no_url_provided', 'No URL provided.' );
        }

        if ( ! filter_var( $url, FILTER_VALIDATE_URL ) ) {
            return new WP_Error( 'invalid_url', 'Invalid URL.' );
        }

        $tmp = download_url( $url );

        if ( is_wp_error( $tmp ) ) {
            return $tmp;
        }

        $file_array = array(
            'name'     => basename( $url ),
            'tmp_name' => $tmp,
        );

        $file_type = wp_check_filetype_and_ext( $tmp, $file_array['name'] );
        if ( $file_type['type'] === false ) {
            wp_delete_file( $tmp );
            return new WP_Error( 'invalid_file_type', 'Invalid file type.' );
        }
        
        $id = media_handle_sideload( $file_array, 0, preg_replace( '/\.\w+$/', '', basename( $url ) ), array( 'test_form' => false ) );

        if ( is_wp_error( $id ) ) {
            wp_delete_file( $file_array['tmp_name'] );
            return $id;
        }

        return $id;
    }

    public function validate_and_process_base64_image( $base64_string ) {
        if ( preg_match( '/^data:image\/(\w+);base64,/', $base64_string, $matches ) ) {
            $file_type   = strtolower( $matches[1] );
            $valid_types = array( 'jpg', 'jpeg', 'png', 'gif' );
            
            if ( ! in_array( $file_type, $valid_types, true ) ) {
                return new WP_Error( 'invalid_file_type', 'Only JPEG, PNG, and GIF images are allowed.' );
            }

            $base64_data   = substr( $base64_string, strpos( $base64_string, ',' ) + 1 );
            $decoded_file  = base64_decode( $base64_data, true );

            if ( $decoded_file === false ) {
                return new WP_Error( 'invalid_base64', 'Invalid Base64 string.' );
            }

            $file_size = strlen( $decoded_file );
            if ( $file_size > 5 * 1024 * 1024 ) { 
                return new WP_Error( 'file_too_large', 'The file is too large.' );
            }

            $tmp_file = wp_tempnam();
            if ( ! $tmp_file ) {
                return new WP_Error( 'temporary_file_error', 'Could not create a temporary file.' );
            }

            if ( false === file_put_contents( $tmp_file, $decoded_file ) ) {
                return new WP_Error( 'file_write_error', 'Could not write to temporary file.' );
            }

            $file_array = array(
                'name'     => 'image.' . $file_type,
                'tmp_name' => $tmp_file,
            );
            
            $file_type_check = wp_check_filetype_and_ext( $tmp_file, $file_array['name'] );
            if ( $file_type_check['type'] === false ) {
                wp_delete_file( $tmp_file );
                return new WP_Error( 'invalid_file_type', 'Invalid file type.' );
            }

            $id = media_handle_sideload( $file_array, 0, preg_replace( '/\.\w+$/', '', $file_array['name'] ), array( 'test_form' => false ) );

            if ( is_wp_error( $id ) ) {
                wp_delete_file( $file_array['tmp_name'] );
                return $id;
            }

            return $id;
        } else {
            return new WP_Error( 'invalid_format', 'Invalid Base64 image format.' );
        }
    }

}
