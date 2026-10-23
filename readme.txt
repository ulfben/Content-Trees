=== Content Trees ===
Contributors: ulfben
Tags: archives, pages, navigation, widget, block
Requires at least: 6.3
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.9.4
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Collapsible navigation for your archives, pages, categories and tags.

== Description ==

Content Trees makes your site's content easier to explore through compact, collapsible navigation.

Content Trees is the modern successor to WP-dTree, rebuilt using native browser features and WordPress APIs. It replaces the aging dTree library with lightweight JavaScript, loads archive post titles on demand, and offers a live preview in the block editor. Built with accessibility in mind, it supports keyboard navigation, touch-friendly controls, and screen-reader announcements.

Version 0.9 includes all four basic tree types: archives, pages, categories and tags.

= Available now =

* **Archive trees:** Browse posts by year and month, or display a simple list of months.
* **Page trees:** Follow page hierarchies, choose a starting page, exclude branches, and sort by title, menu order, publication date or last update.
* **Category trees:** Follow category hierarchies, choose a starting category, and exclude categories together with their children.
* **Tag trees:** Browse a flat list of tags and the posts assigned to each tag.
* **Taxonomy display:** Show or hide posts, post counts and empty terms. Sort categories and tags by name or post count, and posts by title, publication date or last update.
* **On-demand loading:** Posts are loaded only when a month is expanded, with no pagination or arbitrary limits. Previously loaded titles are cached for faster navigation.
* **Archive display:** Show or hide posts and post counts, choose newest or oldest first, and optionally link years and months to their archives.
* **Current-item navigation:** Highlight the current post, page, category or tag and optionally expand its matching branches automatically.
* **Branch controls:** Optional Open All / Close All, with the ability to automatically close sibling branches.
* **Post exclusions:** Search for and exclude individual posts from both the tree and its post counts.
* **WordPress integration:** Blocks with live previews, classic widgets, and shortcode support.
* **Accessible navigation:** Native disclosure controls, keyboard navigation, touch-friendly controls, and screen-reader announcements.

Future possibilities include support for custom taxonomies, custom post types, and category-filtered archives, depending on user demand.

== Installation ==

1. Upload the supplied plugin folder to `wp-content/plugins/`, or install a supplied ZIP through Plugins > Add New > Upload Plugin.
2. Activate Content Trees.
3. Add a Content Trees block (Archive, Pages, Categories or Tags) in Appearance > Widgets. With a block theme, add it to a template or template part in Appearance > Editor.
4. Choose a title and display options, then save. Classic widget areas can use the matching widget instead.

== Frequently Asked Questions ==

= Can I use this alongside WP-dTree? =

Yes. Content Trees installs separately and leaves WP-dTree's widgets and settings intact. Add a new tree to try it; existing widgets are not converted automatically.

= Can I add a tree with a shortcode? =

Yes. Use `[content_trees_archive]`, `[content_trees_pages]`, `[content_trees_categories]` or `[content_trees_tags]`. The blocks and classic widgets offer the easiest way to configure trees.

= How do category and tag counts work? =

Category counts include unique public posts in that category and its visible child categories. Tag counts include posts assigned directly to that tag. A post can appear under more than one assigned term, but is counted only once in a category total. Exclusions affect this tree and its counts; linked archive pages keep their ordinary contents.

= Which features are not included? =

Page counts, a root that follows the current page, and remembering expanded branches are not available. Legacy icons/connector lines, title truncation, author/custom-field filters and page allowlists are not carried over.

== Screenshots ==

1. Configure the archive tree in the Widgets editor, with a live preview and controls for grouping, ordering, counts and branch behaviour.
2. An archive tree on a sample blog, expanded to the current post with its title highlighted.

== Changelog ==

= 0.9.4 =

* Add Category and Tag trees with blocks, classic widgets and shortcodes.
* Choose a category root, exclude terms or posts, and configure ordering, counts, empty terms and branch controls.
* Skip post-title and permalink preparation when individual posts are hidden.

= 0.8.1 =

* Sort page trees by Published date or Last updated, with Oldest first / Newest first ordering.

= 0.8.0 =

* Add page trees as a block with live preview, a classic widget and shortcode.
* Choose a starting page, sort by title or menu order, and exclude pages together with their children.
* Highlight the current page and open its branch automatically, with optional Open All / Close All and sibling closing.

= 0.7 =

* Introduce the archive tree with complete-month loading, current-post highlighting and configurable year/month navigation.
* Add a block with live preview, a classic widget and shortcode support.
* Include post counts, ordering, searchable exclusions, Open All / Close All and optional closing of branches at the same level.
* Rebuild navigation with native browser controls, on-demand requests, cached archive counts and keyboard, touch and screen-reader support.
