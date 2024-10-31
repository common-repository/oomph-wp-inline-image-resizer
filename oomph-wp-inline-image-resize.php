<?php
/*
Plugin Name: Oomph WP Inline Image Resize
Plugin URI: http://thinkoomph.com/wp-plugins/Oomph-WP-Inline-Image-Resize
Description: Resize and cache image thumbs on the fly without having to regenerate and store image thumbnails.
Author: Ben Doherty @ Oomph, Inc.
Version: 0.5.2
Author URI: http://thinkoomph.com/

		Copyright © 2013 Oomph, Inc. <http://oomphinc.com>

    This program is free software: you can redistribute it and/or modify
    it under the terms of the GNU General Public License as published by
    the Free Software Foundation, either version 3 of the License, or
    (at your option) any later version.

    This program is distributed in the hope that it will be useful,
    but WITHOUT ANY WARRANTY; without even the implied warranty of
    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
    GNU General Public License for more details.

    You should have received a copy of the GNU General Public License
    along with this program.  If not, see <http://www.gnu.org/licenses/>.
*/ 

class Oomph_WP_Inline_Image_Resize {
	// One true Singleton
	private static $instance = false;
	public static function instance() {
		if( !self::$instance )
			self::$instance = new Oomph_WP_Inline_Image_Resize;

		return self::$instance;
	}

	private function __clone() { }

	var $name = "Oomph WP Inline Image Resizer";
	var $query_var = 'oomph_inline_image_resize';

	var $htaccess_pattern = '|^(\s*RewriteRule\s+\^(\(\[_0-9a-zA-Z-\]\+/\)\?)?files/\(\.\+\)\s+wp-includes/ms-files\.php\?file=\$[12]\s+\[([A-Z,]+))\]\s*$|im';
	var $htaccess_single_pattern; // Set in constructor

	/**
	 * Set up filters and handle requests
	 *
	 * @constructor
	 */
	private function __construct() {
		$this->htaccess_single_rule = 'RewriteRule ^wp-content/uploads/(.*)$ /index.php?' . $this->query_var . '=$1 [L,QSA]';

		add_filter( 'image_downsize', array( $this, 'filter_image_downsize' ), 10, 3 );
		add_filter( 'image_url', array( $this, 'filter_image_url' ), 10, 2 );
		add_filter( 'mod_rewrite_rules', array( $this, 'filter_mod_rewrite_rules' ) );
		add_action( 'after_plugin_row', array( $this, 'ms_plugin_sunrise_notice' ), 10, 3 );
		add_action( 'admin_action_wp_inline_image_resize_update_htaccess', array( $this, 'update_htaccess' ) );
		add_action( 'network_admin_notices', array( $this, 'action_network_admin_notices' ) );
		add_action( 'wp_head', array( $this, 'set_hrd_cookie' ) );

		// Remove the hack filter once we've been included
		if( is_multisite() )
			remove_filter( 'pre_site_option_ms_files_rewriting', 'oomph_wp_inline_image_resize_execute_ms' );

		if( !is_multisite() || ( is_multisite() && defined( 'WPMU_PLUGIN_DIR' ) ) ) {
			register_activation_hook( __FILE__, array( $this, 'activate_plugin' ) );
			register_deactivation_hook( __FILE__, array( $this, 'deactivate_plugin' ) );
		}

		$this->init(); // Handle any requests for images
	}

