<?php

namespace Deployer;

use Dotenv;

/**
 * Returns the local WP URL or false, if not found.
 *
 * @return false|string
 */
if (!isset($getLocalEnv)) {
    $getLocalEnv = function () {
        $localEnv = \Dotenv\Dotenv::createMutable(get('local_root'), '.env');
        $localEnv->load();
        $localUrl = $_ENV['WP_HOME'];

        if (!$localUrl) {
            writeln("<error>WP_HOME variable not found in local .env file</error>");

            return false;
        }

        return $localUrl;
    };
}

/**
 * Returns the remote WP URL or false, if not found.
 * Downloads the remote .env file to a local tmp file
 * to extract data.
 *
 * @return false|string
 */
if (!isset($getRemoteEnv)) {
    $getRemoteEnv = function () {
        $tmpEnvFile = get('local_root') . '/.env-remote';
        download(get('current_path') . '/.env', $tmpEnvFile);
        $remoteEnv = Dotenv\Dotenv::createMutable(get('local_root'), '.env-remote');
        $remoteEnv->load();
        $remoteUrl = $_ENV['WP_HOME'];
        // Cleanup tempfile
        runLocally("rm {$tmpEnvFile}");

        if (!$remoteUrl) {
            writeln("<error>WP_HOME variable not found in remote .env file</error>");

            return false;
        }

        return $remoteUrl;
    };
}

/**
 * Removes the protocol and trailing slash from submitted url.
 *
 * @param $url
 * @return string
 */
if (!isset($urlToDomain)) {
    $urlToDomain = function ($url) {
        return preg_replace('/^https?:\/\/(.+)/i', '$1', rtrim($url, "/"));
    };
}


/**
 * Reads a value from the environment, falling back to the local .env file.
 *
 * Deploys are usually run from a developer machine where GITHUB_TOKEN lives in
 * the project's .env rather than in the shell, and from CI where it is a real
 * environment variable. This checks both and fails loudly rather than letting
 * an empty token turn into an unauthenticated clone that 404s on a private
 * repository.
 *
 * @throws \Exception when the key is set in neither place
 */
function get_env(string $key): string
{
    $value = getenv($key);
    if ($value !== false && $value !== '') {
        return $value;
    }

    $file = has('env_file') ? get('env_file') : '.env';
    if (is_readable($file)) {
        // INI_SCANNER_RAW: a token is opaque, and .env values regularly contain
        // characters (#, $, quotes) that the default scanner would mangle.
        $env = @parse_ini_file($file, false, INI_SCANNER_RAW) ?: [];
        if (!empty($env[$key])) {
            return trim($env[$key], "\"'");
        }
    }

    throw new \Exception("{$key} must be set as an environment variable or in {$file}");
}
