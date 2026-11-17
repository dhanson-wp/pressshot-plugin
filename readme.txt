=== PressShot ===
Tags: screenshots, screen recording, media library, sharing, team
Requires at least: 7.1
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.3
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A Library for PressShot screenshots and recordings at /shots, with folders, tags, share links, and Trash. Your WordPress account is your sign-in.

== Description ==

PressShot is a Mac app for screenshots and screen recordings. This plugin gives the shots it uploads a home on your WordPress site: a Library at `/shots` where you can browse, search, file shots into folders, tag them, share them, and recover them from Trash.

Everyone on the site who can upload files gets the Library. There's no separate account and nothing to configure.

**A team site is shared.** Everyone with upload access sees every shot, and folders and tags belong to the whole team. There's no manager view and no per-shot privacy inside a team site. WordPress administrators can see every upload in wp-admin, so the plugin doesn't pretend otherwise.

**A personal site is private.** Install the same plugin on a site only you use.

Features:

* All shots and My shots, with filters by person and type, search, and grid or list views.
* Nested folders and tags, shared across the site.
* Anyone-with-link share pages at `/s/…` for a shot or a whole folder, with optional expiry and revocation. Share pages don't need sign-in and are marked `noindex`.
* Trash that keeps shots for 30 days by default.
* VideoPress recordings play through the VideoPress player.

Who can do what follows WordPress's own roles. Authors can share and delete their own shots. Editors and Administrators can share and delete anyone's shots, and share whole folders.

Only attachments registered as shots appear in the Library. Capture apps register a shot with one REST request after uploading; see `docs/shot-marker.md` in the plugin for the contract. By default only the PressShot app is accepted. Sites can allow more with the `pressshot_shot_sources` filter.

== Installation ==

1. Upload the plugin ZIP in **Plugins → Add New → Upload Plugin** and activate it.
2. Make sure **Settings → Permalinks** is set to anything except Plain, and click **Save Changes**.
3. Add teammates as users who can upload files (Author or above).
4. Each person connects the PressShot Mac app with **Connect a site directly** and approves it on the site's Approve page.
5. Visit `/shots`.

== Frequently Asked Questions ==

= Does it work with plain permalinks? =

No. `/shots` and the share pages at `/s/…` need pretty permalinks.

= Can I use a page cache? =

Yes, but never serve `/shots` from a shared page cache. The Library page carries a REST nonce that belongs to the person viewing it. Most caches skip signed-in visitors already; if yours doesn't, exclude `/shots` and `/s/`.

= Can I change the /shots address? =

Yes, with the `pressshot_shots_path` filter. Save **Settings → Permalinks** afterward.

= Are shots private? =

On a team site, every shot is visible to everyone who can upload, and to site administrators in wp-admin. For private shots, use a site only you're on.

= Are the files themselves private? =

Not on their own. The plugin hides shots from WordPress's public listings, and its own routes check who's asking. But each image or video still lives at a normal uploads address, and that address isn't secret. Anyone who has the exact address can open the file, and revoking a link doesn't change that. Only a private WordPress.com site or server rules that block the uploads folder protect the files themselves. A private site also blocks the `/s/` share pages unless an administrator turns on **Settings → PressShot → Let share links open while this site is Private**; once it's on, every live link on the site opens for anyone who has it. The guide [Share links from a private WordPress.com site](https://github.com/dhanson-wp/pressshot-plugin/blob/main/docs/guides/private-site-sharing.md) explains when to turn it on and which links to revoke first.

= Does revoking a link stop a VideoPress video? =

We're still checking. Revoking a link closes its share page. Whether it also stops the VideoPress video from playing for someone who already has its VideoPress address hasn't been verified yet, so don't count on it.

= Does it need WordPress.com? =

No. It works on self-hosted WordPress and on WordPress.com plans that allow plugins.

== Changelog ==

= 1.0.3 =
* A WordPress.com site set to Private can let share links open for signed-out visitors. It's off until an administrator turns it on in **Settings → PressShot**, which shows up only while the site is Private (or with `wp option update pressshot_share_on_private_site 1`; the `pressshot_share_on_private_site` filter wins over both). Only a live link's page and media get through, the page is shown without the theme's header and footer, and revoked, expired, and unknown links still see the site's private screen.
* The REST index (`/wp-json/`) has a `pressshot` object saying whether the site is Private and whether share links open on it, for the Mac app to read.

= 1.0.2 =
* Recordings on shared folder pages show their VideoPress poster instead of a plain tile.

= 1.0.1 =
* Recordings in the Library show their VideoPress poster instead of an empty tile.

= 1.0.0 =
* First public release.
