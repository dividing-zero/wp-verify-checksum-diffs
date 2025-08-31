Feature: WP Verify Checksum Diffs Command
  As a WordPress developer or administrator
  I want to verify file integrity against official checksums
  And diff files which failed checksum with whitespaces and line endings ignored
  So that I can identify unauthorized or unexpected file modifications.

  Background:
    Given a WP installation

  Scenario: All core and plugin files pass verification
    When I run `wp verify-checksum-diffs`
    Then the output should contain:
      """
      Verifying WordPress core checksums...
      """
    And the output should contain:
      """
      Verifying WordPress plugin checksums...
      """
    And the return code should be 0

  Scenario: A core file fails checksum but has no diff, so the command passes
    Given a core file that fails checksum but has no diff at "wp-includes/checksum-fail-no-diff.php"
    When I run `wp verify-checksum-diffs`
    Then the output should contain:
      """
      wp-includes/checksum-fail-no-diff.php (Checksum does not match)
      """
    And the output should not contain:
      """
      Differences found in wp-includes/checksum-fail-no-diff.php
      """
    And the return code should be 0

  Scenario: A plugin file fails checksum but has no diff, so the command passes
    Given a plugin with a file that fails checksum but has no diff at "wp-content/plugins/example-plugin/checksum-fail-no-diff.php"
    When I run `wp verify-checksum-diffs`
    Then the output should contain:
      """
      wp-content/plugins/example-plugin/checksum-fail-no-diff.php (Checksum does not match)
      """
    And the output should not contain:
      """
      Differences found in wp-content/plugins/example-plugin/checksum-fail-no-diff.php
      """
    And the return code should be 0

  Scenario: A failing file is ignored, so the command passes
    Given a plugin with a file that fails checksum and has a diff at "wp-content/plugins/example-plugin/modified-file.php"
    When I run `wp verify-checksum-diffs --ignore=modified-file.php`
    Then the output should contain:
      """
      Differences found in wp-content/plugins/example-plugin/modified-file.php
      """
    And the output should contain:
      """
      Checks failed for the following files:
      """
    And the output should contain:
      """
      wp-content/plugins/example-plugin/modified-file.php (Differences found, ignored)
      """
    And the return code should be 0

  Scenario: A core file fails checksum and has a diff
    Given a core file that fails checksum and has a diff at "wp-includes/example-core-file.php"
    When I run `wp verify-checksum-diffs`
    Then the output should contain:
      """
      wp-includes/example-core-file.php (Checksum does not match)
      """
    And the output should contain:
      """
      Differences found in wp-includes/example-core-file.php
      """
    And the output should contain:
      """
      Checks failed for the following files:
      """
    And the output should contain:
      """
      wp-includes/example-core-file.php (Differences found)
      """
    And the return code should be 1

  Scenario: A core file is removed (case-sensitive)
    Given a core file is missing or has different case at "wp-includes/missing-core-file.php"
    When I run `wp verify-checksum-diffs`
    Then the output should contain:
      """
      Checks failed for the following files:
      """
    And the output should contain:
      """
      wp-includes/missing-core-file.php (File doesn't exist)
      """
    And the return code should be 1

  Scenario: A core file is added (case-sensitive)
    Given a core file is present locally but not in the official release at "wp-includes/extra-core-file.php"
    When I run `wp verify-checksum-diffs`
    Then the output should contain:
      """
      Checks failed for the following files:
      """
    And the output should contain:
      """
      wp-includes/extra-core-file.php (File was added)
      """
    And the return code should be 1

  Scenario: A plugin file fails checksum and has a diff
    Given a plugin with a file that fails checksum and has a diff at "wp-content/plugins/example-plugin/problem-file.php"
    When I run `wp verify-checksum-diffs`
    Then the output should contain:
      """
      wp-content/plugins/example-plugin/problem-file.php (Checksum does not match)
      """
    And the output should contain:
      """
      Differences found in wp-content/plugins/example-plugin/problem-file.php
      """
    And the output should contain:
      """
      Checks failed for the following files:
      """
    And the output should contain:
      """
      wp-content/plugins/example-plugin/problem-file.php (Differences found)
      """
    And the return code should be 1

  Scenario: A plugin file is removed (case-sensitive)
    Given a plugin with a missing file or a file that differs only by case at "wp-content/plugins/example-plugin/missing-file.php"
    When I run `wp verify-checksum-diffs`
    Then the output should contain:
      """
      Checks failed for the following files:
      """
    And the output should contain:
      """
      wp-content/plugins/example-plugin/missing-file.php (File doesn't exist)
      """
    And the return code should be 1

  Scenario: A plugin file is added (case-sensitive)
    Given a plugin with a file present locally but not in the official release at "wp-content/plugins/example-plugin/extra-file.php"
    When I run `wp verify-checksum-diffs`
    Then the output should contain:
      """
      Checks failed for the following files:
      """
    And the output should contain:
      """
      wp-content/plugins/example-plugin/extra-file.php (File was added)
      """
    And the return code should be 1
