# Moodle Coding Standards - Local Testing

This document explains how to test Moodle coding standards locally before pushing to CI/CD.

## Prerequisites

1. Install Composer (if not already installed)
2. Install moodle-plugin-ci locally

```bash
composer create-project -n --no-dev --prefer-dist moodlehq/moodle-plugin-ci ci ^4
```

## Running Code Checks Locally

### 1. PHP Lint (Syntax Check)
```bash
php -l file.php
```

### 2. Moodle Code Checker (phpcs)

If you have phpcs and moodle coding standard installed:

```bash
phpcs --standard=moodle /path/to/your/plugin
```

Or with the provided phpcs.xml:

```bash
phpcs
```

### 3. PHPDoc Checker

```bash
ci/bin/moodle-plugin-ci phpdoc
```

### 4. Validate Plugin
```bash
ci/bin/moodle-plugin-ci validate
```

### 5. Mustache Lint
```bash
ci/bin/moodle-plugin-ci mustache
```

### 6. JavaScript Lint (Grunt)
```bash
ci/bin/moodle-plugin-ci grunt --max-lint-warnings 0
```

## Common Issues and Fixes

### Missing PHPDoc

**Issue:** Functions missing @param, @return, or @throws tags.

**Fix:** Add complete PHPDoc blocks:

```php
/**
 * Short description.
 *
 * Longer description if needed.
 *
 * @param int $param1 Description
 * @param string $param2 Description
 * @return bool Description
 * @throws moodle_exception Description
 */
function my_function(int $param1, string $param2): bool {
    // ...
}
```

### Line Length

**Issue:** Lines longer than 132 characters.

**Fix:** Break long lines:

```php
// Bad:
$result = $DB->get_record('table_name', ['field1' => $value1, 'field2' => $value2, 'field3' => $value3], '*', MUST_EXIST);

// Good:
$result = $DB->get_record('table_name', [
    'field1' => $value1,
    'field2' => $value2,
    'field3' => $value3
], '*', MUST_EXIST);
```

### Indentation

**Issue:** Mixed spaces and tabs, or wrong indentation level.

**Fix:** Use 4 spaces for indentation (not tabs).

### Trailing Whitespace

**Issue:** Spaces at the end of lines.

**Fix:** Configure your editor to remove trailing whitespace on save.

## CI/CD Workflow

The plugin uses GitHub Actions for continuous integration:

1. **moodle-plugin-ci.yml** - Full test suite including:
   - PHP Lint
   - Code Checker (phpcs)
   - PHPDoc Checker
   - Validation
   - Mustache Lint
   - Grunt (JS linting)
   - PHPUnit tests
   - Behat tests

2. **code-style.yml** - Fast code style check for quick feedback

## File Exclusions

The following directories/files are excluded from coding standards checks (see `phpcs.xml`):

- `vendor/` - Composer dependencies
- `node_modules/` - NPM dependencies  
- `gateway/` - External gateway code
- `*.js` and `*.css` files that are third-party (apexcharts.js, bulma.css)

## VSCode Integration

For VSCode users, install these extensions for better development experience:

1. **PHP Intelephense** - PHP language support
2. **phpcs** - PHP CodeSniffer integration
3. **PHP DocBlocker** - Auto-generate PHPDoc blocks
4. **EditorConfig** - Consistent coding styles

## Copilot Actions Extensions

If you have Copilot installed, it can help with:

- Auto-generating PHPDoc blocks
- Suggesting coding standard fixes
- Refactoring code to meet standards

## Pre-commit Hook (Optional)

Create `.git/hooks/pre-commit`:

```bash
#!/bin/sh
# Run PHP lint on staged PHP files
for FILE in $(git diff --cached --name-only --diff-filter=ACM | grep '\.php$'); do
    php -l "$FILE"
    if [ $? -ne 0 ]; then
        echo "PHP Lint failed for $FILE"
        exit 1
    fi
done
```

Make it executable:
```bash
chmod +x .git/hooks/pre-commit
```

## Resources

- [Moodle Coding Style](https://moodledev.io/general/development/policies/codingstyle)
- [Moodle Plugin CI](https://moodlehq.github.io/moodle-plugin-ci/)
- [PHP_CodeSniffer](https://github.com/squizlabs/PHP_CodeSniffer)
