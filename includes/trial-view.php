<?php
namespace Qevora\ImageFlow;
defined('ABSPATH') || exit;
(static function () {
the_post();
if ('product'===get_post_type() && function_exists('wc_setup_product_data')) { wc_setup_product_data(get_post()); }
$qif_template=absint(get_query_var('qif_trial'));
$qif_content=\Elementor\Plugin::$instance->frontend->get_builder_content_for_display($qif_template,true);
?><!doctype html><html <?php language_attributes(); ?>><head><meta charset="<?php bloginfo('charset'); ?>"><meta name="viewport" content="width=device-width,initial-scale=1"><?php wp_head(); ?></head><body <?php body_class(); ?>><?php wp_body_open(); ?>
<main><?php echo $qif_content; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Elementor renders the authorized saved template and widget markup. ?></main><?php wp_footer(); ?></body></html>
<?php })();
