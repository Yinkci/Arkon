# MySite theme workspace

Open this folder by itself in VS Code. Claude can create builder-compatible components using the included instructions and Testimonial example.

Run these from this folder:

```powershell
.\arkon.cmd validate
.\arkon.cmd install
```

Then open Appearance → Themes in the dashboard and Activate for editing (or Refresh from files). Reload your Arkon editor, open Layers and choose **Add Testimonial**. Quote, Author, Alignment and Author photo are editable in Properties; Quote and Author also support canvas editing. Design parts distinguish the card, quote, author and photo.

Installing only registers components; it never publishes a page. Restart long-running Arkon MCP/helper processes after installation so their catalogue is current. See ARKON_THEME_GUIDE.md for supported fields, versioning and limitations.

This is the first theme-component milestone. Per-site activation is available in the dashboard. Parent theme inheritance, global skins and whole-page file imports are still planned.
