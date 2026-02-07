# GitHub CI/CD Failures - Quick Fix Guide

## Problem
All GitHub Actions jobs are failing with exit code 1. Primary cause: **64 missing PHPDoc blocks**.

## Quick Fix (5 minutes)

### Step 1: Add PHPDoc to Most Critical Files

The Moodle code checker requires PHPDoc blocks on ALL functions. Here's what you need to do:

```powershell
cd c:\xampp\htdocs\moodle\local\hlai_quizgen
```

### Step 2: Use the Bulk Fix Script

I've created a helper script. While it won't fix everything, it will show you what needs fixing:

```powershell
php check_standards.php . > phpdoc_errors.txt
```

### Step 3: Option A - Quick Commit (Temporary)

If you want to see which specific check is failing:

1. **Disable the strict phpcs check temporarily** to see other issues:

```yaml
# In .github/workflows/code-style.yml
- name: Moodle Code Checker
  run: moodle-plugin-ci phpcs --max-warnings 100  # Allow warnings temporarily
```

2. Commit and push to see what else fails
3. Then fix PHPDoc issues systematically

### Step 4: Option B - Fix All PHPDoc Now (Recommended)

The files with most violations:

1. **wizard.php** - 15 missing PHPDoc blocks
2. **classes/analytics_helper.php** - 12 missing
3. **classes/task/*.php** - 8 missing
4. **ajax.php** - 2 missing (FIXED)
5. **lib.php** - Already has PHPDoc ✓

## Critical Files to Fix

### 1. wizard.php Functions

Add these PHPDoc blocks:

```php
/**
 * Render step 1 content source selection.
 *
 * @param int $courseid Course ID
 * @param int $requestid Request ID (default 0)
 * @return string HTML output
 */
function render_step1(int $courseid, int $requestid = 0): string {
```

```php
/**
 * Render step indicator with progress.
 *
 * @param int $currentstep Current step number (1-5)
 * @param int $requestid Request ID (default 0)
 * @return string HTML for step indicator
 */
function render_step_indicator(int $currentstep, int $requestid = 0): string {
```

```php
/**
 * Handle bulk actions on questions.
 *
 * @param string $action Action type (approve|reject|delete)
 * @param int $requestid Request ID
 * @return void
 */
function handle_bulk_action(string $action, int $requestid) {
```

### 2. classes/analytics_helper.php

Every function needs:
- Short description
- `@param` for each parameter with type and description
- `@return` with type and description

### 3. classes/task/*.php

Task classes need:
- Class-level PHPDoc with @package
- get_name() function PHPDoc with @return string
- execute() function PHPDoc

### 4. classes/wizard_helper.php

Needs class-level PHPDoc:

```php
/**
 * Helper class for wizard functionality.
 *
 * Provides utility methods for the quiz generation wizard
 * following Moodle coding standards by keeping functions in a class
 * rather than as top-level functions.
 *
 * @package    local_hlai_quizgen
 * @copyright  2025 Human Logic Software LLC
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class wizard_helper {
```

## Automated Fix Strategy

### Use GitHub Copilot (If Available)

1. Open each file with missing PHPDoc
2. Place cursor above function
3. Type `/**` and press Enter
4. Copilot will suggest complete PHPDoc
5. Accept and modify as needed

### OR Use This PowerShell Script

```powershell
# Get list of functions missing PHPDoc
php check_standards.php . | Select-String "missing PHPDoc" > missing_phpdoc.txt

# Open each file and add PHPDoc manually
# Or use Copilot to generate them
```

## Testing Before Commit

```powershell
# Check for errors
php check_standards.php .

# If you have phpcs installed:
phpcs --standard=moodle .

# Count remaining issues
(php check_standards.php .) | Select-String "ERRORS" 
```

## Commit Strategy

### Option 1: Incremental Fixes
```powershell
# Fix critical files first
git add ajax.php lib.php analytics.php
git commit -m "Fix PHPDoc in AJAX and library files"
git push

# Watch CI - see if it passes
# Then fix remaining files
```

### Option 2: Bulk Fix
```powershell
# Fix ALL files with missing PHPDoc
# Then commit everything at once
git add .
git commit -m "Complete PHPDoc coverage for Moodle coding standards compliance"
git push
```

## Expected CI/CD Results After Fix

✅ PHP Lint - Should pass (no syntax errors)
✅ Code Checker (phpcs) - Will pass once PHPDoc complete
✅ PHPDoc Checker - Will pass once complete
✅ Validation - Should pass
✅ Other checks - Should pass

## Time Estimate

- **Quick fix (critical files)**: 15-20 minutes
- **Complete fix (all files)**: 45-60 minutes with Copilot
- **Complete fix (manual)**: 2-3 hours

## Need Help?

### Use Copilot

```
@workspace Add complete PHPDoc blocks to all functions in this file following Moodle coding standards. Include @param with types, @return, and @throws where needed.
```

### Check Specific File

```powershell
php check_standards.php classes/analytics_helper.php
```

### See What GitHub CI Saw

1. Go to GitHub Actions tab
2. Click on failed workflow
3. Click on failed job (e.g., "test (8.1, MOODLE_404_STABLE, pgsql)")
4. Expand "Moodle Code Checker" or "PHP Lint" step
5. See exact errors

## Quick Win: Suppress Non-Critical Warnings

If you want to see ONLY the PHPDoc errors:

```powershell
php check_standards.php . | Select-String "missing PHPDoc"
```

This shows you exactly which functions need fixing.

## Final Checklist

- [ ] All functions have PHPDoc blocks
- [ ] PHPDoc has @param for each parameter
- [ ] PHPDoc has @return (even if void)
- [ ] PHPDoc has @throws if function can throw exceptions
- [ ] Class-level PHPDoc includes @package
- [ ] No trailing whitespace
- [ ] No tabs (use 4 spaces)
- [ ] Lines under 132 characters (warning) or 180 (error)

## Files Modified So Far

✅ ajax.php - send_response() and send_error() fixed
✅ lib.php - Already has complete PHPDoc
✅ analytics.php - get_filtered_sql() fixed
✅ CI workflows - Updated and manual trigger enabled

## Still Need Fixing

❌ wizard.php - 13 functions
❌ classes/analytics_helper.php - 12 functions
❌ classes/task/cleanup_old_requests.php - 2 functions
❌ classes/task/process_generation_queue.php - 6 functions
❌ classes/wizard_helper.php - Class PHPDoc
❌ tests/*.php - Class PHPDoc blocks

---

**Next Action**: Choose Option A (incremental) or Option B (bulk fix) and proceed!
