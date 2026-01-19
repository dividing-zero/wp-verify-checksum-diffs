<?php

namespace WP_CLI\VerifyChecksumDiffs;

use WP_CLI;
use WP_CLI_Command;
use WP_CLI\Utils;

const WP_PLUGIN_DIR = 'wp-content/plugins';
const FILE_CHECKSUM_FAILURE = 'Checksum does not match';
const FILE_DOWNLOAD_FAILURE = 'Failed to download file';
const FILE_MISSING_FAILURE = 'File doesn\'t exist';
const FILE_DIFF_FAILURE = 'Differences found';

/**
 * Verifies file checksums for WordPress core and plugins, and performs a diff against the official versions for any files that have mismatches.
 *
 * This command provides a more detailed analysis than the standard `wp core verify-checksums`
 * and `wp plugin verify-checksums` by showing the actual differences in files that fail
 * the checksum verification.
 *
 * ## OPTIONS
 *
 * [--ignore=<files>]
 * : A comma-separated list of file paths to ignore. This is a simple substring match.
 *
 * ## EXAMPLES
 *
 *     # Verify all core and plugin files and report differences.
 *     $ wp verify-checksum-diffs
 *
 *     # Verify files but ignore changes in wp-config.php
 *     $ wp verify-checksum-diffs --ignore=wp-config.php
 *
 * @when before_wp_load
 */
class VerifyChecksumDiffs extends WP_CLI_Command
{
	private $failed_files = [];
	private $ignored_files = [];
	private $skipped_plugins = [];
	private $work_dir;

	/**
	 * Handle setup and start the verification process when the command is invoked.
	 *
	 * @param array $args
	 * @param array $assoc_args
	 * @return void
	 */
	public function __invoke($args, $assoc_args)
	{
		// Create a temporary working directory for downloaded files
		$this->work_dir = rtrim(sys_get_temp_dir(), '/') . '/wp-verify-checksum-diffs-' . time();
		if (! mkdir($this->work_dir, 0700, true)) {
			WP_CLI::error("Could not create temporary directory: {$this->work_dir}");
		}

		// Ensure cleanup happens on exit, error, or interrupt
		register_shutdown_function([$this, 'cleanup']);

		// Parse ignored patterns
		if (! empty($assoc_args['ignore'])) {
			$this->ignored_files = explode(',', $assoc_args['ignore']);
		}

		// Do verification process
		$this->verify_core();
		$this->verify_plugins();
		$this->log_summary();
	}

	/**
	 * Verifies WordPress core files against their checksums and performs a diff against the official versions for any files that have mismatches.
	 *
	 * @return void
	 */
	protected function verify_core()
	{
		$this->log('Verifying WordPress core checksums...', 'b');

		// Verify core checksums
		$failed_checksum_files = $this->verify_core_checksums();

		// Diff any core files that failed checksums and add failed diff files to the failed files list
		if (!empty($failed_checksum_files['checksum'])) {
			$failed_diff_files = $this->diff_core_files($failed_checksum_files['checksum']);
			$this->failed_files = array_merge($this->failed_files, $failed_diff_files);
		}

		// Add files that failed for any other reason straight to the failed files list
		if (!empty($failed_checksum_files['other'])) {
			$this->failed_files = array_merge($this->failed_files, $failed_checksum_files['other']);
		}
	}

	/**
	 * Verifies WordPress plugin files against their checksums and performs a diff against the official versions for any files that have mismatches.
	 *
	 * @return void
	 */
	protected function verify_plugins()
	{
		$this->log('Verifying WordPress plugin checksums...', 'b');

		$failed_checksum_files = $this->verify_plugin_checksums();

		foreach ($failed_checksum_files as $plugin_slug => $files) {
			// Diff any plugin files that failed checksums and add failed diff files to the failed files list
			if (!empty($files['checksum'])) {
				$failed_diff_files = $this->diff_plugin_files($files['checksum'], $plugin_slug);

				$failed_plugin_files = [];
				foreach ($failed_diff_files as $file => $message) {
					$failed_plugin_files[WP_PLUGIN_DIR . '/' . $plugin_slug . '/' . $file] = $message;
				}

				$this->failed_files = array_merge($this->failed_files, $failed_plugin_files);
			}

			// Add files that failed for any other reason straight to the failed files list
			if (!empty($files['other'])) {
				$failed_plugin_files = [];
				foreach ($files['other'] as $file => $message) {
					$failed_plugin_files[WP_PLUGIN_DIR . '/' . $plugin_slug . '/' . $file] = $message;
				}
				$this->failed_files = array_merge($this->failed_files, $failed_plugin_files);
			}
		}
	}

