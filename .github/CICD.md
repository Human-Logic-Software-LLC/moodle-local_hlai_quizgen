# CI/CD Pipeline Documentation

This plugin uses GitHub Actions for Continuous Integration and Continuous Deployment (CI/CD).

## Workflows

### 1. Moodle Plugin CI (`.github/workflows/moodle-plugin-ci.yml`)

**Comprehensive testing workflow that runs on every push and pull request.**

**Test Matrix:**
- **PHP Versions:** 8.1, 8.2
- **Moodle Versions:** 4.3, 4.4
- **Databases:** PostgreSQL 13, MariaDB 10.6

**Checks Performed:**
1. ✅ **PHP Lint** - Checks for PHP syntax errors
2. ✅ **PHP Copy/Paste Detector** - Identifies duplicate code (non-blocking)
3. ✅ **PHP Mess Detector** - Detects code complexity issues (non-blocking)
4. ✅ **Moodle Code Checker** - Enforces Moodle coding standards (MUST PASS)
5. ✅ **PHPDoc Checker** - Validates documentation comments (MUST PASS)
6. ✅ **Plugin Validation** - Validates plugin structure and files
7. ✅ **Upgrade Savepoints** - Checks database upgrade version consistency
8. ✅ **Mustache Lint** - Validates Mustache templates
9. ✅ **Grunt** - JavaScript linting and minification (MUST PASS)
10. ✅ **PHPUnit Tests** - Runs unit tests
11. ✅ **Behat Tests** - Runs functional/acceptance tests

**When it runs:** On every push and pull request to any branch

**Expected duration:** ~15-20 minutes per matrix job

### 2. Code Style Check (`.github/workflows/code-style.yml`)

**Fast feedback workflow for coding standards only.**

**Checks Performed:**
1. ✅ **PHP Lint** - Syntax validation
2. ✅ **Moodle Code Checker** - Coding standards (max 0 warnings)
3. ✅ **PHPDoc Checker** - Documentation standards (max 0 warnings)
4. ✅ **Plugin Validation** - Structure validation
5. ✅ **Savepoints Check** - Version consistency
6. ✅ **Mustache Lint** - Template validation
7. ✅ **Grunt** - JavaScript linting (max 0 warnings)

**When it runs:** On every push and pull request to any branch

**Expected duration:** ~5-7 minutes

## Viewing CI/CD Results

### On GitHub
1. Go to your repository: https://github.com/Nikhil-HL/HLAI_QUIZGEN
2. Click on the **"Actions"** tab
3. You'll see all workflow runs with their status:
   - ✅ Green checkmark = All checks passed
   - ❌ Red X = Some checks failed
   - 🟡 Yellow circle = Tests running
   - ⚪ Gray circle = Queued

### On Pull Requests
- Checks will appear at the bottom of each PR
- Must pass before merging (if required checks are configured)

### On Commits
- Each commit shows a status icon in the commits list
- Click the icon to see detailed results

## Local Testing

Before pushing, you can run checks locally:

### 1. Install moodle-plugin-ci locally
```bash
composer create-project -n --no-dev --prefer-dist moodlehq/moodle-plugin-ci ci ^4
export PATH="$(cd ci/bin; pwd):$(cd ci/vendor/bin; pwd):$PATH"
```

### 2. Run specific checks
```bash
# PHP Lint
moodle-plugin-ci phplint

# Code Checker
moodle-plugin-ci codechecker --max-warnings 0

# PHPDoc Checker
moodle-plugin-ci phpdoc --max-warnings 0

# Validate plugin
moodle-plugin-ci validate

# Run all checks
moodle-plugin-ci install --plugin ./path/to/plugin --db-host=127.0.0.1
moodle-plugin-ci codechecker
moodle-plugin-ci phpdoc
moodle-plugin-ci validate
moodle-plugin-ci savepoints
moodle-plugin-ci mustache
moodle-plugin-ci grunt
moodle-plugin-ci phpunit
```

## Moodle Coding Standards

This plugin follows the official Moodle coding guidelines:

- **PHP**: https://moodledev.io/general/development/policies/codingstyle
- **JavaScript**: https://moodledev.io/general/development/policies/codingstyle/js
- **PHPDoc**: https://moodledev.io/general/development/policies/codingstyle/phpdoc

### Key Standards
- Indentation: 4 spaces (no tabs)
- Line length: Max 180 characters (132 recommended)
- Naming: lowercase_with_underscores for functions/variables
- Braces: K&R style (opening brace on same line)
- Documentation: PHPDoc blocks for all functions/classes
- Namespaces: Required for all new code

## Badge Status

Add these badges to your README.md:

```markdown
[![Moodle Plugin CI](https://github.com/Nikhil-HL/HLAI_QUIZGEN/workflows/Moodle%20Plugin%20CI/badge.svg)](https://github.com/Nikhil-HL/HLAI_QUIZGEN/actions?query=workflow%3A%22Moodle+Plugin+CI%22)
[![Code Style](https://github.com/Nikhil-HL/HLAI_QUIZGEN/workflows/Code%20Style%20Check/badge.svg)](https://github.com/Nikhil-HL/HLAI_QUIZGEN/actions?query=workflow%3A%22Code+Style+Check%22)
```

## Troubleshooting

### Common Issues

**1. Code Checker Warnings**
- Fix: Follow Moodle coding style guidelines
- Run locally: `moodle-plugin-ci codechecker`

**2. PHPDoc Warnings**
- Fix: Add missing PHPDoc blocks for all functions/classes
- Ensure @param, @return tags are present and correct

**3. JavaScript Linting Errors**
- Fix: Run `grunt` locally in your Moodle directory
- Check `amd/src/*.js` files for issues

**4. PHPUnit Failures**
- Fix: Ensure all tests in `tests/` directory pass
- Run locally: `vendor/bin/phpunit tests/test_file.php`

**5. Behat Failures**
- Check feature files in `tests/behat/`
- Ensure step definitions are correct

## Maintenance

### Updating Moodle Versions
Edit `.github/workflows/moodle-plugin-ci.yml`:
```yaml
matrix:
  moodle-branch: ['MOODLE_403_STABLE', 'MOODLE_404_STABLE', 'MOODLE_405_STABLE']
```

### Updating PHP Versions
Edit `.github/workflows/moodle-plugin-ci.yml`:
```yaml
matrix:
  php: ['8.1', '8.2', '8.3']
```

### Making Checks Required
1. Go to Settings → Branches
2. Add branch protection rule for `main`
3. Check "Require status checks to pass before merging"
4. Select the checks you want to require
