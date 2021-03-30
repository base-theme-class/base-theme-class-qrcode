<?php
/*
+----------------------------------------------------------------------
| Copyright (c) 2018,2019,2020 Genome Research Ltd.
| This is part of the Wellcome Sanger Institute extensions to
| wordpress.
+----------------------------------------------------------------------
| This extension to Worpdress is free software: you can redistribute
| it and/or modify it under the terms of the GNU Lesser General Public
| License as published by the Free Software Foundation; either version
| 3 of the License, or (at your option) any later version.
|
| This program is distributed in the hope that it will be useful, but
| WITHOUT ANY WARRANTY; without even the implied warranty of
| MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the GNU
| Lesser General Public License for more details.
|
| You should have received a copy of the GNU Lesser General Public
| License along with this program. If not, see:
|     <http://www.gnu.org/licenses/>.
+----------------------------------------------------------------------

# See foot of file for documentation on use...
#
# Author         : js5
# Maintainer     : js5
# Created        : 2018-02-09
# Last modified  : 2018-02-12

 * @package   BaseThemeClass/QRcodes
 * @author    JamesSmith james@jamessmith.me.uk
 * @license   GLPL-3.0+
 * @link      https://jamessmith.me.uk/base-theme-class/
 * @copyright 2018 James Smith
 *
 * @wordpress-plugin
 * Plugin Name: Website Base Theme Class - QR Codes
 * Plugin URI:  https://jamessmith.me.uk/base-theme-class-qrcodes/
 * Description: Support functions to: add QR code support to a BaseThemeClass based website
 * Version:     0.1.0
 * Author:      James Smith
 * Author URI:  https://jamessmith.me.uk
 * Text Domain: base-theme-class-locale
 * License:     GNU Lesser General Public v3
 * License URI: https://www.gnu.org/licenses/lgpl.txt
 * Domain Path: /lang
*/

namespace BaseThemeClass;

define( 'QR_FIELDS', [
  'Slug'    => [ 'type' => 'text' ],
  'URL'     => [ 'type' => 'link' ],
]);


class QRCodes {
  var $self;
  function __construct( $self ) {
    $this->self = $self;
    register_setting( 'qr_code', 'qr_code_base_url',     [ 'default' => '' ] );
    if( is_admin() ) {
      add_action( 'admin_menu', [ $this, 'qr_code_admin_menu' ], PHP_INT_MAX );
    }
    add_action( 'update_option_qr_code_base_url', [ $this, 'qr_code_update_base_url' ], PHP_INT_MAX, 2 );
    add_filter( 'wp_insert_post_data',            [ $this, 'qr_code_update_post_data' ] );
    add_action( 'parse_request',                  [ $this, 'qr_code_parse_request' ] );
    add_action( 'rest_api_init', function () { // Nasty SQL query used by static publish to create the rewrite-map-files.txt
      register_rest_route( 'base', 'qr_redirects', array(
        'methods' => 'GET',
        'callback' => [ $this, 'qr_code_results' ]
      ) );
    } );

    $this->self->define_type( 'QR code', QR_FIELDS,
      [ 'title_template' => '[[slug]] - [[url.url]]', 'icon' => 'warning',
        'prefix' => 'q', 'add' => 'edit_private_pages', 'position' => 'bottom' ] );
  }

  function qr_code_admin_menu() {
    add_options_page( 'QR code', 'QR code', 'administrator', 'QR code', 'qr_code_options_form' );
  }

