<?php
namespace Qevora\ImageFlow;
defined('ABSPATH') || exit;
require_once __DIR__.'/admin-help.php';
function pages() { return array('home'=>__('Dashboard','qevora-imageflow'),'settings'=>__('Settings','qevora-imageflow'),'bulk'=>__('Bulk Converter','qevora-imageflow'),'media'=>__('Media Library Tools','qevora-imageflow'),'elementor'=>__('Elementor Integration','qevora-imageflow'),'logs'=>__('Logs & Tools','qevora-imageflow'),'inspector'=>__('Page Inspector','qevora-imageflow'),'origin'=>__('AI Media','qevora-imageflow'),'audit'=>__('Media Size Report','qevora-imageflow'),'workflow'=>__('Image Workbench','qevora-imageflow'),'docs'=>__('Documentation','qevora-imageflow')); }
function page_url($key) { return admin_url('admin.php?page=qif-'.$key); }
function h($str) { echo esc_html($str); }
add_action('admin_menu',function () {
    $screens=array(add_menu_page('Qevora ImageFlow','Qevora ImageFlow','manage_options','qif-home',__NAMESPACE__.'\\page','dashicons-format-image'));
    foreach (pages() as $key=>$title) {
        $screens[]=add_submenu_page('qif-home',$title,$title,'manage_options','qif-'.$key,__NAMESPACE__.'\\page');
    }
    foreach (array_unique(array_filter($screens)) as $screen) {
        add_action('load-'.$screen,__NAMESPACE__.'\\configure_page_notices');
    }
});
// Only registered ImageFlow screens receive this class. Keep other plugins' hooks running.
function configure_page_notices() {
    add_filter('admin_body_class',static function ($classes) { return $classes.' qif-admin-screen '; });
    foreach (array('admin_notices','all_admin_notices') as $hook) {
        add_action($hook,static function () { ob_start(); },PHP_INT_MIN);
        add_action($hook,static function () { ob_end_clean(); },PHP_INT_MAX);
    }
}

