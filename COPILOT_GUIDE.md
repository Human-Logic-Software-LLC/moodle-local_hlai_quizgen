# GitHub Copilot Actions - Quick Reference

This guide shows how to use GitHub Copilot to help maintain Moodle coding standards in this plugin.

## Copilot Chat Commands

### Generate PHPDoc Blocks

```
@workspace /doc Generate complete PHPDoc block for this function including @param, @return, and @throws tags following Moodle coding standards
```

### Fix Coding Standard Violations

```
@workspace Fix all Moodle coding standard violations in this file
```

### Generate Test Cases

```
@workspace /tests Generate PHPUnit test cases for this class following Moodle testing standards
```

### Refactor Code

```
@workspace Refactor this function to follow Moodle coding standards, including proper PHPDoc, error handling, and consistent naming
```

## Copilot Inline Suggestions

Copilot will automatically suggest:

1. **PHPDoc completion** - When you type `/**` above a function
2. **Parameter documentation** - Auto-complete @param tags with correct types
3. **Return types** - Suggest proper return type declarations
4. **Exception handling** - Suggest try-catch blocks and @throws tags

## Using Copilot for Code Reviews

### Review a Function

```
@workspace Review this function for Moodle coding standards compliance. Check:
- PHPDoc completeness
- Naming conventions  
- Error handling
- Security (SQL injection, XSS, etc.)
- Performance considerations
```

### Review an Entire File

```
@workspace /review Analyze this file for Moodle coding standards violations and suggest improvements
```

## Common Patterns to Ask Copilot

### 1. Database Operations

```
@workspace Show me the Moodle-standard way to:
- Insert a record with proper transaction handling
- Update records safely
- Delete records with cascade handling
- Query with proper parameter binding
```

### 2. Capability Checks

```
@workspace Add proper capability checks for this function following Moodle security best practices
```

### 3. String Handling

```
@workspace Convert this hardcoded string to use get_string() for internationalization
```

### 4. Form Validation

```
@workspace Add proper validation for this form data following Moodle param cleaning standards
```

## Copilot Actions Configuration

If using GitHub Copilot for Pull Requests extension:

### .github/copilot-instructions.md

```markdown
## Code Review Focus Areas

1. **Moodle Coding Standards**
   - PHPDoc blocks on all functions and classes
   - Proper @param, @return, @throws tags
   - Line length under 132 characters (180 max)
   - 4-space indentation (no tabs)
   - defined('MOODLE_INTERNAL') || die() after namespace

2. **Security**
   - SQL injection prevention (use $DB methods with params)
   - XSS prevention (use s(), format_string(), etc.)
   - CSRF protection (sesskey() checks)
   - Capability checks before sensitive operations

3. **Performance**
   - Minimize database queries
   - Use proper caching
   - Avoid N+1 query problems

4. **Internationalization**
   - All user-facing strings in language files
   - Use get_string() not hardcoded text

5. **Accessibility**
   - Proper ARIA labels
   - Keyboard navigation support
   - Screen reader compatibility
```

## VSCode Extensions for Better Copilot Integration

1. **GitHub Copilot** - AI pair programming
2. **GitHub Copilot Chat** - Chat interface for code questions
3. **GitHub Copilot for Pull Requests** - AI code reviews (if available)

## Tips for Better Copilot Results

### Be Specific

❌ Bad: "Fix this"
✅ Good: "Add missing @return void and @throws moodle_exception tags to this function's PHPDoc block"

### Provide Context

❌ Bad: "Generate a function"
✅ Good: "Generate a Moodle database query function that safely retrieves quiz questions with proper parameter binding and PHPDoc"

### Iterate

1. Ask Copilot for initial code
2. Review the suggestion
3. Ask for refinements: "Add error handling" or "Include PHPDoc"
4. Verify against coding standards

### Use Workspace Context

Prefix with `@workspace` to give Copilot context about your entire codebase:

```
@workspace How should I structure this new class to match the existing code style in this plugin?
```

## Example: Complete Workflow

### 1. Write Function Stub

```php
function handle_quiz_deployment($requestid, $courseid) {
    // TODO: Implement
}
```

### 2. Ask Copilot for PHPDoc

```
@workspace /doc Generate complete Moodle-standard PHPDoc for this function
```

Copilot suggests:
```php
/**
 * Handle quiz deployment from generation request.
 *
 * @param int $requestid Generation request ID
 * @param int $courseid Course ID
 * @return void
 * @throws moodle_exception If deployment fails
 */
function handle_quiz_deployment($requestid, $courseid) {
```

### 3. Ask for Implementation

```
@workspace Implement this function following Moodle coding standards with:
- Proper database queries using $DB
- Transaction handling
- Error checking
- Capability checks
```

### 4. Review and Refine

```
@workspace Review this implementation for:
- Security vulnerabilities
- Performance issues
- Coding standard compliance
```

## Automated Fixes

For bulk fixes, you can ask Copilot:

```
@workspace Fix all functions in this file that are missing:
1. PHPDoc blocks
2. @return void tags
3. @throws tags where exceptions are thrown
```

## Learning from Copilot

Use Copilot as a teaching tool:

```
@workspace Explain why this code violates Moodle coding standards and show the correct way
```

```
@workspace What's the Moodle-standard way to do [task]? Show an example from this codebase if available
```

## Copilot and CI/CD

Copilot can help prepare code for CI/CD:

```
@workspace This function will fail the Moodle phpcs check. Fix all violations before I commit.
```

```
@workspace Generate a PHPUnit test that will pass the moodle-plugin-ci test runner for this function
```

## Resources

- [GitHub Copilot Documentation](https://docs.github.com/en/copilot)
- [Moodle Coding Style Guide](https://moodledev.io/general/development/policies/codingstyle)
- [Moodle Developer Docs](https://moodledev.io/)

---

**Pro Tip**: The more you use Copilot with explicit Moodle context, the better it gets at suggesting Moodle-compliant code!
