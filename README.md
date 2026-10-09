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

Both plugin dependencies are now guarded — a missing WP-GeoMeta or `voyage`
makes the geo features go quiet rather than fatal — but the plugin cannot do
its job without them.

## The `$special_categories` contract

`index.php:14-15` declares, at **file scope**:

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
```

## Fixed in this pass

The initial commit was a faithful import of what is deployed, with the defects
recorded rather than fixed. They are fixed now.

- **Special categories were never actually excluded.** `bv_update_catline()`
  did `in_array( $cat, $special_categories_local )` where `$cat` is a `WP_Term`
  object and the array holds slug *strings*, so the comparison never matched
  and journey lines were generated for `private`, `standalone` and
  `uncategorized` too. Now compares `$cat->slug` with strict `in_array()`.
  **This changes behaviour on the front end** — posts in those three categories
  will stop contributing to journey lines, which is what the code always meant
  to do.
- **EXIF parsing could fatal on upload.** `read_gps_location()` divided the two
  halves of each GPS rational without checking the denominator; on PHP 8 that
  throws `DivisionByZeroError` and takes down the upload request. All six
  conversions now go through `bv_gtm_exif_rational()`, which returns `0.0`
  for a zero or missing denominator. The function also bails before calling
  `exif_read_data()` unless the file is a string, the `exif` extension is
  loaded, and `isImage()` says it is an image — previously it ran on every
  attachment, returned `false` for non-images, and then warned on
  `$info['DateTimeOriginal']`. `datetime` is only returned when EXIF has it.
- **`gtm_plugin_main.php_gentrit` deleted** (696 lines). It was an abandoned
  predecessor, never loaded because WordPress only executes `.php` files. It
  held superseded copies of `read_gps_location`, `bv_attachment`,
  `bv_thumbnailmeta_postmeta`, `bv_update_gline2`, `bv_update_catline` and
  `bv_get_nghbpost_geom` — renaming it to `.php` would have fatally redeclared
  about eight functions.
- **`isImage()` / `isVideo()` restored at source.** They are the only two
  things from that file anything still wanted: `voyage`'s story-list template
  calls them, and `bv_map/includes/compat.php` had to reimplement them. Both
  definitions are now behind `function_exists()` on *both* sides, so whichever
  plugin loads first wins and no coordinated deploy is needed. The new
  implementation parses the path with `wp_parse_url()` before taking the
  extension, so a URL with a query string (`photo.jpg?w=300`) is classified
  correctly where the old substring check failed; `WEBP` was added.
- **Removed the no-op `transition_post_status` hook.**
  `bv_thumbnailmeta_postmeta()` was attached to both `save_post`
  (`$post_id, $post, $update`) and `transition_post_status`
  (`$new_status, $old_status, $post`). On the latter the first parameter is a
  status string, so the callback returned immediately — it never did anything.
  Its re-entrancy `remove_action()` also omitted the priority 30 it was
  registered at, so it would not have matched anyway. `save_post` is unchanged
  and is the hook that was actually working.
- **`$special_cat_ids` removed.** It accumulated with `+=` — union *by key* —
  so every element after the first was discarded and it could only ever hold
  one id. Nothing read it, and it issued three `get_category_by_slug()`
  queries at file scope on **every** request, front-end and admin.
- **`WP_GeoUtil` guarded.** `bv_update_gline2()` now returns early unless the
  class exists, instead of fatalling on every post save when WP-GeoMeta is
  deactivated. It also keeps the existing line when `LineString()` returns
  empty rather than overwriting good data with nothing — see below.
- **`bv_get_adjacent_post()` guarded**, and `bv_get_nghbpost_geom()`'s
  signature reordered so the PHP 8.0 deprecation (`optional parameter
  $get_value declared before required parameter $current_post`) no longer
  fires. `WP_DEBUG_LOG` is on in production, so that was being written to
  `debug.log` on every request.
- **`$special_categories` read defensively** in this plugin too, via `isset()`
  + `is_array()`, matching the guards `bv_map` and `voyage` already had.

## Known issues

- **WP-GeoMeta is hand-patched.** `wp-geoutil.php:714` adds `linestring` and
  `point` to `get_capabilities()`. A plugin update reverts it, after which
  `WP_GeoUtil::__callStatic()` returns null for `LineString()` and journey
  lines quietly stop being written. `bv_update_gline2()` now preserves the
  existing line in that case rather than erasing it, but the patch still needs
  reapplying after any WP-GeoMeta update.
- **`$special_categories` is still an unguarded file-scope global** owned by
  this plugin — see the contract section above. The consumers all guard their
  reads, so the failure mode is silent rather than fatal.
- Several functions read post meta via magic properties
  (`$current_post->geom_point`). That works — `WP_Post::__get()` falls back to
  `get_post_meta()` — but it is undocumented behaviour and reads like an error.
- `bv_update_gline2()` still carries a large block of commented-out
  `error_log()` debugging. Left in place; it documents what was tried against
  the patched WP-GeoMeta.

## License

GPL-2.0-or-later. See [LICENSE](LICENSE).

## Credits

Lai Consulting Team and Gentrit Biba. Derived from
[digfish/geotagged-media](https://github.com/digfish/geotagged-media).
Previously hosted at `gitlab.com/beauvoyage/bv_geotagged_media`.
