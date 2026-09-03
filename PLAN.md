# Valet Linux+ Improvement Plan

Based on a review of the codebase (commit `b40206b`), the project is feature-rich and well-architected, but the test suite and static analysis are currently broken. This plan prioritizes getting the quality gates green before adding new features.

## Phase 1: Unblock CI and quality gates

### 1. Fix the PHPUnit test suite
- **Problem:** All 271 tests fail with `chown(): Invalid argument` during `Configuration::install()` in the test bootstrap.
- **Root cause:** `Filesystem::chown()` is called with a real system user that does not exist in the test environment.
- **Action:**
  - Make `Filesystem::chown()` / `chgrp()` mockable or stub them in the test bootstrap.
  - Or change `Configuration::install()` to only call chown when not in the `testing` environment.
  - Follow the existing `TODO` comments and consider removing the wrapper methods in favor of direct, injectable ownership logic.

### 2. Fix PHPStan real errors
- **Problem:** PHPStan reports 7 errors not covered by the baseline, mostly in `cli/Valet/SiteIsolate.php` and `tests/Unit/DashboardTest.php`.
- **Action:**
  - Add explicit type handling in `SiteIsolate::isolatedDirectories()` so `mixed` values from `NginxFacade::configuredSites()` are cast/validated before being passed to methods expecting `string`.
  - Change `DashboardTest.php` `serviceName(string $version = null)` to `serviceName(?string $version = null)` to fix PHP 8.4 implicit-nullable deprecation.
  - Regenerate `phpstan-baseline.neon` after the fixes.

### 3. Sync version numbers
- **Problem:** Version is inconsistent across `README.md` (2.2.0), `cli/app.php` (2.2.3), and `CHANGELOG.md` (2.2.0).
- **Action:** Pick a single source of truth and update all three locations.

### 4. Update PHPUnit configuration and constraint
- **Problem:** `composer.json` allows `~5.5|^9.0` but `composer.lock` resolved PHPUnit 10.5. `phpunit.xml` still uses attributes removed in PHPUnit 10.
- **Action:**
  - Update `composer.json` constraint to `^9.0 || ^10.0 || ^11.0`.
  - Remove `convertDeprecationsToExceptions`, `convertErrorsToExceptions`, `convertNoticesToExceptions`, and `convertWarningsToExceptions` from `phpunit.xml`.
  - Add `composer validate` to CI.

### 5. Standardize test namespaces
- **Problem:** Four test files use `namespace Unit;` while the rest use `namespace Valet\Tests\Unit;`.
- **Action:** Update `SiteIsolateTest.php`, `SiteProxyTest.php`, `SiteSecureTest.php`, and `SiteLinkTest.php` to use `namespace Valet\Tests\Unit;`.

## Phase 2: Polish and dependency health

### 6. Upgrade PHPStan
- Move from PHPStan 1.x to PHPStan 2.x.
- Add `phpstan/phpstan-mockery` extension to reduce baseline noise from Mockery mocks.

### 7. Clean up dependency constraints
- Remove the `~5.5` part from `phpunit/phpunit`.
- Verify all `illuminate/*` packages stay on the same minor version.

### 8. Fix CI PHP matrix
- Remove PHP 8.5 and 8.6 from the CI matrix until they are officially released.
- Keep 8.2, 8.3, and 8.4.

### 9. Archive or move the implementation plan
- `IMPLEMENTATION_PLAN.md` is marked "Done" for version 2.1.0. Move it to `docs/implementation-plan-v2.1.md` or replace it with a roadmap of future features.

## Phase 3: Strategic enhancements

### 10. Add integration / smoke tests
- Add a smoke test for `valet diagnose` that validates the JSON schema.
- Consider Docker-based integration tests for `valet install`, `valet link`, and `valet secure` on clean distro images.

### 11. Command-injection audit
- Audit every `passthru`, `exec`, `system`, and `CommandLine::run` call for missing `escapeshellarg` usage, especially where user-supplied paths or names are passed.

### 12. Refactor services behind a common interface
- Introduce a `ServiceInterface` implemented by Nginx, MySQL, PostgreSQL, Redis, Mailpit, and custom services.
- This makes `ServiceRegistry::MAP` cleaner and custom services truly first-class.

### 13. Complete documentation for new commands
- Ensure all commands added in 2.1.x/2.2.x are fully documented in `docs/commands.md` with examples:
  - `valet diagnose`, `valet xdebug`, `valet backup` / `valet restore`, `valet service:add` / `valet service:remove`, `valet pg:*`.

### 14. Harden autoloader detection
- Expand the `valet` binary / `app.php` autoloader search paths to include common Composer global directories (`~/.config/composer/vendor/autoload.php`, etc.).

---

## Definition of done

- `composer test` passes.
- `composer stan` passes.
- `composer cs:check` passes.
- `composer audit` passes.
- CI is green on PHP 8.2, 8.3, and 8.4.

---

*Created: 2026-09-03*
