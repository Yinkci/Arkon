# Theme components: first milestone

Arkon now accepts components authored in a theme folder inside the Arkon project. Open that folder in VS Code and Claude Code can work on it without opening its core folders. Arkon supplies validation, inspector controls, inline editing, undo/redo, managed media, AI discovery and publishing. The component declares fields; it does not define new builder rules.

This milestone provides the extension contract and one complete example. The dashboard now supports per-site activation in Appearance → Themes. Parent/child inheritance, whole-page file imports and replacement of built-in components are not implemented yet. Installed immutable definitions remain available application-wide for historical rendering, while each site selects which custom types its Add palette and AI offer. Existing site's content and live HTML are not changed by installing a package.

## Daily workflow

The theme source lives inside the Arkon repository at `themes/mysite`. Open only that folder in VS Code. On this machine its path is `C:\Herd\ArkonLaravel\themes\mysite`. Its `arkon.cmd` finds the containing Arkon application relative to the theme folder and contains no credentials.

1. Open only the theme folder in VS Code.
2. Let Claude read `CLAUDE.md`, `ARKON_THEME_GUIDE.md`, `style-reference.json` and the testimonial example. The style reference supplies existing design groups/properties without requiring access to core source; the current CMS validator remains authoritative.
3. Create a new component folder and list its name in `theme.json`.
4. Run `.\arkon.cmd validate`, then the same command with `install`.
5. Open Appearance → Themes in the dashboard, preview the components, then Activate for editing (or Refresh from files for the active theme). Activation validates and installs the local files and controls this site’s Add palette and AI catalogue. No frontend rebuild is required for subsequent installs.
6. Restart long-running Arkon MCP/helper processes after installing a package, so their cached catalogue includes the component. Use the existing paired MCP tools to propose page changes, review them in Arkon and save/publish explicitly.

You can also install from the CMS folder:

```powershell
php artisan arkon:theme validate C:\Herd\ArkonLaravel\themes\mysite
php artisan arkon:theme install C:\Herd\ArkonLaravel\themes\mysite
```

Theme source under `themes/` is tracked in the same Git repository as Arkon. Keep the site-specific code here and open only `themes/mysite` in VS Code. This folder arrangement follows the WordPress theme workflow; opening a subfolder is not a security sandbox.

Editing a source file does not update the installed version. After installation, changing any package bytes requires a higher component version. Never delete installed snapshots: old publications need them for reproduction. Include `storage/app/theme-components` in deployments and backups alongside the application/database/media; the snapshots are not tracked by the CMS Git repository.

## Folder contract

```text
ArkonLaravel/themes/mysite/
  theme.json
  CLAUDE.md
  ARKON_THEME_GUIDE.md
  arkon.cmd
  components/
    testimonial/
      component.json
      template.html
      styles.css
```

`theme.json` has `format: 1`, a lowercase `id` (up to 25 characters), and 1–30 component folder names. IDs and names start with a letter and use letters, digits and hyphens. A component's type must be `theme-<id>-<folder>`, so it cannot override a core component. Do not add a `parent` yet; it is rejected rather than silently ignored.

`component.json` declares `type`, `version` (1–1000), `label`, `children: false`, `props` and `defaultProps`. It uses the same field definitions Arkon already validates. This first version supports:

- `string`: a required `maxLength` between 1 and 5000. Optional `editor.fields.<name>.label` and `multiline` configure its inspector control.
- `enum`: 1–20 lowercase identifiers, displayed as a select field. A template's `data-variant` adds a `variant-<value>` class.
- Nullable image object: `assetId` (UUID) and bounded `alt` (string). Declare `<name>.assetId` in `mediaRefs` and a conditional `notBlank` publication check for `<name>.alt`. This preserves Arkon's site isolation, private draft images and published-image rules.
- `style`: declare named `slots` with a human `label` and existing style `groups`. Include a `root` slot. The existing responsive design inspector, validated token references and entrance animations work for declared parts. Use Arkon's styling model for responsive settings, not custom CSS scripts or at-rules.

Each prop must have a valid default in `defaultProps`. An empty style must be `{}`, not `[]`. `inlineFields` maps declared text fields to `{"kind":"line"}` or `{"kind":"multiline"}`. Every inline field must be bound in the template. Every declared design part must identify exactly one element. `publishChecks` supports the existing `present` and `notBlank` rules. See the example for exact syntax.

## Template contract

`template.html` is well-formed XML with one non-image root carrying `data-part="root"`; self-close images with `/>`. Allowed tags are `article`, `blockquote`, `p`, `cite`, `div`, `span`, `figure`, `figcaption`, `img` and `h2`–`h6`.

