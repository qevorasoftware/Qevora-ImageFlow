<?php
namespace Qevora\ImageFlow;
defined('ABSPATH') || exit;

function workflow_options() {
    return wp_parse_args(get_option('qif_workflow',array()),array('first_load'=>false,'rules'=>array(),'budgets'=>array()));
}

function size_config($config,$size) {
    $rule=$config['rules'][$size] ?? null;
    if ($rule && encoder($rule['format'])) {
        $format=$rule['format'];
        $config['targets']=array($format);
        $config['preferred']=$format;
        $config[$format]=$rule['quality'];
    }
    return $config;
}

function sample_controls() {
    $s=settings();
    echo '<details class="qif-lab"><summary>Test before converting</summary><p>Check up to three library rows above. Compare the full-size original with local WebP and AVIF previews. Click a preview to inspect its pixels.</p>';
    foreach (array('webp','avif') as $format) {
        echo '<label>'.esc_html(strtoupper($format)).' quality <input class="qif-quality" data-format="'.esc_attr($format).'" type="number" min="1" max="100" value="'.absint($s[$format]).'" '.disabled(!encoder($format),true,false).'></label> ';
    }
    echo '<button class="button" type="button" id="qif-sample">Test checked images</button><p role="status" id="qif-sample-status"></p><div id="qif-samples"></div></details>';
}

add_action('wp_ajax_qif_sample',function () {
    check_ajax_referer('qif_admin');
    $id=absint($_POST['id'] ?? 0);
    if (!current_user_can('manage_options') || !current_user_can('edit_post',$id) || !wp_attachment_is_image($id)) { wp_send_json_error('Access denied',403); }
    $to=sanitize_key($_POST['format'] ?? '');
    $s=settings();
    if (!in_array($to,array('webp','avif'),true) || !encoder($to)) { wp_send_json_error('Encoder unavailable',400); }
    $s[$to]=max(1,min(100,absint($_POST['quality'] ?? 0)));
    $file=get_attached_file($id,true);
    $src=$file?realpath($file):false;
    if (!$src || strpos($src,root_dir().DIRECTORY_SEPARATOR)!==0 || !is_readable($src)) { wp_send_json_error('Source unavailable',400); }
    $from=array_search(wp_get_image_mime($src),formats(),true);
    $info=wp_getimagesize($src);
    $bytes=filesize($src);
    if (!$info || !in_array($from,$s['sources'],true) || $from===$to || in_array($id,wp_parse_id_list($s['excluded_ids']),true) || $bytes>$s['megabytes']*1048576 || $info[0]*$info[1]>$s['megapixels']*1000000) { wp_send_json_error('Same format or excluded by source and size settings',400); }
    $tmp=wp_tempnam('qif-sample');
    if (!$tmp) { wp_send_json_error('Temporary storage unavailable',500); }
    $start=microtime(true);
    try {
        $reason=encode($src,$tmp,$from,$to,$s);
        $out=wp_getimagesize($tmp);
        if ($reason || !$out || $info[0]!==$out[0] || $info[1]!==$out[1] || wp_get_image_mime($tmp)!==formats()[$to]) { throw new \RuntimeException($reason ?: 'Encoder verification failed'); }
        $data=file_get_contents($tmp);
        if (false===$data) { throw new \RuntimeException('Preview unavailable'); }
    } catch (\Throwable $e) { $error=$e instanceof \RuntimeException?$e->getMessage():'Sample failed'; }
    finally { wp_delete_file($tmp); }
    if (isset($error)) { wp_send_json_error($error,400); }
    nocache_headers();
    header('Content-Type: '.formats()[$to]);
    header('X-QIF-Milliseconds: '.absint(1000*(microtime(true)-$start)));
    header('X-QIF-Original-Bytes: '.absint($bytes));
    header('X-QIF-Original: '.rawurlencode(wp_get_attachment_url($id)));
    echo $data; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Verified binary image response.
    exit;
});

