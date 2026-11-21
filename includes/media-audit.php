<?php
namespace Qevora\ImageFlow;
defined('ABSPATH') || exit;

function audit_defaults() {
    return array('image'=>array('medium'=>300,'high'=>1024),'video'=>array('medium'=>10,'high'=>50));
}

function audit_begin($limits) {
    $latest=attachment_query(array('cursor'=>0,'limit'=>PHP_INT_MAX,'mode'=>'audit'),true,'','DESC',array('image','video'),1);
    $empty=array_fill_keys(array('high','medium','low','unknown'),array('count'=>0,'bytes'=>0,'largest'=>array()));
    return array('cursor'=>0,'limit'=>(int)($latest->posts[0] ?? 0),'total'=>(int)$latest->found_posts,'done'=>0,
        'started'=>microtime(true),'limits'=>$limits,'groups'=>array('image'=>$empty,'video'=>$empty));
}

function audit_measure($id) {
    $file=get_attached_file($id,true);
    if ($file && !wp_is_stream($file) && is_file($file) && is_readable($file)) {
        $bytes=filesize($file);
        if (false!==$bytes) { return array('bytes'=>$bytes,'source'=>'Local file'); }
    }
    $meta=wp_get_attachment_metadata($id);
    if (isset($meta['filesize']) && is_numeric($meta['filesize']) && $meta['filesize']>0) {
        return array('bytes'=>(int)$meta['filesize'],'source'=>'Saved metadata');
    }
    return array('bytes'=>null,'source'=>'Size unavailable');
}

function audit_add(&$scan,$id) {
    $type=strtok((string)get_post_mime_type($id),'/');
    if (!isset($scan['groups'][$type])) { return; }
    $measure=audit_measure($id);
    $bytes=$measure['bytes'];
    $unit='image'===$type?1024:1048576;
    $limits=$scan['limits'][$type];
    $band=null===$bytes?'unknown':($bytes >= $unit*$limits['high']?'high':($bytes >= $unit*$limits['medium']?'medium':'low'));
    $bucket=&$scan['groups'][$type][$band];
    ++$bucket['count'];
    $bucket['bytes']+=$bytes ?? 0;
    $rows=&$bucket['largest'];
    if (count($rows)>=20 && ($bytes ?? 0)<=($rows[19]['bytes'] ?? 0)) { return; }
    $rows[]=array('id'=>$id,'title'=>get_the_title($id) ?: '#'.$id,'bytes'=>$bytes,'source'=>$measure['source'],
        'status'=>'image'===$type?(get_post_meta($id,'_qif_status',true) ?: 'Not processed'):('high'===$band || 'medium'===$band?'Check duration and bitrate':('unknown'===$band?'Check source file':'Within size threshold')));
    usort($rows,static function ($a,$b) { return ($b['bytes']<=>$a['bytes']) ?: ($a['id']<=>$b['id']); });
    if (count($rows)>20) { array_pop($rows); }
}

function audit_step(&$scan) {
    $batch=attachment_query(array('cursor'=>$scan['cursor'],'limit'=>$scan['limit'],'mode'=>'audit'),false,'','ASC',array('image','video'),50)->posts;
    if ($batch) { update_meta_cache('post',$batch); }
    foreach ($batch as $id) {
        audit_add($scan,(int)$id);
        $scan['cursor']=(int)$id;
        ++$scan['done'];
    }
    return count($batch)<50 || $scan['cursor'] >= $scan['limit'];
}

