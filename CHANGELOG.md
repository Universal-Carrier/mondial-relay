# Changelog

All notable changes to this package will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this
package adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

Nothing since 0.1.0. The driver follows `universal-carrier/core`, which is itself still in 0.x, so
its behaviour may still change with the contract.

Two kinds of change will be recorded here from 1.0.0 on:

- **The mapping of Mondial Relay's tracking wordings onto `TrackingStatus`.** Events carry no
  code, only a label, and Mondial Relay publishes no list of them: every wording added to the
  table changes the status reported for parcels that were already being tracked. Those entries
  are minor releases, and each one names the wordings concerned.
- **Anything the carrier changes on its side** — a Web Service, a STAT code, the shape of a
  label URL — which reaches consumers through this package alone.

## [0.1.0] - 2026-11-14

First public pre-release.

[Unreleased]: https://github.com/Universal-Carrier/mondial-relay/compare/v0.1.0...HEAD
[0.1.0]: https://github.com/Universal-Carrier/mondial-relay/releases/tag/v0.1.0
