# Changelog

## [1.3.1](https://github.com/openCoreEMR/oce-module-sinch-conversations/compare/1.3.0...1.3.1) (2026-06-11)


### Bug Fixes

* **bootstrap:** resolve Kernel projectDir throw on oce-810 ([#184](https://github.com/openCoreEMR/oce-module-sinch-conversations/issues/184)) ([b0e2efa](https://github.com/openCoreEMR/oce-module-sinch-conversations/commit/b0e2efa1f465d1db55b1b3db180712e4fd7c395c))
* **csrf:** pass SessionInterface to CsrfUtils for oce-810 ([#185](https://github.com/openCoreEMR/oce-module-sinch-conversations/issues/185)) ([097d416](https://github.com/openCoreEMR/oce-module-sinch-conversations/commit/097d416ef74dc540204e778633bb7c5c2aa18b6f))
* **deps:** bump guzzlehttp/guzzle from 7.11.0 to 7.11.1 ([#183](https://github.com/openCoreEMR/oce-module-sinch-conversations/issues/183)) ([aff4e48](https://github.com/openCoreEMR/oce-module-sinch-conversations/commit/aff4e48b980c88d0891af19da165a24a0909a645))
* drop redundant !$testSend condition in ConsentCheckCommand ([#179](https://github.com/openCoreEMR/oce-module-sinch-conversations/issues/179)) ([8eb7804](https://github.com/openCoreEMR/oce-module-sinch-conversations/commit/8eb78044a4261e3432cc6526c29ce2d44e686f73))


### Dependencies

* bump openCoreEMR/github-workflows-public/.github/workflows/conventional-pr-title.yml ([#169](https://github.com/openCoreEMR/oce-module-sinch-conversations/issues/169)) ([a703f55](https://github.com/openCoreEMR/oce-module-sinch-conversations/commit/a703f556c769a4bddaefc1f525c0c774cadbe982))
* bump openCoreEMR/github-workflows-public/.github/workflows/php-composer-script.yml ([#167](https://github.com/openCoreEMR/oce-module-sinch-conversations/issues/167)) ([2748741](https://github.com/openCoreEMR/oce-module-sinch-conversations/commit/27487413e9863458d3745612b2a6545880892225))
* bump openCoreEMR/github-workflows-public/.github/workflows/php-tests.yml ([#168](https://github.com/openCoreEMR/oce-module-sinch-conversations/issues/168)) ([a787512](https://github.com/openCoreEMR/oce-module-sinch-conversations/commit/a787512587e618d3c10077ebf65beecfd39e0fa0))
* bump opencoreemr/github-workflows-public/.github/workflows/release-please-reusable.yml ([#170](https://github.com/openCoreEMR/oce-module-sinch-conversations/issues/170)) ([e6f0b85](https://github.com/openCoreEMR/oce-module-sinch-conversations/commit/e6f0b85817271a6313424622dce674f2e4a6b53a))

## [1.3.0](https://github.com/openCoreEMR/oce-module-sinch-conversations/compare/1.2.1...1.3.0) (2026-05-15)


### Features

* automate Sinch webhook provisioning from settings page ([#105](https://github.com/openCoreEMR/oce-module-sinch-conversations/issues/105)) ([027be53](https://github.com/openCoreEMR/oce-module-sinch-conversations/commit/027be53b38a540ba1f965abe7dcb54e60b2fbb45))


### Dependencies

* bump actions/checkout from 4 to 6 ([#152](https://github.com/openCoreEMR/oce-module-sinch-conversations/issues/152)) ([9c66ede](https://github.com/openCoreEMR/oce-module-sinch-conversations/commit/9c66ede7698d88668d518a07538e8a5b3f62ce72))
* bump openCoreEMR/github-workflows-public/.github/workflows/conventional-pr-title.yml ([#159](https://github.com/openCoreEMR/oce-module-sinch-conversations/issues/159)) ([4be1f8e](https://github.com/openCoreEMR/oce-module-sinch-conversations/commit/4be1f8e3137e177189b09a1f39ad9ddb79730e35))
* bump openCoreEMR/github-workflows-public/.github/workflows/php-composer-script.yml ([#158](https://github.com/openCoreEMR/oce-module-sinch-conversations/issues/158)) ([9971acc](https://github.com/openCoreEMR/oce-module-sinch-conversations/commit/9971acc4263a3a8a9f780e0ffb57fae5b93e923d))
* bump openCoreEMR/github-workflows-public/.github/workflows/php-tests.yml ([#157](https://github.com/openCoreEMR/oce-module-sinch-conversations/issues/157)) ([f966b0e](https://github.com/openCoreEMR/oce-module-sinch-conversations/commit/f966b0e46f29eb9f7e89380fecf2b28ec5fe5127))
* bump opencoreemr/github-workflows-public/.github/workflows/release-please-reusable.yml ([#160](https://github.com/openCoreEMR/oce-module-sinch-conversations/issues/160)) ([dfe3cb4](https://github.com/openCoreEMR/oce-module-sinch-conversations/commit/dfe3cb4b540fa8b43c0c449ebef2c4e234836746))

## [1.2.1](https://github.com/openCoreEMR/oce-module-sinch-conversations/compare/1.2.0...1.2.1) (2026-05-13)


### Bug Fixes

* **api:** route Sinch API calls to the configured region, not always US ([#150](https://github.com/openCoreEMR/oce-module-sinch-conversations/issues/150)) ([36354da](https://github.com/openCoreEMR/oce-module-sinch-conversations/commit/36354dadf1a7e7a6f48832ce6863e339d16bb77a))
* **reminders:** call fetchAppointments, not fetchAllEvents ([#143](https://github.com/openCoreEMR/oce-module-sinch-conversations/issues/143)) ([fba9ec5](https://github.com/openCoreEMR/oce-module-sinch-conversations/commit/fba9ec560e0b2208883943c05f5e3b531ff39aa9))
* **reminders:** read pid from fetchAppointments rows, not pc_pid ([#149](https://github.com/openCoreEMR/oce-module-sinch-conversations/issues/149)) ([4926035](https://github.com/openCoreEMR/oce-module-sinch-conversations/commit/49260356121ca3d2a7fa6c2eee7dc922ca967ee7))


### Dependencies

* bump opencoreemr/github-workflows-public/.github/workflows/release-please-reusable.yml ([#141](https://github.com/openCoreEMR/oce-module-sinch-conversations/issues/141)) ([718bd4c](https://github.com/openCoreEMR/oce-module-sinch-conversations/commit/718bd4caccf8ca43f4ac9a83f7e3f645914b477e))

## [1.2.0](https://github.com/openCoreEMR/oce-module-sinch-conversations/compare/1.1.2...1.2.0) (2026-05-12)


### Features

* **settings:** validate Sinch credentials before saving ([#135](https://github.com/openCoreEMR/oce-module-sinch-conversations/issues/135)) ([f8d3571](https://github.com/openCoreEMR/oce-module-sinch-conversations/commit/f8d3571b51b1e8422daddcb625fc6fa7aa5f865f))


### Bug Fixes

* **reminders:** expand recurring appointments into per-occurrence SMS reminders ([#137](https://github.com/openCoreEMR/oce-module-sinch-conversations/issues/137)) ([ea63da6](https://github.com/openCoreEMR/oce-module-sinch-conversations/commit/ea63da6365f4db39b8256fa2c1d1e318aec8cb02))

## [1.1.2](https://github.com/openCoreEMR/oce-module-sinch-conversations/compare/1.1.1...1.1.2) (2026-05-11)


### Bug Fixes

* **settings:** hide Default Channel field until WhatsApp/RCS are supported ([#133](https://github.com/openCoreEMR/oce-module-sinch-conversations/issues/133)) ([eb12960](https://github.com/openCoreEMR/oce-module-sinch-conversations/commit/eb1296015a6a660a1c8365584791b10a3192c598))

## [1.1.1](https://github.com/openCoreEMR/oce-module-sinch-conversations/compare/1.1.0...1.1.1) (2026-05-11)


### Bug Fixes

* stop shadowing OpenEMR core's autoloader from our vendor dir ([#119](https://github.com/openCoreEMR/oce-module-sinch-conversations/issues/119)) ([60da6f0](https://github.com/openCoreEMR/oce-module-sinch-conversations/commit/60da6f08aaf15252d82ab25047de0df2fad52bfd))
* sync templates against Sinch v2 schema and surface real errors ([#122](https://github.com/openCoreEMR/oce-module-sinch-conversations/issues/122)) ([4d32abc](https://github.com/openCoreEMR/oce-module-sinch-conversations/commit/4d32abc07276e9ff63adb87916c8c0a5b2e4db19))
* version Sinch template descriptions by content hash ([#125](https://github.com/openCoreEMR/oce-module-sinch-conversations/issues/125)) ([4b2d735](https://github.com/openCoreEMR/oce-module-sinch-conversations/commit/4b2d73568b98f0674f72e9bff378e222e6b692a0))


### Documentation

* **listener:** correct stale PatientConsentListener coverage note ([#131](https://github.com/openCoreEMR/oce-module-sinch-conversations/issues/131)) ([c598aaa](https://github.com/openCoreEMR/oce-module-sinch-conversations/commit/c598aaabdf477a3f3617cd90fe1516917be409f2))


### Code Refactoring

* **logging:** introduce ExceptionContext::fromThrowable() ([#123](https://github.com/openCoreEMR/oce-module-sinch-conversations/issues/123)) ([9cd3fbe](https://github.com/openCoreEMR/oce-module-sinch-conversations/commit/9cd3fbefc88347f6ff9839e99481a291aa2204f6))
* route remaining JSON call sites through Common\Json wrapper ([#128](https://github.com/openCoreEMR/oce-module-sinch-conversations/issues/128)) ([dd3e9e4](https://github.com/openCoreEMR/oce-module-sinch-conversations/commit/dd3e9e413a69af8811d66f7722d8e7e42caaee2c))
* treat hipaa_allowsms as consent source of truth ([#116](https://github.com/openCoreEMR/oce-module-sinch-conversations/issues/116)) ([15319fe](https://github.com/openCoreEMR/oce-module-sinch-conversations/commit/15319fed4a1ec7bede1b73ba68e46e89bf4e2bdd))


### Dependencies

* bump opencoreemr/github-workflows-public/.github/workflows/release-please-reusable.yml ([#130](https://github.com/openCoreEMR/oce-module-sinch-conversations/issues/130)) ([08a4c2b](https://github.com/openCoreEMR/oce-module-sinch-conversations/commit/08a4c2ba21d0a87d9397b9dd286c76415b96285f))

## [1.1.0](https://github.com/openCoreEMR/oce-module-sinch-conversations/compare/1.0.1...1.1.0) (2026-04-29)


### Features

* opt patients in via hipaa_allowsms transitions on the chart ([#114](https://github.com/openCoreEMR/oce-module-sinch-conversations/issues/114)) ([1cafe18](https://github.com/openCoreEMR/oce-module-sinch-conversations/commit/1cafe182df18d9fd9c425a014038dbe094024378))


### Bug Fixes

* emit structured warnings for appointment reminder skip decisions ([#115](https://github.com/openCoreEMR/oce-module-sinch-conversations/issues/115)) ([3849641](https://github.com/openCoreEMR/oce-module-sinch-conversations/commit/3849641470b6d98718f59c23e67c2d5e78ccf51f))
* normalize phone number in appointment reminder consent check ([#107](https://github.com/openCoreEMR/oce-module-sinch-conversations/issues/107)) ([d4e56e2](https://github.com/openCoreEMR/oce-module-sinch-conversations/commit/d4e56e28f29ef0cf03a184fd64cc2fb0b0cfcd0c)), closes [#106](https://github.com/openCoreEMR/oce-module-sinch-conversations/issues/106)
* render Sinch channel state object in Test API Connection result ([#110](https://github.com/openCoreEMR/oce-module-sinch-conversations/issues/110)) ([87c796e](https://github.com/openCoreEMR/oce-module-sinch-conversations/commit/87c796e8ee40e2f4380c7357051bb83d72b907cb))


### Dependencies

* bump googleapis/release-please-action from 4 to 5 ([#109](https://github.com/openCoreEMR/oce-module-sinch-conversations/issues/109)) ([06947e7](https://github.com/openCoreEMR/oce-module-sinch-conversations/commit/06947e7f79c9c99622b97d23364cd860f79563d9))

## [1.0.1](https://github.com/openCoreEMR/oce-module-sinch-conversations/compare/1.0.0...1.0.1) (2026-04-06)


### Bug Fixes

* conditionally load module autoloader to prevent crash in Docker image ([#101](https://github.com/openCoreEMR/oce-module-sinch-conversations/issues/101)) ([b342a5d](https://github.com/openCoreEMR/oce-module-sinch-conversations/commit/b342a5d631e16ce823dc2a6a1b0c2d585b7efd76))

## [1.0.0](https://github.com/openCoreEMR/oce-module-sinch-conversations/compare/0.9.0...1.0.0) (2026-04-03)


### Features

* add ADR-0001, consent API client methods, and refutation condition CLI ([#84](https://github.com/openCoreEMR/oce-module-sinch-conversations/issues/84)) ([c9fa4e5](https://github.com/openCoreEMR/oce-module-sinch-conversations/commit/c9fa4e5273d7cf104a7626bf349703d203e348f3)), closes [#83](https://github.com/openCoreEMR/oce-module-sinch-conversations/issues/83)
* add appointment reminder cron service ([#49](https://github.com/openCoreEMR/oce-module-sinch-conversations/issues/49)) ([9ec3fe1](https://github.com/openCoreEMR/oce-module-sinch-conversations/commit/9ec3fe1539d680d3809cb6f06327ed1dcb4b4a62))
* add pagination support to consent API client methods ([#88](https://github.com/openCoreEMR/oce-module-sinch-conversations/issues/88)) ([e6fb8c1](https://github.com/openCoreEMR/oce-module-sinch-conversations/commit/e6fb8c1b623583381c235c852a01a55f35795717)), closes [#87](https://github.com/openCoreEMR/oce-module-sinch-conversations/issues/87)
* add webhook endpoint for inbound messages ([#24](https://github.com/openCoreEMR/oce-module-sinch-conversations/issues/24)) ([099ecf9](https://github.com/openCoreEMR/oce-module-sinch-conversations/commit/099ecf9d5b3f21aee965a8fd90015d512df1e72e)), closes [#20](https://github.com/openCoreEMR/oce-module-sinch-conversations/issues/20)
* add webhook nonce tracking for replay protection ([#89](https://github.com/openCoreEMR/oce-module-sinch-conversations/issues/89)) ([c52d930](https://github.com/openCoreEMR/oce-module-sinch-conversations/commit/c52d93090840a238a95444c6ca8baadda499c1ae)), closes [#67](https://github.com/openCoreEMR/oce-module-sinch-conversations/issues/67)
* add webhook tunnel and URL tasks for local development ([#58](https://github.com/openCoreEMR/oce-module-sinch-conversations/issues/58)) ([099cfa3](https://github.com/openCoreEMR/oce-module-sinch-conversations/commit/099cfa3c5f430165b8a509241eac1e3f6ab0db52))
* detect carrier-level opt-outs via delivery failures and consent API polling ([#96](https://github.com/openCoreEMR/oce-module-sinch-conversations/issues/96)) ([3e7514b](https://github.com/openCoreEMR/oce-module-sinch-conversations/commit/3e7514b1b66a1b40a4088dba78f0739f091aa06a)), closes [#31](https://github.com/openCoreEMR/oce-module-sinch-conversations/issues/31)
* disable API config fields when using environment variables ([#37](https://github.com/openCoreEMR/oce-module-sinch-conversations/issues/37)) ([bbd5c3c](https://github.com/openCoreEMR/oce-module-sinch-conversations/commit/bbd5c3c3bc2762a44d593793c9de07391562cc1c))
* gate message sending on consent and hipaa_allowsms ([#29](https://github.com/openCoreEMR/oce-module-sinch-conversations/issues/29)) ([a7df20d](https://github.com/openCoreEMR/oce-module-sinch-conversations/commit/a7df20da219e23f4d54cca231353052e6a5ba561)), closes [#21](https://github.com/openCoreEMR/oce-module-sinch-conversations/issues/21)
* portal-aware appointment reminder template selection ([#38](https://github.com/openCoreEMR/oce-module-sinch-conversations/issues/38)) ([306a6cd](https://github.com/openCoreEMR/oce-module-sinch-conversations/commit/306a6cd325b60602bcc81241de9f9d24a8ab4f82))
* register appointment reminders as OpenEMR background service ([#68](https://github.com/openCoreEMR/oce-module-sinch-conversations/issues/68)) ([daf493b](https://github.com/openCoreEMR/oce-module-sinch-conversations/commit/daf493b049fcef8ce4161787f5fc175c4c8d62a3)), closes [#50](https://github.com/openCoreEMR/oce-module-sinch-conversations/issues/50)
* replace Basic Auth with HMAC-SHA256 webhook signature validation ([#64](https://github.com/openCoreEMR/oce-module-sinch-conversations/issues/64)) ([a6057b9](https://github.com/openCoreEMR/oce-module-sinch-conversations/commit/a6057b9f0b61eb6552dd5aa72df876c0065036b5)), closes [#60](https://github.com/openCoreEMR/oce-module-sinch-conversations/issues/60)
* wire keyword handler into polling fallback and sync hipaa_allowsms ([#34](https://github.com/openCoreEMR/oce-module-sinch-conversations/issues/34)) ([8592005](https://github.com/openCoreEMR/oce-module-sinch-conversations/commit/8592005d4a031695c76cb34450fbde9e7a172e0a)), closes [#22](https://github.com/openCoreEMR/oce-module-sinch-conversations/issues/22)


### Bug Fixes

* centralize phone number normalization to E.164 format ([#46](https://github.com/openCoreEMR/oce-module-sinch-conversations/issues/46)) ([6909718](https://github.com/openCoreEMR/oce-module-sinch-conversations/commit/6909718f4916a768174ad9b56ca8b26a0c632f58))
* consolidate settings to module page, remove globals duplicates ([#74](https://github.com/openCoreEMR/oce-module-sinch-conversations/issues/74)) ([5130be1](https://github.com/openCoreEMR/oce-module-sinch-conversations/commit/5130be1eb6af152066fce97046fc8463bd82e7e3)), closes [#57](https://github.com/openCoreEMR/oce-module-sinch-conversations/issues/57)
* detect webhook event type by field presence, not trigger field ([#91](https://github.com/openCoreEMR/oce-module-sinch-conversations/issues/91)) ([cec03f1](https://github.com/openCoreEMR/oce-module-sinch-conversations/commit/cec03f1a1fdb11d89cb1a90edd903dd9ab273df7))
* line length warning in WebhookController opt-out call ([#54](https://github.com/openCoreEMR/oce-module-sinch-conversations/issues/54)) ([9d2a2fc](https://github.com/openCoreEMR/oce-module-sinch-conversations/commit/9d2a2fce048aeed1e459867c9786faa5358da2f4))
* move kernel resolution inside try/catch in public entry points ([#86](https://github.com/openCoreEMR/oce-module-sinch-conversations/issues/86)) ([1b4551a](https://github.com/openCoreEMR/oce-module-sinch-conversations/commit/1b4551a8b9b7934c689dd9aa782ab83e95af4234)), closes [#82](https://github.com/openCoreEMR/oce-module-sinch-conversations/issues/82)
* normalize phone in ConversationController::handleReply ([#77](https://github.com/openCoreEMR/oce-module-sinch-conversations/issues/77)) ([8e4fbed](https://github.com/openCoreEMR/oce-module-sinch-conversations/commit/8e4fbedb78dc36a709465b8b7021077482caeddf)), closes [#53](https://github.com/openCoreEMR/oce-module-sinch-conversations/issues/53)
* only sync hipaa_allowsms on SMS channel opt-out ([#47](https://github.com/openCoreEMR/oce-module-sinch-conversations/issues/47)) ([03c6a38](https://github.com/openCoreEMR/oce-module-sinch-conversations/commit/03c6a38244b68daf3983823817dd07185a5d2813))
* require module's vendor/autoload.php in bootstrap and background entry ([#97](https://github.com/openCoreEMR/oce-module-sinch-conversations/issues/97)) ([d851ca3](https://github.com/openCoreEMR/oce-module-sinch-conversations/commit/d851ca396f5a62bf78de2ed291c0fbf7686d62f8))
* send messages by channel identity instead of Sinch contact ID ([#99](https://github.com/openCoreEMR/oce-module-sinch-conversations/issues/99)) ([a1a98d1](https://github.com/openCoreEMR/oce-module-sinch-conversations/commit/a1a98d1cda25a601e88ff6978cffee36f57947bd))
* surface exceptions instead of swallowing in webhook and consent service ([#36](https://github.com/openCoreEMR/oce-module-sinch-conversations/issues/36)) ([4d0a6f9](https://github.com/openCoreEMR/oce-module-sinch-conversations/commit/4d0a6f9fffff555b18b2f8ec03664cff46799529))
* use #[AsCommand] attribute for CLI command names ([#71](https://github.com/openCoreEMR/oce-module-sinch-conversations/issues/71)) ([6fd81df](https://github.com/openCoreEMR/oce-module-sinch-conversations/commit/6fd81dfbc5dcaec733b819d520238ce9c56456c2)), closes [#59](https://github.com/openCoreEMR/oce-module-sinch-conversations/issues/59)
* use config accessor for menu visibility instead of global_req ([#55](https://github.com/openCoreEMR/oce-module-sinch-conversations/issues/55)) ([0727c1d](https://github.com/openCoreEMR/oce-module-sinch-conversations/commit/0727c1d3528e5fcd971913e81ce7badcbf366643))


### Documentation

* add Sinch provisioning guide ([#63](https://github.com/openCoreEMR/oce-module-sinch-conversations/issues/63)) ([#85](https://github.com/openCoreEMR/oce-module-sinch-conversations/issues/85)) ([c60b11b](https://github.com/openCoreEMR/oce-module-sinch-conversations/commit/c60b11bead0e8f49849b7fac65d563447fb8c14a))
* add sinch setup guide, messaging docs, and troubleshooting ([#45](https://github.com/openCoreEMR/oce-module-sinch-conversations/issues/45)) ([c164436](https://github.com/openCoreEMR/oce-module-sinch-conversations/commit/c16443648094e8f15469f27c074ce2126574a0e2))


### Miscellaneous Chores

* release 1.0.0 ([2d33029](https://github.com/openCoreEMR/oce-module-sinch-conversations/commit/2d330294d2e60643398d73867e2281edb31594a5))


### Code Refactoring

* add patient-id-aware keyword handler entry point ([#41](https://github.com/openCoreEMR/oce-module-sinch-conversations/issues/41)) ([#93](https://github.com/openCoreEMR/oce-module-sinch-conversations/issues/93)) ([a9b8422](https://github.com/openCoreEMR/oce-module-sinch-conversations/commit/a9b8422dd44253f730e4c9d380dda9f429b23565))
* adopt PSR-3 logging context and surface exceptions ([#48](https://github.com/openCoreEMR/oce-module-sinch-conversations/issues/48)) ([ddcfbca](https://github.com/openCoreEMR/oce-module-sinch-conversations/commit/ddcfbcab423b7fb26f5ab759ad26d8794cba1268))
* adopt PSR-3 logging context in Sinch client layer ([#52](https://github.com/openCoreEMR/oce-module-sinch-conversations/issues/52)) ([#94](https://github.com/openCoreEMR/oce-module-sinch-conversations/issues/94)) ([cb3750b](https://github.com/openCoreEMR/oce-module-sinch-conversations/commit/cb3750bd334e8d4626c4fe0207a1c5235ad24db1))
* surface chained exception details in CLI commands ([#100](https://github.com/openCoreEMR/oce-module-sinch-conversations/issues/100)) ([f1a4957](https://github.com/openCoreEMR/oce-module-sinch-conversations/commit/f1a495717327a6f37e730ef23e5c425889f32193)), closes [#95](https://github.com/openCoreEMR/oce-module-sinch-conversations/issues/95)

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
