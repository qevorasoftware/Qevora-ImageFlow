<?php
namespace Qevora\ImageFlow;
defined('ABSPATH') || exit;

function guidance_html($label, $message, $disabled=false) {
    $summary=$disabled ? __('Why is this unavailable?', 'qevora-imageflow') : __('How it works', 'qevora-imageflow');
    return '<details class="qif-help"><summary>'.esc_html($summary).'<span class="screen-reader-text"> — '.esc_html($label).'</span></summary><p>'.esc_html($message).'</p></details>';
}

function format_guidance($format, $source, $disabled) {
    $names=array(
        'jpeg'=>'JPEG is commonly used for photographs.',
        'png'=>'PNG is useful for logos, screenshots and transparent artwork.',
        'gif'=>'GIF can contain animation. Multi-frame images are skipped to preserve motion.',
        'webp'=>'WebP is a compact format for photos and transparent graphics.',
        'avif'=>'AVIF can produce compact photographic images, though encoding may take longer.',
        'bmp'=>'BMP is a bitmap format often used by older desktop applications.',
        'tiff'=>'TIFF is used for scans and print images. Multi-page files are skipped.',
        'heic'=>'HEIC is commonly used for iPhone photos.',
        'heif'=>'HEIF is a container for photos and image sequences.'
    );
    $text=translate($names[$format],'qevora-imageflow').' ';
    if ($source) {
        $text.=sprintf(__('Select this to process existing %s files into your chosen outputs. The original stays in the Media Library.', 'qevora-imageflow'),strtoupper($format));
        if ($disabled) {
            $text.=' '.(in_array($format,array('jpeg','png','bmp'),true)
                ? __('ImageFlow could not find a compatible reader for this format in GD or Imagick.', 'qevora-imageflow')
                : __('Reading this format requires Imagick with the corresponding codec. The server may still support writing this format.', 'qevora-imageflow'));
        }
    } else {
        $text.=sprintf(__('Select this to generate a separate %s copy for each eligible size. You can select several outputs. Sources already in this format are skipped.', 'qevora-imageflow'),strtoupper($format));
        if ($format==='jpeg') { $text.=' '.__('Images requiring transparency can be skipped when JPEG would lose it.', 'qevora-imageflow'); }
        if ($disabled) { $text.=' '.__('The server needs a GD or Imagick encoder for this output. Ask your host about enabling it.', 'qevora-imageflow'); }
    }
    return $text;
}
function option_guidance($key) {
    $notes=array(
        'preferred'=>'Choose one of your generated formats to display to visitors. For example, generate WebP and AVIF and prefer WebP. If the preferred copy is unavailable, the original remains visible.',
        'auto'=>'Schedules conversion of new Media Library uploads through WordPress cron. Use Bulk Converter for files uploaded earlier.',
        'smaller'=>'Keep an alternative only when it uses fewer bytes than its source. A skipped result can mean the original was already smaller.',
        'strip'=>'Remove embedded metadata from new alternatives where supported. With this disabled, metadata-preserving conversion needs Imagick. Original files retain their metadata.',
        'delivery'=>'Allow supported pages to display generated alternatives. Turn this off to display original images while keeping your converted files.',
        'elementor'=>'Allow the delivery script on sites with Elementor active. This controls display across that site, including ordinary WordPress images.',
        'background'=>'On Elementor sites, optimize supported single-image CSS backgrounds when a matching alternative exists. External or complex backgrounds keep the original.',
        'webp'=>'Set WebP quality from 1 to 100. Higher values generally preserve more detail and produce larger files. Start at 82 and compare a sample.',
        'avif'=>'Set AVIF quality from 1 to 100. Start at 60 and compare a photo before applying it across your library.',
        'jpeg'=>'Set JPEG output quality from 1 to 100. Start at 82. Lower values can reduce file size but introduce visible artifacts.',
        'png'=>'Set lossless PNG compression from 0 to 9. Higher values can reduce file size and take longer to encode. The default is 6.',
        'megabytes'=>'Skip a source file above this file-size limit. At 24 MB, a 30 MB source is skipped. Smaller WordPress sizes are checked separately.',
        'megapixels'=>'Limit the number of pixels processed per image to control memory use. A 6000 × 4000 image is 24 megapixels.',
        'exclude'=>'Enter Media Library attachment IDs separated by commas, for example 42,73. The matching attachments will be skipped on future conversion runs.',
        'scope'=>'Checked images processes the rows you select. All eligible images scans the entire library using enabled source formats. Failed eligible images retries failures. Force reconvert rebuilds alternatives for checked rows.',
        'launch'=>'Review the image scope and chosen outputs, then start the batch. Keep this page open to process the queue and see progress after each image.',
        'pause'=>'Pause between attachments. An image already processing can finish first. Resume continues from the saved position.',
        'resume'=>'Continue the paused batch with the same images and output settings.',
        'stop'=>'End the current queue. Completed alternatives are retained. Start a new batch to process more images.',
        'selected'=>'Convert the checked attachments using the saved conversion settings. Check a sample first to review its generated formats.',
        'restore'=>'Remove generated alternatives for the selected attachments. Original uploads remain. Refresh cached pages that reference removed alternatives.',
        'css'=>'Ask Elementor to clear its generated CSS cache so Elementor can rebuild styles. Use this when old styles persist after changes.',
        'original'=>'Switch frontend delivery back to original images site-wide. Existing alternatives remain available if you enable delivery again in Settings.',
        'export'=>'Download the current ImageFlow settings as JSON for your records. The export contains configuration, not images.',
        'clear'=>'Remove the latest log messages from attachments. Conversion status, generated files and original images remain.',
        'fmt'=>'Narrow the visible list by the original upload format. For example, select JPEG to find JPEG sources even if they have WebP copies.',
        'state'=>'Show rows with the selected conversion result. For failed or skipped images, read Last result for the reason.',
        'month'=>'Choose the month the attachment was uploaded. Apply filters to update this list. A library-wide batch still uses all eligible images.',
        'convert'=>'Generate missing alternatives for this attachment using the saved formats, sizes and quality.',
        'force'=>'Rebuild alternatives for this attachment using current settings, even when matching files already exist.'
    );
    return translate($notes[$key],'qevora-imageflow');
}
