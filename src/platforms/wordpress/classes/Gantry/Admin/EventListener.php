<?php

/**
 * @package   Gantry5
 * @author    RocketTheme http://www.rockettheme.com
 * @copyright Copyright (C) 2007 - 2020 RocketTheme, LLC
 * @license   GNU/GPLv2 and later
 *
 * http://www.gnu.org/licenses/gpl-2.0.html
 */

namespace Gantry\Admin;

use Gantry\Admin\Events\MenuEvent;
use Gantry\Component\Config\Config;
use Gantry\Component\Menu\Item;
use Gantry\Framework\Menu;
use RocketTheme\Toolbox\Event\Event;
use RocketTheme\Toolbox\Event\EventSubscriberInterface;

/**
 * Class EventListener
 * @package Gantry\Admin
 */
class EventListener implements EventSubscriberInterface
{
    /**
     * @return array
     */
    public static function getSubscribedEvents()
    {
        return [
            'admin.global.save' => ['onGlobalSave', 0],
            'admin.styles.save' => ['onStylesSave', 0],
            'admin.settings.save' => ['onSettingsSave', 0],
            'admin.layout.save' => ['onLayoutSave', 0],
            'admin.assignments.save' => ['onAssignmentsSave', 0],
            'admin.menus.save' => ['onMenusSave', 0]
        ];
    }

    /**
     * @param Event $event
     */
    public function onGlobalSave(Event $event)
    {
        $option = (array) \get_option('gantry5_plugin');
        $option['production'] = (int)(bool) $event->data['production'];
        \update_option('gantry5_plugin', $option);
    }

    /**
     * @param Event $event
     */
    public function onStylesSave(Event $event)
    {
        $event->theme->preset_styles_update_css();
    }

    /**
     * @param Event $event
     */
    public function onSettingsSave(Event $event)
    {
    }

    /**
     * @param Event $event
     */
    public function onLayoutSave(Event $event)
    {
    }

    /**
     * @param Event $event
     */
    public function onAssignmentsSave(Event $event)
    {
    }

