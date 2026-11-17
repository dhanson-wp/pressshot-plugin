<picture>
  <source media="(prefers-color-scheme: dark)" srcset="docs/brand/lockup-dark.svg">
  <img src="docs/brand/lockup-light.svg" alt="PressShot WordPress plugin" width="480">
</picture>

# PressShot for WordPress

The PressShot Library for WordPress: shots, folders, tags, share links, and Trash, served at `/shots` for people who can upload files on the site. Your WordPress account is your sign-in.

## How it works

- A **shot** is an attachment registered by a capture app. The PressShot Mac app uploads with `POST /wp/v2/media`, then calls `POST /wp-json/pressshot/v1/captures/{id}/register` with `{ "source": "pressshot" }` and gets back `{ id, source, shareUrl }`. Registering sets `_pressshot_capture` and `_pressshot_source`. Allowed sources come from the `pressshot_shot_sources` filter. The full contract is in [docs/shot-marker.md](docs/shot-marker.md).
- **A team site is shared.** Everyone with `upload_files` sees every shot. Folders (nested) and tags are shared too. There's no manager view and no per-shot privacy. WordPress administrators can see every upload in wp-admin anyway.
- **A personal site is private** because nobody else is on it.
- **The files aren't secret.** The plugin hides shots from WordPress's public listings (`/wp/v2/media`, the folder and tag routes, attachment pages, and oEmbed) for anyone without Library access, and its own routes check access on every request. Each file still lives at a normal uploads address that isn't secret: anyone who has the exact address can open it, and revoking a link doesn't change that. Only a private WordPress.com site or server rules protect the files themselves, and a private site also blocks the `/s/` share pages unless you turn on **Settings → PressShot → Let share links open while this site is Private** (the `pressshot_share_on_private_site` option), after which every live link on the site opens for anyone who has it. See [Share links from a private WordPress.com site](docs/guides/private-site-sharing.md).
- **Share links** live at `/s/{token}`, one per shot or folder, with optional expiry and revocation. Shot links need permission to edit that attachment; folder links need Editor or above (`edit_others_posts`). Delete and restore follow `delete_post`.
- **Trash** keeps deleted shots for 30 days (WordPress's `EMPTY_TRASH_DAYS`). Restoring doesn't bring back old links.

## Requirements

- WordPress 7.1 or newer, PHP 7.4 or newer.
- Pretty permalinks. Plain permalinks aren't supported.
- **Never serve `/shots` from a shared page cache.** The page carries a per-person REST nonce. Exclude `/shots` (and `/s/`) from any page cache that doesn't already skip signed-in visitors.
- The `/shots` path can be changed with the `pressshot_shots_path` filter. Save **Settings → Permalinks** after changing it.

## Still being checked

- Whether revoking a link also stops a VideoPress recording from playing for someone who already has its VideoPress address. Until that's verified on a hosted site, revocation is only known to close the share page and the plugin's own token media route, not the VideoPress file.
- Hosted behavior on WordPress.com: VideoPress upload and playback, protected image delivery and caching, and application-password approval.

## Guides

- [Set up a team Library](docs/guides/team-library.md)
- [Set up your own private Library](docs/guides/private-library.md)
- [Share links from a private WordPress.com site](docs/guides/private-site-sharing.md)

## Development

- `bin/sync-studio.sh` copies the plugin into the local Studio site and activates it.
- `bin/test-php.sh [file...]` runs `tests/php/*-test.php` through Studio WP-CLI and exits non-zero on any `FAIL` line.
- `npm run build`, `npm start`, and `npm run test:js` use `@wordpress/scripts`.
- `npm run plugin-zip` builds the app, then writes `pressshot-plugin.zip` with a `pressshot/` folder inside, then runs `bin/check-zip.sh`, which fails if `build/library.js` or `build/library.asset.php` is missing from the ZIP. The `files` field in `package.json` decides what goes in.

Tests share `tests/php/harness.php`, which provides `$check`, `$call`, `$make_user`, and `$make_shot`, and removes only the users and attachments it created.

## License

GPL-2.0-or-later, the same license as WordPress. See [LICENSE](LICENSE).
