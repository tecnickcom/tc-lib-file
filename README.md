# tc-lib-file

> PHP utilities for low-level file access and byte-level reading.

[![Latest Stable Version](https://poser.pugx.org/tecnickcom/tc-lib-file/version)](https://packagist.org/packages/tecnickcom/tc-lib-file)
[![Build](https://github.com/tecnickcom/tc-lib-file/actions/workflows/check.yml/badge.svg)](https://github.com/tecnickcom/tc-lib-file/actions/workflows/check.yml)
[![Coverage](https://codecov.io/gh/tecnickcom/tc-lib-file/graph/badge.svg?token=iZ9snwgkUA)](https://codecov.io/gh/tecnickcom/tc-lib-file)
[![License](https://poser.pugx.org/tecnickcom/tc-lib-file/license)](https://packagist.org/packages/tecnickcom/tc-lib-file)
[![Downloads](https://poser.pugx.org/tecnickcom/tc-lib-file/downloads)](https://packagist.org/packages/tecnickcom/tc-lib-file)

[![Sponsor on GitHub](https://img.shields.io/badge/sponsor-github-EA4AAA.svg?logo=githubsponsors&logoColor=white)](https://github.com/sponsors/tecnickcom)

If this project is useful to you, please consider [supporting development via GitHub Sponsors](https://github.com/sponsors/tecnickcom).

---

## Overview

`tc-lib-file` provides safe primitives for opening files, reading bytes, and handling binary-oriented workflows used by higher-level PDF and document libraries.

The package is intentionally small but critical: it centralizes low-level I/O concerns so higher-level libraries can focus on document semantics instead of stream safety and byte handling. This separation improves reliability, testability, and reuse across the broader Tecnick ecosystem.

| | |
|---|---|
| **Namespace** | `\Com\Tecnick\File` |
| **Author** | Nicola Asuni <info@tecnick.com> |
| **License** | [GNU LGPL v3](https://www.gnu.org/copyleft/lesser.html) - see [LICENSE](LICENSE) |
| **API docs** | <https://tcpdf.org/docs/srcdoc/tc-lib-file> |
| **Packagist** | <https://packagist.org/packages/tecnickcom/tc-lib-file> |

---

## Features

### File Access
- Local and URL-backed file reading helpers
- Path-safety checks for local operations
- cURL-based retrieval options for remote resources

### Binary Utilities
- Byte, integer, and structured binary reads
- Helpers used by parser and image/font import stacks
- Error handling via typed exceptions

---

## Requirements

- PHP 8.2 or later
- Extensions: `curl`, `pcre`
- Composer

---

## Installation

```bash
composer require tecnickcom/tc-lib-file
```

---

## Quick Start

```php
<?php

require_once __DIR__ . '/vendor/autoload.php';

$file = new \Com\Tecnick\File\File(
	allowedHosts: ['example.com', 'cdn.example.com'],
	allowedPaths: [__DIR__, '/var/app/uploads'],
	curlopts: [
		CURLOPT_MAXREDIRS => 3,
	],
);
$fh = $file->fopenLocal(__FILE__, 'rb');
$header = $file->fReadInt($fh);

var_dump($header);
```

## Security Configuration (Required)

`File` defaults to strict-deny behavior for host and path validation.

- `allowedHosts` defaults to an empty array, so remote URLs and host-based alternate path resolution are rejected unless you explicitly trust hosts.
- `allowedPaths` defaults to an empty array, so local file operations are rejected unless you explicitly trust path prefixes.

You should always pass explicit allowlists in the constructor (or set them immediately via setters) for production use.

Example:

```php
$file = new \Com\Tecnick\File\File(
	allowedHosts: ['example.com'],
	allowedPaths: ['/srv/my-app/data'],
);

// Equivalent runtime configuration:
$file
	->setAllowedHosts(['example.com'])
	->setAllowedPaths(['/srv/my-app/data']);
```

Avoid wildcard trust (`'*'`) unless you fully control all inputs and deployment boundaries.

### Redirect Handling via `CURLOPT_MAXREDIRS`

Redirect validation is enabled when `CURLOPT_MAXREDIRS` is non-zero.

- `CURLOPT_MAXREDIRS => 0` (default): no redirect-follow validation callback is installed.
- `CURLOPT_MAXREDIRS > 0`: redirects are processed and each `Location` target is validated.

To allow redirects, set a positive max-redirs value and ensure redirect target hosts are present in `allowedHosts`.

```php
$file = new \Com\Tecnick\File\File(
	allowedHosts: ['example.com', 'downloads.example.com'],
	curlopts: [
		CURLOPT_MAXREDIRS => 5,
	],
);
```

---

## Development

```bash
make deps
make help
make qa
```

---

## Packaging

```bash
make rpm
make deb
```

For system packages, bootstrap with:

```php
require_once '/usr/share/php/Com/Tecnick/File/autoload.php';
```

---

## Contributing

Contributions are welcome. Please review [CONTRIBUTING.md](CONTRIBUTING.md), [CODE_OF_CONDUCT.md](CODE_OF_CONDUCT.md), and [SECURITY.md](SECURITY.md).

