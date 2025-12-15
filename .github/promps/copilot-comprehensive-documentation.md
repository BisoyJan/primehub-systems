---
description: Comprehensive documentation synchronization check for Laravel backend and React frontend application
---

# Comprehensive Documentation Synchronization Check

Please perform a thorough audit to verify that our project documentation is fully synchronized with the current codebase. This is a Laravel backend with a React frontend application.

## Areas to Check

### 1. API Documentation
- Verify all API endpoints documented match actual routes in `routes/api.php` and `routes/web.php`
- Confirm request/response schemas match controller implementations
- Check authentication/authorization requirements are accurately documented
- Validate middleware documentation reflects actual middleware stack

### 2. Database Schema
- Compare documented database tables/columns with actual migrations
- Verify relationships (`hasMany`, `belongsTo`, etc.) match Eloquent model definitions
- Check if any new migrations have been added since documentation was last updated
- Validate seeder documentation if applicable

### 3. React Components
- Verify component props documentation matches actual TypeScript/PropTypes definitions
- Check if component usage examples are still valid
- Confirm state management documentation (Redux/Context/etc.) reflects current implementation
- Validate hooks documentation matches actual custom hooks

### 4. Environment Variables
- Compare `.env.example` with documented environment variables
- Check if any new config values have been added to `config/` files
- Verify third-party service configurations are documented

### 5. Dependencies & Versions
- Check `composer.json` and `package.json` against documented dependencies
- Verify major version numbers are accurate
- Flag any deprecated packages still documented

### 6. Setup/Installation Instructions
- Validate installation steps match current `composer install` and `npm install` requirements
- Check if any new setup steps (queue workers, schedulers, etc.) are missing
- Verify deployment documentation reflects current process

### 7. Code Examples
- Test documented code snippets for syntax errors
- Verify examples use current API patterns
- Check if deprecated methods are still shown in examples

## Deliverables

1. **List of discrepancies found** with specific file/line references
2. **Outdated sections** that need updating
3. **Missing documentation** for new features
4. **Recommendations** for documentation improvements
5. **Priority level** for each issue (Critical/High/Medium/Low)

Please ensure thoroughness in your review to maintain high-quality documentation that accurately reflects our codebase.
