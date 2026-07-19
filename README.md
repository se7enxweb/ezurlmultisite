# ezurlmultisite

Kernel class override for Exponential / Exponential Platform Legacy that extends the
built-in `ezurl` template operator with multi-siteaccess link handling and a
number of optional `sitelink`-inspired features, while keeping the original
`ezurl` API intact.

## What it does

In a multi-site installation `ezurl` normally only prepends the index path and
quotes the input. It does not know that a relative URL such as
`websites/sole-kracker-org/foo` or `slashnews` might belong to a different
siteaccess and must be rendered as an absolute URL on the correct host.

This extension overrides `eZURLOperator` so `ezurl` can, without template
changes:

1. Detect a different target siteaccess from a `PathPrefix` match.
2. Fall back to resolving the content node from the URL alias to determine the
   target siteaccess.
3. Build the correct `http(s)://<SiteURL>/<path>` output when linking across
   siteaccesses.
4. Leave local/internal links unchanged.
5. Accept an optional third `parameters` hash with extra options such as
   `absolute`, `siteaccess`, `hash`, `query`, `no_self_link`, `protocol`, etc.

It also registers three new operators for advanced use:

- `ezurl_path`
- `ezurl_siteaccess`
- `ezurl_roots`

## Requirements

- eZ Publish 4.x / eZ Platform Legacy 2017+
- `eZURLOperator` kernel class override support enabled
- `EZP_AUTOLOAD_ALLOW_KERNEL_OVERRIDE` enabled in `config.php` for the kernel
  override layer

## Installation

1. Copy or clone this extension to `extension/ezurlmultisite`.
2. Add the extension to `settings/override/site.ini.append.php`:

```ini
[ExtensionSettings]
ActiveExtensions[]=ezurlmultisite
```

3. Regenerate autoload arrays:

```bash
php bin/php/ezpgenerateautoloads.php --extension
php bin/php/ezpgenerateautoloads.php --kernel-override
```

4. Clear caches:

```bash
php bin/php/ezcache.php --allow-root-user --clear-tag=ini
php bin/php/ezcache.php --allow-root-user --clear-tag=template
```

## File layout

```
extension/ezurlmultisite/
├── autoloads/
│   └── eztemplateautoload.php
├── classes/
│   ├── ezurlmultisite.php
│   ├── ezurlmultisiteoperator.php
│   ├── ezurlmultisitedatatypehandler.php
│   ├── ezurlmultisiteurlhandler.php
│   ├── ezurlmultisitebinaryfilehandler.php
│   ├── ezurlmultisiteobjectrelationhandler.php
│   └── kerneloverride/
│       └── v2025.01.15/
│           └── kernel/
│               └── common/
│                   └── ezurloperator.php
├── settings/
│   └── ezurlmultisite.ini
└── README.md
```

- `classes/kerneloverride/.../ezurloperator.php` is the kernel override for
  `kernel/common/ezurloperator.php`.
- `classes/ezurlmultisite.php` contains the shared helper logic.
- `classes/ezurlmultisiteoperator.php` registers `ezurl_path`,
  `ezurl_siteaccess` and `ezurl_roots`.

## Basic usage

Templates continue to use `|ezurl` as before:

```html
<a href={$node.url_alias|ezurl}>...</a>
```

No template changes are required for the multi-siteaccess fix.

## Extended `ezurl` parameters

The operator keeps the original positional parameters:

```html
{$url|ezurl}
{$url|ezurl('single')}
{$url|ezurl('no','full')}
```

A third positional parameter, `parameters`, accepts a hash of advanced options:

```html
{$url|ezurl('double','relative',hash('absolute',true(),'protocol','https'))}
```

### Available parameters

| Key | Type | Description |
|-----|------|-------------|
| `absolute` | boolean | Force a full absolute URL. |
| `siteaccess` | string | Force the target siteaccess. |
| `protocol` | string | Override the URL scheme: `http`, `https`, `//` (protocol-relative), or empty for current scheme. |
| `hash` | string | Append a URL fragment (`#section`). |
| `query` | string or hash | Append a query string; a hash is converted to `key=value&...`. |
| `user_parameters` | array | Append eZ Publish view user parameters as `/(key)/value` segments. |
| `no_self_link` | boolean | Return an empty string if the link target is the current page. |
| `node_id` | boolean | When the input is an integer, treat it as a node ID (`true`, default) or object ID (`false`). |
| `link_type` | string | For class-based links, request a specific type such as `internal`, `external` or `download`. |

### Examples