	/**
	 * Verifies WordPress core checksums and returns the list of failed files, separated by checksum failures and other failures
	 *
	 * @return array
	 */
	protected function verify_core_checksums()
	{
		$failed_files = ['checksum' => [], 'other' => []];

		// Run the command with `launch` to display the fully formatted output.
		$this->run_command('core verify-checksums', ['launch' => true], true);

		// Run again with `return` to capture the output for processing.
		$result = $this->run_command('core verify-checksums', ['return' => 'all'], true);

		// Iterate over each line and extract file paths
		$lines = explode("\n", $result->stderr);

		foreach ($lines as $line) {
			// Add files that failed checksum
			if (preg_match("/Warning: File doesn't verify against checksum: (.+)/", $line, $matches)) {
				$failed_files['checksum'][trim($matches[1])] = FILE_CHECKSUM_FAILURE;
			}
			// Add files that were warned against for any other reason
			else if (preg_match("/Warning: ([^:]+): (.+)/", $line, $matches)) {
				$failed_files['other'][trim($matches[2])] = trim($matches[1]);
			}
			// Ignore any other lines such as "Error: WordPress installation doesn't verify against checksums."
		}

		// Return the list of failed files
		return $failed_files;
	}

	/**
	 * Diffs the specified core files against the official WordPress release and returns the list of files with differences.
	 *
	 * @param array $files
	 * @return array
	 */
	protected function diff_core_files($files)
	{
		// Get the current WordPress version
		$wp_version = $this->run_command('core version', ['return' => 'all']);
		$wp_version = trim($wp_version->stdout);

		// Download the official WordPress core files
		$this->log("Downloading WordPress core v{$wp_version} for diff comparison...");
		$official_path = "{$this->work_dir}/official_core";
		$wp_version_escaped = escapeshellarg($wp_version);
		$official_path_escaped = escapeshellarg($official_path);
		$this->run_command("core download --version={$wp_version_escaped} --path={$official_path_escaped} --force", ['return' => 'all']);

		// Compare files passed in with official files
		$failed_files = $this->diff_files($files, $official_path);

		// Return the list of failed files
		return $failed_files;
	}

	/**
	 * Verifies WordPress plugin checksums and returns the list of failed files grouped by plugin name, separated by checksum failures and other failures
	 * This will also perform a check for missing files, since versions of WP CLI >2.5.0 no longer include missing files in the checksum verification process.
	 *
	 * @return array
	 */
	protected function verify_plugin_checksums()
	{
		$failed_files = [];

		// Run the command with `launch` to display the fully formatted output.
		$this->run_command('plugin verify-checksums --all', ['launch' => true], true);

		// Run again with `return` to capture the output for processing.
		$result = $this->run_command('plugin verify-checksums --all --format=json', ['return' => 'all'], true);

		// Parse stderr for skipped plugins
		$stderr_lines = explode("\n", $result->stderr);
		foreach ($stderr_lines as $line) {
			if (preg_match('/Warning: Could not retrieve the checksums for version [^ ]+ of plugin ([^,]+), skipping\./', $line, $matches)) {
				$this->skipped_plugins[] = $matches[1];
			}
		}

		// Iterate over each line and extract file paths
		$reported_files = json_decode($result->stdout);
		foreach ($reported_files as $file) {
			// Group failed files by plugin name
			if (!isset($failed_files[$file->plugin_name])) {
				$failed_files[$file->plugin_name] = ['checksum' => [], 'other' => []];
			}
			// Add files that failed checksum
			if ($file->message === 'Checksum does not match') {
				$failed_files[$file->plugin_name]['checksum'][$file->file] = FILE_CHECKSUM_FAILURE;
			}
			// Add files that were warned against for any other reason
			else {
				$failed_files[$file->plugin_name]['other'][$file->file] = $file->message;
			}
		}

		// Now check for missing files for all plugins using their checksum manifests
		$this->log("Checking for missing plugin files...");
		$plugin_list_result = $this->run_command('plugin list --format=json', ['return' => 'all']);
		$plugins = json_decode($plugin_list_result->stdout, true);

		foreach ($plugins as $plugin) {

			// Skip plugins that were already skipped by checksum verification or are not active or inactive (e.g. must-use/dropin plugins)
			if (in_array($plugin['name'], $this->skipped_plugins) || ($plugin['status'] !== 'active' && $plugin['status'] !== 'inactive')) {
				continue;
			}

			$plugin_slug = $plugin['name'];
			$plugin_version = $plugin['version'];

			// Fetch the plugin checksum manifest from WordPress.org
			$url = "https://downloads.wordpress.org/plugin-checksums/{$plugin_slug}/{$plugin_version}.json";
			$response = Utils\http_request('GET', $url, null, [], ['timeout' => 30]);

			// Handle potential error responses and keep track of skipped plugins
			if (200 !== $response->status_code) {
				WP_CLI::warning("Could not fetch manifest for {$plugin_slug} v{$plugin_version}, skipping.");
				$this->skipped_plugins[] = $plugin_slug;
				continue;
			}

			$manifest = json_decode($response->body, true);
			if (empty($manifest['files'])) {
				WP_CLI::warning("No files found in manifest for {$plugin_slug} v{$plugin_version}, skipping.");
				$this->skipped_plugins[] = $plugin_slug;
				continue;
			}

			// Check for missing files with a case sensitive search
			$plugin_path = WP_PLUGIN_DIR . '/' . $plugin_slug;
			foreach ($manifest['files'] as $manifest_file => $checksum) {
				$local_file = $plugin_path . '/' . $manifest_file;

				if (!$this->file_exists_case_sensitive($local_file)) {
					if (!isset($failed_files[$plugin_slug])) {
						$failed_files[$plugin_slug] = [];
					}

					$failed_files[$plugin_slug]['other'][$manifest_file] = FILE_MISSING_FAILURE;
					$this->log("File is missing {$local_file}", 'r');
				}
			}
		}

		// Return the list of failed files, grouped by plugin name
		return $failed_files;
	}