function settings_notices() {
    $messages=get_settings_errors('qif_settings');
    if (!$messages && isset($_GET['settings-updated']) && 'true'===sanitize_key(wp_unslash($_GET['settings-updated']))) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only confirmation after the Settings API redirect.
        $messages[]=array('type'=>'success','message'=>__('ImageFlow settings saved.','qevora-imageflow'));
    }
    foreach ($messages as $message) {
        $type=in_array($message['type'],array('success','warning','info','error'),true)?$message['type']:'info';
        echo '<div class="notice inline qif-notice notice-'.esc_attr($type).'"><p>'.wp_kses_post($message['message']).'</p></div>';
    }
}
add_action('admin_enqueue_scripts',function () {
    if (strpos(get_current_screen()->id,'qif-')===false) { return; }
    wp_enqueue_style('qif-admin',QIF_URL.'assets/admin.css',array(),'1.4.2');
    wp_enqueue_script('qif-admin',QIF_URL.'assets/admin.js',array(),'1.3.0',true);
    wp_enqueue_script('qif-workbench',QIF_URL.'assets/workbench.js',array('qif-admin'),'1.3.0',true);
    wp_localize_script('qif-admin','qifAdmin',array('nonce'=>wp_create_nonce('qif_admin')));
});
add_action('admin_init',function () { register_setting('qif','qif_settings',array('sanitize_callback'=>__NAMESPACE__.'\\sanitize')); });
function check($key,$label,$value=null,$disabled=false) {
    $s=settings();
    $on=$value===null?$s[$key]:in_array($value,$s[$key],true);
    echo '<div class="qif-field"><label class="qif-check"><input type="checkbox" name="qif_settings['.esc_attr($key).']'.($value===null?'':'[]').'" value="'.esc_attr($value ?? 1).'" '.checked($on && !$disabled,true,false).' '.disabled($disabled,true,false).'> ';
    h($label); if ($disabled) { echo ' <small>'; esc_html_e('Codec unavailable','qevora-imageflow'); echo '</small>'; } echo '</label>';
    if ($key==='sizes') {
        /* translators: %s: registered WordPress image size name. */
        $note=sprintf(__('Generate an alternative for the %s size when WordPress has created it. Enable sizes used by your theme or galleries so smaller screens can load smaller images.', 'qevora-imageflow'),$value);
    } elseif ($key==='sources' || $key==='targets') {
        $note=format_guidance($value,$key==='sources',$disabled);
    } else { $note=option_guidance($key); }
    echo wp_kses( guidance_html($label,$note,$disabled), help_tags() ).'</div>';
}
function options_page() {
    settings_notices();
    $s=settings();
    echo '<form method="post" action="options.php">'; settings_fields('qif');
    echo '<section><h2>'; esc_html_e('Image formats','qevora-imageflow'); echo '</h2><h3>'; esc_html_e('Convert from','qevora-imageflow'); echo '</h3><div class="qif-grid">';
    foreach (formats() as $key=>$mime) { check('sources',strtoupper($key),$key,!capable($key)); }
    echo '</div><h3>'; esc_html_e('Generate','qevora-imageflow'); echo '</h3><div class="qif-grid">';
    foreach (outputs() as $key) { check('targets',strtoupper($key),$key,!encoder($key)); }
    echo '</div><p><label>'; esc_html_e('Preferred delivery format','qevora-imageflow'); echo ' <select name="qif_settings[preferred]">';
    foreach (outputs() as $key) { echo '<option '.selected($key,$s['preferred'],false).' '.disabled(!encoder($key),true,false).' value="'.esc_attr($key).'">'.esc_html(strtoupper($key)).'</option>'; }
    echo '</select></label></p>'.wp_kses( guidance_html('Preferred delivery format',option_guidance('preferred')), help_tags() ).'<p>'; esc_html_e('Same-format outputs are skipped. Images with transparency are retained when JPEG would lose alpha.','qevora-imageflow'); echo '</p></section><section><h2>'; esc_html_e('Conversion & delivery','qevora-imageflow'); echo '</h2>';
    foreach (array('auto'=>__('Convert new uploads in the background','qevora-imageflow'),'smaller'=>__('Keep only smaller alternatives','qevora-imageflow'),'strip'=>__('Strip metadata where supported','qevora-imageflow'),'delivery'=>__('Deliver optimized images','qevora-imageflow'),'elementor'=>__('Enable delivery on Elementor sites','qevora-imageflow'),'background'=>__('Optimize supported CSS backgrounds on Elementor sites','qevora-imageflow')) as $k=>$label) { check($k,$label); }
    echo '<h3>'; esc_html_e('Intermediate image sizes','qevora-imageflow'); echo '</h3><div class="qif-grid">';
    foreach (get_intermediate_image_sizes() as $size) { check('sizes',$size,$size); }
    echo '</div></section><section><h2>'; esc_html_e('Quality & limits','qevora-imageflow'); echo '</h2><div class="qif-grid">';
    foreach (array('webp'=>__('WebP quality','qevora-imageflow'),'avif'=>__('AVIF quality','qevora-imageflow'),'jpeg'=>__('JPEG quality','qevora-imageflow'),'png'=>__('PNG compression','qevora-imageflow'),'megabytes'=>__('Maximum MB','qevora-imageflow'),'megapixels'=>__('Maximum megapixels','qevora-imageflow')) as $k=>$label) {
        $max=array('png'=>9,'megabytes'=>128,'megapixels'=>64)[$k] ?? 100;
        echo '<div class="qif-field"><label>'; h($label); echo '<input type="number" min="'.($k==='png'?0:1).'" max="'.intval($max).'" name="qif_settings['.esc_attr($k).']" value="'.intval($s[$k]).'"></label>'.wp_kses( guidance_html($label,option_guidance($k)), help_tags() ).'</div>';
    }
    echo '</div><p><label>'; esc_html_e('Excluded attachment IDs (comma-separated)','qevora-imageflow'); echo ' <input name="qif_settings[excluded_ids]" value="'.esc_attr($s['excluded_ids']).'"></label></p>'.wp_kses( guidance_html('Excluded IDs',option_guidance('excluded_ids')), help_tags() ).'<p>'; esc_html_e('Run bulk conversion after changing output, quality, size or exclusion settings.','qevora-imageflow'); echo '</p></section>'; submit_button(); echo '</form>';
}
function button($cmd,$label,$id=0) {
    echo '<div class="qif-help-action"><button type="button" class="button" data-qif="'.esc_attr($cmd).'" data-id="'.absint($id).'">'; h($label); echo '</button>'.wp_kses( guidance_html($label,option_guidance($cmd)), help_tags() ).'</div>';
}
function output_labels($id) {
    $map=get_post_meta($id,'_qif_map',true);
    $formats=array();
    foreach (is_array($map) ? $map : array() as $variants) {
        foreach ($variants as $format=>$variant) {
            if (is_file(root_dir().'/qevora-imageflow/'.$id.'/'.basename($variant['file']))) {
                $formats[$format]=strtoupper($format);
            }
        }
    }
    return implode(', ',$formats);
}
function bulk_form() {
    $s=settings();
    echo '<section><h2>1. Choose images</h2><label>Images to convert <select id="qif-scope"><option value="selected">Checked images</option><option value="start">All eligible images in the library</option><option value="retry">Failed eligible images</option><option value="force">Force reconvert checked images</option></select></label>';
    echo wp_kses( guidance_html('Images to convert',option_guidance('scope')), help_tags() );
    echo '<p>Enabled source formats: '.esc_html(strtoupper(implode(', ',$s['sources']))).'.</p>';
    media_rows('bulk');
    echo '</section><section><h2>2. Choose output formats</h2>';
    bulk_format_choices($s);
    sample_controls();
    echo '<p>Choose one or more formats for this batch. Settings controls the preferred format delivered to visitors.</p></section><section><h2>3. Review and convert</h2><p id="qif-review" aria-live="polite"></p>';
    button('launch','Start conversion');
    foreach (array('pause'=>__('Pause','qevora-imageflow'),'resume'=>__('Resume','qevora-imageflow'),'stop'=>__('Stop','qevora-imageflow')) as $cmd=>$label) { button($cmd,$label); }
    progress_meter();
    echo '<p role="status" id="qif-status"></p></section>';
}
function bulk_format_choices($s) {
    echo '<fieldset id="qif-targets"><legend>Convert images to</legend><div class="qif-format-options">';
    foreach (outputs() as $format) {
        $available=encoder($format);
        $note_id='qif-format-help-'.$format;
        echo '<div class="qif-format-option"><label class="qif-format"><input type="checkbox" value="'.esc_attr($format).'" '.checked($available && in_array($format,$s['targets'],true),true,false).' '.disabled(!$available,true,false).'> '.esc_html(strtoupper($format)).($available?'':' (encoder unavailable)').'</label>';
        echo '<button type="button" class="qif-format-help" aria-expanded="false" aria-controls="'.esc_attr($note_id).'">'.esc_html($available ? __('How it works','qevora-imageflow') : __('Why is this unavailable?','qevora-imageflow')).'<span class="screen-reader-text"> — '.esc_html(strtoupper($format)).'</span></button></div>';
    }
    echo '</div></fieldset>';
    foreach (outputs() as $format) {
        echo '<div class="qif-format-note" id="qif-format-help-'.esc_attr($format).'" hidden><strong>'.esc_html(strtoupper($format)).'</strong><p>'.esc_html(format_guidance($format,false,!encoder($format))).'</p></div>';
    }
}
function progress_meter() {
    echo '<div id="qif-progress" hidden><label for="qif-meter">Conversion progress <strong id="qif-percent">0%</strong></label><progress id="qif-meter" value="0" max="100"></progress></div>';
}
function media_rows($key) {
    // Read-only list filters; AJAX writes have a separate capability and nonce check.
    $page=max(1,absint(filter_input(INPUT_GET,'paged',FILTER_VALIDATE_INT)));
    $mime=sanitize_key((string)filter_input(INPUT_GET,'fmt',FILTER_UNSAFE_RAW));
    $state=sanitize_key((string)filter_input(INPUT_GET,'state',FILTER_UNSAFE_RAW));
    $args=array('post_type'=>'attachment','post_status'=>'inherit','post_mime_type'=>formats()[$mime] ?? 'image','posts_per_page'=>20,'paged'=>$page);
    if (in_array($state,array('converted','failed','skipped'),true)) { $args['meta_query']=array(array('key'=>'_qif_status','value'=>$state)); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Explicit paginated filter on plugin-owned metadata.
    }
    $month=sanitize_text_field((string)filter_input(INPUT_GET,'month',FILTER_UNSAFE_RAW));
    if (preg_match('/^(\d{4})-(0[1-9]|1[0-2])$/',$month,$parts)) { $args['date_query']=array(array('year'=>(int)$parts[1],'month'=>(int)$parts[2])); }
    if ($key==='logs' && !$state) { $args['meta_query']=array(array('key'=>'_qif_detail','compare'=>'EXISTS')); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Paginated latest-result log.
    }
    if (isset($args['meta_query'])) { $args['cache_results']=false; }
    $query=new \WP_Query($args);
    $filtered = $mime || $state || $month;
    echo '<div id="qif-results" class="qif-library" data-view="'.esc_attr($key).'">';
    echo '<div class="qif-list-title"><h3>'; esc_html_e('Image list','qevora-imageflow'); echo '</h3><span>'.esc_html(number_format_i18n($query->found_posts)).' '.esc_html(_n('image','images',$query->found_posts,'qevora-imageflow')).'</span></div>';
    echo '<details class="qif-filters" '.($filtered ? 'open' : '').'><summary>'; esc_html_e('Filter image list','qevora-imageflow'); echo '</summary>';
    echo '<form method="get" action="'.esc_url(admin_url('admin.php')).'"><input type="hidden" name="page" value="qif-'.esc_attr($key).'">';
    echo '<div><label>Original format<select name="fmt"><option value="">All formats</option>';
    foreach (formats() as $k=>$val) { echo '<option '.selected($mime,$k,false).' value="'.esc_attr($k).'">'.esc_html(strtoupper($k)).'</option>'; }
    echo '</select></label>'.wp_kses( guidance_html('Original format',option_guidance('fmt')), help_tags() ).'</div><div><label>Conversion status<select name="state"><option value="">Any status</option>';
    foreach (array('converted','failed','skipped') as $k) { echo '<option value="'.esc_attr($k).'" '.selected($state,$k,false).'>'.esc_html(ucfirst($k)).'</option>'; }
    echo '</select></label>'.wp_kses( guidance_html('Conversion status',option_guidance('state')), help_tags() ).'</div><div><label>Uploaded in<input type="month" name="month" value="'.esc_attr($month).'"></label>'.wp_kses( guidance_html('Uploaded in',option_guidance('month')), help_tags() ).'</div><button class="button">Apply filters</button>';
    if ($filtered) { echo '<a href="'.esc_url(page_url($key)).'">Clear filters</a>'; }
    echo '</form></details><div class="qif-list-scroll" tabindex="0" role="region" aria-label="Image list"><table class="qif-list-table"><thead><tr><th><input type="checkbox" aria-label="Select visible page" id="qif-all"></th><th>Image</th><th>Original</th><th>Generated</th><th>Status</th><th>Last result (UTC)</th></tr></thead><tbody>';
    foreach ($query->posts as $p) {
        $id=$p->ID;
        $status=get_post_meta($id,'_qif_status',true) ?: 'pending';
        $detail=rtrim((string)get_post_meta($id,'_qif_detail',true)," ·");
        $title=$p->post_title ?: '#'.$id;
        $thumb=wp_get_attachment_image($id,'thumbnail',false,array('class'=>'qif-thumbnail','alt'=>'','loading'=>'lazy'));
        echo '<tr data-attachment="'.absint($id).'"><td><input class="qif-id" type="checkbox" aria-label="'.esc_attr('Select '.$title).'" value="'.absint($id).'"></td>';
        echo '<td><a class="qif-image-link" href="'.esc_url(get_edit_post_link($id)).'">'.wp_kses_post($thumb).'<span>'.esc_html($title).'</span></a></td>';
        echo '<td>'.esc_html(strtoupper(str_replace('image/','',get_post_mime_type($id)))).'</td><td><span class="qif-output" data-result="formats">'.esc_html(output_labels($id) ?: '—').'</span></td>';
        echo '<td><span class="qif-state" data-state="'.esc_attr($status).'" data-result="status">'.esc_html($status).'</span></td><td data-result="detail">'.esc_html($detail ?: '—').'</td></tr>';
    }
    if (!$query->posts) { echo '<tr><td colspan="6" class="qif-empty">No images match this list.</td></tr>'; }
    echo '</tbody></table></div>';
    $links=paginate_links(array('base'=>add_query_arg(array('paged'=>'%#%','fmt'=>$mime,'state'=>$state,'month'=>$month),page_url($key)),'format'=>'','total'=>$query->max_num_pages,'current'=>$page));
    if ($links) { echo '<div class="qif-pages">'.wp_kses_post($links).'</div>'; }
    echo '</div>';
}
function invalidate_total($meta_id,$object_id,$key) {
    if ('_qif_after'===$key) { delete_transient('qif_has_generated'); }
    if (in_array($key,array('_qif_before','_qif_after'),true)) { wp_cache_delete($key,'qif_totals'); }
}
add_action('updated_post_meta',__NAMESPACE__.'\\invalidate_total',10,3);
add_action('added_post_meta',__NAMESPACE__.'\\invalidate_total',10,3);
add_action('deleted_post_meta',__NAMESPACE__.'\\invalidate_total',10,3);
function stats($meta) {
    global $wpdb;
    $total=wp_cache_get($meta,'qif_totals');
    if (false===$total) {
        // WP_Query has no aggregate API. Sum one plugin-owned key and cache it until metadata changes.
        $total=(int)$wpdb->get_var($wpdb->prepare("SELECT SUM(CAST(meta_value AS SIGNED)) FROM $wpdb->postmeta WHERE meta_key=%s",$meta)); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Cache surrounds this aggregate.
        wp_cache_set($meta,$total,'qif_totals',300);
    }
    return $total;
}
function page() {
    global $plugin_page;
    $key=substr(sanitize_key((string)$plugin_page),4);
    echo '<div class="wrap qif"><header class="qif-brand"><img class="qif-brand-mark" src="'.esc_url(QIF_URL.'assets/qevora-mark.png').'" alt=""><div class="qif-brand-type"><span>Qevora Software</span><strong class="qif-product-name">ImageFlow</strong></div></header><nav>';
    foreach (pages() as $k=>$title) { echo '<a '.($k===$key?'aria-current="page"':'').' href="'.esc_url(page_url($k)).'">'; h($title); echo '</a>'; }
    echo '</nav><h1 class="qif-title">'; h(pages()[$key] ?? 'Dashboard'); echo '</h1><hr class="wp-header-end">'; if ($key!=='bulk') { echo '<p role="status" id="qif-status"></p>'; }
    if ($key==='origin') { origin_page(); } elseif ($key==='audit') { audit_panel(); } elseif ($key==='workflow') { workflow_screen(); } elseif ($key==='docs') {
        require __DIR__.'/../docs/guide.html';
    } elseif ($key==='inspector') {
        echo '<section><h2>Inspect a published page</h2><p>Open any frontend page while signed in, then choose <strong>Inspect page images</strong> in the WordPress toolbar. Open the widget or popup you want to inspect and select <strong>Scan viewport</strong>.</p>';
        echo '<a class="button button-primary" href="'.esc_url(add_query_arg('qif_inspect','1',home_url('/'))).'">Inspect homepage</a> <a class="button" href="'.esc_url(page_url('docs').'#inspector').'">Read inspector guide</a></section>';
    } elseif ($key==='settings') { options_page(); }
    elseif ($key==='home') {
        $a=stats('_qif_before'); $b=stats('_qif_after');
        echo '<div class="qif-grid cards">';
        $base=array('mode'=>'start','cursor'=>0,'limit'=>PHP_INT_MAX);
        $eligible=attachment_query($base,true)->found_posts;
        $pending=attachment_query($base,true,'pending')->found_posts;
        $base['config']['sources']=array_keys(formats());
        foreach (array('Eligible sources'=>$eligible,'Pending'=>$pending,'Converted'=>attachment_query($base,true,'converted')->found_posts,'Skipped'=>attachment_query($base,true,'skipped')->found_posts,'Failed'=>attachment_query($base,true,'failed')->found_posts,'Delivery savings'=>size_format(abs($a-$b)).($a<$b?' increase':'').' ('.($a?round(100*($a-$b)/$a):0).'%)') as $label=>$val) { echo '<section><span>'; h($label); echo '</span><strong>'.esc_html($val).'</strong></section>'; }
        echo '</div><section><h2>Storage comparison</h2><p>Matched originals · '.esc_html(size_format($a)).' &nbsp; Preferred alternatives · '.esc_html(size_format($b)).'</p><a class="button button-primary" href="'.esc_url(page_url('bulk')).'">Convert existing images</a></section>';
        audit_panel(false);
        diagnostics();
    } elseif ($key==='elementor') {
        echo '<section><h2>Elementor assets</h2><p>Image URLs and responsive candidates are resolved in the browser. Same-origin style rules with one background URL can use typed image-set alternatives, including media queries and hover states. Complex backgrounds retain their original CSS.</p>';
        button('css','Regenerate Elementor CSS'); echo '<a class="button" href="'.esc_url(page_url('settings')).'">Delivery settings</a></section>'; diagnostics();
    } elseif ($key==='bulk') { bulk_form(); }
    else {
        echo '<section>';
        if ($key==='media') { progress_meter(); button('selected','Convert selected'); button('restore','Remove selected derivatives'); }
        if ($key==='logs') { button('original','Restore original delivery'); button('export','Export settings'); button('clear','Clear log messages'); }
        media_rows($key); echo '</section>';
    }
    echo '</div>';
}
function diagnostics() {
    echo '<section><h2>Server diagnostics</h2><dl>';
    foreach (array('Imagick'=>class_exists('Imagick')?'Available':'Unavailable','GD'=>extension_loaded('gd')?'Available':'Unavailable','WebP encoder'=>encoder('webp')?'Available':'Unavailable','AVIF encoder'=>encoder('avif')?'Available':'Unavailable','Elementor'=>defined('ELEMENTOR_VERSION')?ELEMENTOR_VERSION:'Inactive','Upload limit'=>size_format(wp_max_upload_size()),'PHP memory'=>ini_get('memory_limit')) as $k=>$v) { echo '<dt>'.esc_html($k).'</dt><dd>'.esc_html($v).'</dd>'; }
    echo '</dl></section>';
}
function queue($cmd,$ids,$requested=null,$quality=array()) {
    $job=get_option('qif_job',array('state'=>'idle','done'=>0,'total'=>0));
    if (in_array($cmd,array('start','selected','force','retry'),true)) {
        if ($job['state']==='running' || $job['state']==='paused') { throw new \RuntimeException('Stop the current job first.'); }
        $list=in_array($cmd,array('selected','force'),true);
        if ($list && !$ids) { throw new \RuntimeException('Select at least one attachment.'); }
        $job=array('mode'=>$cmd,'ids'=>$ids,'cursor'=>0,'state'=>'running','done'=>0,'converted'=>0,'skipped'=>0,'failed'=>0,'saved'=>0,'last'=>'');
        $config=settings();
        $config['rules']=workflow_options()['rules'];
        foreach ($quality as $format=>$value) { $config[$format]=$value; }
        $targets=$requested===null ? $config['targets'] : $requested;
        if (!is_array($targets) || !$targets || array_diff($targets,array_filter(outputs(),__NAMESPACE__.'\\encoder'))) {
            throw new \RuntimeException('Choose at least one available output format.');
        }
        $config['targets']=array_values(array_unique($targets));
        if (!in_array($config['preferred'],$targets,true)) { $config['preferred']=$config['targets'][0]; }
        $job['config']=$config;
        $job['limit']=PHP_INT_MAX;
        $job['limit']=(int)(attachment_query($job,false,'','DESC')->posts[0] ?? 0);
        $job['total']=$list?count($ids):(int)attachment_query($job,true)->found_posts;
    } elseif ($cmd==='pause' && $job['state']==='running') { $job['state']='paused'; }
    elseif ($cmd==='resume' && $job['state']==='paused') { $job['state']='running'; }
    elseif ($cmd==='stop') { $job['state']='stopped'; }
    elseif ($cmd==='tick' && $job['state']==='running') {
        $list=in_array($job['mode'],array('selected','force'),true);
        $id=$list?($job['ids'][$job['done']] ?? 0):(int)(attachment_query($job)->posts[0] ?? 0);
        if (!$id) { $job['state']='complete'; }
        else {
            $result=convert($id,$job['mode']==='force',$job['config'] ?? null);
            if ($result!=='busy') {
                ++$job[$result]; ++$job['done']; $job['cursor']=$id;
                $job['saved']+=(int)get_post_meta($id,'_qif_saved',true);
                $job['last']='#'.$id.' '.$result.' '.get_post_meta($id,'_qif_detail',true);
            }
        }
    }
    if ($cmd!=='status') { update_option('qif_job',$job,false); }
    $job['targets']=$job['config']['targets'] ?? array();
    if (!empty($id) && isset($result) && $result!=='busy') {
        $job['row']=array('id'=>$id,'formats'=>output_labels($id) ?: '—','status'=>$result,'detail'=>get_post_meta($id,'_qif_detail',true));
    }
    unset($job['ids'],$job['config']); return $job;
}
function attachment_query($job,$count=false,$state='',$order='ASC',$mime_override=null,$page_size=1) {
    $mimes=$mime_override ?? array_values(array_intersect_key(formats(),array_flip($job['config']['sources'] ?? settings()['sources'])));
    $args=array('post_type'=>'attachment','post_status'=>'inherit','post_mime_type'=>$mimes,
        'fields'=>'ids','posts_per_page'=>$page_size,'orderby'=>'ID','order'=>$order,'no_found_rows'=>!$count,
        'qif_bounds'=>true,'ignore_sticky_posts'=>true,'cache_results'=>false);
    if (!$mimes) { $args['post__in']=array(0); }
    if ($job['mode']==='retry') { $state='failed'; }
    if ($state) {
        $args['meta_query']=array($state==='pending' ? array('key'=>'_qif_status','compare'=>'NOT EXISTS') : array('key'=>'_qif_status','value'=>$state)); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Match persisted conversion state, including pre-upgrade records.
    }
    $bounds=static function ($where,$query) use ($job) {
        global $wpdb;
        if ($query->get('qif_bounds')) {
            $where.=$wpdb->prepare(" AND {$wpdb->posts}.ID > %d AND {$wpdb->posts}.ID <= %d",$job['cursor'],$job['limit']);
        }
        return $where;
    };
    add_filter('posts_where',$bounds,10,2);
    try { return new \WP_Query($args); }
    finally { remove_filter('posts_where',$bounds,10); }
}
add_action('wp_ajax_qif_action',function () {
    check_ajax_referer('qif_admin');
    if (!current_user_can('manage_options')) { wp_send_json_error('Access denied',403); }
    $cmd=sanitize_key($_POST['cmd'] ?? '');
    $ids=array_slice(wp_parse_id_list(wp_unslash($_POST['ids'] ?? '')),0,100);
    $quality=array();
    foreach (array('webp','avif') as $format) { if (isset($_POST[$format])) { $quality[$format]=max(1,min(100,absint($_POST[$format]))); } }
    $requested=isset($_POST['targets']) ? explode(',',sanitize_text_field(wp_unslash($_POST['targets']))) : null;
    try {
        switch ($cmd) {
            case 'results':
                $view=sanitize_key($_POST['view'] ?? '');
                if (!in_array($view,array('bulk','media','logs'),true)) { wp_send_json_error('Invalid view',400); }
                ob_start();
                media_rows($view);
                wp_send_json_success(array('html'=>ob_get_clean()));
                break;
            case 'export': wp_send_json_success(settings()); break;
            case 'original': $s=settings(); $s['delivery']=0; update_option('qif_settings',$s); $result='Original delivery enabled'; break;
            case 'clear': delete_post_meta_by_key('_qif_detail'); $result='Log messages cleared'; break;
            case 'css':
                if (!class_exists('\Elementor\Plugin') || empty(\Elementor\Plugin::$instance->files_manager) || !is_callable(array(\Elementor\Plugin::$instance->files_manager,'clear_cache'))) { throw new \RuntimeException('Elementor cache API unavailable.'); }
                \Elementor\Plugin::$instance->files_manager->clear_cache(); $result='Elementor CSS cache cleared'; break;
            case 'review_restore':
                if (!$ids) { throw new \RuntimeException('Select attachments first.'); }
                wp_send_json_success(array('usage'=>removal_review($ids),'confirm'=>wp_create_nonce('qif_remove_'.implode(',',$ids))));
                break;
            case 'restore':
                check_ajax_referer('qif_remove_'.implode(',',$ids),'confirm');
                if (!$ids) { throw new \RuntimeException('Select attachments first.'); }
                $result=array();
                foreach ($ids as $id) { if (wp_attachment_is_image($id)) { $result[]='#'.$id.' '.remove_files($id); } }
                $result=implode(', ',$result); break;
            default: $result=locked(0,function () use ($cmd,$ids,$requested,$quality) { return queue($cmd,$ids,$requested,$quality); });
        }
        wp_send_json_success($result);
    } catch (\RuntimeException $e) { wp_send_json_error($e->getMessage(),400); }
});
add_filter('manage_upload_columns',function ($cols) { $cols['qif']='Qevora ImageFlow'; return $cols; });
add_action('manage_media_custom_column',function ($col,$id) {
    if ($col!=='qif' || !wp_attachment_is_image($id)) { return; }
    $map=get_post_meta($id,'_qif_map',true);
    $types=array();
    foreach (is_array($map)?$map:array() as $variants) { $types=array_merge($types,array_keys($variants)); }
    echo esc_html(get_post_mime_type($id).' · '.(get_post_meta($id,'_qif_status',true) ?: 'pending').' '.implode(', ',array_unique($types)).' · '.(int)get_post_meta($id,'_qif_saved',true).' B saved');
},10,2);
add_filter('attachment_fields_to_edit',function ($fields,$post) {
    if (!wp_attachment_is_image($post->ID) || !current_user_can('manage_options')) { return $fields; }
    $html='';
    foreach (array('convert'=>__('Convert now','qevora-imageflow'),'force'=>__('Reconvert','qevora-imageflow'),'restore'=>__('Remove derivatives','qevora-imageflow')) as $cmd=>$label) {
        $url=wp_nonce_url(add_query_arg(array('action'=>'qif_single','id'=>$post->ID,'cmd'=>$cmd),admin_url('admin-post.php')),'qif_'.$post->ID);
        $html.='<a class="button" href="'.esc_url($url).'">'.esc_html($label).'</a> '.wp_kses( guidance_html($label,option_guidance($cmd)), help_tags() );
    }
    $base=wp_get_upload_dir()['baseurl'];
    $map=get_post_meta($post->ID,'_qif_map',true);
    foreach (is_array($map)?$map:array() as $rel=>$variants) {
        $html.='<p>Original · '.esc_html(size_format(reset($variants)['original'])).'<br><img width="96" alt="Original preview" src="'.esc_url($base.'/'.$rel).'"></p>';
        foreach ($variants as $fmt=>$v) {
            $url=$base.'/qevora-imageflow/'.$post->ID.'/'.basename($v['file']);
            $html.='<a href="'.esc_url($url).'"><img width="96" alt="'.esc_attr($fmt).' preview" src="'.esc_url($url).'"> '.esc_html(strtoupper($fmt).' · '.size_format($v['bytes'])).'</a> ';
        }
        break;
    }
    $fields['qif']=array('label'=>__('Qevora ImageFlow','qevora-imageflow'),'input'=>'html','html'=>$html);
    return $fields;
},10,2);
add_action('admin_post_qif_single',function () {
    $id=absint($_GET['id'] ?? 0);
    check_admin_referer('qif_'.$id);
    if (!current_user_can('manage_options') || !current_user_can('edit_post',$id) || !wp_attachment_is_image($id)) { wp_die('Access denied'); }
    $cmd=sanitize_key($_GET['cmd'] ?? '');
    if ($cmd==='restore') {
        $action='qif_confirm_remove_'.$id;
        if (empty($_POST['qif_confirm'])) {
            echo '<main><h1>Review recent image usage</h1><p>Recent scans for attachment #'.absint($id).'</p><pre>'.esc_html(removal_review(array($id))).'</pre><p>Confirm to remove generated files. The original stays in the Media Library.</p><form method="post">';
            wp_nonce_field($action,'qif_confirm',false);
            echo '<button type="submit">Remove derivatives</button> <a href="'.esc_url(get_edit_post_link($id,'raw')).'">Cancel</a></form></main>';
            exit;
        }
        check_admin_referer($action,'qif_confirm');
        remove_files($id);
    } else { convert($id,$cmd==='force'); }
    wp_safe_redirect(admin_url('post.php?post='.$id.'&action=edit')); exit;
});
