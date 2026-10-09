# BV Geotagged Media

Reads EXIF GPS data from uploaded images and writes it onto posts as GeoJSON,
then maintains the per-category "journey" lines that connect them.

This is the **producer** side of the beauVoyage geo pipeline. Everything the
`bv_map` plugin renders — marker positions and journey routes — originates
here:

| Meta key | Written by | Read by |
| --- | --- | --- |
| `geom_point` | `bv_attachment()` on upload, copied to the post by `bv_thumbnailmeta_postmeta()` | `bv_map`, `digital-nomad-child`, `voyage` |
| `geom_line2__<term_id>` | `bv_update_gline2()` | `bv_map` (journey lines) |
| `datetime` | `bv_attachment()`, from EXIF `DateTimeOriginal` | `bv_map` (orders journey legs) |

It also owns `$special_categories`, a global read by three other codebases.

## Requirements

- WordPress multisite
- PHP 7.4+ with the **`exif`** extension
- **WP-GeoMeta** — `WP_GeoUtil::LineString()`, `st_startpoint()`, `st_endpoint()`
- **`voyage`** plugin — `bv_get_adjacent_post()`

Neither plugin dependency is guarded; see Known issues.

## The `$special_categories` contract

`index.php:12-13` declares, at **file scope**:

```php
global $special_categories;
$special_categories = array('private','standalone','uncategorized');
```

so it exists only once this plugin has loaded. It is read by:

- `voyage/voyage.php` and `voyage/includes/tec_hooks.php`
- `bv_map`, via `bv_map_special_categories()`
- this plugin, in `bv_update_catline()`

There is no load-order guarantee between plugins. It works today because
`bv_geotagged_media` sorts alphabetically before `bv_map` and `voyage`. Both of
those now wrap the read in an `isset()` guard — `bv_map/includes/compat.php`
and `voyage`'s `bv_special_categories()` — precisely because this owner is
unguarded. **If this plugin is renamed or deactivated, the global silently
disappears.**

## Layout

```
index.php                      The whole plugin
gtm_plugin_main.php_gentrit    Not loaded — see below
```

## Known issues

Recorded, not fixed — the initial commit is a faithful import of what is
deployed.

- **`gtm_plugin_main.php_gentrit` is never loaded.** The file parses cleanly —
  it is purely the extension that is wrong. WordPress only executes `.php`
  files, so the `isImage()` and `isVideo()` helpers it defines do not exist at
  runtime. `bv_map/includes/compat.php` had to reimplement both,
  because `voyage`'s story-list template calls them. Either restore the file
  with a `.php` extension behind `function_exists()` guards, or delete it and
  treat the `bv_map` copies as canonical.
- **Special categories are never actually excluded.** `index.php:233` does
  `in_array( $cat, $special_categories_local )` where `$cat` is a `WP_Term`
  object and the array holds slug *strings*, so the comparison never matches
  and journey lines are generated for `private`, `standalone` and
  `uncategorized` as well. Should compare `$cat->slug`.
- **`$special_cat_ids` (lines 15-19) is both broken and unused.** It accumulates
  with `+=`, which is union *by key*, so after the first element every
  subsequent `+= array( $term_id )` is discarded — it can only ever hold one
  id. Nothing reads it. It also issues three `get_category_by_slug()` queries at
  file scope on **every request**, front-end and admin.
- **EXIF parsing can fatal on upload.** `read_gps_location()` divides the two
  halves of each GPS rational (`index.php:44-49`) without checking that the
  denominator exists or is non-zero. On PHP 8 division by zero throws
  `DivisionByZeroError`, so an image with malformed GPS EXIF takes down the
  upload request. `exif_read_data()` is also called on every attachment
  regardless of type and returns `false` for non-images, after which
  `$info['DateTimeOriginal']` warns.
- **`bv_thumbnailmeta_postmeta()` is hooked to two actions with incompatible
  signatures.** `save_post` passes `( $post_id, $post, $update )`;
  `transition_post_status` passes `( $new_status, $old_status, $post )`. On the
  latter the first parameter is a status string, so `get_post_type( 'publish' )`
  returns false and the callback returns immediately — that hook does nothing.
- **The re-entrancy guard is half-broken.** `remove_action( 'transition_post_status',
  'bv_thumbnailmeta_postmeta' )` omits the priority, but the hook was registered
  at 30, so the removal does not match and the action stays attached. The
  `save_post` removal is correct (both default to 10).
- **`WP_GeoUtil` is called unguarded** (`index.php:182-183`, `:200`), so
  deactivating WP-GeoMeta makes every post save fatal. Note also that the
  installed WP-GeoMeta has been **hand-patched** — `wp-geoutil.php:714` adds
  `linestring` and `point` to `get_capabilities()` — and a plugin update
  reverts that, after which `WP_GeoUtil::LineString()` returns null and journey
  lines quietly stop being written.
- **`bv_get_adjacent_post()` (`index.php:154`) lives in the `voyage` plugin**
  and is called with no `function_exists()` guard.
- **A PHP 8.0 deprecation fires on every load.** `php -l index.php` reports:
  `bv_get_nghbpost_geom(): Optional parameter $get_value declared before
  required parameter $current_post is implicitly treated as a required
  parameter`. Harmless, but `WP_DEBUG_LOG` is enabled in production, so it is
  being written to `debug.log` on every request.

Not a bug, but worth knowing: several functions read post meta via magic
properties (`$current_post->geom_point`). That works — `WP_Post::__get()` falls
back to `get_post_meta()` — but it is undocumented behaviour and reads like an
error.

## License

GPL-2.0-or-later. See [LICENSE](LICENSE).

## Credits

Lai Consulting Team and Gentrit Biba. Derived from
[digfish/geotagged-media](https://github.com/digfish/geotagged-media).
Previously hosted at `gitlab.com/beauvoyage/bv_geotagged_media`.
