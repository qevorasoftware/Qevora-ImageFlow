# Qevora ImageFlow

A WordPress plugin by Qevora Software. Version 1.0.5 is packaged for staging validation. See [acceptance results](tests/acceptance.md) for release coverage.

## Start on an existing Elementor site

1. Use the packaged ZIP with the top-level folder `qevora-imageflow/`. GitHub’s **Code → Download ZIP** names the folder after the branch and causes Plugin Check text-domain mismatches. Install the packaged ZIP from Plugins → Add New → Upload Plugin on staging. Open ImageFlow → Settings. Keep original delivery enabled by turning off “Deliver optimized images” while preparing your first batch.
2. Select source types, outputs, preferred format, and sizes. JPG and PNG are initially selected. The server must provide GD or Imagick with the relevant codecs. Advertised codec availability is provisional until a real conversion succeeds.
3. Open an attachment, click Convert now, and inspect the previews and byte counts. Use a transparent logo, a large photo and an animation as samples.
4. In Bulk Converter choose checked images or the whole eligible library, select output formats, review the summary and click Start conversion. Selection and forced conversion use the checked rows. Filters narrow the displayed list. Start all eligible scans all enabled source formats. Pause or stop between attachments. Closing the page suspends processing until the bulk screen reopens. New uploads use WP-Cron.
5. Enable delivery. Check editor canvas, preview and published pages at desktop, tablet and mobile widths. Inspect the image currentSrc and computed background-image. Exercise sliders, hover states, overlays, dynamic templates and popups.
6. Logs & Tools → Restore original delivery disables new substitutions. Reload open pages. Purge page/CDN caches after changing delivery settings. Remove derivatives only after cached references expire.

## Delivery and limitations

Original URLs remain in stored HTML, Elementor data and generated stylesheets. JavaScript resolves same-origin uploads using a bounded public lookup. With JS disabled or the REST endpoint blocked, original markup remains. The browser may download the original before substitution. Performance gains require measurement on the actual site.

Same-origin stylesheets and inline styles with a single background-image URL can receive a typed CSS image-set in memory. CSS media queries and selectors keep their existing scope. Cross-origin stylesheets, multiple background layers, CSS variables, shorthand-only cases, picture elements and CDN URL transformations may keep the original. Editor, WooCommerce, ACF and Pro widget behavior needs staging verification. Disabling Elementor integration disables this delivery script site-wide when Elementor is active.

Imagick skips multi-frame assets and images requiring orientation changes. GD accepts JPEG, BMP and PNG sources, conservatively skips potential APNG, PNG-to-JPEG, and metadata-preserving jobs. GD may drop color profiles. Use Imagick for ICC-sensitive work. Same-format conversion is skipped. Settings changes require a bulk pass. Superseded files are retained until explicit per-attachment removal. Logs show the latest result per attachment. Savings use one preferred (or first available) alternative per source size. The plugin does not enable new WordPress upload MIME types.

WP Rocket, LiteSpeed Cache and Cloudflare users should allow the lookup endpoint, avoid caching POST responses, and choose one image rewriting system during testing. Remote/offloaded uploads need a local readable source and verified same-origin mapping.

## CLI and cleanup

Run `wp imageflow convert 42 73` for specified attachment IDs. Use Media Library Tools to remove selected derivatives. Deactivation and uninstall retain originals. Uninstall retains conversion settings, per-attachment metadata and derivative files for cached pages and reinstalls. Multisite settings and upload paths are per site.
