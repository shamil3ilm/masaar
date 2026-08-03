# Contributing to Masaar

Thank you for your interest in contributing to Masaar! This document provides guidelines and requirements for contributing to this controlled open source project.

## Before You Start

### Required Reading

Before contributing, you MUST read and agree to:

1. **[LICENSE](LICENSE)** - Controlled Open Source License (COSL)
2. **[TERMS.md](TERMS.md)** - Terms of Use and Acceptable Use Policy
3. **[SECURITY.md](SECURITY.md)** - Security Policy

### Contributor Agreement

By submitting a pull request or any contribution, you:

1. **Certify** that you have the right to submit the contribution
2. **Agree** to the LICENSE terms, including the Controlled Open Source License
3. **Agree** to the Terms of Use in TERMS.md
4. **Grant** a perpetual, worldwide, non-exclusive, royalty-free license to your contribution
5. **Understand** that your contribution may be used commercially
6. **Confirm** you have not introduced any security vulnerabilities intentionally

### Registration for Contributors

Active contributors must register by emailing: contributors@{YOUR_DOMAIN}

Include:
- GitHub username
- Full name
- Email address
- Organization (if applicable)
- Areas of intended contribution

## How to Contribute

### Reporting Bugs

1. **Search existing issues** to avoid duplicates
2. **Use the bug report template**
3. **Include**:
   - Clear description of the issue
   - Steps to reproduce
   - Expected vs actual behavior
   - Environment details (PHP version, Laravel version, etc.)
   - Error messages and logs (sanitized of sensitive data)

**Important**: Do NOT include any sensitive data (API keys, certificates, VAT numbers, etc.) in bug reports.

### Suggesting Features

1. **Search existing feature requests**
2. **Use the feature request template**
3. **Describe**:
   - The problem you're trying to solve
   - Your proposed solution
   - Alternative solutions considered
   - ZATCA compliance implications (if any)

### Pull Requests

#### Setup

```bash
# Fork the repository
git clone https://github.com/YOUR_USERNAME/zatca.git
cd zatca

# Add upstream remote
git remote add upstream https://github.com/ORIGINAL_OWNER/zatca.git

# Install dependencies
composer install
npm install

# Copy environment file
cp .env.example .env
php artisan key:generate

# Run tests
php artisan test
```

#### Development Workflow

1. **Create a feature branch**:
   ```bash
   git checkout -b feature/your-feature-name
   ```

2. **Make your changes** following our coding standards

3. **Write/update tests** for your changes

4. **Run the full test suite**:
   ```bash
   php artisan test
   ./vendor/bin/phpstan analyse
   ./vendor/bin/pint --test
   ```

5. **Commit with clear messages**:
   ```bash
   git commit -m "Add feature: brief description

   - Detailed change 1
   - Detailed change 2

   Fixes #123"
   ```

6. **Push and create PR**:
   ```bash
   git push origin feature/your-feature-name
   ```

#### Pull Request Requirements

- [ ] Code follows project style guidelines
- [ ] All tests pass
- [ ] New tests added for new functionality
- [ ] Documentation updated if needed
- [ ] No security vulnerabilities introduced
- [ ] ZATCA compliance maintained
- [ ] Signed-off-by line included (see below)

#### Sign-off Requirement

All commits must include a sign-off line:

```
Signed-off-by: Your Name <your.email@example.com>
```

This certifies you agree to the Developer Certificate of Origin:

```
Developer Certificate of Origin
Version 1.1

By making a contribution, I certify that:

(a) The contribution was created by me and I have the right to submit it
    under the LICENSE terms.

(b) I understand and agree that this project and the contribution are
    public and that a record of the contribution is maintained.

(c) I have read and agree to the TERMS.md and LICENSE files.

(d) I have not introduced any intentional security vulnerabilities.
```

Use `git commit -s` to automatically add the sign-off.

## Coding Standards

### PHP

- Follow PSR-12 coding style
- Use strict types: `declare(strict_types=1);`
- Document all public methods
- Use meaningful variable and method names
- Maximum line length: 120 characters

### Security Requirements

When contributing code:

1. **Never log sensitive data** (passwords, API keys, certificates)
2. **Always validate input** at system boundaries
3. **Use parameterized queries** for database operations
4. **Escape output** appropriately
5. **Follow OWASP guidelines**

### ZATCA Compliance

Contributions affecting ZATCA functionality must:

1. Maintain compliance with ZATCA Phase 2 requirements
2. Not bypass validation or security checks
3. Preserve XML namespace URIs exactly as specified
4. Maintain cryptographic integrity

## Code Review Process

1. **Automated checks** run on all PRs
2. **Maintainer review** required for all changes
3. **Security review** for sensitive changes
4. **ZATCA compliance review** for e-invoicing changes

### Review Criteria

- Code quality and readability
- Test coverage and quality
- Security implications
- Performance impact
- Documentation completeness
- ZATCA compliance

## Prohibited Contributions

The following will NOT be accepted:

1. **Security bypasses** or vulnerabilities
2. **ZATCA compliance violations**
3. **Tax evasion enablers**
4. **Fraudulent invoice features**
5. **Backdoors or malicious code**
6. **Features violating Saudi Arabian law**
7. **Contributions without sign-off**

## Recognition

Contributors are recognized in:

- CONTRIBUTORS.md file
- Release notes
- Project documentation

Top contributors may be invited to:
- Private contributor channels
- Early access to features
- Advisory roles

## Getting Help

- **General questions**: discussions@{YOUR_DOMAIN}
- **Technical issues**: Create a GitHub issue
- **Security concerns**: security@{YOUR_DOMAIN}
- **Contribution questions**: contributors@{YOUR_DOMAIN}

## Code of Conduct

### Expected Behavior

- Be respectful and inclusive
- Provide constructive feedback
- Focus on the best outcomes for the project
- Accept responsibility for mistakes

### Unacceptable Behavior

- Harassment or discrimination
- Trolling or inflammatory comments
- Publishing others' private information
- Conduct violating the Terms of Use

### Enforcement

Violations may result in:
- Warning
- Temporary ban
- Permanent ban
- Reporting to appropriate authorities

Report conduct issues to: conduct@{YOUR_DOMAIN}

---

## Summary Checklist

Before submitting:

- [ ] I have read LICENSE, TERMS.md, and SECURITY.md
- [ ] I agree to the Contributor Agreement
- [ ] My code follows project standards
- [ ] I have written tests for my changes
- [ ] I have updated documentation if needed
- [ ] All tests pass
- [ ] My commits include sign-off
- [ ] I have not introduced security vulnerabilities
- [ ] My changes maintain ZATCA compliance

---

Thank you for contributing to Masaar!
