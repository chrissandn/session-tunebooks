# Session Tunebooks — WordPress theme

A tune collection for folk sessions, as running on
[tunes2.chrissandner.at](https://tunes2.chrissandner.at/):

- take over tunes from thesession.org, ABC notation or scanned sheet music
- build sets, tunebooks for sessions, personal books per user
- gig mode for phones

## What you need

- Web hosting with **WordPress 6.5 or later** and **PHP 8.0 or later**.
  Almost any host with "one-click WordPress" will do.
- The plugin **Advanced Custom Fields** (ACF), the free version 6.1 or later.

## Installation

1. **Install WordPress** and log in to the admin area.
2. **Install ACF:** *Plugins → Add New*, search for "Advanced Custom Fields",
   install and activate it.
3. **Install the theme**, either way:
   - **ZIP:** download `irishsession.zip` from the
     [latest release](https://github.com/chrissandn/session-tunebooks/releases/latest), then
     *Appearance → Themes → Add New Theme → Upload Theme*. (Use this file, not GitHub's
     "Source code" ZIP: its folder name changes with every version, so a later upload
     would install a second copy instead of replacing the theme.)
   - **git:** in the installation's `wp-content/themes/` folder run
     `git clone https://github.com/chrissandn/session-tunebooks.git irishsession`.
4. **Activate the theme** (*Appearance → Themes*). It then sets itself up:
   - creates the pages Home, Tunes, Sets, Books, My Books, Add Tune, Scan Review,
     Random Set, Settings, Help
   - makes "Home" the front page
   - sets permalinks to `/%postname%/`

   Pages that already exist and settings you have already changed are left
   alone. A notice at the top of the admin lists what was created.

## Importing tunes

Under *Tools → Tune Sync* you import a `tunes-export` ZIP, either as a file or
**from a URL**. A full collection with sheet-music scans is about 50 MB, too large
for the file upload on most hosts. Use the URL then: the server downloads the
file itself.

**Starter collection:** on a site without tunes, a notice at the top of the admin
links to Tune Sync with the URL of the session books *KTN-Session* and *Graz-Session*
from tunes.chrissandner.at already filled in (`tunes-starter.zip` in the
[latest release](https://github.com/chrissandn/session-tunebooks/releases/latest)).
Click "Upload & prepare import", then start the import.

The import is **additive**: it creates new tunes and updates existing ones (mode
*sync*: the newer version wins), but never deletes anything. You can repeat it
later with a newer file without losing your own tunes. The same page exports
your collection, in full or a single tunebook.

## After installation

- **Impressum:** the page "Impressum" is waiting as a draft. Fill in your details
  and publish it; the footer then links to it under its title. It stays in
  German on purpose: in Austria and Germany a public site needs one.
- **Footer:** *Appearance → Customize → Footer*, e.g. links to your sessions.
- **Name and icon:** *Settings → General* for the site title,
  *Customize → Site Identity* for the icon. It also shows when someone adds the
  site to their phone's home screen.
- **Users:** *Users → Add New*. Reading works without an account. The roles:
  - **Member** (added by the theme): create personal books
  - **Editor:** add tunes and sets, edit all of them, import, plus personal books
  - **Author:** add tunes, but no personal books

  To give new accounts personal books right away, set the default role to
  *Member* under *Settings → General*.

## Updates

- **git:** run `git pull` in the theme folder.
- **ZIP:** upload the new `irishsession.zip` the same way as during installation. WordPress
  asks whether to replace the installed theme; that is what you want.

Your tunes, sets and settings live in the database, not in the theme, so an
update does not touch them.

## Technical notes

- The ACF field groups (Tune, Set) and the `set` content type ship in
  `acf-json/`. Nothing needs to be set up in ACF itself.
- Pages are bound to their templates by slug (`page-tunes.php` → page `tunes`).
  Don't rename the slugs.
- Without ACF the site shows "This site is being set up" instead of an error,
  and the admin shows a notice with a link to install it.