```html
{* Force absolute URL *}
<a href={$node.url_alias|ezurl('double','relative',hash('absolute',true()))}>...</a>

{* Force a specific siteaccess *}
<a href={$node.url_alias|ezurl('double','relative',hash('siteaccess','korg_sole'))}>...</a>

{* HTTPS absolute link, with query and fragment *}
<a href={$node.url_alias|ezurl('double','relative',hash('absolute',true(),'protocol','https','query','foo=bar','hash','section'))}>...</a>

{* Pass an integer node ID *}
<a href={$node_id|ezurl('double','relative',hash('node_id',true()))}>...</a>

{* Pass an object ID instead *}
<a href={$object_id|ezurl('double','relative',hash('node_id',false()))}>...</a>
```

### Protocol / HTTPS

The current request scheme is used by default, so an HTTPS page generates
`https://` absolute URLs. The `protocol` option can override this:

```html
{* Protocol-relative URL *}
<a href={$node.url_alias|ezurl('double','relative',hash('absolute',true(),'protocol','//'))}>...</a>

{* Force plain HTTP *}
<a href={$node.url_alias|ezurl('double','relative',hash('absolute',true(),'protocol','http'))}>...</a>
```

## New operators

### `ezurl_path`

Returns the path part of the resolved link, with the target siteaccess prefix
stripped.

```html
{def $path = $node.url_alias|ezurl_path()}
```

### `ezurl_siteaccess`

Returns the name of the siteaccess that should host the given input.

```html
{def $sa = $node.url_alias|ezurl_siteaccess()}
```

### `ezurl_roots`

Returns an array of root node information per siteaccess. Each element contains
`siteaccess`, `host`, `prefix` and `root_node_id`.

```html
{def $roots = ezurl_roots()}
```

## Configuration

Settings are in `extension/ezurlmultisite/settings/ezurlmultisite.ini`. Copy
the file to `settings/override/ezurlmultisite.ini.append.php` to customize it
safely.

### `[OperatorSettings]`

```ini
[OperatorSettings]
ForceAbsoluteURL=disabled
DefaultLinkType=internal
DefaultQuote=double
DefaultServerURL=relative
RoleOverride=disabled
SiteLinkClassList[]
```

- `ForceAbsoluteURL=enabled` makes every `ezurl` call absolute by default.
- `SiteLinkClassList[]` lists class identifiers that should use class-based link
  generation (see below).

### `[HostMap]`

Override the host used for a siteaccess when `site.ini` `SiteURL` is not enough:

```ini
[HostMap]
SiteAccess[korg_sole]=sole.example.com
```

### `[RoleMap]`

Force the target siteaccess for users with a given role. Enable with
`RoleOverride=enabled` in `[OperatorSettings]`.

```ini
[RoleMap]
RoleList[12]=korg_edit
```

### `[ClassSettings]` and per-class blocks

```ini
[ClassSettings]
DefaultLinkType=internal
SelfLinking=enabled

[link]
DefaultLinkType=external
LinkTypeList[internal]=internal_link
LinkTypeList[external]=external_link
LinkTypeList[download]=file
DataTypeClass=eZURLMultisiteURLHandler
```

For classes listed in `SiteLinkClassList[]`, `ezurl` can resolve the link from a
specific attribute. `LinkTypeList` maps a link type to an attribute identifier,
and `DataTypeClass` can override the datatype handler used for the attribute.

### `[PathSettings]`

```ini
[PathSettings]
DisableNodeID=disabled
DisableObjectID=disabled
NodeIDList[]
ObjectIDList[]
```

When `DisableNodeID` or `DisableObjectID` is `enabled`, listed IDs cause `ezurl`
to return an empty string.

### `[DataTypeSettings]`

```ini
[DataTypeSettings]
HandlerList[ezurl]=eZURLMultisiteURLHandler
HandlerList[ezstring]=eZURLMultisiteURLHandler
HandlerList[ezbinaryfile]=eZURLMultisiteBinaryFileHandler
HandlerList[ezobjectrelation]=eZURLMultisiteObjectRelationHandler
```

Custom handlers must implement `eZURLMultisiteDataTypeHandler`.

## Datatype handlers

The extension ships with three handlers:

- `eZURLMultisiteURLHandler` for `ezurl` and `ezstring` attributes.
- `eZURLMultisiteBinaryFileHandler` for `ezbinaryfile` attributes, with
  `download` link type support.
- `eZURLMultisiteObjectRelationHandler` for `ezobjectrelation` attributes,
  resolving the related object's main node URL alias.

## License

Copyright (C) 1998 - 2026 7x

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