add_action('wp_ajax_qif_audit',function () {
    check_ajax_referer('qif_admin');
    if (!current_user_can('manage_options')) { wp_send_json_error('Access denied',403); }
    $cmd=sanitize_key($_POST['cmd'] ?? '');
    $key='qif_scan_'.get_current_user_id();
    $token=sanitize_key($_POST['token'] ?? '');
    $limits=audit_defaults();
    if ('start'===$cmd) {
        foreach ($limits as $type=>$bands) {
            foreach ($bands as $band=>$value) {
                $field=$type.'_'.$band;
                $limits[$type][$band]=absint($_POST[$field] ?? $value);
            }
            if ($limits[$type]['medium']<1 || $limits[$type]['high']<=$limits[$type]['medium'] || $limits[$type]['high']>1048576) {
                wp_send_json_error('Use positive thresholds, with High larger than Medium (maximum 1048576).',400);
            }
        }
    }
    try {
        $reply=locked(0,static function () use ($cmd,$key,$token,$limits) {
            if ('start'===$cmd) {
                $scan=audit_begin($limits);
                $scan['token']=wp_generate_uuid4();
            } else {
                $scan=get_transient($key);
                if ('tick'!==$cmd || !$scan || $scan['token']!==$token) { throw new \RuntimeException('Scan expired or replaced. Start a new scan.'); }
                if (!empty($scan['finished'])) { return $scan; }
            }
            if (audit_step($scan)) {
                $scan['finished']=gmdate('c');
                $previous=get_option('qif_last_audit',array());
                if ($scan['started']>=($previous['started'] ?? 0)) { update_option('qif_last_audit',$scan,false); }
            }
            set_transient($key,$scan,HOUR_IN_SECONDS);
            return empty($scan['finished'])?array_intersect_key($scan,array_flip(array('token','total','done'))):$scan;
        });
        wp_send_json_success($reply);
    } catch (\Throwable $e) { wp_send_json_error('Unable to continue the media scan. Start a new scan.',400); }
});

add_action('admin_enqueue_scripts',function () {
    $screen=get_current_screen();
    $full='qif-audit'===substr($screen->id,-9);
    if (!$full && 'qif-home'!==substr($screen->id,-8)) { return; }
    $saved=get_option('qif_last_audit',array());
    wp_enqueue_style('qif-audit',QIF_URL.'assets/media-audit.css',array('qif-admin'),'1.3.0');
    wp_enqueue_script('qif-audit',QIF_URL.'assets/media-audit.js',array('qif-admin'),'1.3.0',true);
    wp_localize_script('qif-audit','qifMediaAudit',array('report'=>$saved,'edit'=>admin_url('post.php'),'full'=>(int)$full));
});

function audit_panel($full=true) {
    $saved=get_option('qif_last_audit',array());
    $limits=$saved['limits'] ?? audit_defaults();
    ?>
    <section id="qif-media-audit" aria-label="Media size overview">
    <div class="qif-audit-heading"><div><h2>Media size overview</h2><p>Find the images and videos that deserve a closer look.</p></div>
    <?php if ($full) { ?><button class="button button-primary" id="qif-run-audit">Scan library</button><?php } else { ?><a class="button" href="<?php echo esc_url(page_url('audit')); ?>">Open media report</a><?php } ?>
    </div>
    <?php if ($full) { ?>
    <details class="qif-thresholds"><summary>Size thresholds</summary><p>Medium and High start at these sizes. Smaller files are Low. Changes apply to the next scan. Priority reflects source file size. A large source may already have smaller delivery copies.</p><div>
    <?php foreach ($limits as $type=>$bands) { foreach ($bands as $band=>$value) { ?>
    <label><?php echo esc_html(ucfirst($type).' · '.ucfirst($band).('image'===$type?' (KiB)':' (MiB)')); ?>
    <input type="number" id="<?php echo esc_attr('qif-'.$type.'-'.$band); ?>" min="1" max="1048576" step="1" required value="<?php echo absint($value); ?>"></label>
    <?php } } ?></div></details>
    <progress id="qif-audit-progress" aria-label="Library scan progress" hidden></progress><?php } ?>
    <p role="status" id="qif-audit-message"></p><div id="qif-audit-chart"></div>
    <?php if ($full) { ?>
    <div id="qif-audit-list" hidden><div class="qif-audit-heading"><div><h3>Files to review</h3><p>Up to 20 largest files per type and priority. Share is each file’s percentage of all measured bytes.</p></div></div>
    <div class="qif-audit-filters"><label>Media type <select id="qif-audit-type"><option value="all">Images and videos</option><option value="image">Images</option><option value="video">Videos</option></select></label>
    <label>Priority <select id="qif-audit-band"><option value="all">All priorities</option><option value="high">High</option><option value="medium">Medium</option><option value="low">Low</option><option value="unknown">Unknown size</option></select></label></div>
    <div class="qif-audit-scroll"><table class="widefat"><thead><tr><th>Attachment</th><th>Type</th><th>Priority</th><th>File size</th><th>Share</th><th>Review</th></tr></thead><tbody id="qif-audit-rows"></tbody></table></div></div>
    <?php } ?></section>
    <?php
}