	/**
	 * Diffs the specified plugin files against the official WordPress release and returns the list of files with differences.
	 *
	 * @param array $files
	 * @param string $plugin_slug
	 * @return array
	 */
	protected function diff_plugin_files($files, $plugin_slug)
	{
		// Get the plugin version
		$plugin_version = $this->run_command("plugin get {$plugin_slug} --field=version", ['return' => 'all']);
		$plugin_version = trim($plugin_version->stdout);

		// Download the official plugin files
		$this->log("Downloading plugin {$plugin_slug} v{$plugin_version} for diff comparison...");

		$plugin_path = WP_PLUGIN_DIR . '/' . $plugin_slug;
		$download_url = "https://downloads.wordpress.org/plugin/{$plugin_slug}.{$plugin_version}.zip";
		$archive_path = "{$this->work_dir}/{$plugin_slug}.zip";
		$official_path = "{$this->work_dir}/official_plugin_{$plugin_slug}";
		$response = Utils\http_request('GET', $download_url, null, [], ['timeout' => 600, 'filename' => $archive_path]);

		// Handle download errors
		if (200 !== $response->status_code) {
			WP_CLI::warning("Failed to download plugin {$plugin_slug} (HTTP status: {$response->status_code}), skipping.");
			$this->skipped_plugins[] = $plugin_slug;
			return [];
		}

		// Extract the downloaded files
		if (! file_exists($official_path)) {
			mkdir($official_path, 0700, true);
		}
		shell_exec("unzip -q {$archive_path} -d {$official_path}");
		$unzipped_contents = scandir($official_path);
		// Find the first directory in the unzipped contents to handle zip files that contain the plugin directory at the next level
		foreach ($unzipped_contents as $entry) {
			if ($entry !== '.' && $entry !== '..' && is_dir("{$official_path}/{$entry}")) {
				$official_path = "{$official_path}/{$entry}";
				break;
			}
		}

		// Compare files passed in with official files
		$failed_files = $this->diff_files($files, $official_path, $plugin_path);

		// Return the list of failed files, grouped by plugin name
		return $failed_files;
	}


	/**
	 * Perform diff on the specified files between the current and passed in official directory.
	 *
	 * @param array $files
	 * @param string $official_dir
	 * @return array
	 */
	protected function diff_files($files, $official_dir, $local_dir = null)
	{
		$failed_files = [];

		foreach ($files as $file => $message) {
			$official_file_path = "{$official_dir}/{$file}";
			$local_file_path = $local_dir ? "{$local_dir}/{$file}" : $file;


			// Use `diff -q -bB` to quickly check for differences, ignoring whitespace.
			// The output is suppressed, we only care about the return code.
			// Diff returns 0 for same, 1 for different, >1 for error.
			$local_escaped = escapeshellarg($local_file_path);
			$official_escaped = escapeshellarg($official_file_path);
			exec("diff -q -bB {$local_escaped} {$official_escaped}", $output, $return_code);

			// No differences found
			if (0 === $return_code) {
				$this->log("No differences found in {$local_file_path}", 'g');
			}
			// Differences found
			else if (1 === $return_code) {
				$this->log("Differences found in {$local_file_path}", 'r');
				$failed_files[$local_file_path] = FILE_DIFF_FAILURE;
			}
			// An error occurred with the diff command itself
			else {
				WP_CLI::error("Could not perform diff on {$local_file_path}. Error code: {$return_code}");
			}
		}

		return $failed_files;
	}

