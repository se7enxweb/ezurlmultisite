# ezurlmultisite

Kernel class override for eZ Publish / eZ Platform Legacy that makes the
built-in `ezurl` template operator aware of multiple siteaccesses and their
`PathPrefix` / `SiteURL` configuration.

## What it does

In a multi-site installation `ezurl` normally only prepends the index path and
quotes the input. It does not know that a relative URL such as
`websites/kracker-org/some-page` or `slashnews` might belong to a different
siteaccess and must be rendered as an absolute URL on the correct host.

This extension overrides `eZURLOperator` so `ezurl` (used without template
changes) can:

1. Detect a different target siteaccess from a `PathPrefix` match.
2. Fall back to resolving the content node from the URL alias to determine the
target siteaccess.
3. Build the correct `http(s)://<SiteURL>/<path>` output when linking across
siteaccesses.
4. Leave local/internal links unchanged.

## Installation

1. Copy or clone this extension to `extension/ezurlmultisite`.
2. Make sure `EZP_AUTOLOAD_ALLOW_KERNEL_OVERRIDE` is enabled in `config.php`.
3. Regenerate the kernel override autoload array:

```bash
php bin/php/ezpgenerateautoloads.php --kernel-override
```

4. Clear INI and template caches:

```bash
php bin/php/ezcache.php --allow-root-user --clear-tag=ini
php bin/php/ezcache.php --allow-root-user --clear-tag=template
```

The extension does **not** need to be added to `ActiveExtensions[]` in
`site.ini`; kernel overrides are loaded through the autoload override layer.

## Requirements

- eZ Publish 4.x / eZ Platform Legacy 2017+
- `eZURLOperator` kernel class override support enabled

## File layout

```
extension/ezurlmultisite/
└── classes/
    └── kerneloverride/
        └── v2025.01.15/
            └── kernel/
                └── common/
                    └── ezurloperator.php
```

`ezurloperator.php` is the override for `kernel/common/ezurloperator.php`.

## Usage

Templates continue to use `|ezurl` as before:

```html
<a href={$node.url_alias|ezurl}>...</a>
```

No template changes are required.

## License

Copyright (C) 2026 7x

This program is free software; you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation; either version 2 of the License, or
(at your option) any later version.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License along
with this program; if not, write to the Free Software Foundation, Inc.,
51 Franklin Street, Fifth Floor, Boston, MA 02110-1301 USA.
