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
