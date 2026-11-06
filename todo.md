# phpSimpleImageSharing — Product plan

Goal: a small, reliable private media album for friends that runs on ordinary PHP 8.4 hosting without long-running services or complicated maintenance.

## Principles

- One installation, a small interface, and few settings.
- The gallery and original files stay behind sign-in. Shares are explicit, revocable links with a clear expiration.
- PHP 8.4, PDO SQLite, HTTPS, and a data directory outside the web root; no Node, Docker, queue, or external service required.
- Start with images and a good everyday workflow. Add video and other media through the same flow once storage limits and delivery are understood.
- Keep every feature easy to understand, operate securely, and deploy on shared hosting.
- Keep the interface simple and flat, with restrained colors and no decorative effects.
- All interface text and project documentation use English. Display dates as `YYYY-MM-DD`.

## Design direction

Chosen direction: **C · Album** from `design-directions.html` — warm, restrained colors, serif page headings, and larger image cards.

- [ ] Implement light and dark themes with CSS variables; follow the system preference on first visit.
- [ ] Provide an easy-to-find light/dark/system switch and save the manual choice in the browser.
- [ ] Use neutral surfaces and text, with one subtle accent color for primary actions, warnings, and errors.
- [ ] Use a flat visual style without gradients, strong shadows, or unnecessary borders; keep spacing, typography, and components consistent.
- [ ] Design mobile-first: upload, copy-link, and gallery actions must work on narrow screens without horizontal scrolling.
- [ ] Ensure keyboard access, visible focus, sufficient contrast in both themes, and clear labels.
- [ ] Define consistent loading, empty-gallery, upload-progress, success, error, and expired-share states.

**Done when:** both themes are calm and readable, and the common actions are easy to reach on a phone.

## Agreed storage layout

```text
data_dir/
  phpSimpleImageSharing.sqlite
  users/
    <internal-user-id>/
      <random-media-id>.<extension>
  tmp/
```

- [ ] Store users, media metadata, share-token hashes, and expiration times in SQLite; keep media as regular files.
- [ ] Use a permanent random internal user ID for directory names, never a login or display name.
- [ ] Keep the database and user directories outside the web root. Serve media through PHP only after checking authorization.
- [ ] Check and choose the SQLite journal mode on the Hetzner host; in WAL mode, the database and journal files must be local to the same host and filesystem.
- [ ] Keep write transactions short, set `busy_timeout`, and handle temporary write locks gracefully.
- [ ] Back up the SQLite database and media directories as a consistent set; document restoring them together.

## 0. Solid foundation

- [ ] Simplify first-time setup: clear instructions, configuration checks, and actionable errors for PHP version, extensions, permissions, HTTPS, and upload limits.
- [ ] Protect secrets and private data: keep `config.php`, the data directory, and backups outside the web root; verify Git ignore rules and examples.
- [ ] Harden sign-in: limit login attempts, set session cookies before starting the session, rotate the session ID after sign-in, and consistently enforce logout and CSRF protection.
- [ ] Secure uploads: enforce size limits server-side, inspect actual file content, reject malformed or oversized images, and sanitize original names used as display metadata.
- [ ] Use consistent error handling and logs without recording passwords, session IDs, or private share tokens.
- [ ] Add maintenance information: show configuration and storage usage in the admin area; document backup and restore.

**Done when:** a fresh install can be configured securely and common hosting problems come with a concrete fix.

## 1. Comfortable uploads

- [ ] Support file selection and drag-and-drop.
- [ ] Upload multiple images at once and show progress, failures, and successes per file.
- [ ] Add uploaded images to the gallery immediately and sort by upload time.
- [ ] After upload, offer a clear “Copy link” action, confirm success, and show the link as a fallback.
- [ ] Add an upload preview and clear guidance about supported formats and size limits.
- [ ] Allow retrying after cancellation or connection errors without duplicating successful uploads.

**Done when:** a friend can upload several images from a phone or desktop and copy their links easily.

## 2. Useful, compact gallery

- [ ] Build a responsive thumbnail grid with loading states and a keyboard-accessible detail view.
- [ ] Add next/previous navigation, open/download original, and copy-link actions to the detail view.
- [ ] Sort by newest/oldest upload; optionally add basic filename search.
- [ ] Add multi-select with a few bulk actions: copy links and delete selected images.
- [ ] Confirm deletion with the filename and show a clear success or error message.
- [ ] Add an empty state and pagination or “Load more” for larger collections.

**Done when:** the gallery stays clear with hundreds of files and key actions work on desktop and mobile.

## 3. Clear, secure sharing

