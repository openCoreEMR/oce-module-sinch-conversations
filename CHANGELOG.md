# Changelog

## [0.1.1](https://github.com/openCoreEMR/oce-module-sinch-conversations/compare/0.1.0...0.1.1) (2025-12-10)


### Features

* add MVC controllers for messaging UI ([4e7142e](https://github.com/openCoreEMR/oce-module-sinch-conversations/commit/4e7142e5c756e79e30b93419105e3a97dd4ae21b))
* add public entry points and Twig templates ([94dde74](https://github.com/openCoreEMR/oce-module-sinch-conversations/commit/94dde746b469ff9b1a40dc8a8b4870de017e7630))
* **config:** configure sinch in globals ([d271870](https://github.com/openCoreEMR/oce-module-sinch-conversations/commit/d2718701a7051009fcaf30961543f01bd731da66))
* implement Sinch API client and core services ([752ba28](https://github.com/openCoreEMR/oce-module-sinch-conversations/commit/752ba28dada00d2224ca4f894fb1173ff465c4c8))
* initial Sinch Conversations module structure ([bed55cd](https://github.com/openCoreEMR/oce-module-sinch-conversations/commit/bed55cd7e9bd894ad97f8053e27e4a431405ded1))
* sync templates and oauth to sinch ([fdbb0c9](https://github.com/openCoreEMR/oce-module-sinch-conversations/commit/fdbb0c948f6e24ef62326483f9ab76573a2da146))
* update Bootstrap and GlobalConfig for Sinch Conversations ([41c1aa5](https://github.com/openCoreEMR/oce-module-sinch-conversations/commit/41c1aa54140f280ba040d6ac52cedaaa9431b44f))


### Documentation

* **agents:** teach agents local dev ([13ea3e2](https://github.com/openCoreEMR/oce-module-sinch-conversations/commit/13ea3e2e74a51b21ab0358c69eb5471dddc68157))

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
