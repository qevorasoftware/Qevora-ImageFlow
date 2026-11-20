# Acceptance report — version 1.0.0

This build has not run on a live WordPress, Elementor Free/Pro or WooCommerce installation. No PHP interpreter or WordPress test stack was available in the build workspace.

| Acceptance test | Result |
|---|---|
| Clean WordPress installation activates with no errors. | NOT TESTED |
| Plugin installs and works even if Elementor is absent. | NOT TESTED |
| Format checkboxes actually govern which uploads/images are converted. | NOT TESTED |
| Unsupported encoder choices are visibly disabled with explanation. | NOT TESTED |
| JPEG and PNG uploads generate valid smaller WebP (where possible). | NOT TESTED |
| PNG transparency is preserved in WebP/AVIF derivatives. | NOT TESTED |
| Animated GIF is not accidentally replaced by a static frame. | NOT TESTED |
| Existing original media file remains intact and accessible. | NOT TESTED |
| All selected WordPress intermediate sizes have valid derivatives/mappings. | NOT TESTED |
| Single-image Media Library conversion and restore actions work. | NOT TESTED |
| Bulk conversion of 100+ attachments can pause, resume and retry. | NOT TESTED |
| Elementor Image and Image Box: editor/preview/frontend show correct optimized images. | NOT TESTED |
| Elementor Gallery and Carousel: no broken src/srcset/JS. | NOT TESTED |
| Elementor container/section background and overlay are correctly displayed. | NOT TESTED |
| Elementor desktop/tablet/mobile backgrounds are correct on each viewport. | NOT TESTED |
| Elementor generated CSS is not corrupted and cache handling works. | NOT TESTED |
| Elementor Theme Builder templates, Loop Grid and dynamic images work. | NOT TESTED |
| WooCommerce product main image + gallery remain functional. | NOT TESTED |
| Gutenberg Image / Cover blocks and featured images remain functional. | NOT TESTED |
| Plugin deactivation / Restore Original Delivery never breaks images. | NOT TESTED |
| Unsupported assets retain original delivery (no 404/broken links). | NOT TESTED |
| Conversion fails safely for insufficient disk space or server capabilities. | NOT TESTED |
| Admin AJAX and settings reject non-authorized users and invalid nonces. | NOT TESTED |
| Production frontend does not do expensive conversions on every page view. | NOT TESTED |
| PHP lint, WordPress Coding Standards and automated plugin checks pass. | NOT TESTED |
| No console errors and no major layout regressions with a common caching plugin. | NOT TESTED |

Package structure, matching version headers, ZIP integrity and JavaScript syntax were checked independently. See delivery.test.cjs for browser delivery unit coverage.
