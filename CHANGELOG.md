# Changelog

All notable changes to this project will be documented in this file.

## [2.0.0]

### Changed

- **Breaking:** Require Deployer 8 (`deployer/deployer: ^8.0`) and PHP 8.3 or later.
  Projects on older PHP versions must stay on `^1.15`.
- Converted `run()`/`runLocally()` option arrays to named arguments, as required by Deployer 8.
- Database imports and restores (`db:pull`, `db:push`, `db:import:*`) and the local migration after `db:pull` run without a timeout again;
  with Deployer 8, `timeout: null` would fall back to the default timeout of 300 seconds.

### Fixed

- `autoload.php` is a no-op unless running inside `dep` (checks the `DEPLOYER` constant instead of the Deployer class).
  Deployer 8 ships its classes via composer, so the old check let the application's own `vendor/autoload.php`
  (e.g. Contao requests or `contao-console` in dev environments) register tasks and crash with "Deployer is not initialized".

### Removed

- Redundant `deploy:failed` → `deploy:unlock` hook in the TYPO3 recipe; Deployer 8's TYPO3 recipe registers it itself.
