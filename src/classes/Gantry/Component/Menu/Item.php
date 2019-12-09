<?php

/**
 * @package   Gantry5
 * @author    RocketTheme http://www.rockettheme.com
 * @copyright Copyright (C) 2007 - 2019 RocketTheme, LLC
 * @license   Dual License: MIT or GNU/GPLv2 and later
 *
 * http://opensource.org/licenses/MIT
 * http://www.gnu.org/licenses/gpl-2.0.html
 *
 * Gantry Framework code that extends GPL code is considered GNU/GPLv2 and later
 */

namespace Gantry\Component\Menu;

use RocketTheme\Toolbox\ArrayTraits\ArrayAccessWithGetters;
use RocketTheme\Toolbox\ArrayTraits\Export;

/**
 * @property string $id
 * @property string $type
 * @property string $path
 * @property string $alias
 * @property string $title
 * @property string $link
 * @property string $parent_id
 * @property string $layout
 * @property int $browserNav
 * @property bool $menu_text
 * @property bool $visible
 * @property int $group
 * @property int $level
 */
class Item implements \ArrayAccess, \Iterator, \Serializable, \Countable
{
    use ArrayAccessWithGetters, Export;

    const VERSION = 1;

    /** @var array */
    protected $items;
    /** @var AbstractMenu */
    protected $menu;
    /** @var array */
    protected $groups = [];
    /** @var array */
    protected $children = [];
    /** @var string */
    protected $url;
    /** @var array */
    protected static $defaults = [
        'id' => 0,
        'type' => 'link',
        'path' => null,
        'alias' => null,
        'title' => null,
        'link' => null,
        'parent_id' => null,
        'layout' => 'list',
        'target' => '_self',
        'dropdown' => '',
        'icon' => '',
        'image' => '',
        'subtitle' => '',
        'hash' => '',
        'class' => '',
        'icon_only' => false,
        'enabled' => true,
        'visible' => true,
        'group' => 0,
        'columns' => [],
        'level' => 0,
        'link_title' => '',
        'anchor_class' => ''
    ];

    /**
     * Item constructor.
     * @param AbstractMenu $menu
     * @param string $name
     * @param array $item
     */
    public function __construct(AbstractMenu $menu, $name, array $item = [])
    {
        $this->menu = $menu;

        $tree = explode('/', $name);
        $alias = array_pop($tree);
        $parent = implode('/', $tree);

        // As we always calculate parent (it can change), prevent old one from being inserted.
        unset($item['parent_id']);

        $this->items = $item + [
            'id' => preg_replace('|[^a-z0-9]|i', '-', $name) ?: 'root',
            'path' => $name,
            'alias' => $alias,
            'title' => ucfirst($alias),
            'link' => $name,
            'parent_id' => $parent !== '.' ? $parent : '',
        ] + static::$defaults;
    }

    /**
     * @return string
     */
    public function getDropdown()
    {
        if (!$this->items['dropdown']) {
            return count($this->groups()) > 1 ? 'fullwidth' : 'standard';
        }

        return $this->items['dropdown'];
    }

    /**
     * @return string
     */
    public function serialize()
    {
        // FIXME: need to create collection class to gather the sibling data.
        return serialize([
            'version' => static::VERSION,
            'items' => $this->items,
            'groups' => $this->groups,
            'children' => $this->children,
            'url' => $this->url
        ]);
    }

    /**
     * @param string $serialized
     */
    public function unserialize($serialized)
    {
        // FIXME: need to create collection class to gather the sibling data.
        $data = unserialize($serialized);

        if (!isset($data['version']) && $data['version'] === static::VERSION) {
            throw new \UnexpectedValueException('Serialized data is not valid');
        }

        $this->items = $data['items'];
        $this->groups =  $data['groups'];
        $this->children = $data['children'];
        $this->url = $data['url'];
    }

    /**
     * @param  string|null|bool $url
     * @return string
     */
    public function url($url = false)
    {
        if ($url !== false) {
            $this->url = $url;
        }

        return $this->url;
    }

    /**
     * @return AbstractMenu
     * @todo Need to break relationship to the menu and use a collection instead.
     */
    protected function menu()
    {
        return $this->menu;
    }

    /**
     * @return Item
     */
    public function parent()
    {
        return $this->menu()[$this->items['parent_id']];
    }

    /**
     * @param string|int $column
     * @return float|int
     */
    public function columnWidth($column)
    {
        if (isset($this->items['columns'][$column])) {
            return $this->items['columns'][$column];
        }

        return 100 / count($this->groups());
    }