    /**
     * @param MenuEvent|Event $event
     */
    public function onMenusSave(Event $event)
    {
        /*
         * Automatically create navigation menu items:
         * https://clicknathan.com/web-design/automatically-create-wordpress-navigation-menu-items/
         *
         * Add widgets and particles to any menu:
         * http://www.wpbeginner.com/wp-themes/how-to-add-custom-items-to-specific-wordpress-menus/
         *
         * Skip menu items dynamically:
         * http://wordpress.stackexchange.com/questions/31748/dynamically-exclude-menu-items-from-wp-nav-menu
         *
         * Updating menu item (extra data goes to wp_postmeta table):
         *   get_post()
         *   wp_insert_post()
         *   wp_update_post($menu_item_data)
         *   register_post_type()
         *   update_post_meta()
         *
         * https://github.com/WordPress/WordPress/blob/master/wp-admin/nav-menus.php#L65
         *
         * Example to handle custom menu items:
         * https://github.com/wearerequired/custom-menu-item-types/blob/master/inc/Custom_Menu_Items.php
         */

        $debug = [];
        $menu = $event->menu;

        $menus = array_flip($event->gantry['menu']->getMenus());
        $menuId = isset($menus[$event->resource]) ? $menus[$event->resource] : 0;

        // Save global menu settings into Wordpress.
        $menuObject = \wp_get_nav_menu_object($menuId);
        if (\is_wp_error($menuObject)) {
            throw new \RuntimeException("Saving menu failed: Menu {$event->resource} ({$menuId}) not found", 400);
        }

        $options = [
            'menu-name' => trim(\esc_html($menu['settings.title']))
        ];

        $debug['update_menu'] = ['menu_id' => $menuId, 'options' => $options];

        if (Menu::WRITE_DB) {
            $menuId = \wp_update_nav_menu_object($menuId, $options);
            if (\is_wp_error($menuId)) {
                throw new \RuntimeException("Saving menu failed: Failed to update {$event->resource}", 400);
            }
        }

        // Get all menu items (or false).
        $unsorted_menu_items = \wp_get_nav_menu_items(
            $menuId,
            [
                'orderby' => 'menu_order',
                'order' => 'ASC',
                'post_status' => 'draft,publish',
                'output' => ARRAY_N
            ]
        );

        $menu_items = [];
        if ($unsorted_menu_items) {
            foreach ($unsorted_menu_items as $item) {
                $menu_items[$item->db_id] = $item;
            }
        }
        unset($unsorted_menu_items);

        // Each menu has ordering from 1..n counting all menu items. Children come right after parent ordering.
        $ordering = $this->flattenOrdering($menu['ordering']);
        $this->embedMeta($menu['ordering'], $menu);

        if (Menu::WRITE_DB) {
            \wp_defer_term_counting(true);
        }

        // Delete removed particles from the menu.
        foreach ($menu_items as $wpItem) {
            if ($wpItem->type === 'custom' && !isset($menu['items'][$wpItem->db_id]) && strpos($wpItem->attr_title, 'gantry-particle-') === 0) {
                $db_id = $wpItem->db_id;

                $debug['delete_' . $db_id] = ['id' => $db_id];

                if (Menu::WRITE_DB) {
                    \delete_post_meta($db_id, '_menu_item_gantry5');
                    \wp_delete_post($db_id);
                }
            }
        }

        $ignore = ['title', 'link', 'class', 'target', 'id'];
        $ignore_db = array_merge($ignore, ['object_id']);
        $items = [];

        foreach ($menu['items'] as $key => $item) {
            $key = isset($item['path']) ? $item['path'] : $key;

            if (!empty($item['id']) && isset($menu_items[$item['id']])) {
                if (!empty($item['object_id'])) {
                    $item['object_id'] = (int)$item['object_id'];
                } else {
                    unset($item['object_id']);
                }
                $wpItem = $menu_items[$item['id']];
                $db_id = $wpItem->db_id;

                $args = [
                    'menu-item-db-id' => $db_id,
                    'menu-item-object-id' => $wpItem->object_id,
                    'menu-item-object' => $wpItem->object,
                    'menu-item-parent-id' => $wpItem->menu_item_parent,
                    'menu-item-position' => isset($ordering[$key]) ? $ordering[$key] : 0,
                    'menu-item-type' => $wpItem->type,
                    'menu-item-title' => \wp_slash(trim($item['title'])),
                    'menu-item-url' => $wpItem->url,
                    'menu-item-description' => $wpItem->description,
                    'menu-item-attr-title' => $wpItem->attr_title,
                    'menu-item-target' => $item['target'] !== '_self' ? $item['target'] : '',
                    'menu-item-classes' => \wp_slash(trim($item['class'])),
                    'menu-item-xfn' => $wpItem->xfn,
                    'menu-item-status' => $wpItem->status
                ];
                $meta = $this->normalizeMenuItem($item, $ignore_db);

                $debug['update_' . $key] = ['menu_id' => $menuId, 'id' => $db_id, 'args' => $args, 'meta' => $meta];

                if (Menu::WRITE_DB) {
                    \wp_update_nav_menu_item($menuId, $db_id, $args);
                    if (Menu::WRITE_META) {
                        \update_post_meta($db_id, '_menu_item_gantry5', \wp_slash(json_encode($meta)));
                    } else {
                        \delete_post_meta($db_id, '_menu_item_gantry5');
                    }
                }
            } elseif ($item['type'] === 'particle') {
                if (isset($item['parent_id']) && is_numeric($item['parent_id'])) {
                    // We have parent id available, use it.
                    $parent_id = $item['parent_id'];
                } else {
                    $parts = explode('/', $key);
                    $slug = array_pop($parts);
                    $parent_path = implode('/', $parts);
                    $parent_item = $parent_path && isset($menu['items'][$parent_path]) ? $menu['items'][$parent_path] : null;

                    $item['path'] = $key;
                    $item['route'] = (!empty($parent_item['route']) ? $parent_item['route'] . '/' : '') . $slug;

                    $wpItem = isset($menu_items[$parent_item['id']]) ? $menu_items[$parent_item['id']] : null;
                    $parent_id = !empty($wpItem->db_id) ? $wpItem->db_id : 0;
                }

                // Create new particle menu item.
                $particle = isset($item['particle']) ? $item['particle'] : '';
                $args = [
                    'menu-item-db-id' => 0,
                    'menu-item-parent-id' => $parent_id,
                    'menu-item-position' => isset($ordering[$key]) ? $ordering[$key] : 0,
                    'menu-item-type' => 'custom',
                    'menu-item-title' => \wp_slash(trim($item['title'])),
                    'menu-item-attr-title' => 'gantry-particle-' . $particle,
                    'menu-item-description' => '',
                    'menu-item-target' => $item['target'] !== '_self' ? $item['target'] : '',
                    'menu-item-classes' => \wp_slash(trim($item['class'])),
                    'menu-item-xfn' => '',
                    'menu-item-status' => 'publish'
                ];
                $meta = $this->normalizeMenuItem($item, $ignore_db);

                $debug['create_' . $key] = ['menu_id' => $menuId, 'args' => $args, 'meta' => $meta, 'item' => $item];

                if (Menu::WRITE_DB) {
                    $db_id = \wp_update_nav_menu_item($menuId, 0, $args);
                    if ($db_id) {
                        // We need to update post_name to match the alias
                        //wp_update_nav_menu_item($menuId, $db_id, $args);
                        if (Menu::WRITE_META) {
                            \update_post_meta($db_id, '_menu_item_gantry5', \wp_slash(json_encode($meta)));
                        }
                    }
                }
            }

            // Add menu items for YAML file.
            $path = isset($item['yaml_path']) ? $item['yaml_path'] : $key;
            $meta = $this->normalizeMenuItem($item, $item['type'] !== 'particle' ? $ignore : []);
            $count = count($meta);
            // But only add menu items which have useful data in them.
            if ($count > 1 || ($count === 1 && !isset($meta['object_id']))) {
                $items[$path] = $meta;
            }
        }

        unset($menu['settings']);
        $menu['items'] = $items;

        $debug['yaml'] = $event->menu->toArray();
        $event->debug = $debug;

        if (!Menu::WRITE_YAML) {
            $event->save = false;
        }

        if (Menu::WRITE_DB) {
            \wp_defer_term_counting(false);
        }
    }

