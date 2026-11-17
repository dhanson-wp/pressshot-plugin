# Share links from a private WordPress.com site

I like keeping a Library on a WordPress.com site set to Private. Nobody gets in without being on the site, and that includes the image and video files, which a public site can't lock down. The catch is share links. A Private site turns away everyone who isn't on it, so a link at `/s/…` shows the sign-in screen to exactly the people you sent it to.

PressShot has a setting for that. It's off until you turn it on, and this guide explains why, when it's worth turning on, and how.

## What the setting does

**Let share links open while this site is Private** lets a live share link open for anyone who has it, signed out, while the rest of the site stays private. Only the link itself gets through:

- The `/s/…` share page, drawn on its own without your theme's header, footer, or menu.
- The shot's image or video, through that link.

Revoked, expired, and unknown links still get the site's sign-in screen. So does everything else: posts, pages, the Library at `/shots`, and the REST API. WordPress.com still shows the site's name as "Private Site" to visitors.

The setting only matters on a WordPress.com site set to Private. On a public site, share links already open for anyone who has them, so you won't see it there.

## The part to think about first

Every capture gets a share link when it's uploaded. That's what puts a link on your clipboard the moment you take a shot.

On a Private site, those links have been closed the whole time. Turn this setting on and **every live link on the site opens at once**, to anyone who has its address. That includes links you copied months ago and pasted somewhere you've forgotten about, and links on shots you never meant to share at all.

So before you turn it on, go through the Library and revoke the links you don't want open. A link with an expiry stops working when it expires, so setting one is a good middle ground for anything you're unsure about.

## When to turn it on

Turn it on when you want the privacy of a Private site and still want to send a shot to someone outside it, like a bug report for a vendor or a mockup for a client.

Leave it off when the site is only for you, or only for your team, and every shot stays inside it. Teammates who are on the site can open share links either way.

## Turn it on in wp-admin

You'll need to be an administrator on the site.

1. Revoke the links you don't want open. Open the Library at `/shots`, open a shot or folder, and revoke its link.
2. In wp-admin, go to **Settings → PressShot**. This page only shows up while the site is set to Private.
3. Check **Let share links open while this site is Private** and click **Save Changes**.
4. Open one of your share links in a private browser window, where you're signed out, to make sure it opens.

If the box is grayed out, code on the site sets this with the `pressshot_share_on_private_site` filter. The filter always wins over the setting, so change it there.

## Turn it on with WP-CLI

If you have SSH access to the site, this does the same thing:

```bash
wp option update pressshot_share_on_private_site 1
```

And this turns it back off:

```bash
wp option update pressshot_share_on_private_site 0
```

Turning it off closes every link again right away. Nothing about the links themselves changes, so turning it back on opens the same ones.

## For the Mac app and other tools

The site's REST index (`/wp-json/`) carries a `pressshot` object with two fields, so an app that's signed in can tell where things stand:

```json
"pressshot": {
  "privateSite": true,
  "shareOnPrivateSite": false
}
```

`shareOnPrivateSite` is what the site actually does, filter included. Administrators can also read and change the saved option at `/wp/v2/settings`.

## Have an agent do it

If you work with a coding agent that can run WP-CLI on your site, paste this in. It won't revoke or turn anything on until you say so.

```text
I want share links from my PressShot Library to open while my WordPress.com site stays Private. Use WP-CLI on the site, and walk me through it one step at a time.

1. Check the site is set to Private. Run `wp option get blog_public`. It should print -1. If it doesn't, stop and tell me; on a public site, share links already open and there's nothing to do.

2. List every live share link, so I can decide what to revoke first. Run:

   wp eval 'foreach ( get_posts( array( "post_type" => "pressshot_link", "post_status" => "publish", "posts_per_page" => -1, "fields" => "ids", "suppress_filters" => true ) ) as $id ) { $l = pressshot_link_resolve( get_post_meta( $id, "_pressshot_link_token", true ) ); if ( ! $l ) { continue; } $name = "shot" === $l["kind"] ? get_the_title( $l["target"] ) : get_term( $l["target"], "pressshot_folder" )->name; echo implode( "\t", array( $l["kind"], $l["target"], $name, $l["url"], $l["expiresAt"] ? $l["expiresAt"] : "never expires" ) ), "\n"; }'

   Show me the list as a table: kind, ID, name, link, and expiry. Remind me that turning the setting on opens every one of these to anyone who has the address.

3. Ask me which links to revoke, and wait for my answer. For each one I pick, run `wp eval 'pressshot_link_revoke( "KIND", ID );'` with its kind (shot or folder) and ID. Then run step 2 again and show me what's left.

4. When I say go, turn the setting on: `wp option update pressshot_share_on_private_site 1`. Then run `wp eval 'echo wp_json_encode( pressshot_site_sharing_info() );'`. It should print "privateSite":true and "shareOnPrivateSite":true. If shareOnPrivateSite is still false, a pressshot_share_on_private_site filter on the site is turning it off; tell me where it is.

5. Confirm it works signed out. Pick one link that's still live and run `curl -sS -o /dev/null -w "%{http_code} %{redirect_url}\n" "LINK"`. A 200 means the link opens. A redirect to a WordPress.com sign-in page means it's still blocked, so tell me what you see.

Don't revoke links I didn't pick, and don't change any other setting.
```

What would you share from a private Library? I'd love to hear how you use it.
