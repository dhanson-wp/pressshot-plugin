# Set up your own private Library

Some shots aren't for the team. A half-finished idea, a bug in someone else's product, a recording you'll redo three times before anyone sees it. For those, I keep a Library on a WordPress site that only I use.

A private Library is the same PressShot plugin on a site where you're the only person who can upload. Nothing about the plugin changes. It's private because nobody else is on the site.

## What you need

- A WordPress site you control, on WordPress 7.1 or newer. That can be:
  - **Self-hosted**, on any host you like.
  - **WordPress.com**, on a plan that allows plugins. Plans without plugin uploads can't run the Library.
- Pretty permalinks (any setting in **Settings → Permalinks** except Plain).
- HTTPS, so you can approve the Mac app with an application password.

If your host runs a page cache, make sure it skips signed-in visitors or excludes `/shots`. The Library page carries a sign-in token that's yours alone, and it can't be served to anyone else from a cache.

## Set it up

1. **Install the plugin.** In wp-admin, go to **Plugins → Add New → Upload Plugin**, choose `pressshot.zip`, and activate it.
2. **Check your permalinks.** Open **Settings → Permalinks**, pick anything but Plain, and click **Save Changes**.
3. **Visit `/shots`** on your site. You'll see an empty Library.
4. **Optional: the PressShot theme.** A matching block theme is in the works. The Library already uses your current theme's header, footer, colors, and fonts, so you don't need it.

## Connect your Mac

1. In PressShot, open **Settings → Upload destinations → Add destination** and choose **Connect a site directly**.
2. Enter your site's address and click **Connect**.
3. Approve PressShot on your site's **Approve** page in the browser.
4. Save the connection.

If you also belong to a team Library, keep both connections and pick which one is primary. Your primary destination is where new shots go by default and whose link lands on your clipboard.

The PressShot Mac app update that registers uploads as shots is still on its way. Until it's out, uploads from PressShot 1.0.x land in the Media Library as plain media and don't appear at `/shots`.

## Keeping it private

"Private" here means "only people on this site," so keep it that way:

- **Don't add other users who can upload files.** Anyone with the Author role or above sees every shot at `/shots`.
- **Anyone who administers the site can see your uploads** in wp-admin's Media Library. On a self-hosted site that's you. On a managed host, it includes whoever has administrator access to your WordPress install.
- **Share links are public by design.** A link at `/s/…` opens for anyone who has it, without signing in. Set an expiry or revoke it from the Library when you're done, and the share page closes.
- **The files themselves aren't locked.** The plugin hides your shots from WordPress's public listings, and its own routes check who's asking. But each image or video still lives at a normal uploads address, and that address isn't secret. Anyone who has the exact address can open the file, and revoking a link doesn't change that. If the files themselves need protecting, use a private WordPress.com site or server rules that block the uploads folder. (A private site also blocks the `/s/` share pages, so you can't share outside the site there until you turn that on in **Settings → PressShot**. Every capture gets a link when it's uploaded, so turning it on opens all of them at once to anyone who has their address. [Share links from a private WordPress.com site](private-site-sharing.md) walks through it, including which links to revoke first.)
- **VideoPress is still being checked.** For recordings stored in VideoPress, we're still checking whether revoking a link also stops the video itself from playing for someone who already has its address, so don't count on revocation to lock a VideoPress file yet.

What would you keep in a Library just for you? I'm curious what ends up there.
