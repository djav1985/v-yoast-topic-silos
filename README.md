# Vontainment Yoast Topic Silos

A fully standards-compliant WordPress plugin that extends Yoast SEO with topic-silo optimisations, schema enhancements, and Dublin Core metadata.

## Requirements

- WordPress 5.0 or higher
- PHP 7.4 or higher
- [Yoast SEO](https://wordpress.org/plugins/wordpress-seo/) (free or premium) – **required**

## Installation

1. Upload the `v-yoast-topic-silos` folder to your `wp-content/plugins/` directory.
2. Activate **Yoast SEO** first (if not already active).
3. Activate **Vontainment Yoast Topic Silos** from the *Plugins* screen.

If Yoast SEO is not active the plugin will deactivate itself and display an admin notice.

## Features

### Backend (`includes/backend.php`)

- **Topic Silo metabox** – Adds a *"Topic Silo – Related Links"* sidebar metabox to every post and page edit screen. It lists published posts and pages that share a category or tag with the current post. Clicking any link **copies its permalink to the clipboard** (instead of navigating away), making it easy to grab internal-link URLs without leaving the editor. A brief "Copied!" confirmation is shown after each click. Falls back gracefully to `execCommand('copy')` in older browsers.
- **Auto-set social images** – On every `save_post`, automatically populates the Yoast OpenGraph and Twitter image meta fields with the post's featured image.
- **Disable transition-words check** – Removes the transition-words readability assessment from the Yoast analysis panel.

### Frontend (`includes/frontend.php`)

- **Aggregate rating in schema** – Injects an `AggregateRating` block into the `Organization` schema on the front page, the `/services/web-design/` path, and the `web-design-portfolio` page.
- **Replace Place with LocalBusiness** – Strips `Place` from any `@type` array in the schema graph so the organisation is correctly typed as `LocalBusiness`.
- **Dublin Core metadata** – Outputs `DC.*` `<meta>` tags in `wp_head` using Yoast's stored focus-keyword and meta-description.
- **Force Organisation as author** – Replaces any `Person` author node in `Article`/`BlogPosting` schema with the Vontainment `Organization` entity and removes standalone `Person` nodes.
- **Name BreadcrumbList** – Adds `"name": "Site Breadcrumbs"` to `BreadcrumbList` nodes that lack a name.
- **Replace author meta tag** – Uses output buffering to rewrite the Yoast `<meta name="author">` tag so it always shows the site name instead of a user display name.

## File Structure

```
v-yoast-topic-silos/
├── v-yoast-topic-silos.php   ← main plugin file (activation, dependency check)
├── uninstall.php              ← removes all plugin options on deletion
├── includes/
│   ├── backend.php            ← backend hooks (save_post, readability filter)
│   └── frontend.php           ← frontend hooks (schema, Dublin Core, output buffer)
└── languages/                 ← (reserved for translation files)
```

## License

MIT – see [LICENSE](LICENSE) for details.
