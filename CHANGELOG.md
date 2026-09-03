# Changelog

All notable changes to `coolms/ooxml` are recorded here.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).
Versioning is described in `CONTRIBUTING.md` -- read it before assuming what a
major number means here.
## 1.0.0-alpha1 - 2026-09-03

**First published release.** Nothing before this was ever released, so there
is no earlier history to describe.

**A pre-release. It carries no compatibility promise**, which is the honest
statement of where the platform is: the shape is still moving, and a stable
tag would be a promise that cannot be kept yet.

Composer will not install it under default stability. Set

```json
"minimum-stability": "alpha",
"prefer-stable": true
```

in your root `composer.json`, then:

```
composer require coolms/ooxml:^1.0
```

### What it is

Open Packaging Conventions containers in pure PHP, in **7 classes**:

```
Zip/ZipWriter        Zip/ZipReader
Package/OpcPackage   Package/OpcReader   Package/OpcEditor
Package/Relationships
Xml/Xml
```

It knows nothing about workbooks or documents -- it is the container layer
and nothing above it. No dependency on any other `coolms/*` package, which
is why the release classifier puts it on the **independent** side and it
takes its own version numbers rather than the platform generation's.

### Why it exists rather than `ZipArchive`

PHP's `ZipArchive` **cannot open a string**, so a workbook arriving over
HTTP would have to be written to disk before it could be looked at.

### Two things the reader gets right that a naive one does not

- **Sizes and CRC come from the CENTRAL DIRECTORY, never the local header.**
  An entry written with a data descriptor (general-purpose bit 3) carries
  zeroes for all three in its local header. LibreOffice writes *every* entry
  that way, so a reader that trusts the local values rejects every OOXML file
  LibreOffice saves -- documents and workbooks alike -- as damaged.
- **But the name and extra-field lengths come from the LOCAL header**, which
  are frequently different from the directory's for the same entry. Trusting
  the directory's lengths reads from the wrong offset and inflates garbage.

Bit 3 is cleared when an entry is re-emitted, because the sizes are known by
then and an archive must not claim a descriptor it does not write.

### Version

An alpha rather than 1.0.0. The package is in production use inside CoolMS
and covered by tests, but it has never been consumed from a registry by
anyone, and a stable tag is a compatibility promise that no external use has
yet tested.