function workflow_screen() {
    $opts=workflow_options();
    ?>
    <section><h2>Elementor template trial</h2><p>Render a saved Elementor template with up to three published sample posts or products. Each preview uses the selected post as its dynamic content context.</p>
    <label>Template ID <input id="qif-trial-template" type="number" min="1"></label>
    <label>Sample post or product IDs <input id="qif-trial-posts" placeholder="42, 56"></label>
    <button type="button" class="button" id="qif-trial">Build previews</button>
    <p role="status" id="qif-trial-status"></p><div id="qif-trial-results"></div></section>
    <form action="<?php echo esc_url(admin_url('admin-post.php')); ?>" method="post">
    <input type="hidden" name="action" value="qif_workflow"><?php wp_nonce_field('qif_workflow'); ?>
    <section><h2>First-load delivery</h2><label><input type="checkbox" name="first_load" value="1" <?php checked($opts['first_load']); ?>> Include optimized sources in the initial HTML for WordPress attachment images</label><p>The browser chooses a supported source before fetching the image. Original markup provides the fallback. Check your theme and purge cached pages after saving.</p></section>
    <section><h2>Conversion rules by usage</h2><p>Choose a format and quality for the registered size used by a listing, gallery or zoom view. Rules override batch choices for matching sizes. Enable the size in Settings for conversion.</p>
    <table class="widefat striped"><thead><tr><th>WordPress size</th><th>Output</th><th>Quality / PNG effort</th></tr></thead><tbody>
    <?php foreach (array_unique(array_merge(array('full','original'),get_intermediate_image_sizes())) as $size) {
        $rule=$opts['rules'][$size] ?? array();
        echo '<tr><td>'.esc_html($size).'</td><td><select name="formats['.esc_attr($size).']"><option value="">Follow batch</option>';
        foreach (outputs() as $format) { echo '<option value="'.esc_attr($format).'" '.selected($rule['format'] ?? '',$format,false).' '.disabled(!encoder($format),true,false).'>'.esc_html(strtoupper($format)).'</option>'; }
        echo '</select></td><td><input name="qualities['.esc_attr($size).']" type="number" min="0" max="100" value="'.absint($rule['quality'] ?? 80).'"></td></tr>';
    } ?>
    </tbody></table><p>JPEG, WebP and AVIF accept 1–100. PNG accepts 0–9. Review the affected sizes below before saving.</p><output id="qif-rule-review"></output></section>
    <section><h2>Image budgets per template</h2><p>Set a maximum file size per image. Enter the Elementor template ID. Optionally limit a rule to one widget ID or to browser widths up to a given number of pixels. The Page Inspector reports over-budget images when you scan.</p>
    <table class="widefat"><thead><tr><th>Template ID</th><th>Widget ID (optional)</th><th>Max viewport px (0 = all)</th><th>Budget KiB</th></tr></thead><tbody>
    <?php foreach (array_merge($opts['budgets'],array(array())) as $i=>$budget) {
        echo '<tr>';
        foreach (array('template','widget','width','kib') as $field) { echo '<td><input style="width:100%" aria-label="'.esc_attr($field).'" name="budgets['.absint($i).']['.esc_attr($field).']" type="'.($field==='widget'?'text':'number').'" value="'.esc_attr($budget[$field] ?? '').'"></td>'; }
        echo '</tr>';
    } ?>
    </tbody></table><p>Save to add another row. Clear a template ID to remove its rule.</p></section>
    <?php submit_button('Save workflow settings'); ?></form>
    <?php
}

add_action('admin_post_qif_workflow',function () {
    check_admin_referer('qif_workflow');
    if (!current_user_can('manage_options')) { wp_die('Access denied'); }
    $formats=array_map('sanitize_key',(array)wp_unslash($_POST['formats'] ?? array()));
    $qualities=array_map('absint',(array)wp_unslash($_POST['qualities'] ?? array()));
    $rules=array();
    foreach (array_merge(array('full','original'),get_intermediate_image_sizes()) as $size) {
        $to=$formats[$size] ?? '';
        if (in_array($to,outputs(),true) && encoder($to)) {
            $rules[$size]=array('format'=>$to,'quality'=>max($to==='png'?0:1,min($to==='png'?9:100,$qualities[$size] ?? 80)));
        }
    }
    $budgets=array();
    $rows=map_deep(wp_unslash($_POST['budgets'] ?? array()),'sanitize_text_field');
    foreach ((array)$rows as $row) {
        if (!is_array($row) || empty($row['template']) || empty($row['kib'])) { continue; }
        $budgets[]=array('template'=>absint($row['template']),'widget'=>sanitize_key($row['widget'] ?? ''),'width'=>absint($row['width'] ?? 0),'kib'=>absint($row['kib']));
    }
    update_option('qif_workflow',array('rules'=>$rules,'budgets'=>$budgets,'first_load'=>!empty($_POST['first_load'])),false);
    wp_safe_redirect(page_url('workflow')); exit;
});

add_action('wp_ajax_qif_trial',function () {
    check_ajax_referer('qif_admin');
    if (!current_user_can('manage_options')) { wp_send_json_error('Access denied',403); }
    $template=absint($_POST['template'] ?? 0);
    if (!class_exists('Elementor\Plugin') || get_post_type($template)!=='elementor_library' || !current_user_can('edit_post',$template)) { wp_send_json_error('Choose an editable Elementor library template',400); }
    $ids=array_slice(wp_parse_id_list(wp_unslash($_POST['posts'] ?? '')),0,3);
    $pages=array();
    foreach ($ids as $id) {
        if ('publish'!==get_post_status($id) || !is_post_publicly_viewable($id) || post_password_required($id)) { wp_send_json_error('Choose published, publicly viewable samples',400); }
        $pages[]=array('title'=>get_the_title($id),'url'=>add_query_arg(array('qif_trial'=>$template,'qif_inspect'=>'1','qif_key'=>wp_create_nonce('qif_trial_'.$template.'_'.$id)),get_permalink($id)));
    }
    wp_send_json_success($pages);
});
add_filter('query_vars',function ($vars) { return array_merge($vars,array('qif_trial','qif_key')); });
add_filter('template_include',function ($file) {
    $template=absint(get_query_var('qif_trial'));
    if (!$template || !is_singular() || !current_user_can('manage_options') || !wp_verify_nonce(get_query_var('qif_key'),'qif_trial_'.$template.'_'.get_queried_object_id()) || get_post_type($template)!=='elementor_library' || !current_user_can('edit_post',$template) || !class_exists('Elementor\Plugin')) { return $file; }
    nocache_headers();
    return __DIR__.'/trial-view.php';
},99);
