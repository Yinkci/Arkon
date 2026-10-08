# Arkon theme workspace

Read ARKON_THEME_GUIDE.md and style-reference.json before editing. This folder is a developer theme workspace inside Arkon, alongside its core folders. Open only this folder in VS Code.

- Edit only files inside this folder. Do not edit files outside this theme folder, built-in components, database credentials or installed snapshots.
- Create components using theme.json plus component.json, template.html and styles.css. Declare editable fields and named design parts. Do not invent new builder rules or execute PHP/JavaScript.
- Treat installed component versions as immutable. Increase the version for presentation changes; create a new component type for field/schema changes. Preserve old source versions in your theme's version control.
- Run .\arkon.cmd validate and .\arkon.cmd install after changes. Those commands invoke the trusted CMS validator and installer; they do not edit core source or publish pages.
- After installation, tell the user to activate or refresh this theme in Appearance → Themes, reload the builder and restart their existing Arkon MCP/helper processes. Use the already paired Arkon MCP tools to propose page edits. Do not write SQL or alter live publications. Review and publish are explicit steps.
- Never copy secrets from the app into this folder. Do not commit credentials or authentication tokens.

Example task: create a team-member card with editable name, biography and managed photo. Match the example's field, media, template and styling contracts, validate it, install it, and make it available in the builder without changing Arkon core.
