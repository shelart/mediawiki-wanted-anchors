# Special:WantedAnchors ([MediaWiki](https://www.mediawiki.org/) 1.35+)

## Overview

This extension adds a new special page to a wiki, which purpose is similar
to [Special:WantedPages](https://www.mediawiki.org/wiki/Manual:WantedPages)
but lists only hashlinks pointing to pages with missing sections/anchors.

**Example:** you have a "Foo" page which contains links `[[Bar#Section 3]]` and
`[[Bar#some-anchor]]`. The "Bar" page exists, but it misses a
`== Section 3 ==` or `<a id="some-anchor" />`. Unfortunately, MediaWiki is not
capable to report such issues (it doesn't track anchor links at all).
This extension helps it.

## Requirements

* MediaWiki 1.35+
* PHP `ext-dom` extension (you probably have it, it's enabled by the default
  PHP distribution)

## Installation

1. Place the entire `WantedAnchors/` directory within your wiki `extensions/`
   directory (so, `extensions/WantedAnchors/extension.json` path should be
   valid).
2. Add the following line to your wiki `LocalSettings.php`:
   ```
   wfLoadExtension( 'WantedAnchors' );
   ```
3. Validate the installation by visiting your wiki `Special:Version` page.
   You should see the `WantedAnchors` plugin under "**Special pages**"
   section of "**Installed extension**" chapter.

## Caveats

* This extension relies on `pagelinks` table to collect links (exactly as
  [Special:WantedPages](https://www.mediawiki.org/wiki/Manual:WantedPages)),
  but then it has to retrieve & parse wikitext for every origin page in order
  to extract hashlinks, and then it has to retrieve & parse HTMLs for every
  target page (discovered by hashlinks) in order to find out which anchors
  exist and which are missing. This is a **heavy work**, which might not be
  appropriately running on a cheap shared hosting. More links between pages
  you have, more time is required to render the special page. (Only distinct
  links are counted, though.) **Use with extreme caution on large
  (1000+ pages) wikis!**
* This extension *sometimes* catches broken hashlinks within a page (e.g.
  `[[#Section 3]]` link when the page doesn't have `== Section 3 ==`).
  Indeed, it occurs if such a page has *any* links to other pages within
  your wiki. However, if a page only has hashlinks to itself (regardless of
  interwiki links or pure `<a />` links), then it's not going to be processed
  by this extension.
* This extension does not respect redirect pages. It may report a broken
  hashlink even if the final page has the required anchor.