	/**
	 * Process the image fetch request
	 */
	private function init() {
		global $current_blog;
		if( is_multisite() && $_SERVER['PHP_SELF'] == '/wp-includes/ms-files.php' ) {
			if( !isset( $_GET['file'] ) )
				return;

			ms_file_constants();
			ms_upload_constants();

			$file = rtrim( BLOGUPLOADDIR, '/' ) . '/' . str_replace( '..', '', $_GET[ 'file' ] );
		}
		else {
			// Process via regular query string parameter
			parse_str( $_SERVER['QUERY_STRING'], $vars );

			if( !isset( $vars[$this->query_var] ) )
				return;

			$file = $vars[$this->query_var];

			$upload_dir = wp_upload_dir();

			$file = str_replace( 'sites/' . get_current_blog_id(), '', $file );
			$file = $upload_dir['basedir'] . $file;
		}

		if( is_multisite() ) {
			// Most of this is copied from /wp-incudes/ms-files.php, but 
			// modified to support both MU and single site installs

			if ( $current_blog->archived == '1' || $current_blog->spam == '1' || $current_blog->deleted == '1' ) {
				status_header( 404 );
				die( '404 &#8212; File not found.' );
			}
		}

		if ( !is_file( $file ) ) {
			status_header( 404 );
			die( '404 &#8212; File not found.' );
		}

		$mime = wp_check_filetype( $file, array( 'jpg|jpeg' => 'image/jpeg', 'gif' => 'image/gif', 'png' => 'image/png' ) );
		if( false === $mime[ 'type' ] && function_exists( 'mime_content_type' ) )
			$mime[ 'type' ] = mime_content_type( $file );

		if( $mime[ 'type' ] )
			$mimetype = $mime[ 'type' ];
		else
			$mimetype = 'image/' . substr( $file, strrpos( $file, '.' ) + 1 );

		header( 'Content-Type: ' . $mimetype ); // always send this

		// Optional support for X-Sendfile and X-Accel-Redirect
		if ( defined( 'WPMU_ACCEL_REDIRECT' ) && WPMU_ACCEL_REDIRECT ) {
			header( 'X-Accel-Redirect: ' . str_replace( WP_CONTENT_DIR, '', $file ) );
			exit;
		} elseif ( defined( 'WPMU_SENDFILE' ) && WPMU_SENDFILE ) {
			header( 'X-Sendfile: ' . $file );
			exit;
		}

		$last_modified = gmdate( 'D, d M Y H:i:s', filemtime( $file ) );
		$etag = '"' . md5( $last_modified ) . '"';
		header( "Last-Modified: $last_modified GMT" );
		header( 'ETag: ' . $etag );
		header( 'Expires: ' . gmdate( 'D, d M Y H:i:s', time() + 100000000 ) . ' GMT' );

		// Support for Conditional GET
		$client_etag = isset( $_SERVER['HTTP_IF_NONE_MATCH'] ) ? stripslashes( $_SERVER['HTTP_IF_NONE_MATCH'] ) : false;

		if( ! isset( $_SERVER['HTTP_IF_MODIFIED_SINCE'] ) )
			$_SERVER['HTTP_IF_MODIFIED_SINCE'] = false;

		$client_last_modified = trim( $_SERVER['HTTP_IF_MODIFIED_SINCE'] );
		// If string is empty, return 0. If not, attempt to parse into a timestamp
		$client_modified_timestamp = $client_last_modified ? strtotime( $client_last_modified ) : 0;

		// Make a timestamp for our most recent modification...
		$modified_timestamp = strtotime($last_modified);

		if ( ( $client_last_modified && $client_etag )
			? ( ( $client_modified_timestamp >= $modified_timestamp) && ( $client_etag == $etag ) )
			: ( ( $client_modified_timestamp >= $modified_timestamp) || ( $client_etag == $etag ) )
		) {
			status_header( 304 );
			exit;
		}

		// Try to serve up resized images on the fly if they differ from what's stored.
		// A little hacky, but works.
		if( isset( $_GET['w'] ) || isset( $_GET['h'] ) )
			$file = $this->resize_image( $file );

		// Send Content-Length header, but not on IIS
		if ( false === strpos( $_SERVER['SERVER_SOFTWARE'], 'Microsoft-IIS' ) )
			header( 'Content-Length: ' . filesize( $file ) );

		readfile( $file );
		exit;
	}

	/**
	 * Set a cookie in JavaScript that flags the device as being a
	 * high-resolution-display (HDR,) which can be read to know
	 * that double-resolution images should be provided instead
	 *
	 * @action wp_head
	 */
	function set_hrd_cookie() { ?>
		<script>if(window.devicePixelRatio >= 1.5) { document.cookie = 'owpiirhrd=1'; }</script>
	<?php
	}

	/**
	 * Emit admin notices depending on value of owpiir GET parameter
	 *
	 * @action network_admin_notices
	 */
	function action_network_admin_notices() {
		if( !isset( $_GET['owpiir'] ) )
			return;

		switch( $_GET['owpiir'] ) {
		case 'site_not_multisite':
			echo '<div id="message" class="error"><p>' . esc_html( $this->name ) . ': Site is not multisite. Will not touch .htaccess file.</p></div>';
			break;
		case 'htaccess_not_writeable':
			echo '<div id="message" class="error"><p>' . esc_html( $this->name ) . ': .htaccess file is not writeable. Can not automatically update.</p></div>';
			break;
		case 'updated_htaccess':
			echo '<div id="message" class="updated"><p>' . esc_html( $this->name ) . ': Updated .htaccess file.</p></div>';
			break;
		}
	}