    /**
     * @param array $item
     * @param array $ignore
     * @return array
     */
    protected function normalizeMenuItem(array $item, array $ignore = [])
    {
        if (isset($item['link_title'])) {
            $item['link_title'] = trim($item['link_title']);
        }

        // Do not save default values.
        $defaults = Item::$defaults;
        $ignore = array_flip($ignore);
        foreach ($defaults as $var => $default) {
            // Check if variable should be ignored.
            if (isset($ignore[$var])) {
                unset($item[$var]);
            }

            if (isset($item[$var])) {
                // Convert boolean values.
                if (is_bool($default)) {
                    $item[$var] = (bool)$item[$var];
                }

                if ($item[$var] == $default) {
                    unset($item[$var]);
                }
            }
        }
        foreach ($item as $var => $val) {
            // Check if variable should be ignored.
            if (isset($ignore[$var])) {
                unset($item[$var]);
            }
        }

        if (isset($item['object_id'])) {
            $item['object_id'] = (int)$item['object_id'];
        }

        // Do not save derived values.
        unset($item['id'], $item['path'], $item['route'], $item['alias'], $item['parent_id'], $item['level'], $item['group'], $item['current'], $item['yaml_path'], $item['yaml_alias'], $item['tree']);

        // Do not save WP variables we do not use.
        unset($item['rel'], $item['attr_title']);

        // Particles have no link.
        if (isset($item['type']) && $item['type'] === 'particle') {
            unset($item['link']);
        } else {
            unset($item['title'], $item['link'], $item['type']);
        }

        return $item;

    }

    /**
     * @param array $ordering
     * @param array $parents
     * @param int $i
     * @return array
     */
    protected function flattenOrdering(array $ordering, $parents = [], &$i = 0)
    {
        $list = [];
        $group = isset($ordering[0]);
        foreach ($ordering as $id => $children) {
            $tree = $parents;
            if (!$group) {
                $tree[] = $id;
                $name = implode('/', $tree);
                $list[$name] = ++$i;
            }
            if (\is_array($children)) {
                $list += $this->flattenOrdering($children, $tree, $i);
            }
        }

        return $list;
    }

    /**
     * @param array $ordering
     * @param Config $menu
     * @param array $parents
     * @param int $pos
     */
    protected function embedMeta(array $ordering, Config $menu, $parents = [], $pos = 0)
    {
        $isGroup = isset($ordering[0]);
        $name = implode('/', $parents);

        $counts = [];
        foreach ($ordering as $id => $children) {
            $tree = $parents;

            if ($isGroup) {
                $counts[] = \count($children);
            } else {
                $tree[] = $id;
            }
            if (\is_array($children)) {
                $this->embedMeta($children, $menu, $tree, $isGroup ? $pos : 0);

                $pos += \count($children);
            }
        }

        if ($isGroup) {
            $menu["items.{$name}.columns_count"] = $counts;
        }
    }
}
