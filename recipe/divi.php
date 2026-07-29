<?php

/**
 * Share Divi's generated CSS across releases.
 *
 * Opt-in -- require this from your deploy.php when the site runs Divi:
 *
 *     require './vendor/esquire900/wordpress-deployer/recipe/divi.php';
 *
 * Divi writes per-page stylesheets into {{content_dir}}/et-cache, which is
 * gitignored, so a fresh release starts without them. On its own that is only a
 * small regeneration cost -- but combined with a *shared* WP Rocket page cache
 * it is not: the cached HTML survives the release swap while the et-cache files
 * it links to do not. Those requests 404 into index.php, where a multilingual
 * plugin will happily 301 them and WordPress renders a full 404 page for what
 * the browser thinks is a render-blocking stylesheet -- seconds per file, on
 * every visit, until the HTML cache rotates.
 *
 * Sharing the directory makes the two caches agree with each other.
 */

namespace Deployer;

add('shared_dirs', ['{{content_dir}}/et-cache']);
add('writable_dirs', ['{{content_dir}}/et-cache']);
