#!/bin/bash
# Quick Moodle Coding Standards Check
# Run this before committing to catch issues early

echo "========================================"
echo "Moodle Coding Standards Quick Check"
echo "========================================"
echo ""

# Check if PHP is available
if ! command -v php &> /dev/null; then
    echo "ERROR: PHP not found in PATH"
    echo "Please install PHP or add it to your PATH"
    exit 1
fi

echo "[1/3] Running quick standards check..."
echo ""
php check_standards.php .
result=$?

if [ $result -ne 0 ]; then
    echo ""
    echo "WARNING: Found coding standard violations"
    echo "Review the output above and fix before committing"
    echo ""
else
    echo ""
    echo "SUCCESS: No obvious violations found"
    echo ""
fi

echo "[2/3] Checking for common issues..."
echo ""

# Check for trailing whitespace
echo "Checking for trailing whitespace..."
if grep -r -n "[[:space:]]$" --include="*.php" . > /dev/null 2>&1; then
    echo "WARNING: Found trailing whitespace in PHP files"
    echo "Run: grep -r -n '[[:space:]]$' --include='*.php' . to see details"
else
    echo "OK: No trailing whitespace found"
fi

# Check for tabs
echo "Checking for tabs instead of spaces..."
if grep -r -n $'\t' --include="*.php" . > /dev/null 2>&1; then
    echo "WARNING: Found tabs in PHP files (should use 4 spaces)"
    echo "Run: grep -r -n $'\\t' --include='*.php' . to see details"
else
    echo "OK: No tabs found"
fi

echo ""
echo "[3/3] Summary"
echo ""
echo "Next steps:"
echo "1. Fix any violations shown above"
echo "2. Run full phpcs check if available: phpcs"
echo "3. Test your changes locally"
echo "4. Commit and push"
echo ""
echo "Documentation:"
echo "- CODING_STANDARDS.md - Full developer guide"
echo "- COPILOT_GUIDE.md - Using Copilot for fixes"
echo "- FIXES_SUMMARY.md - Changes applied to this plugin"
echo ""

exit $result