	/**
	 * Update the .htaccess file
	 *
	 * @action admin_action_wp_inline_image_resize_update_htaccess
	 */
	function update_htaccess() {
		$htaccess_file = ABSPATH . '/.htaccess';

		if( !isset( $_GET['_wpnonce'] ) || !wp_verify_nonce( $_GET['_wpnonce'] ) ) {
			wp_redirect( network_admin_url( 'plugins.php' ) );
			exit;
		}

		if( !is_multisite() ) {
			wp_redirect( network_admin_url( 'plugins.php?owpiir=site_not_multisite' ) );
			exit;
		}

		if( !is_writeable( $htaccess_file ) ) {
			wp_redirect( network_admin_url( 'plugins.php?owpiir=htaccess_not_writeable' ) );
			exit;
		}

		$htaccess_file = ABSPATH . '/.htaccess';
		$htaccess = file_get_contents( $htaccess_file );

		preg_match( $this->htaccess_pattern, $htaccess, $htaccess_matches );
	 
		$need_htaccess = $htaccess_matches && !in_array( 'QSA', array_map( 'strtoupper', explode( ',', $htaccess_matches[3] ) ) );

		if( $need_htaccess ) {	
			$htaccess = str_replace( $htaccess_matches[1], $htaccess_matches[1] . ',QSA', $htaccess );
		}

		// Put in a rule for the top-level site as well
		$htaccess = $this->filter_mod_rewrite_rules( $htaccess );

		file_put_contents( $htaccess_file, $htaccess );

		wp_redirect( network_admin_url( 'plugins.php?owpiir=updated_htaccess' ) );

	}

