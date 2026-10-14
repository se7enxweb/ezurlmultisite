# ezurlmultisite

Multisite-aware `ezurl` for Exponential. In an installation that serves several
sites from one content tree, a link to content of another site points to the
siteaccess that serves that content, instead of to the current siteaccess.

## The problem it solves

Every site of a multisite installation has its own subtree, and its siteaccess
hides that subtree's path with `PathPrefix`. A node of another site keeps that
site's path in its URL alias. On the Bold Agency siteaccess (`/bold`,
`PathPrefix=bold-agency`) a Fit & Healthy article has the alias
`fit-healthy/video/jump-rope-beginner-basics-hiit-workout`, and the kernel
`ezurl` makes it

    /bold/fit-healthy/video/jump-rope-beginner-basics-hiit-workout   -> 404

because the Bold Agency siteaccess has no `fit-healthy` subtree. With
ezurlmultisite the same template code makes

    /video/jump-rope-beginner-basics-hiit-workout                    -> 200

the address of the article on the Fit & Healthy siteaccess. Links within the
current site, module links (`user/login`, `content/search`) and every link
made on a siteaccess that does not take part (the administration) are exactly
what the kernel makes.

## How it works

- `eZURLMultisiteURLOperator` extends the kernel's `eZURLOperator` and is
  registered by `autoloads/eztemplateautoload.php` for all ten operator names
  of the kernel class. Extension autoloads are read after the kernel's, so it
  takes over `ezurl`; every operator other than `ezurl` is the kernel's code.
  No kernel override and no `EZP_AUTOLOAD_ALLOW_KERNEL_OVERRIDE` are needed.
- For each `ezurl` call made on a participating siteaccess,
  `eZURLMultisite::crossSiteURL()` finds the siteaccess the link belongs to:
  - a node or object: the participating siteaccess whose root node is the
    deepest on the node's path (`RootNodeList`), else by URL alias prefix;
  - a URL alias string: the participating siteaccess whose `PathPrefix` the
    path starts with (longest prefix wins). No database query is made.
- When that is another siteaccess, the link is built from its `SiteURL`
  (or `[HostMap]`) with its `PathPrefix` removed. On the current host the
  link is host-relative (`/bold/services`), so it keeps the scheme and port of
  the page; on another host it is absolute (`https://other.example/services`).
- When several siteaccesses serve the same subtree (a site and its
  translations), the current one wins, then one with the current locale, then
  the first in `SiteAccessList`.
- Other extensions that build content links without `ezurl` can use the same
  resolution:

  ```php
  if ( class_exists( 'eZURLMultisite' ) && eZURLMultisite::isActive() )
  {
      $url = eZURLMultisite::crossSiteURL( $nodeOrPath, 'relative' ); // false: keep the local link
  }
  ```

The per-request state (current node, URL alias lookups) is reset on each
request, so the extension is safe in application servers that keep their PHP
workers between requests (Exponential Velocity).

## Installation

1. Clone or install the extension to `extension/ezurlmultisite`
   (`composer require se7enxweb/ezurlmultisite`).
2. Activate it globally in `settings/override/site.ini.append.php`:

   ```ini
   [ExtensionSettings]
   ActiveExtensions[]=ezurlmultisite
   ```

3. Configure the participating siteaccesses (see below) in
   `settings/override/ezurlmultisite.ini.append.php`.
4. Regenerate the extension autoloads and clear the INI and template caches
   (compiled templates contain the kernel's `ezurl` code until they are
   compiled again):

   ```bash
   php bin/php/ezpgenerateautoloads.php -e
   php bin/php/ezcache.php --allow-root-user --clear-tag=ini
   php bin/php/ezcache.php --allow-root-user --clear-tag=template
   php bin/php/ezcache.php --allow-root-user --clear-id=content
   ```

The Exponential multisite installer activates the extension and writes
`settings/override/ezurlmultisite.ini.append.php` with the root node ids of the
installation it creates.

## Configuration

`settings/ezurlmultisite.ini` holds the defaults; override them in
`settings/override/ezurlmultisite.ini.append.php`.

### `[MultisiteSettings]`