	/**
	 * Log the summary of the verification process.
	 * This will exit with status code 1 if there are non-ignored failures.
	 *
	 * @return void
	 */
	protected function log_summary()
	{
		$non_ignored_failure_count = 0;

		// Log skipped plugins
		if (!empty($this->skipped_plugins)) {
			$this->log('Checks were skipped for the following plugins:', 'y');
			foreach ($this->skipped_plugins as $plugin) {
				$this->log($plugin, 'y');
			}
		}

		// Log failed files
		if (!empty($this->failed_files)) {
			$this->log('Checks failed for the following files:', 'r');
			foreach ($this->failed_files as $base_file => $message) {
				if ($this->is_ignored($base_file)) {
					$this->log("{$base_file} ({$message}, ignored)", 'y');
				}
				else {
					$this->log("{$base_file} ({$message})", 'r');
					$non_ignored_failure_count++;
				}
			}
		}

		// Halt with exit code 1 if there are non-ignored failures
		if ($non_ignored_failure_count > 0) {
			WP_CLI::halt(1);
			return;
		}

		WP_CLI::success('All files passed verification.');
	}

	/**
	 * Check if a file is ignored based on the ignored files list.
	 *
	 * @param string $file_path
	 * @return boolean
	 */
	private function is_ignored($file_path)
	{
		foreach ($this->ignored_files as $pattern) {
			if (false !== strpos($file_path, $pattern)) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Logs a message to the console.
	 *
	 * @param string $message The message to log.
	 * @param string|null $color The color to use for the message.
	 * @return void
	 */
	private function log($message, $color = null)
	{
		if ($color) {
			$message = WP_CLI::colorize("%{$color}{$message}%n");
		}
		WP_CLI::log($message);
	}

	/**
	 * Run a WP-CLI command and handle errors, since WP CLI doesn't report issues such as "out of memory" on its own.
	 * Optionally suppress error code 1 if desired (for commands like verify-checksums that return 1 on checksum failures).
	 *
	 * @param string $command
	 * @param array $options
	 * @param boolean $surpress_error_code_1 Pass true to suppress error code 1
	 * @return object
	 */
	private function run_command($command, $options = [], $surpress_error_code_1 = false)
	{
		$options = array_merge($options, ['exit_error' => false]);

		$result = WP_CLI::runcommand($command, $options);

		if (isset($result->return_code)) {
			if ($surpress_error_code_1 === true && $result->return_code === 1) {
				return $result;
			}
			else if ($result->return_code > 1) {
				$message = '';
				if (!empty($result->stderr)) {
					$message .= trim($result->stderr);
				}
				$message .= " (exit code: {$result->return_code})";
				WP_CLI::error($message, $result->return_code);
			}
		}

		return $result;
	}

	/**
	 * Checks if a file exists with case sensitivity.
	 *
	 * @param string $file_path
	 * @return boolean
	 */
	private function file_exists_case_sensitive($file_path) {
		if (!file_exists($file_path)) {
			return false;
		}

		$dir = dirname($file_path);
		$target = basename($file_path);
		foreach (scandir($dir) as $file) {
			if (strcmp($file, $target) === 0) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Recursively remove a directory.
	 *
	 * @param string $dir
	 * @return void
	 */
	private function rmdir_recursive($dir)
	{
		if (! file_exists($dir)) {
			return;
		}
		$it = new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS);
		$files = new \RecursiveIteratorIterator($it, \RecursiveIteratorIterator::CHILD_FIRST);
		foreach ($files as $file) {
			if ($file->isDir()) {
				rmdir($file->getRealPath());
			} else {
				unlink($file->getRealPath());
			}
		}
		rmdir($dir);
	}

	/**
	 * Cleans up the temporary directory.
	 *
	 * @return void
	 */
	public function cleanup()
	{
		if (file_exists($this->work_dir)) {
			$this->rmdir_recursive($this->work_dir);
		}
	}
}