	/**
	 * Meat n' potatoes: Actually resize an image (and cache it) based on
	 * query string parameters.
	 *
	 * If there's a Pragma: no-cache header, the file will be regenerated.
	 */
	function resize_image( $file ) {
		$w = isset( $_GET['w'] ) ? (int) $_GET['w'] : 'auto';
		$h = isset( $_GET['h'] ) ? (int) $_GET['h'] : 'auto';

		if( $w <= 0 ) {
			$w = 'auto';
		}

		if( $h <= 0 ) {
			$h = 'auto';
		}

		// Deliver 2x images to retina displays
		if( isset( $_COOKIE['owpiirhrd'] ) && $_COOKIE['owpiirhrd'] ) {
			if( is_int( $w ) ) {
				$w *= 2;
			}
			if( is_int( $h ) ) {
				$h *= 2;
			}
		}

		$crop = isset( $_GET['crop'] );

		// Meh. Nothing was actually requested after sanitizing inputs
		if( $w == 'auto' && $h == 'auto' )
			return $file;

		$tmpname = 'wp-autosized-' . md5( $file . '/' . $w . 'x' . $h . '-' . isset( $_GET['crop'] ) );
		$tempfile = path_join( sys_get_temp_dir(), $tmpname ); 

		$headers = getallheaders();

		if( file_exists( $tempfile ) && ( !isset( $headers['Pragma'] ) || $headers['Pragma'] != 'no-cache' ) )
			// If a tmpfile already exists and we don't see a Pragma: no-cache header, return the 
			// cached file
			return $tempfile;

		$sizeinfo = getimagesize( $file );

		if( strpos( $sizeinfo['mime'], 'image/' ) === 0 ) {
			$imgtype = substr( $sizeinfo['mime'], strlen( 'image/' ) );

			switch( $imgtype ) {
			case 'jpg':
			case 'jpeg':
				$open_function = 'imagecreatefromjpeg';
				$out_function = 'imagejpeg';
				$quality = 90; // Maximum quality.
				break;
			case 'png':
				$open_function = 'imagecreatefrompng';
				$out_function = 'imagepng';
				$quality = 9; // Actually, maximum compression.
				break;
			case 'gif':
				$open_function = 'imagecreatefromgif';
				$out_function = 'imagegif';
				$quality = 9; // Eh, moot.
				break;
			}

			if( isset( $open_function ) && function_exists( $open_function ) && ( $image = $open_function( $file ) ) ) {
				if( $w == 'auto' )
					$w = $h * $sizeinfo[0] / $sizeinfo[1];
				if( $h == 'auto' )
					$h = $w * $sizeinfo[1] / $sizeinfo[0];

				if( !$crop ) {
					// Don't scale images up if not cropping
					if( $w > $sizeinfo[0] )
						$w = $sizeinfo[0];
					if( $h > $sizeinfo[1] )
						$h = $sizeinfo[1];	 		
				}

				$scale_type = $sizeinfo[0] / $sizeinfo[1] > $w / $h ? 'landscape' : 'portrait';

				if( !$crop xor $scale_type == 'landscape' ) {
					// More landscapy, scale to height, or width when not cropping
					$sf = $h / $sizeinfo[1];		// Scale factor determined by height ratio
					$sw = $sizeinfo[0] * $sf;
					$sh = $h;
				}
				else {
					// More portraity, or equal, scale to width, or height when not cropping
					$sf = $w / $sizeinfo[0];		// Scale factor determined by width ratio
					$sw = $w;
					$sh = $sizeinfo[1] * $sf;
				}

				if( $crop ) {
					$dst_x = max(0, ($w - $sw) / 2);
					$dst_y = max(0, ($h - $sh) / 2);
					$src_x = ($sw - $w) / 2 / $sf;
					$src_y = ($sh - $h) / 2 / $sf;
					$dst_w = $w;
					$dst_h = $h;
					$src_w = $w / $sf;
					$src_h = $h / $sf;
				} else {
					$dst_x = $w > $sw ? ($w - $sw) / 2 : 0;
					$dst_y = $h > $sh ? ($h - $sh) / 2 : 0;
					$src_x = 0;
					$src_y = 0;
					$dst_w = $sw;
					$dst_h = $sh;
					$src_w = $sw / $sf;
					$src_h = $sh / $sf;
				}

				$out = imagecreatetruecolor( $w, $h );

				// Fill with transparent white
				imagefill( $out, 0, 0, imagecolorallocatealpha( $out, 255, 255, 255, 127 ) );
				imagesavealpha( $out, true );

				imagecopyresampled( $out, $image, $dst_x, $dst_y, $src_x, $src_y, $dst_w, $dst_h, $src_w, $src_h );

				$out_function( $out, $tempfile, $quality );
				$file = $tempfile;
			}
		}

		return $file;
	}

	/**
	 * Generic filter to add query string arguments for a size to image
	 * URLs.
	 *
	 * @filter image_url
	 */
	function filter_image_url( $url, $size ) {
		global $_wp_additional_image_sizes;

		if( !isset( $_wp_additional_image_sizes[$size] ) )
			return $url;

		$dims = $_wp_additional_image_sizes[$size];

		return add_query_arg( array(
			'w' => $dims['width'],
			'h' => $dims['height'],
			'crop' => isset( $dims['crop'] ) && $dims['crop']
		), $url );
	}

	/**
	 * Add query string parameters to images grabbed via image_downsize
	 *
	 * @filter image_downsize
	 */
	function filter_image_downsize( $image, $id, $size ) {
		if( $size == 'full' )
			return $image;

		global $_wp_additional_image_sizes;

		if( !isset( $_wp_additional_image_sizes[$size] ) )
			return $image;

		$dims = $_wp_additional_image_sizes[$size];

		$url = $this->filter_image_url( wp_get_attachment_url($id), $size );

		return array( $url, $dims['width'], $dims['height'] );
	}

	/**
	 * Add necessary RewriteRules (single-site installs) to redirect image requests
	 * to this module
	 *
	 * @filter mod_rewrite_rules
	 */
	function filter_mod_rewrite_rules( $rules ) {
		if( strpos( $rules, $this->htaccess_single_rule . "\n" ) === false )
			// Insert the rule right before the first RewriteCond
			$rules = preg_replace( '/^(?=\s*RewriteCond)/mi', str_replace( '$', '\$', $this->htaccess_single_rule . "\n" ), $rules, 1 );

		return $rules;
	}