```ini
[MultisiteSettings]
# The siteaccesses that take part, in order of preference.
SiteAccessList[]
SiteAccessList[]=site
SiteAccessList[]=bold
SiteAccessList[]=bold_ger

# The root node of the subtree each siteaccess serves (ids of this installation).
RootNodeList[]
RootNodeList[site]=89
RootNodeList[bold]=73
RootNodeList[bold_ger]=73

# Look up paths that start with no PathPrefix as URL aliases (one query per link).
ResolveUnprefixedPaths=disabled
```

- `SiteAccessList[]` empty: every siteaccess of `AvailableSiteAccessList` with
  `RequireUserLogin=false` and a `PathPrefix` (or a `RootNodeList` entry)
  takes part. Administration siteaccesses are never listed: their links are
  left as the kernel makes them, and content is never sent to them.
- `RootNodeList[]` without an entry for a siteaccess: its `content.ini`
  `[NodeSettings] RootNode` when that is above 2, else the node of its
  `IndexPage` (`/content/view/full/<id>`). Node ids differ between
  installations, so never copy them from another one.

### `[HostMap]`

Override the host used for a siteaccess when `site.ini` `SiteURL` is not enough:

```ini
[HostMap]
SiteAccess[bold]=bold.example.com
```

### `[PathMap]`

Send paths below a given alias to a fixed siteaccess, before prefix detection:

```ini
[PathMap]
Path[campaigns/summer]=bold
```

### `[OperatorSettings]`, `[RoleMap]`, `[ClassSettings]`, `[PathSettings]`, `[DataTypeSettings]`

```ini
[OperatorSettings]
ForceAbsoluteURL=disabled
DefaultLinkType=internal
DefaultQuote=double
DefaultServerURL=relative
RoleOverride=disabled
SiteLinkClassList[]
```

- `ForceAbsoluteURL=enabled` makes every `ezurl` call absolute.
- `RoleOverride=enabled` with `[RoleMap] RoleList[<role id>]=<siteaccess>`
  sends every link of users with that role to one siteaccess.
- `SiteLinkClassList[]` lists class identifiers whose links come from an
  attribute: `[<class identifier>] LinkTypeList[internal|external|download]=<attribute>`,
  with the datatype handlers of `[DataTypeSettings] HandlerList[]`
  (`eZURLMultisiteURLHandler`, `eZURLMultisiteBinaryFileHandler`,
  `eZURLMultisiteObjectRelationHandler`; custom handlers implement
  `eZURLMultisiteDataTypeHandler`).
- `[PathSettings] DisableNodeID=enabled` / `DisableObjectID=enabled` with
  `NodeIDList[]` / `ObjectIDList[]`: `ezurl` returns an empty string for those.

## Template usage

No template changes are needed:

```html
<a href={$node.url_alias|ezurl}>...</a>
```

An optional third parameter, a hash, selects extended options:

```html
{$node.url_alias|ezurl('double','relative',hash('absolute',true(),'protocol','https','hash','section'))}
```

| Key | Type | Description |
|-----|------|-------------|
| `absolute` | boolean | Force a full absolute URL. |
| `siteaccess` | string | Force the target siteaccess. |
| `protocol` | string | `http`, `https`, `//` (protocol-relative) or empty for the current scheme. |
| `hash` | string | Append a URL fragment. |
| `query` | string or hash | Append a query string. |
| `user_parameters` | hash | Append view parameters as `/(key)/value`. |
| `no_self_link` | boolean | Empty string when the link target is the current page. |
| `node_id` | boolean | An integer input is a node id (`true`, default) or an object id (`false`). |
| `link_type` | string | For class-based links: `internal`, `external` or `download`. |

### Additional operators

- `ezurl_path` - the path of the link without the prefix of the siteaccess it
  belongs to: `{$node.url_alias|ezurl_path()}`
- `ezurl_siteaccess` - the siteaccess the link belongs to:
  `{$node.url_alias|ezurl_siteaccess()}`
- `ezurl_roots` - per participating siteaccess: `siteaccess`, `host`,
  `prefix`, `root_node_id`: `{def $roots = ezurl_roots()}`

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