    /**
     * @return array
     */
    public function groups()
    {
        if ($this->groups) {
            $list = [];
            foreach ($this->groups as $i => $group) {
                $list[$i] = [];
                foreach ($group as $path) {
                    $list[$i][] = $this->menu()[$path];
                }
            }

            return $list;
        }

        return [$this->children()];
    }

    /**
     * @return array
     */
    public function children()
    {
        $list = [];
        foreach ($this as $child) {
            $list[] = $child;
        }

        return $list;
    }

    /**
     * @return bool
     */
    public function hasChildren()
    {
        return !empty($this->children);
    }

    /**
     * @param int $i
     * @return array
     */
    public function getGroup($i)
    {
        $groups = $this->groups();
        $i = (int) $i;

        return isset($groups[$i]) ? $groups[$i] : [];
    }

    /**
     * @param array $data
     * @return $this
     */
    public function update(array $data)
    {
        $this->items = array_replace($this->items, $data);

        return $this;
    }

    /**
     * @param Item $child
     * @return $this
     */
    public function addChild(Item $child)
    {
        $child->level = $this->level + 1;
        $child->parent_id = $this->path;
        $this->children[$child->alias] = $child->path;

        return $this;
    }

    /**
     * @param Item $child
     * @return $this
     */
    public function removeChild(Item $child)
    {
        unset($this->children[$child->alias]);

        return $this;
    }

    /**
     * @param array|null $ordering
     * @return $this
     */
    public function sortChildren($ordering)
    {
        // Array with keys that point to the items.
        $children =& $this->children;

        if ($children) {
            if (is_array($ordering)) {
                // Remove extra items from ordering and reorder.
                $children = array_replace(array_intersect_key($ordering, $children), $children);
            } else {
                switch ((string) $ordering) {
                    case 'abc':
                        // Alphabetical ordering.
                        ksort($children, SORT_NATURAL);
                        break;
                    case 'cba':
                        // Reversed alphabetical ordering.
                        krsort($children, SORT_NATURAL);
                        break;
                }
            }
        }

        return $this;
    }

    /**
     * @return $this
     */
    public function reverse()
    {
        array_reverse($this->children, true);
        array_reverse($this->groups, true);

        return $this;
    }

    /**
     * @param array $groups
     * @return $this
     */
    public function groupChildren(array $groups)
    {
        // Array with keys that point to the items.
        $children =& $this->children;

        if ($children) {
            $menu = $this->menu();
            $ordered = [];

            // Create empty groups.
            $this->groups = array_fill(0, max(1, count($this->items['columns'])), []);

            foreach ($groups as $i => $ordering) {
                if (!is_array($ordering)) {
                    continue;
                }

                // Get the items for this group with proper ordering.
                $group = array_replace(
                    array_intersect_key($ordering, $children), array_intersect_key($children, $ordering)
                );

                // Assign each menu items to the group.
                $group = array_map(
                    static function($value) use ($i, $menu) {
                        $item = $menu[$value];
                        $item->group = $i;

                        return $value;
                    },
                    $group
                );

                // Update remaining children.
                $children = array_diff_key($children, $ordering);

                // Build child ordering.
                $ordered += $group;

                // Add items to the current group.
                $this->groups[$i] = $group;
            }

            if ($children) {
                // Add leftover children to the ordered list and to the first group.
                $ordered += $children;
                $this->groups[0] += $children;
            }

            // Reorder children by their groups.
            $children = $ordered;
        }

        return $this;
    }

    // Implements \Iterator

    /**
     * Returns the current child.
     *
     * @return Item
     */
    public function current()
    {
        return $this->menu()[current($this->children)];
    }

    /**
     * Returns the key of the current child.
     *
     * @return mixed  Returns scalar on success, or NULL on failure.
     */
    public function key()
    {
        return key($this->children);
    }

    /**
     * Moves the current position to the next child.
     *
     * @return void
     */
    public function next()
    {
        next($this->children);
    }

    /**
     * Rewinds back to the first child.
     *
     * @return void
     */
    public function rewind()
    {
        reset($this->children);
    }

    /**
     * Count number of children.
     *
     * @return int
     */
    public function count()
    {
        return count($this->children);
    }

    /**
     * This method is called after Iterator::rewind() and Iterator::next() to check if the current position is valid.
     *
     * @return bool  Returns TRUE on success or FALSE on failure.
     */
    public function valid()
    {
        return key($this->children) !== null;
    }

    /**
     * Convert object into an array.
     *
     * @param bool $withDefaults
     * @return array
     */
    public function toArray($withDefaults = true)
    {
        $items = $this->items;

        if (!$withDefaults) {
            foreach (static::$defaults as $key => $value) {
                if ($items[$key] === $value) {
                    unset($items[$key]);
                }
            }
        }

        return $items;
    }
}
