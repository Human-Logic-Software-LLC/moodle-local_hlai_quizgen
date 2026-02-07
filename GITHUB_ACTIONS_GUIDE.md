# Running CI/CD Pipeline on GitHub

This guide shows you how to trigger the CI/CD pipeline on GitHub.

## Prerequisites

Your local changes need to be pushed to GitHub for the workflows to run.

## Method 1: Automatic Trigger (Push/PR)

The workflows automatically run when you:

### Push to any branch:
```bash
cd c:\xampp\htdocs\moodle\local\hlai_quizgen
git add .
git commit -m "Applied Moodle coding standards fixes"
git push origin main
```

### Create a Pull Request:
1. Push to a feature branch:
   ```bash
   git checkout -b fix/coding-standards
   git add .
   git commit -m "Fix coding standards violations"
   git push origin fix/coding-standards
   ```

2. Go to GitHub and create a Pull Request
3. CI/CD runs automatically on the PR

## Method 2: Manual Trigger (Added Now!)

I've enabled `workflow_dispatch` so you can manually trigger workflows from GitHub UI:

### Steps:
1. Go to your GitHub repository
2. Click **Actions** tab
3. Select the workflow you want to run:
   - **Moodle Plugin CI** (full test suite)
   - **Code Style Check** (quick style check)
4. Click **Run workflow** button (on the right side)
5. Select the branch to run on
6. Click **Run workflow** (green button)

## Current Status - What to Do Now:

### Step 1: Check Git Status
```powershell
cd c:\xampp\htdocs\moodle\local\hlai_quizgen
git status
```

### Step 2: Commit the Fixes
```powershell
git add .
git commit -m "Fix Moodle coding standards: Update CI/CD workflows, add PHPDoc, documentation"
```

### Step 3: Push to GitHub
```powershell
# Push to main branch
git push origin main

# OR push to a new branch for review
git checkout -b fix/coding-standards
git push origin fix/coding-standards
```

### Step 4: View Results on GitHub

1. Go to: `https://github.com/YOUR_USERNAME/YOUR_REPO/actions`
2. Watch the workflows run in real-time
3. Click on a workflow run to see detailed logs
4. Green checkmark ✅ = All checks passed
5. Red X ❌ = Some checks failed (click to see details)

## What Gets Checked

### Moodle Plugin CI (Full Suite)
- ✅ PHP Lint (syntax errors)
- ✅ PHP Copy/Paste Detector
- ✅ PHP Mess Detector
- ✅ **Moodle Code Checker (phpcs)**
- ✅ Moodle PHPDoc Checker
- ✅ Plugin Validation
- ✅ Database Savepoints Check
- ✅ Mustache Template Lint
- ✅ Grunt (JavaScript Lint)
- ✅ PHPUnit Tests
- ✅ Behat Tests

### Code Style Check (Quick)
- ✅ PHP Lint
- ✅ Moodle Code Checker (phpcs)
- ✅ PHPDoc Checker
- ✅ Plugin Validation
- ✅ Savepoints Check
- ✅ Mustache Lint
- ✅ Grunt

## Viewing Results

### In GitHub UI:
1. Actions tab shows all workflow runs
2. Green/red status indicators
3. Click any run for detailed logs
4. Download artifacts (if any)

### In VS Code (with GitHub Pull Requests extension):
1. Install "GitHub Pull Requests and Issues" extension
2. View PR status checks directly in VS Code
3. See failed checks inline

## Troubleshooting

### If Workflow Doesn't Appear in Actions Tab:

**Check 1: Is the repository on GitHub?**
```powershell
git remote -v
```
Should show GitHub URL.

**Check 2: Are workflows in the correct location?**
They should be in: `.github/workflows/`

**Check 3: Push the workflow files:**
```powershell
git add .github/
git commit -m "Add CI/CD workflows"
git push
```

### If Checks Fail:

1. **Click on the failed check** to see error details
2. **Common issues:**
   - Missing PHPDoc → Use `check_standards.php` locally
   - Code style violations → Run `phpcs` locally
   - Line too long → Break into multiple lines
   - Trailing whitespace → Remove with editor

3. **Fix locally and push again:**
   ```powershell
   # Fix the issues
   php check_standards.php
   
   # Commit fixes
   git add .
   git commit -m "Fix CI/CD violations"
   git push
   ```

## Quick Commands for This Repository

```powershell
# Navigate to plugin directory
cd c:\xampp\htdocs\moodle\local\hlai_quizgen

# Check current branch
git branch

# Check what's changed
git status
git diff

# Stage all changes
git add .

# Commit with message
git commit -m "Your message here"

# Push to GitHub (triggers CI/CD)
git push origin main

# View recent commits
git log --oneline -5

# Check remote repository
git remote -v
```

## Files Changed That Need to Be Committed

✅ `.github/workflows/moodle-plugin-ci.yml` - Updated CI commands + manual trigger
✅ `.github/workflows/code-style.yml` - Updated CI commands + manual trigger
✅ `phpcs.xml` - NEW: Local coding standards config
✅ `thirdpartylibs.xml` - NEW: Third-party library documentation
✅ `wizard.php` - Fixed PHPDoc blocks
✅ `.gitignore` - Updated exclusions
✅ `CODING_STANDARDS.md` - NEW: Developer guide
✅ `COPILOT_GUIDE.md` - NEW: Copilot integration guide
✅ `check_standards.php` - NEW: Local checker script
✅ `FIXES_SUMMARY.md` - NEW: Summary of fixes
✅ `quick_check.bat` - NEW: Windows quick checker
✅ `quick_check.sh` - NEW: Linux/Mac quick checker
✅ `GITHUB_ACTIONS_GUIDE.md` - NEW: This file

## Expected CI/CD Results

After pushing, you should see:

✅ **Code Style Check** completes in ~3-5 minutes
- Fast feedback on coding standards
- No database/Moodle installation needed

✅ **Moodle Plugin CI** completes in ~10-15 minutes
- Full integration testing
- Installs Moodle, runs all checks
- Tests on PHP 8.1 & 8.2
- Tests on PostgreSQL & MariaDB

## Next Steps

1. **Commit all changes:**
   ```powershell
   cd c:\xampp\htdocs\moodle\local\hlai_quizgen
   git add .
   git commit -m "Apply Moodle coding standards fixes and CI/CD improvements"
   ```

2. **Push to GitHub:**
   ```powershell
   git push origin main
   ```

3. **Watch it run:**
   - Go to GitHub Actions tab
   - Watch workflows execute
   - Review any failures

4. **Iterate if needed:**
   - Fix any issues found by CI/CD
   - Commit and push again
   - Workflows run automatically

---

**Pro Tip:** Use `git push -u origin branch-name` for the first push of a new branch, then just `git push` for subsequent pushes.

**Documentation:** See `CODING_STANDARDS.md` for local testing before pushing to avoid CI/CD failures.
