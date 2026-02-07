@echo off
REM Quick Moodle Coding Standards Check
REM Run this before committing to catch issues early

echo ========================================
echo Moodle Coding Standards Quick Check
echo ========================================
echo.

REM Check if PHP is available
php --version >nul 2>&1
if errorlevel 1 (
    echo ERROR: PHP not found in PATH
    echo Please add PHP to your PATH or run from XAMPP shell
    pause
    exit /b 1
)

echo [1/3] Running quick standards check...
echo.
php check_standards.php .
if errorlevel 1 (
    echo.
    echo WARNING: Found coding standard violations
    echo Review the output above and fix before committing
    echo.
) else (
    echo.
    echo SUCCESS: No obvious violations found
    echo.
)

echo [2/3] Checking for common issues...
echo.

REM Check for trailing whitespace
echo Checking for trailing whitespace...
findstr /R /N "[ 	]$" *.php >nul 2>&1
if not errorlevel 1 (
    echo WARNING: Found trailing whitespace in PHP files
    echo Run: findstr /R /N "[ 	]$" *.php to see details
) else (
    echo OK: No trailing whitespace found
)

REM Check for tabs
echo Checking for tabs instead of spaces...
findstr /R /N "	" *.php >nul 2>&1
if not errorlevel 1 (
    echo WARNING: Found tabs in PHP files (should use 4 spaces)
    echo Run: findstr /R /N "	" *.php to see details
) else (
    echo OK: No tabs found
)

echo.
echo [3/3] Summary
echo.
echo Next steps:
echo 1. Fix any violations shown above
echo 2. Run full phpcs check if available: phpcs
echo 3. Test your changes locally
echo 4. Commit and push
echo.
echo Documentation:
echo - CODING_STANDARDS.md - Full developer guide
echo - COPILOT_GUIDE.md - Using Copilot for fixes
echo - FIXES_SUMMARY.md - Changes applied to this plugin
echo.

pause
