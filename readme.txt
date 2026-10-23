=== Content Trees ===
Contributors: ulfben
Tags: archives, navigation, widget, block
Requires at least: 6.3
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.8.0-alpha.1
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Collapsible archive trees with on-demand post loading, live editor previews and keyboard-friendly navigation.

== Description ==

Content Trees makes your site's content easier to explore through compact, collapsible navigation.

Content Trees is the modern successor to WP-dTree, rebuilt using native browser features and WordPress APIs. It replaces the aging dTree library with lightweight JavaScript, loads post titles on demand, and offers a live preview in the block editor. Built with accessibility in mind, it supports keyboard navigation, touch-friendly controls, and screen-reader announcements.

Version 0.7 introduces the archive tree. Page, category and tag trees are planned for 1.0.

= Available now =

* **Archive trees:** Browse posts by year and month, or display a simple list of months.
* **On-demand loading:** Posts are loaded only when a month is expanded, with no pagination or arbitrary limits. Previously loaded titles are cached for faster navigation.
* **Flexible display:** Show or hide posts and post counts, choose newest or oldest first, and optionally link years and months to their archives.
* **Current-post navigation:** Highlight the current post and optionally expand its branch automatically.
* **Post exclusions:** Search for and exclude individual posts from both the tree and its post counts.
* **WordPress integration:** A block with live preview, a classic widget, and shortcode support.
* **Accessible navigation:** Native disclosure controls, keyboard navigation, touch-friendly controls, and screen-reader loading announcements.

= Coming next =

* **Page trees:** Navigate hierarchical pages and their parent-child relationships.
* **Category trees:** Browse categories and the posts within them.
* **Tag trees:** Browse posts organized by tag.

These three tree types are planned for version 1.0, which is intended to become the recommended upgrade path for most WP-dTree users.

Future possibilities include support for custom taxonomies, custom post types, and category-filtered archives, depending on user demand.

== Installation ==

1. Upload the supplied plugin folder to `wp-content/plugins/`, or install a supplied ZIP through Plugins > Add New > Upload Plugin.
2. Activate Content Trees.
3. Add the Content Trees: Archive block in Appearance > Widgets. With a block theme, add it to a template or template part in Appearance > Editor.
4. Choose a title and display options, then save. Classic widget areas can use the archive widget instead.

== Frequently Asked Questions ==

= Can I use this alongside WP-dTree? =

Yes. Content Trees installs separately and leaves WP-dTree's widgets and settings intact. Add a new archive tree to try it; existing widgets are not converted automatically.

= Can I add a tree with a shortcode? =

Yes. Use `[content_trees_archive title="Archives"]`. The block and classic widget offer the easiest way to configure a tree.

== Screenshots ==

1. Configure the archive tree in the Widgets editor, with a live preview and controls for grouping, ordering, counts and branch behaviour.
2. An archive tree on a sample blog, expanded to the current post with its title highlighted.

== Changelog ==

= 0.7 =

* Introduce the archive tree with complete-month loading, current-post highlighting and configurable year/month navigation.
* Add a block with live preview, a classic widget and shortcode support.
* Include post counts, ordering, searchable exclusions, Open All / Close All and optional closing of branches at the same level.
* Rebuild navigation with native browser controls, on-demand requests, cached archive counts and keyboard, touch and screen-reader support.
