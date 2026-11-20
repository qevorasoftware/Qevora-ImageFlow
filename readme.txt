=== Qevora ImageFlow ===
Tags: images, webp, avif, elementor, optimization
Requires at least: 6.4
Requires PHP: 7.4
Stable tag: 1.0.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Create local WebP, AVIF, JPEG or PNG alternatives for selected Media Library images and responsive sizes.

== Description ==
Choose source formats, preferred output, additional outputs, size limits and quality. Inspect a sample in Media Library before converting a whole site. The dashboard compares the bytes of mapped originals and preferred alternatives. These figures estimate delivery savings. Derivatives require additional disk space.

== Installation ==
1. Upload the ZIP in Plugins > Add New > Upload Plugin, then activate.
2. Go to Qevora ImageFlow > Settings and select available codecs.
3. Convert a sample from its Media Library attachment details. Inspect its alternatives.
4. Open Bulk Converter and start the queue. Keep this admin page open while it processes. Return to it to reconnect.

== Frequently Asked Questions ==
= What happens when I deactivate? =
WordPress continues serving the original attachments. Metadata, settings and derivatives are retained. Remove selected derivatives explicitly through Media Library Tools.
= Where are the setup guide and test results? =
See README.md and tests/acceptance.md in the package.

== Changelog ==
= 1.0.4 =
* Add expandable guidance for settings, output choices, image list filters and conversion tools.
= 1.0.3 =
* Group image list filters and add thumbnails, status indicators and clearer row selection.
= 1.0.2 =
* Show live batch progress and refresh conversion results through AJAX.
= 1.0.1 =
* Choose bulk output formats before starting and see generated formats per attachment.
= 1.0.0 =
* Initial implementation of local conversion, browser delivery, Elementor CSS adaptation, Media Library tools and a resumable admin queue.
