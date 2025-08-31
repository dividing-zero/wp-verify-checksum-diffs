<?php

use Behat\Behat\Context\Context;
use Behat\Behat\Hook\Scope\AfterScenarioScope;
use Behat\Behat\Hook\Scope\BeforeScenarioScope;
use Behat\Gherkin\Node\PyStringNode;
use WP_CLI\Process;
use WP_CLI\Process\ProcessRunException;

/**
 * Defines application features from the specific context.
 */
class FeatureContext implements Context
{
	private $command_output;
	private $return_code;
	private $backup_files = [];
	private $created_files = [];

	/**
	 * @BeforeScenario
	 */
	public function beforeScenario(BeforeScenarioScope $scope)
	{
		// Reset state before each scenario
		$this->command_output = null;
		$this->return_code = null;
		$this->backup_files = [];
		$this->created_files = [];
	}

	/**
	 * @AfterScenario
	 */
	public function afterScenario(AfterScenarioScope $scope)
	{
		// Restore backed-up files
		foreach ($this->backup_files as $path => $content) {
			if (null === $content) {
				if (file_exists($path)) {
					unlink($path);
				}
			} else {
				file_put_contents($path, $content);
			}
		}

		// Remove created files
		foreach ($this->created_files as $path) {
			if (file_exists($path) && !isset($this->backup_files[$path])) {
				unlink($path);
			}
		}
	}

	/**
	 * Backs up a file if it hasn't been backed up already.
	 *
	 * @param string $path The path to the file.
	 */
	private function backup_file($path)
	{
		if (!array_key_exists($path, $this->backup_files)) {
			$this->backup_files[$path] = file_exists($path) ? file_get_contents($path) : null;
		}
	}

	/**
	 * Modifies a file with a given content.
	 *
	 * @param string $path The path to the file.
	 * @param string $content The new content.
	 */
	private function modify_file($path, $content)
	{
		$this->backup_file($path);
		file_put_contents($path, $content);
	}

	/**
	 * Creates a new file.
	 *
	 * @param string $path The path to the file.
	 * @param string $content The content of the file.
	 */
	private function create_file($path, $content = 'new file')
	{
		$this->backup_file($path); // In case it exists, to restore its absence
		file_put_contents($path, $content);
		$this->created_files[] = $path;
	}

	/**
	 * Deletes a file.
	 *
	 * @param string $path The path to the file.
	 */
	private function delete_file($path)
	{
		$this->backup_file($path);
		if (file_exists($path)) {
			unlink($path);
		}
	}

	/**
	 * Ensures the parent directory exists before file operations.
	 */
	private function ensure_parent_dir($path)
	{
		$dir = dirname($path);
		if (!is_dir($dir)) {
			mkdir($dir, 0777, true);
		}
	}

	/**
	 * @When I run :command
	 */
	public function iRun($command)
	{
		// Use `run_check()` which throws an exception on non-zero exit code.
		// We will catch it to allow testing for failure scenarios.
		try {
			$result = Process::create($command)->run();
			$this->command_output = $result->stdout . $result->stderr;
			$this->return_code = $result->return_code;
		} catch (ProcessRunException $e) {
			$process = $e->getProcess();
			$this->command_output = $process->getOutput() . $process->getErrorOutput();
			$this->return_code = $process->getExitCode();
		}
	}

	/**
	 * @Then the output should contain:
	 */
	public function theOutputShouldContain(PyStringNode $string)
	{
		if (strpos($this->command_output, (string) $string) === false) {
			throw new \Exception("Expected output not found: " . (string) $string . "\n\nActual output:\n" . $this->command_output);
		}
	}

	/**
	 * @Then the output should not contain:
	 */
	public function theOutputShouldNotContain(PyStringNode $string)
	{
		if (strpos($this->command_output, (string) $string) !== false) {
			throw new \Exception("Unexpected output found: " . (string) $string . "\n\nActual output:\n" . $this->command_output);
		}
	}

	/**
	 * @Given a core file that fails checksum but has no diff at :path
	 */
	public function aCoreFileThatFailsChecksumButHasNoDiffAt($path)
	{
		$content = file_get_contents($path);
		// Change line endings to simulate a checksum mismatch without a diff
		$new_content = str_replace("\n", "\r\n", $content);
		$this->modify_file($path, $new_content);
	}

	/**
	 * @Given a plugin with a file that fails checksum but has no diff at :path
	 */
	public function aPluginWithAFileThatFailsChecksumButHasNoDiffAt($path)
	{
		$content = file_get_contents($path);
		// Change line endings to simulate a checksum mismatch without a diff
		$new_content = str_replace("\n", "\r\n", $content);
		$this->modify_file($path, $new_content);
	}

	/**
	 * @Given /^a (plugin|core) with a file that fails checksum and has a diff at "([^"]*)"$/
	 */
	public function aFileThatFailsChecksumAndHasADiffAt($type, $path)
	{
		$this->modify_file($path, '<?php // Modified file');
	}

	/**
	 * @Given /^a (core|plugin) file is missing or has different case at "([^"]*)"$/
	 */
	public function aFileIsMissingOrHasDifferentCaseAt($type, $path)
	{
		$this->delete_file($path);
	}

	/**
	 * @Given /^a (core|plugin) file is present locally but not in the official release at "([^"]*)"$/
	 */
	public function aFileIsPresentLocallyButNotInTheOfficialReleaseAt($type, $path)
	{
		$this->create_file($path, '<?php // Extra file');
	}

	/**
	 * @Given a core file that fails checksum and has a diff at :path
	 */
	public function aCoreFileThatFailsChecksumAndHasADiffAt($path)
	{
		$this->ensure_parent_dir($path);
		$this->modify_file($path, '<?php // Modified core file');
	}

	/**
	 * @Given a plugin with a missing file or a file that differs only by case at :path
	 */
	public function aPluginWithAMissingFileOrAFileThatDiffersOnlyByCaseAt($path)
	{
		$this->ensure_parent_dir($path);
		$this->delete_file($path);
	}

	/**
	 * @Given a plugin with a file present locally but not in the official release at :path
	 */
	public function aPluginWithAFilePresentLocallyButNotInTheOfficialReleaseAt($path)
	{
		$this->ensure_parent_dir($path);
		$this->create_file($path, '<?php // Extra plugin file');
	}
}