	/**
	 * Activate plugin by regenerating rewrite rules
	 *
	 * @activation_hook
	 */
	function activate_plugin() {
		if( is_multisite() && !is_network_admin() ) {
			// Can only network-activate in MU
			die( "Can only network-activate this plugin in multisite installations." );
		}

		save_mod_rewrite_rules();
	}

	/**
	 * De-activate plugin by regenerating rewrite rules
	 *
	 * @deactivation_hook
	 */
	function deactivate_plugin() {
		remove_filter( 'mod_rewrite_rules', array( $this, 'filter_mod_rewrite_rules' ) );

		save_mod_rewrite_rules();
	}

	/**
	 * Show code required for sunrise.php for multisite installations
	 *
	 * @action after_plugin_row
	 */
	function ms_plugin_sunrise_notice( $plugin_file, $plugin_data, $status ) {
		$this_plugin = str_replace( ABSPATH . PLUGINDIR . '/', '', __FILE__ );

		if( $plugin_file != $this_plugin || !is_multisite() )
			return;

		$need_sunrise = !defined( 'SUNRISE' ) || !( defined( 'OOMPH_WP_INLINE_IMAGE_RESIZE_SUNRISE' ) && OOMPH_WP_INLINE_IMAGE_RESIZE_SUNRISE );
		$htaccess_file = ABSPATH . '/.htaccess';
		$htaccess = file_get_contents( $htaccess_file );

		preg_match( $this->htaccess_pattern, $htaccess, $htaccess_matches );

		$need_htaccess = $htaccess_matches && !in_array( 'QSA', array_map( 'strtoupper', explode( ',', $htaccess_matches[3] ) ) );
		$need_htaccess_single = strpos( $htaccess, $this->htaccess_single_rule ) === false;

		if( !$need_sunrise && !$need_htaccess && !$need_htaccess_single )
			return;

		?>
		<tr class="plugin-update-tr">
			<td colspan="3">
			<div class="update-message">
		<?php if( $need_sunrise ) { ?>
		<p>You must place the following code into <strong>/wp-content/sunrise.php</strong> 
		<?php if( !defined( 'SUNRISE' ) ) { ?>and define the <strong>SUNRISE</strong> constant in <strong>wp-config.php</strong><?php } ?>
		in order for this plugin to function:</p>

		<pre>
// Load the image plugin when trying to load ms-files.php
define( 'OOMPH_WP_INLINE_IMAGE_RESIZE_SUNRISE', true );

function oomph_wp_inline_image_resize_execute_ms( $bool ) {
	define( 'WP_PLUGIN_DIR', WP_CONTENT_DIR . '/plugins' );

	foreach( wp_get_active_network_plugins() as $network_plugin )
		if( basename( $network_plugin ) == 'oomph-wp-inline-image-resize.php' )
			include_once( $network_plugin );

	return true;
}

if( $_SERVER['PHP_SELF'] == '/wp-includes/ms-files.php' )
	add_filter( 'pre_site_option_ms_files_rewriting', 'oomph_wp_inline_image_resize_execute_ms' );
		</pre>	

		<?php 
		}

		if( $need_htaccess || $need_htaccess_single ) { ?>
			<p>Your <strong>.htaccess</strong> file must be updated for this plugin to work.</p>

			<?php if( !is_writeable( $htaccess_file ) ) { ?>
				<p>If your <strong>.htaccess</strong> were writable, the following change would be made:</p>
			<?php } 

			if( $need_htaccess ) { ?>
			<p>Change <code><?php echo esc_html( trim( $htaccess_matches[0] ) ); ?></code> to <code><?php echo esc_html( preg_replace( '|\]\s*$|', ',QSA]', $htaccess_matches[0] ) ); ?></code></p>
			<?php }

			if( $need_htaccess_single ) { ?>
			<p>Add <code><?php echo esc_html( $this->htaccess_single_rule ); ?></code> before the first <strong>RewriteCond</strong> line.</p>
			<?php }
			
			if( is_writeable( $htaccess_file ) ) { ?>
				<p>To update automatically, <a href="<?php echo network_admin_url( '?action=wp_inline_image_resize_update_htaccess&_wpnonce=' . wp_create_nonce() ); ?>">click here</a>.</p>
			<?php } 
		} ?>
		</div>
		</td>
	</tr>
<?php
	}
}

Oomph_WP_Inline_Image_Resize::instance();