- [ ] Create explicit share links for individual media; keep the gallery sign-in-only.
- [ ] Allow generating a new link for a media item. The new link immediately replaces the old one (token rotation).
- [ ] Use short share URLs in the form `/s/<token>`; store token hashes in SQLite and compare them safely on access.
- [ ] Use a small number of explicit Apache `mod_rewrite` rules to route `/s/<token>` and allowed media routes to `index.php`. PHP must still check sign-in/share status, expiration, and media permissions before serving anything.
- [ ] If media routes include extensions, match only explicitly allowed image/video extensions and compare them with the media type in SQLite; never use a URL parameter as a file path.
- [ ] Keep share options minimal: revoke a link and optionally set an expiration date.
- [ ] Generate share tokens cryptographically at random and store them separately from filenames; never log tokens or include them in errors.
- [ ] Allow an unauthenticated share page to access only its specific media item; never reveal the gallery, other files, or unrelated metadata.
- [ ] Redirect unknown, revoked, or expired share links to the operator-selected Rickroll YouTube video. Apply this only to share-link requests, not sign-in, gallery, or ordinary media errors.

**Done when:** a link can be shared, regenerated, optionally expired, and revoked without exposing the private gallery.

## 4. Individual accounts for friends

- [ ] Replace the shared password with individual user accounts.
- [ ] Add simple admin actions: create, disable, and reset accounts; keep public self-registration off by default.
- [ ] Store password hashes with PHP `password_hash`, limit login attempts, and revoke sessions when an account is switched or disabled.
- [ ] Show all private group media in the gallery, with the uploader and upload date identified.
- [ ] Keep permissions to two roles: administrator and member. Admins manage accounts and all media; members upload and manage their own media.
- [ ] Enforce delete and share permissions so members cannot change other people’s media or accounts.

**Done when:** every friend has an individual account, accounts can be disabled independently, and actions can be attributed to a person.

## 5. Automatic deletion

- [ ] Let the uploader choose an optional retention period, for example “never”, 1 day, 7 days, 30 days, or a custom date.
- [ ] Store the expiration time server-side and prevent expired media from appearing or being served.
- [ ] Automatically remove expired files and metadata; on hosting without cron, perform small, bounded cleanup steps on page requests.
- [ ] Optionally document a host cron job that runs the same cleanup task regularly.
- [ ] Show the expiration date in the gallery and detail view and let authorized users change it.
- [ ] Explain that deleting from phpSimpleImageSharing does not remove external copies or backups; backups need a separate retention period.

**Done when:** media becomes inaccessible at its chosen expiration and is removed from active storage soon afterward, with or without cron.

## 6. Simple shared-hosting operations

- [ ] Document installation as a short checklist: upload files, create the data directory, configure settings, enable HTTPS.
- [ ] Support hosts without shell access; required values should be configurable in a file.
- [ ] Explain how `upload_max_filesize`, `post_max_size`, execution time, and available disk space affect uploads.
- [ ] Document backup and restore for media plus metadata; optionally offer a simple admin-only ZIP export.
- [ ] Document upgrades and safe defaults; keep configuration examples in sync when options change.
- [ ] Prepare the GitHub release with a clear README, MIT license, screenshots, release notes, and an issue template.

**Done when:** a technically curious person can install phpSimpleImageSharing on PHP hosting without a separate database server and restore it if needed.

## 7. Add media types after images are stable

- [ ] Structure media metadata and delivery so type, size, duration, and thumbnails can be added without rebuilding the gallery.
- [ ] Set file-size, storage, and supported-container limits before adding video; do not require server-side transcoding.
- [ ] Add video upload and browser playback; check HTTP Range delivery for reliable seeking.
- [ ] Offer unsupported formats as downloads or reject them with a clear explanation.
- [ ] Show storage use per item and in total; provide a simple cleanup workflow.

**Done when:** a video can be uploaded within clear hosting limits and played in the browser or clearly offered as a download.

## First useful release sequence

1. **Foundation:** setup checks, upload security, and individual sign-in.
2. **Accounts:** admins can create, disable, and reset friend accounts.
3. **Comfort:** multi-upload with progress and copy-link.
4. **Gallery:** detail view, sorting, search, and safe bulk actions.
5. **Sharing:** generate new links, invalidate old links, set expiration, and redirect invalid share links.
6. **Retention:** selectable expiration and cleanup without a permanent background service.
7. **Hosting:** backup, restore, and clear upgrade instructions.
8. **Video:** only after testing on the target host.

## Explicitly out of scope for the first version

- Comments, likes, public profiles, or a public discovery page.
- Federation, cloud sync, mobile apps, or external image CDNs.
- Automatic video conversion, AI features, or extensive role management.
- A complex Docker deployment for ordinary shared hosting.
