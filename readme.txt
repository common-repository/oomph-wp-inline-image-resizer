=== Oomph WP Inline Image Resize ===
Contributors: bendoh, thinkoomph
Donate link: http://www.thinkoomph.com/
Tags: clone, widget, sidebar
Requires at least: 3.2
Tested up to: 3.5.2
Stable tag: 0.5.2

Resize images on the fly! Great for rapid theme development when your image sizes may change often!

== Description ==

Resize images on the fly! Great for theme developers who add image sizes to their theme after image assets have already been added to the image library - images come out the right size, every time.

Works great for both single-site and multi-site installations. Multi-site installation requires SUNRISE to be set and wp-content/sunrise.php to be populated - Instructions are given within the plugin.

== Installation ==

1. Upload oomph-wp-inline-image-resizer to /wp-content/plugins/.

2. Activate plugin through the WordPress Plugins menu. 

	2a. On a MULTISITE installation, this plugin must be activated network-wide.

	2b. On a MULTISITE installation, code must be added to wp-content/sunrise.php and SUNRISE must be defined in wp-config.php. The code is provided when the plugin is activated.

	2c. On a MULTISITE installation, the .htaccess file must be updated. Instructions are provided in the plugin.

3. Now all attachments displayed via wp_get_attachment_url() will be resized according to the image size in the theme.

== Changelog ==

=0.5.2= 
Fix issue in determining image paths in MU site installs

=0.5.1=
Fix issue that would serve cached high-res images to low-res displays

=0.5=
Introduce owpiirhrd cookie setting to flag for high-resolution displays; try to deliver 2x sized images when that's the case.

=0.4=
Fix bug that would break images in media explorer. Fix PHP notices from checking current_blog when not multisite

=0.3.1=
Fix bug that would prevent root site RewriteCond rule from being injected if there is whitespace before the first RewriteCond

=0.3=
Add code to address the root site in a multi-site installation.

=0.2.1=
Fix plugin name in readme file

=0.2=
Add code to update .htaccess file in MU installations. QSA must be part of the RewriteRule flags in order for this plugin to work.

=0.1=
Initial release

