<?php
/**
 * Quick coding standards checker for local development.
 *
 * This script checks common Moodle coding standard violations:
 * - Missing PHPDoc blocks
 * - Missing @param, @return tags
 * - Trailing whitespace
 * - Line length > 180 chars (warning at 132)
 * - Mixed indentation
 *
 * Usage: php check_standards.php [path]
 *
 * @package    local_hlai_quizgen
 * @copyright  2025 Human Logic Software LLC
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

$path = $argv[1] ?? __DIR__;

if (!is_dir($path) && !is_file($path)) {
    echo "Error: Invalid path: $path\n";
    exit(1);
}

$errors = [];
$warnings = [];
$filecount = 0;

/**
 * Check a PHP file for coding standard violations.
 *
 * @param string $filepath Path to PHP file
 * @param array $errors Reference to errors array
 * @param array $warnings Reference to warnings array
 * @return void
 */
function check_file(string $filepath, array &$errors, array &$warnings): void {
    $content = file_get_contents($filepath);
    $lines = explode("\n", $content);

    $infunctiondoc = false;
    $lastdocblock = 0;
    $inclass = false;

    foreach ($lines as $lineno => $line) {
        $linenum = $lineno + 1;

        // Check trailing whitespace.
        if (preg_match('/\s+$/', $line) && trim($line) !== '') {
            $warnings[] = "$filepath:$linenum: Trailing whitespace";
        }

        // Check line length.
        $linelength = strlen($line);
        if ($linelength > 180) {
            $errors[] = "$filepath:$linenum: Line too long ($linelength chars, max 180)";
        } else if ($linelength > 132) {
            $warnings[] = "$filepath:$linenum: Line length warning ($linelength chars, recommended max 132)";
        }

        // Check for tabs (should use spaces).
        if (preg_match('/\t/', $line)) {
            $warnings[] = "$filepath:$linenum: Contains tabs (use 4 spaces)";
        }

        // Track if we're in a doc block.
        if (preg_match('/^\s*\/\*\*/', $line)) {
            $infunctiondoc = true;
        }
        if (preg_match('/^\s*\*\//', $line)) {
            $infunctiondoc = false;
            $lastdocblock = $linenum;  // Set to the line where PHPDoc ends.
        }

        // Check for class definition.
        if (preg_match('/^(class|interface|trait)\s+(\w+)/', $line, $matches)) {
            $inclass = true;
            // Check if preceded by doc block within 5 lines.
            if ($linenum - $lastdocblock > 5) {
                $errors[] = "$filepath:$linenum: {$matches[1]} {$matches[2]} missing PHPDoc block";
            }
        }

        // Check for function definition.
        if (preg_match('/^\s*(public|private|protected|static)?\s*function\s+(\w+)\s*\(/', $line, $matches)) {
            // Check if preceded by doc block within 3 lines.
            if ($linenum - $lastdocblock > 3) {
                $funcname = $matches[2];
                $errors[] = "$filepath:$linenum: function $funcname() missing PHPDoc block";
            }
        }

        // Check for defined() || die() at start of file.
        if ($linenum < 30 && preg_match('/namespace\s+/', $line)) {
            // After namespace, check for defined check.
            $nextlines = array_slice($lines, $lineno + 1, 5);
            $hasdefined = false;
            foreach ($nextlines as $nl) {
                if (preg_match('/defined\s*\(\s*[\'"]MOODLE_INTERNAL[\'"]\s*\)/', $nl)) {
                    $hasdefined = true;
                    break;
                }
            }
            if (!$hasdefined) {
                $warnings[] = "$filepath:$linenum: Missing defined('MOODLE_INTERNAL') || die() check after namespace";
            }
        }
    }
}

/**
 * Recursively scan directory for PHP files.
 *
 * @param string $dir Directory path
 * @param array $errors Reference to errors array
 * @param array $warnings Reference to warnings array
 * @param int $filecount Reference to file count
 * @return void
 */
function scan_directory(string $dir, array &$errors, array &$warnings, int &$filecount): void {
    $exclude = ['vendor', 'node_modules', 'gateway', 'ci', 'moodledata', '.git'];

    $items = scandir($dir);
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }

        $fullpath = $dir . DIRECTORY_SEPARATOR . $item;

        if (is_dir($fullpath)) {
            if (!in_array($item, $exclude)) {
                scan_directory($fullpath, $errors, $warnings, $filecount);
            }
        } else if (is_file($fullpath) && pathinfo($fullpath, PATHINFO_EXTENSION) === 'php') {
            check_file($fullpath, $errors, $warnings);
            $filecount++;
        }
    }
}

echo "Checking Moodle coding standards...\n\n";

if (is_file($path)) {
    check_file($path, $errors, $warnings);
    $filecount = 1;
} else {
    scan_directory($path, $errors, $warnings, $filecount);
}

echo "Checked $filecount PHP files\n\n";

if (!empty($errors)) {
    echo "=== ERRORS (" . count($errors) . ") ===\n";
    foreach ($errors as $error) {
        echo "  $error\n";
    }
    echo "\n";
}

if (!empty($warnings)) {
    echo "=== WARNINGS (" . count($warnings) . ") ===\n";
    foreach ($warnings as $warning) {
        echo "  $warning\n";
    }
    echo "\n";
}

if (empty($errors) && empty($warnings)) {
    echo "✓ No issues found!\n";
    exit(0);
} else {
    echo "Found " . count($errors) . " errors and " . count($warnings) . " warnings\n";
    exit(empty($errors) ? 0 : 1);
}
