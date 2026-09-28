# Changelog

All notable changes to this project will be documented in this file.

## [Unreleased]

### Added

- Bundle the Deployer 8.0.5 phar and expose it as `vendor/bin/dep` (see "Bundled Deployer" in the README).
- Refuse to run under a Deployer major version other than the bundled one, with a hint to use `vendor/bin/dep`.
- Scheduled workflow that bundles new Deployer releases and releases them automatically;
  Deployer majors, and updates while `main` has unreleased commits, arrive as a pull request.
- CI with unit, smoke and signature tests.

### Changed

- **Upgrade:** `deployer/deployer` is no longer a dependency and now conflicts with this package.
  Run `composer remove --dev deployer/deployer` before updating if your project requires it directly; `deploy.php` stays unchanged.
  Use `vendor/bin/dep` instead of a globally installed `dep`.
- Require PHP 8.3 or later. Projects no longer need Symfony 7.4 or later, so Contao 4.13 and 5.3 projects can install this package.
  Projects on older PHP versions can stay on `~1.15.0`, which uses Deployer 7.
- Converted `run()`/`runLocally()` option arrays to named arguments, as required by Deployer 8.
- Database imports and restores (`db:pull`, `db:push`, `db:import:*`) and the local migration after `db:pull` run without a timeout again;
  with Deployer 8, `timeout: null` would fall back to the default timeout of 300 seconds.

### Fixed

- `autoload.php` is a no-op unless running inside `dep` (checks the `DEPLOYER` constant instead of the Deployer class).
  Deployer 8 ships its classes via composer, so the old check let the application's own `vendor/autoload.php`
  (e.g. Contao requests or `contao-console` in dev environments) register tasks and crash with "Deployer is not initialized".

### Removed

- Redundant `deploy:failed` → `deploy:unlock` hook in the TYPO3 recipe; Deployer 8's TYPO3 recipe registers it itself.
