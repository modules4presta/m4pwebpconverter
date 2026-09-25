# Changelog

All notable changes to this project are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses
[semantic versioning](https://semver.org/).

## [Unreleased]

### Changed
- Released under the MIT license: license headers, `LICENSE`, `composer.json`.
- Added the module manifests `config.xml` and `config_pl.xml`.

## [1.1.0] — 2026-09-08

### Added
- Thumbnail conversion and WebP delivery on the front office.
- `index.php` guards in every module directory.

### Fixed
- Database values escaped in the conversion log.
- The AJAX batch runner no longer loops forever.
- Palette images convert correctly and keep transparency.
- The database handle is resolved lazily.

## [1.0.0] — 2026-04-17

### Added
- First release: bulk conversion of product images to WebP from the back office.
