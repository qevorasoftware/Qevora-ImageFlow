<?php
namespace Qevora\ImageFlow;
defined('ABSPATH') || exit;
function formats() {
    return array('jpeg'=>'image/jpeg','png'=>'image/png','gif'=>'image/gif','webp'=>'image/webp','avif'=>'image/avif','bmp'=>'image/bmp','tiff'=>'image/tiff','heic'=>'image/heic','heif'=>'image/heif');
}
function outputs() { return array('webp','avif','jpeg','png'); }
function capable($fmt) {
    static $cache = array();
    if (!isset($cache[$fmt])) {
        $im = class_exists('Imagick') && \Imagick::queryFormats(strtoupper($fmt));
        // GD alone cannot reliably detect multiple frames in container formats.
        $gd = in_array($fmt,array('jpeg','png','bmp'),true) && function_exists('imagecreatefrom'.$fmt);
        $cache[$fmt] = $im || $gd;
    }
    return $cache[$fmt];
}
function encoder($fmt) {
    return (class_exists('Imagick') && \Imagick::queryFormats(strtoupper($fmt))) || function_exists('image'.$fmt);
}
function defaults() {
    return array('sources'=>array('jpeg','png'),'targets'=>array('webp'),'preferred'=>'webp','auto'=>1,'delivery'=>1,'elementor'=>1,'background'=>1,'smaller'=>1,'strip'=>1,'sizes'=>get_intermediate_image_sizes(),'webp'=>82,'avif'=>60,'jpeg'=>82,'png'=>6,'megabytes'=>24,'megapixels'=>24,'excluded_ids'=>'');
}
function settings() { $saved=(array)get_option('qif_settings',array());
    if (!isset($saved['excluded_ids']) && isset($saved['exclude'])) { $saved['excluded_ids']=$saved['exclude']; }
    return wp_parse_args($saved,defaults()); }
function sanitize($raw) {
    $raw = (array)$raw;
    $s = defaults();
    foreach (array('auto','delivery','elementor','background','smaller','strip') as $k) { $s[$k] = empty($raw[$k]) ? 0 : 1; }
    foreach (array('sources'=>array_filter(array_keys(formats()),__NAMESPACE__.'\\capable'),'targets'=>array_filter(outputs(),__NAMESPACE__.'\\encoder'),'sizes'=>get_intermediate_image_sizes()) as $k=>$allowed) {
        $s[$k] = array_values(array_intersect($allowed,(array)($raw[$k] ?? array())));
    }
    foreach (array('webp'=>100,'avif'=>100,'jpeg'=>100,'png'=>9,'megabytes'=>128,'megapixels'=>64) as $k=>$max) {
        $s[$k] = max($k==='png'?0:1,min($max,(int)($raw[$k] ?? $s[$k])));
    }
    $s['preferred'] = in_array($raw['preferred'] ?? '',$s['targets'],true)?$raw['preferred']:($s['targets'][0] ?? '');
    $s['excluded_ids'] = implode(',',wp_parse_id_list($raw['excluded_ids'] ?? ''));
    return $s;
}
