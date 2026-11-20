# Versioning

How turborel-php's releases are numbered, and how one is published.

[Release numbers](#release-numbers) · [Alpha](#alpha) ·
[The protocol](#the-protocol) · [Where the version is](#where-the-version-is) ·
[Publishing](#publishing)

---

## Release numbers

The driver's version is the turborel release it is made for. A turborel
release and each driver's release share one number, so turborel-php
1.4.0 is the driver of turborel 1.4.0, and a driver and a server of the
same number always work together. The driver has no version scheme of
its own (RULES.md D13). What each kind of release may change, PATCH,
MINOR or MAJOR, is what turborel's VERSIONING.md says of its own.

## Alpha

turborel is in alpha, version **0.0.0**, and so is the driver. While its
version is 0.y.z nothing is promised: any class, method, setting or
behaviour may change in any build. The first stable release is turborel's
1.0.0, and the driver's with it.

## The protocol

The alpha speaks turborel's native protocol version 1, the only version.
The protocol grows by capabilities, never by a new version, so a
driver asks for those it knows and uses those the server grants.

## Where the version is

- `version` in composer.json (RULES.md D11).
- `Turborel\Turborel::VERSION`, the same number.
- The release's git tag, `v` and the number, as `v1.4.0`.

## Publishing

The driver is published on [Packagist](https://packagist.org) as
`turborel/turborel`, which Composer installs from. Nothing is published
until every turborel driver is done, and then the owner publishes them
together. Until then, `.github/workflows/release.yml` builds and checks
the package by hand, and its publish job stays disabled.

A release is published from a pushed commit that has passed
`composer verify` and `composer coverage` in CI:

1. Set the release's number in composer.json and in
   `Turborel\Turborel::VERSION`, in a commit of its own.
2. Run the release workflow by hand, and check the package it builds:
   LICENSE and `src/` in it, the development files left out
   (`.gitattributes`).
3. Push the tag, `v` and the number, on that commit.
4. On packagist.org, linked to the GitHub repository, the package is
   read from the tag; the first time, submit the repository's URL. The
   workflow's publish job, once the owner enables it, asks Packagist to
   read the tag.

A published version cannot be published again. A fix is a new release.
