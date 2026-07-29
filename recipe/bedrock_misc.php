<?php

/**
 * Miscellaneous Bedrock tasks.
 *
 * `bedrock:vendors` used to be defined here as well as in common.php, and the
 * copy here was missing the `install` subcommand. common.php is required after
 * this file so its definition always won, making this one dead code -- removed
 * rather than left as a trap for whoever edits the wrong one.
 */

namespace Deployer;
