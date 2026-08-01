<?php

namespace StaticPHP\Presentation\Models\Menu;

use Twig\TwigFunction;
use StaticPHP\Core\Models\Load;
use StaticPHP\Core\Models\Config;
use StaticPHP\Core\Models\Router;
use StaticPHP\Core\Controllers\Controller;
use StaticPHP\Presentation\Models\Menu\MenuType;

/**
 * Generate Menus
 */
class Menu
{
    public MenuType $type;
    public array $menuList = [];
    public $preMenuList = '';
    public $postMenuList = '';

    public bool $parentActive = false;

    private array $itemDefaults = [
        'title' => 'No Title',

        // Views switch on this to tell a link apart from a separator - a 'divider' item
        // carries nothing else, so without a default here every ordinary item reached the
        // template with no 'type' at all and the comparison was against an undefined key
        'type' => 'link',

        'end_icon' => '',
        'before_icon' => '',
        'after_icon' => '',
        'url' => '',
        'show' => true,
        'active' => false,
        'nav_class' => '',
        'link_class' => '',
        'contents' => '',
        'children' => [],
    ];

    public function __construct($parentActive = false)
    {
        if (is_callable($parentActive)) {
            $this->parentActive = $parentActive();
        } else {
            $this->parentActive = $parentActive;
        }
    }

    private function prepareUrl($url)
    {
        return str_replace(
            [
                '%base_url',
                '%module_url',
                '%controller_url',
                '%method_url',
                '%module',
                '%controller',
                '%class',
                '%method',
            ],
            [
                Router::$base_url,
                Controller::moduleUrl(),
                Controller::controllerUrl(),
                Controller::methodUrl(),
                Router::$module,
                Router::$controller,
                Router::$class,
                Router::$method,
            ],
            $url
        );
    }

    /**
     * Merge the defaults into one item and resolve whatever of it is a closure.
     *
     * @access private
     * @param  array $item
     * @return ?array Null when the item is not to be shown.
     */
    private function prepareItem(array $item): ?array
    {
        $item = array_merge($this->itemDefaults, $item);

        // Can we show the item? Read defensively - an item is allowed to carry an explicit
        // null here, and the default only covers a missing key
        $show = $item['show'] ?? false;
        $shouldShow = (is_callable($show) ? $show() : $show);
        if (empty($shouldShow)) {
            return null;
        }

        // Is the item active?
        $item['active'] = (is_callable($item['active']) ? $item['active']() : $item['active']);

        // Custom contents
        if (is_callable($item['contents'])) {
            $item['contents'] = $item['contents']($item);
        }

        // Fix url
        $item['url'] = $this->prepareUrl($item['url']);

        return $item;
    }

    public function html()
    {
        $preMenuContent = '';
        if (is_callable($this->preMenuList)) {
            $preMenuContent = ($this->preMenuList)();
        } else {
            $preMenuContent = $this->preMenuList;
        }
        $postMenuContent = '';
        if (is_callable($this->postMenuList)) {
            $postMenuContent = ($this->postMenuList)();
        } else {
            $postMenuContent = $this->postMenuList;
        }

        $menuItems = [];
        foreach ($this->menuList as $item) {
            $item = $this->prepareItem($item);
            if ($item === null) {
                continue;
            }

            // Group items - a submenu nested under a single entry
            if (!empty($item['children'])) {
                $children = [];
                $childMenuHtml = '';

                foreach ($item['children'] as $child) {
                    // A nested Menu renders itself and joins the group as content
                    if ($child instanceof Menu) {
                        $childMenuHtml .= $child->html();
                        continue;
                    }

                    $child = $this->prepareItem($child);
                    if ($child === null) {
                        continue;
                    }

                    $children[] = $child;
                }

                $item['children'] = $children;

                if ($childMenuHtml !== '') {
                    $item['contents'] = $childMenuHtml . $item['contents'];
                }

                // A group holds no url of its own, so nothing else would ever mark it
                // active while the open page is one of its children
                if (empty($item['active'])) {
                    foreach ($children as $child) {
                        if (!empty($child['active'])) {
                            $item['active'] = true;
                            break;
                        }
                    }
                }
            }

            $menuItems[] = $item;
        }

        $viewData = [
            'parent_active' => $this->parentActive,
            'pre_menu_content' => $preMenuContent,
            'post_menu_content' => $postMenuContent,
            'menu_items' => $menuItems,
        ];
        return Load::view(["Views/components/menu_type_{$this->type->value}.html"], $viewData, true);
    }

    /**
     * The first item in the list this request is allowed to open.
     *
     * For sections whose landing page not every role can reach - redirect here instead of
     * to a fixed url, and whoever is looking arrives somewhere they are permitted to be.
     * Dividers and entries without a url are skipped; there is nothing to navigate to.
     *
     * @access public
     * @return ?array The prepared item with its resolved url under 'full_url', or null.
     */
    public function firstVisibleMenu(): ?array
    {
        foreach ($this->menuList as $item) {
            $item = $this->prepareItem($item);
            if ($item === null || $item['type'] === 'divider' || empty($item['url'])) {
                continue;
            }

            $item['full_url'] = $item['url'];

            return $item;
        }

        return null;
    }

    // MARK: Twig
    public static function registerTwig()
    {
        // Twig is a suggestion of staticphp-core, not a requirement, so there may be no
        // engine to register against
        if (empty(Config::get('view_engine'))) {
            return;
        }

        $function = new TwigFunction(
            'DisplayMenu',
            function (Menu $instance) {
                return $instance->html();
            },
            ['is_safe' => ['html']]
        );
        Config::$items['view_engine']->addFunction($function);
    }

    public static function registerMenu(Menu $instance)
    {
        if ($instance->type == MenuType::MAIN_MENU) {
            Config::$items['view_data']['menu_main'] = $instance;
        }
        if ($instance->type == MenuType::SUB_MENU) {
            Config::$items['view_data']['menu_submenu'] = $instance;
        }
        if ($instance->type == MenuType::TABS) {
            Config::$items['view_data']['menu_tabs'] = $instance;
        }
    }

    public static function hideMenus($menuFlags)
    {
        if ($menuFlags & MenuType::MAIN_MENU->value) {
            unset(Config::$items['view_data']['menu_main']);
        }
        if ($menuFlags & MenuType::SUB_MENU->value) {
            unset(Config::$items['view_data']['menu_submenu']);
        }
        if ($menuFlags & MenuType::TABS->value) {
            unset(Config::$items['view_data']['menu_tabs']);
        }
    }
}
