# Changelog


## [0.39.0](https://github.com/getmilpa/framework/compare/v0.38.1...v0.39.0) (2026-09-03)


### Features

* emit through ResponseEmitter so a new app can stream SSE ([#85](https://github.com/getmilpa/framework/issues/85)) ([d06f793](https://github.com/getmilpa/framework/commit/d06f79337b62dfe4e700fb0dbc26640e5649b183))

## [0.38.1](https://github.com/getmilpa/framework/compare/v0.38.0...v0.38.1) (2026-08-28)


### Bug Fixes

* **mcp:** register the booted Kernel so the MCP surface can reach the session store ([#82](https://github.com/getmilpa/framework/issues/82)) ([6cee7bf](https://github.com/getmilpa/framework/commit/6cee7bf36918986636732022902fae88f16ad140))

## [0.38.0](https://github.com/getmilpa/framework/compare/v0.37.0...v0.38.0) (2026-08-27)


### Features

* **http:** thread the event dispatcher into the HTTP projector so executions are audited ([#80](https://github.com/getmilpa/framework/issues/80)) ([ebf82a3](https://github.com/getmilpa/framework/commit/ebf82a32f3efa89c76fbab374cf12d15c1efac03))

## [0.37.0](https://github.com/getmilpa/framework/compare/v0.36.2...v0.37.0) (2026-08-27)


### Features

* **http:** wire FileConfirmTokenStore so the HTTP confirm gate completes ([#78](https://github.com/getmilpa/framework/issues/78)) ([8be4a63](https://github.com/getmilpa/framework/commit/8be4a636ec5494861e57fd034e2cbedb1bd49884))

## [0.26.1](https://github.com/getmilpa/framework/releases/tag/v0.26.1) (2026-08-13)

Accepts `app-runtime ^0.20`, where what an agent configuration key looks like has a single definition (`AgentKeyScan`) instead of two copies that disagreed on whether a key may start with a digit.

## [0.26.0](https://github.com/getmilpa/framework/releases/tag/v0.26.0) (2026-08-12)

`config` names the agent configuration keys that exist, each with its type and what it decides.

The code reads seventeen and the template's comment documents four, so `config:set` would take any key without telling the caller which ones the app understands. A rail in `app-runtime` checks the declaration against the `Config::get('agent.*')` call sites in both directions, so it does not become the next stale comment.

## [0.25.1](https://github.com/getmilpa/framework/releases/tag/v0.25.1) (2026-08-12)

`config:set` derives its ceiling from what this app can actually do, instead of assuming the maximum.

Built from `config/operations.php` a provider gets no catalogue, so it borrowed from an empty one and GOV-05 made that the maximum of every axis. `app-runtime v0.18.0` hands the finished catalogue back in a second pass:

```
mutation Persistent · externality ThirdParty · reversibility ManualRecovery
authority Privileged · subject Executable        — all five strictly below the maximum
```

Consent does not change; `subject` and `authority` are exactly what S2 demands. What changes is that the number is derived, so it drops when the app is milder and never drops while it is assumed.

## [0.25.0](https://github.com/getmilpa/framework/releases/tag/v0.25.0) (2026-08-12)

**The agent configuration is an operation of the app, not a file to hand-edit.**

`config` reads what the app runs on and names the keys two files declare at once. `config:set` writes one key through the governed path, so nobody has to know where the configuration lives or how it nests — the same reason `make` scaffolds a controller instead of teaching the layout.

Writing carries a **borrowed ceiling** (greenhouse `decisions/0027`): the heaviest thing the criterion it edits can permit, because whoever edits the judge does not weigh less than what the judge governs. Built from the operations list it receives no catalogue, so it borrows from an empty one — and GOV-05 makes the unclassified the maximum of every dimension. It asks for consent instead of skipping it, which is the right side to be wrong on.

## [0.24.0](https://github.com/getmilpa/framework/releases/tag/v0.24.0) (2026-08-12)

**The rehearsal no longer weighs like the install.**

Wiring S2 made `capabilities:enable --dry-run --json` ask for a signature, and the prompt landed where JSON was expected — a rehearsal carrying the same ceiling as the real install, because S2 judged the *operation* and not the *invocation*.

Three releases answer it, and all three arrive here:

- `command v0.8.0` — the descent field: an argument that lowers a ceiling, carrying its reason
- `app-runtime v0.16.1` — declares one on `capabilities:enable`, with two measurements behind it
- `console v0.9.1` — resolves a ceiling **for the call**, the reader that did not exist

The middle one had been declared and read nowhere. Greenhouse `evidence/0152` measured the field inert before console grew its reader.

Also brings wired S2 itself: an operation whose ceiling reaches `Executable` + `Privileged` asks for consent whether or not it set a flag by hand.

## [0.23.4](https://github.com/getmilpa/framework/compare/v0.23.3...v0.23.4) (2026-08-12)


### Bug Fixes

* accept milpa/plugin ^0.11, and follow app-runtime's message out of Spanish ([#53](https://github.com/getmilpa/framework/issues/53)) ([7b82b10](https://github.com/getmilpa/framework/commit/7b82b103b99b1f58339c33e0585e9fd3730a500b))

## [0.23.3](https://github.com/getmilpa/framework/compare/v0.23.2...v0.23.3) (2026-08-11)


### Bug Fixes

* accept milpa/app-runtime ^0.12 so a new app receives the withdrawal-naming fix ([#51](https://github.com/getmilpa/framework/issues/51)) ([9b47010](https://github.com/getmilpa/framework/commit/9b4701043f19feeacaab9fcf455badf8d5022345))

## [0.23.2](https://github.com/getmilpa/framework/compare/v0.23.1...v0.23.2) (2026-08-09)


### Bug Fixes

* **tests:** the agent helper declares what makes the agent operation exist ([6337db5](https://github.com/getmilpa/framework/commit/6337db514b68196973760bb66c956635176372f1))

## [0.23.1](https://github.com/getmilpa/framework/compare/v0.23.0...v0.23.1) (2026-08-09)


### Bug Fixes

* **tests:** founding your app must not turn its own suite red ([dd93cb5](https://github.com/getmilpa/framework/commit/dd93cb5c306258d599d3870082b225bfc250acea))

## [0.23.0](https://github.com/getmilpa/framework/compare/v0.22.2...v0.23.0) (2026-08-09)


### Features

* the newborn can found itself, and two tests stopped measuring by accident ([820b1e0](https://github.com/getmilpa/framework/commit/820b1e06d9674fadb06533a851eb84d88161c948))

## [0.22.2](https://github.com/getmilpa/framework/compare/v0.22.1...v0.22.2) (2026-08-09)


### Bug Fixes

* **tests:** the newborn suite is green, and every skip teaches its own install ([2af466e](https://github.com/getmilpa/framework/commit/2af466ee9089f884ab36c75eb6ea74d21505f9ff))
* **welcome:** the first-five-minutes page recommended five commands that do not exist ([a159479](https://github.com/getmilpa/framework/commit/a159479ac34b17d0c606052e2a7d6ada58f5b0ed))

## [0.22.1](https://github.com/getmilpa/framework/compare/v0.22.0...v0.22.1) (2026-08-07)


### Bug Fixes

* **deps:** the newborn reaches milpa/app-runtime ^0.10 ([4031e99](https://github.com/getmilpa/framework/commit/4031e99cb4197ffb73928b5ba4dec41e033843dc))

## [0.22.0](https://github.com/getmilpa/framework/compare/v0.21.6...v0.22.0) (2026-08-07)


### Features

* born with a constitution — the newborn ships its foundation kit ([c33ee19](https://github.com/getmilpa/framework/commit/c33ee19c8ad96f5493062eac60c439d2c1ef244b))

## [0.21.6](https://github.com/getmilpa/framework/compare/v0.21.5...v0.21.6) (2026-08-06)


### Bug Fixes

* the kernel goes into the container over HTTP too — the line bin/coa always had ([74cafd4](https://github.com/getmilpa/framework/commit/74cafd4fdec364aad48f20aca418dea1b34d0347))

## [0.21.5](https://github.com/getmilpa/framework/compare/v0.21.4...v0.21.5) (2026-08-05)


### Bug Fixes

* **deps:** el rango de milpa/plugin admite 0.10, y con eso app-runtime 0.8 se puede instalar ([bda84f1](https://github.com/getmilpa/framework/commit/bda84f1d11c1e14f8091411f2eb87cd3d0506140))

## [0.21.4](https://github.com/getmilpa/framework/compare/v0.21.3...v0.21.4) (2026-08-05)


### Bug Fixes

* **deps:** el rango de milpa/app-runtime admite 0.8 ([40551dd](https://github.com/getmilpa/framework/commit/40551ddf703288ff2123c1267468dbd9b7a4ae12))

## [0.21.3](https://github.com/getmilpa/framework/compare/v0.21.2...v0.21.3) (2026-08-05)


### Bug Fixes

* **deps:** el rango de milpa/app-runtime admite 0.7 ([9a3f880](https://github.com/getmilpa/framework/commit/9a3f88006a4bf9047d613cf0288dbc0abc20b738))

## [0.21.2](https://github.com/getmilpa/framework/compare/v0.21.1...v0.21.2) (2026-08-05)


### Bug Fixes

* **deps:** el rango de milpa/app-runtime admite 0.6 ([1b04cac](https://github.com/getmilpa/framework/commit/1b04cacc7e723a61028114e0fabeb983c5628564))

## [0.21.1](https://github.com/getmilpa/framework/compare/v0.21.0...v0.21.1) (2026-08-05)


### Bug Fixes

* **deps:** el rango de milpa/app-runtime admite 0.5 ([99cc4d6](https://github.com/getmilpa/framework/commit/99cc4d657803fd23790f9494a249f46ea78e688b))

## [0.21.0](https://github.com/getmilpa/framework/compare/v0.20.0...v0.21.0) (2026-08-04)


### ⚠ BREAKING CHANGES

* el pin sube a milpa/app-runtime ^0.4 — tiny vuelve a ser tiny
* el runtime del agente llega por versión, no copiado

### Features

* el runtime del agente llega por versión, no copiado ([47de263](https://github.com/getmilpa/framework/commit/47de263785f81f33a3b2c76cf0b45616b1468d3c))


### Bug Fixes

* el pin sube a milpa/app-runtime ^0.4 — tiny vuelve a ser tiny ([b845989](https://github.com/getmilpa/framework/commit/b8459891b4417d0de01fba6e8c88300477877cc1))

## [0.20.0](https://github.com/getmilpa/framework/compare/v0.19.0...v0.20.0) (2026-08-04)


### Features

* **agent:** tablero, reparación gobernada y el TUI que dice quién habla ([c470588](https://github.com/getmilpa/framework/commit/c47058848ffca086a80aae2adfea07732de656a1))

## [0.19.0](https://github.com/getmilpa/framework/compare/v0.18.0...v0.19.0) (2026-08-04)


### Features

* **agent:** agent:discard — cerrar una sesión que quedó esperando a nadie ([09e0666](https://github.com/getmilpa/framework/commit/09e066602bd331606fe7b94b71998b3eef53eb84))

## [0.18.0](https://github.com/getmilpa/framework/compare/v0.17.2...v0.18.0) (2026-08-04)


### Features

* **agent:** el árbol de sub-agentes comparte un fondo de pasos ([68a258a](https://github.com/getmilpa/framework/commit/68a258a6f553550c842db630e308cb617d37cd2d))

## [0.17.2](https://github.com/getmilpa/framework/compare/v0.17.1...v0.17.2) (2026-08-04)


### Bug Fixes

* **deps:** admite milpa/plugin ^0.9 ([451d790](https://github.com/getmilpa/framework/commit/451d7908aeb66160d261c43e41c8dd8e7c2d2df0))

## [0.17.1](https://github.com/getmilpa/framework/compare/v0.17.0...v0.17.1) (2026-08-04)


### Bug Fixes

* **agent:** el sub-agente escribe su plan en su propia sesión ([cffba04](https://github.com/getmilpa/framework/commit/cffba040f9deabf767f72713a6304e343d79dca0))

## [0.17.0](https://github.com/getmilpa/framework/compare/v0.16.0...v0.17.0) (2026-08-04)


### Features

* **agent:** delegación completa — spawn, resume, obligaciones y prohibiciones ejecutadas ([0b2d2db](https://github.com/getmilpa/framework/commit/0b2d2db5860f5e64258bf3a2631919d3df3d9376))


### Bug Fixes

* **test:** la cobertura dejó de depender de lo que quedó en el disco ([f05a0ea](https://github.com/getmilpa/framework/commit/f05a0ea9771d431582d23e5a90f58df767c6362f))

## [0.16.0](https://github.com/getmilpa/framework/compare/v0.15.0...v0.16.0) (2026-08-03)


### Features

* el plan se reproyecta, y Esc interrumpe al agente en vez de cerrar el chat ([aa170a1](https://github.com/getmilpa/framework/commit/aa170a17c34cb547163e81bf2dc0ed5884b8551b))

## [0.15.0](https://github.com/getmilpa/framework/compare/v0.14.1...v0.15.0) (2026-08-02)


### Features

* a fresh session per chat, --continue, /sessions — and a screen that survives what it cannot prevent ([bc99ea5](https://github.com/getmilpa/framework/commit/bc99ea5af015c323ae9a3857a0ca00acc7fbc0a8))

## [0.14.1](https://github.com/getmilpa/framework/compare/v0.14.0...v0.14.1) (2026-08-02)


### Bug Fixes

* a long answer blanked the `coa chat` screen ([61a22c6](https://github.com/getmilpa/framework/commit/61a22c665579a8e0ada224b416ff4a81b719a84d))

## [0.14.0](https://github.com/getmilpa/framework/compare/v0.13.0...v0.14.0) (2026-08-02)


### Features

* the intent contract enforced in the session floor — and the adjudicated cannot adjudicate ([142a3a5](https://github.com/getmilpa/framework/commit/142a3a5867cd5a1a08be5db6bd7f25521d2eecd8))

## [0.13.0](https://github.com/getmilpa/framework/compare/v0.12.0...v0.13.0) (2026-08-02)


### Features

* agent.secondOpinion wires the second reader, with the floor underneath ([334f097](https://github.com/getmilpa/framework/commit/334f09706533523870c4910e33f3c74a9afbf15d))

## [0.12.0](https://github.com/getmilpa/framework/compare/v0.11.0...v0.12.0) (2026-08-02)


### Features

* activity moves to its own status bar ([4cc54ab](https://github.com/getmilpa/framework/commit/4cc54abe5f43fdc58f0a191a2bb7979416c9bd43))

## [0.11.0](https://github.com/getmilpa/framework/compare/v0.10.2...v0.11.0) (2026-08-02)


### Features

* the agent screen says what it is doing, through the same bridge as any surface ([44a19a1](https://github.com/getmilpa/framework/commit/44a19a13dd75efbd3da61e4961fef98241e5873d))

## [0.10.2](https://github.com/getmilpa/framework/compare/v0.10.1...v0.10.2) (2026-08-01)


### Bug Fixes

* running the agent and remembering sessions are two capabilities ([d015a2f](https://github.com/getmilpa/framework/commit/d015a2fa74d5094a7744d585658088104c638fea))

## [0.10.1](https://github.com/getmilpa/framework/compare/v0.10.0...v0.10.1) (2026-08-01)


### Bug Fixes

* what a capability unlocked is read after installing it ([77d058c](https://github.com/getmilpa/framework/commit/77d058cffb5cb0ca48a3cddcb8d98e4db87024e1))

## [0.10.0](https://github.com/getmilpa/framework/compare/v0.9.1...v0.10.0) (2026-08-01)


### Features

* capabilities:enable — one step instead of three, and English at runtime ([c5bc355](https://github.com/getmilpa/framework/commit/c5bc3552441be3e2fa35fddb201d9b0ef5516a12))

## [0.9.1](https://github.com/getmilpa/framework/compare/v0.9.0...v0.9.1) (2026-08-01)


### Bug Fixes

* el corte a tiny tambien vale para create-project ([2105935](https://github.com/getmilpa/framework/commit/21059355159b412b44636021671c97dd8c5f5ed1))
* los opt-in salen tambien de require-dev ([5332748](https://github.com/getmilpa/framework/commit/533274897865e72cb5a01c8d29d563ac02a376c4))

## [0.9.0](https://github.com/getmilpa/framework/compare/v0.8.0...v0.9.0) (2026-08-01)


### ⚠ BREAKING CHANGES

* tiny por default — seis dependencias en vez de doce

### Features

* tiny por default — seis dependencias en vez de doce ([a67df97](https://github.com/getmilpa/framework/commit/a67df9718dde17a25bbf3d92ba599818490453ae))

## [0.8.0](https://github.com/getmilpa/framework/compare/v0.7.0...v0.8.0) (2026-08-01)


### Features

* el stream de sesion llega en vivo a la superficie ([256effe](https://github.com/getmilpa/framework/commit/256effe21497b5afd87fe4a0b06ffa64f901a0e3))

## [0.7.0](https://github.com/getmilpa/framework/compare/v0.6.0...v0.7.0) (2026-08-01)


### Features

* agent:answer se abre por HTTP, y la respuesta sabe quien la dio ([a18371a](https://github.com/getmilpa/framework/commit/a18371a6c5ccaaa78a85579f50729a6eba5274b9))

## [0.6.0](https://github.com/getmilpa/framework/compare/v0.5.1...v0.6.0) (2026-08-01)


### Features

* la superficie de agente entra al framework ([f1e2ff1](https://github.com/getmilpa/framework/commit/f1e2ff10ed8d81eb63ea571e0d907e634bf3b0d1))


### Bug Fixes

* **deps:** el pin de milpa/core deja de ser una jaula de un minor ([852ee49](https://github.com/getmilpa/framework/commit/852ee493d94dc93b0740f9b34245e3a0391fa30b))

## [0.5.1](https://github.com/getmilpa/framework/compare/v0.5.0...v0.5.1) (2026-07-31)


### Bug Fixes

* document the hooks, and cut a release whose CI is green ([81e3534](https://github.com/getmilpa/framework/commit/81e35347def9b0ba3a145cf0612adf5e7c30b5f9))

## [0.5.0](https://github.com/getmilpa/framework/compare/v0.4.0...v0.5.0) (2026-07-31)


### Features

* this app's operations emit hooks from every surface ([15d9055](https://github.com/getmilpa/framework/commit/15d9055b2dff2c751d3d140aab582bb0264310c6))

## [0.4.0](https://github.com/getmilpa/framework/compare/v0.3.0...v0.4.0) (2026-07-31)


### Features

* two screens — a shell that runs any operation, and a conversation with the agent ([d511f3d](https://github.com/getmilpa/framework/commit/d511f3d2ed79659ddeee5357a86903db4784c248))

## [0.3.0](https://github.com/getmilpa/framework/compare/v0.2.2...v0.3.0) (2026-07-31)


### Features

* identity is wired, so the protected operations can be served over HTTP ([8a52820](https://github.com/getmilpa/framework/commit/8a528203bbd3d74f8edf1c639984de256e4aa66b))

## [0.2.2](https://github.com/getmilpa/framework/compare/v0.2.1...v0.2.2) (2026-07-31)


### Bug Fixes

* a boot refusal prints its message instead of a stack trace ([733860c](https://github.com/getmilpa/framework/commit/733860c5513005c50e096583a0e3384ecb3647da))

## [0.2.1](https://github.com/getmilpa/framework/compare/v0.2.0...v0.2.1) (2026-07-31)


### Bug Fixes

* tell milpa/plugin where this app lives, and gain verify + lock ([3d2f4b4](https://github.com/getmilpa/framework/commit/3d2f4b4dfbd0b300cb1a8b0dfdcdc191f6fc06e7))

## [0.2.0](https://github.com/getmilpa/framework/compare/v0.1.3...v0.2.0) (2026-07-31)


### Features

* the agent gets called, and operations can be served over HTTP ([03bfc20](https://github.com/getmilpa/framework/commit/03bfc20589a7df183d50ae124814dde56906635f))

## [0.1.3](https://github.com/getmilpa/framework/compare/v0.1.2...v0.1.3) (2026-07-31)


### Bug Fixes

* this is now the only create-project entry point ([06cb50c](https://github.com/getmilpa/framework/commit/06cb50c018e64ff14692d0f5f30a4c7508014310))

## [0.1.2](https://github.com/getmilpa/framework/compare/v0.1.1...v0.1.2) (2026-07-31)


### Bug Fixes

* require milpa/plugin ^0.5 and milpa/runtime ^0.7 ([0b2796b](https://github.com/getmilpa/framework/commit/0b2796b9792224207f4b5cedd9b228019c2c80de))

## [0.1.1](https://github.com/getmilpa/framework/compare/v0.1.0...v0.1.1) (2026-07-31)


### Bug Fixes

* require milpa/console ^0.3 ([8f6a668](https://github.com/getmilpa/framework/commit/8f6a6689f11f7c4bccb59feba9326dd39e44bce2))

## 0.1.0 (2026-07-31)


### Features

* milpa/framework — a runtime whose every capability is a declared Operation ([13736a2](https://github.com/getmilpa/framework/commit/13736a24ac5a9b5ca04be2634db4b57a0f573e66))


### Bug Fixes

* keep storage/ in the tree, or a fresh clone dies on its first write ([10a96e3](https://github.com/getmilpa/framework/commit/10a96e396f01c00d5e9d0b426e62dfc75226de7f))