- `data-field="quote"` renders a declared text field with HTML escaping. If also listed in `inlineFields`, it is editable on the canvas.
- `data-image="photo"` on an `img` renders a declared managed image. No photo renders no image element. Arkon supplies its URL, dimensions, alt text, responsive variants and loading priority.
- `data-part="quote"` binds an element to a declared style slot, giving the builder an explicit design target.
- `data-variant="alignment"` binds an enum and adds its variant class.
- Static `class` and `aria-label` attributes are allowed.

There are no PHP/Blade expressions, JavaScript, raw HTML fields, arbitrary attributes, external image URLs or component children in this milestone. Templates compile to Arkon's existing element renderer. Binding attributes do not appear in published HTML. Limits: 64 KiB per source file, 200 template nodes, 12 levels of nesting, 20 props.

## CSS and performance

Every selector begins with `.component`; optional class qualifiers and descendant classes are accepted. Arkon rewrites it to a type-and-version-specific class inside `:where(...)`, keeping template defaults less specific than builder design choices. CSS may use simple layout, spacing, size, typography, colour, borders and image-fit declarations. Imports, URLs, scripts, at-rules and CSS animations are rejected. Use builder animations so reduced motion and LCP safeguards remain in force.

Only components used by the page contribute CSS. Theme templates add no JavaScript, fonts or external requests. Managed images reuse intrinsic dimensions, responsive variants and the existing priority policy. These constraints support good performance; actual Core Web Vitals still depend on content, image choices, layout and hosting and must be measured on the deployed site.

## Immutable versions and current limitations

Installing the same bytes again is safe. Different bytes at the same version are refused. Install consecutive versions; v2 needs v1. New versions may change presentation/defaults/editor labels while keeping `props`, `inlineFields`, `mediaRefs` and `publishChecks` identical. Their migration preserves existing field values. Field/schema changes require a new component type in this milestone; programmable migrations are not accepted.

Old snapshots stay available, and publications record the type/version they rendered. Opening an editor upgrades a draft in memory; installation itself does not modify database history or publish anything. Saving and publishing continue through the existing permission and stale-version checks.

Opening only a theme folder is a useful work boundary, not an operating-system sandbox. Claude can still access other paths if given permission/tools. Keep the folder's instructions focused on package files, and never put database secrets, session tokens or app environment files in it. Package installation is a trusted local developer operation, not an end-user upload endpoint.

Next milestones: parent inheritance, explicit declarative schema migrations, and validated page blueprints that use the same registered component definitions. They should build on this tested contract.

## Dashboard activation

Themes are currently component bundles, not site-wide skins. Activation never rewrites a page, changes design tokens, publishes a page or changes live HTML. Owners and admins can select a theme for editing, preview default components in a script-free sandbox, and explicitly publish the selection. Editors/viewers can view and preview only. Metadata may include name (80 bytes), description (300 bytes) and a positive integer version; the folder and id must match.

The draft choice has a version checked on every change. Publish records an immutable selection under the per-site epoch; subsequent page publications record its version as audit metadata alongside their existing pinned rendering inputs. Activation and publishing retain request keys for exact retries, including after a later selection change. Unconfirmed browser requests retain their original payload/key in sessionStorage for retries after reload.

Switching back to Arkon core removes custom types from the Add palette on the next editor navigation/reload. Existing custom blocks stay editable and duplicable, restore retains historical blocks, reusable instances keep their published definitions, and earlier publications still reproduce byte for byte. Server saves refuse newly introduced inactive types; creating a new reusable component from an inactive type requires activating that theme. A theme switch in another tab may require reloading the editor before adding new components.

Selection stores allowed types; it does not pin the editor to a particular component version. Installing a consecutive presentation version advances that type’s current editor version globally; old publications retain their own immutable versions. Restart long-running MCP/helper processes after installing components or updating their server code.

Theme sources must be trusted local files under themes/. The dashboard cannot upload packages or accept an arbitrary filesystem path. Installed snapshots remain required in backups and deployments, even after deactivation. There is no new CSS, script or database lookup in the public request path: it continues serving stored HTML.

## New core website blocks

Prefer core Logo, Icon, Slider/Slide, Form and Back-to-top blocks for these functions. Theme components remain declarative leaf components; they cannot install arbitrary JavaScript or relax builder rules. The refreshed style-reference.json includes bounded gradients, sticky positioning, hover/focus settings and local Inter. New capabilities become available through the supplied AI catalogue after restarting the helper/MCP server. See the professional website layouts section in README.md for usage and limits.