  function qr_code_options_form() {
    $base_url = get_option(    'qr_code_base_url' );
    echo '
  <div>
    <h2>QR code generation options</h2>
    <p>
      <strong>QR code/short urls</strong> allow easier to publish URLs for pages (on this site and on others) to be
      referenced by a shorter "typeable" URL...
    </p>
    <form method="post" action="options.php">';
    settings_fields(      'qr_code'        );
    do_settings_sections( 'qr_code'        );
    echo '
      <table class="form-table">
        <tbody>
          <tr>
            <th>Base URL:</th>
            <td>
              <input type="text" name="qr_code_base_url" id="qr_code_base_url" value=', $base_url, '
              Base URL to use if not using default: ', $_SERVER['HTTP_HOST'],'/q/ , e.g. if you are
              using an alternative subdomain
            </td>
          </tr>
        </tbody>
      </table>';
    submit_button();
    echo '
    </form>
  </div>';
  }

  function qr_code_update_base_url( $old, $new ) {
    if( $old == $new ) {
      return;
    }
    $posts = get_posts([
      'post_type'   => 'qr_code',
      'numberposts' => 1e6
    ]);
    $base_url = $new == '' ? 'https:'.$_SERVER['HTTP_HOST'].'/q/' : $new;
    foreach( $posts as $p ) {
      $p->post_title = preg_replace( '/^.*?->/',$base_url.substr($p->post_name,3).' ->', $p->post_title );
      wp_update_post( $p );
    }
  }

  function qr_code_update_post_data( $post_data ) {
    if( $post_data[ 'post_type' ] === 'qr_code' && array_key_exists( 'acf', $_POST ) ) {
      $slug = preg_replace('/\W+/', '', $_POST['acf']['field_q_slug'] );
      do {
        if( $slug === '' ) {
          $slug = implode( '', array_map( function($i) { $p = '0123456789abcdefghijklmnopqrstuvwxyz'; return $p[mt_rand(0,35)]; }, range(1,8) ) );
          $_POST['acf']['field_q_slug'] = $slug;
        }
        // Could add test here to see if generated slug already exists!
      } while( $slug === '' );
      $post_data[ 'post_name' ]  = 'qr-'.$slug;
      $post_data[ 'post_title' ] = 'https://'.$_SERVER['HTTP_HOST'].'/q/'.$slug.'  ->  '.$_POST['acf']['field_q_url']['url'] ;
    }
    return $post_data;
  }

  function qr_code_parse_request() {
    global $wp;
    if( preg_match( '/^q\/(\w+)([.]png)?$/', $wp->request, $matches ) ) {
      // Find post $matches[1];
      header( 'Content-type: text/plain' );
      $render_image = sizeof( $matches ) > 2;
      $my_post = get_page_by_path( 'qr-'.$matches[1], OBJECT, 'qr_code' );
      if( !$my_post || $my_post->post_status !== 'publish' ) {
        return;
      }
      if( $render_image ) {
        $URL = escapeshellcmd( 'https://'.$_SERVER['HTTP_HOST'].'/q/'.$matches[1] );
        $cmd = implode( ' ',[ '/usr/bin/qrencode', '-m', '1', '-s', '4', '-l', 'Q', '-8', '-v', '3', '-o', '-', $URL ] );
        header( 'Content-type: image/png' );
        passthru( $cmd );
      } else {
        header( 'Location: '. get_field( 'url', $my_post->ID ) );
      }
      exit;
    }
    return;
  }

  function qr_code_base_url() {
    $base_url = get_option(    'qr_code_base_url' );
    if( ! $base_url ) {
      $base_url = 'https:'.$_SERVER['HTTP_HOST'].'/q/';
    }
    return $base_url;
  }

  function qr_code_results( $data ) {
    global $wpdb;
    $res = $wpdb->dbh->query( '
select group_concat(if(m.meta_key="slug",m.meta_value,"") separator "") code,
       group_concat(if(m.meta_key="url",
        SUBSTRING_INDEX(SUBSTRING_INDEX(SUBSTRING_INDEX(m.meta_value,"\"url\";s:",-1),
          "\"",2),"\"",-1),"") separator "") url
  from wp_posts p, wp_postmeta m where p.ID = m.post_id and
       p.post_type = "qr_code" and p.post_status = "publish"
 group by p.ID' );
    return $res->fetch_all();
  }
}

