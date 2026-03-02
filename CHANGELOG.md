# Changelog

## [0.9.0](https://github.com/openCoreEMR/oce-module-sinch-conversations/compare/0.1.0...0.9.0) (2026-03-02)


### Features

* add MVC controllers for messaging UI ([4e7142e](https://github.com/openCoreEMR/oce-module-sinch-conversations/commit/4e7142e5c756e79e30b93419105e3a97dd4ae21b))
* add public entry points and Twig templates ([94dde74](https://github.com/openCoreEMR/oce-module-sinch-conversations/commit/94dde746b469ff9b1a40dc8a8b4870de017e7630))
* add Sinch Conversations module infrastructure and tooling ([#5](https://github.com/openCoreEMR/oce-module-sinch-conversations/issues/5)) ([91d527c](https://github.com/openCoreEMR/oce-module-sinch-conversations/commit/91d527c1c8897b3cee5899faa87b4906ba11f7ba))
* add YAML file-based configuration support ([#13](https://github.com/openCoreEMR/oce-module-sinch-conversations/issues/13)) ([ca98c30](https://github.com/openCoreEMR/oce-module-sinch-conversations/commit/ca98c309e1116a63ce0c2e7020560aa3a4e65237))
* **config:** configure sinch in globals ([d271870](https://github.com/openCoreEMR/oce-module-sinch-conversations/commit/d2718701a7051009fcaf30961543f01bd731da66))
* implement Sinch API client and core services ([752ba28](https://github.com/openCoreEMR/oce-module-sinch-conversations/commit/752ba28dada00d2224ca4f894fb1173ff465c4c8))
* initial Sinch Conversations module structure ([bed55cd](https://github.com/openCoreEMR/oce-module-sinch-conversations/commit/bed55cd7e9bd894ad97f8053e27e4a431405ded1))
* sync templates and oauth to sinch ([fdbb0c9](https://github.com/openCoreEMR/oce-module-sinch-conversations/commit/fdbb0c948f6e24ef62326483f9ab76573a2da146))
* update Bootstrap and GlobalConfig for Sinch Conversations ([41c1aa5](https://github.com/openCoreEMR/oce-module-sinch-conversations/commit/41c1aa54140f280ba040d6ac52cedaaa9431b44f))


### Bug Fixes

* **config:** use ConfigFactory to properly read environment variables ([#6](https://github.com/openCoreEMR/oce-module-sinch-conversations/issues/6)) ([309a5fa](https://github.com/openCoreEMR/oce-module-sinch-conversations/commit/309a5fa9846c6413fff476597d8a7a7945712183))
* **phpstan:** add type to $defaultName property in command classes ([d9516d2](https://github.com/openCoreEMR/oce-module-sinch-conversations/commit/d9516d231bde55b21f04b41b86a4bdb62edb6dd9))


### Documentation

* add info.txt, versioning, and error handling rules ([#11](https://github.com/openCoreEMR/oce-module-sinch-conversations/issues/11)) ([4fc5666](https://github.com/openCoreEMR/oce-module-sinch-conversations/commit/4fc56661caae6f1d1f687ae59a67b22b63cd628d))
* **agents:** teach agents local dev ([13ea3e2](https://github.com/openCoreEMR/oce-module-sinch-conversations/commit/13ea3e2e74a51b21ab0358c69eb5471dddc68157))


### Miscellaneous Chores

* release 0.9.0 ([0b63ac2](https://github.com/openCoreEMR/oce-module-sinch-conversations/commit/0b63ac2915fdc83592400f3b1194d181041740d5))


### Dependencies

* bump actions/cache from 4 to 5 ([#4](https://github.com/openCoreEMR/oce-module-sinch-conversations/issues/4)) ([d5fc489](https://github.com/openCoreEMR/oce-module-sinch-conversations/commit/d5fc4896b1cc87beb9b1ecd88765a59ed5eee1b3))
* bump actions/upload-artifact from 6 to 7 ([#15](https://github.com/openCoreEMR/oce-module-sinch-conversations/issues/15)) ([79e23a4](https://github.com/openCoreEMR/oce-module-sinch-conversations/commit/79e23a4a93a5af63f028abc3d3443e14354b41de))
* update squizlabs/php_codesniffer requirement from ^3.0 to ^4.0 ([#3](https://github.com/openCoreEMR/oce-module-sinch-conversations/issues/3)) ([be4a686](https://github.com/openCoreEMR/oce-module-sinch-conversations/commit/be4a6868b042e3390be1730a85a4c196347a9a12))
* widen psr/http-message constraint to ^1.1 || ^2.0 ([#17](https://github.com/openCoreEMR/oce-module-sinch-conversations/issues/17)) ([ded20b1](https://github.com/openCoreEMR/oce-module-sinch-conversations/commit/ded20b1044e171d626ab5d325497805aec1a6ef0))
* widen symfony constraints to ^6.4 || ^7.0 ([#16](https://github.com/openCoreEMR/oce-module-sinch-conversations/issues/16)) ([d9516d2](https://github.com/openCoreEMR/oce-module-sinch-conversations/commit/d9516d231bde55b21f04b41b86a4bdb62edb6dd9))

## 0.1.0 (Unreleased)

Initial release of the OpenCoreEMR Sinch Conversations Module.

### Features

- Initial module structure and configuration
- Database schema for conversations, messages, contacts, and consent tracking
- Support for Sinch Conversations API integration
- Template-based messaging system with 12 pre-approved templates
- HELP/STOP/START/UNSTOP keyword response handling
- Multi-channel support (SMS, WhatsApp, RCS)
- HIPAA-compliant consent tracking and opt-out management
