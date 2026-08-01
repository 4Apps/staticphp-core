---
title: Menus
description: The Menu builder, the template it expects you to supply, and the MenuType cases.
sidebar:
    order: 9
---

`StaticPHP\Presentation\Models\Menu\Menu` collects a list of menu item arrays, resolves the
dynamic parts of each one, and hands the result to a view. It renders nothing itself - the
markup lives entirely in a template the application provides.

## The class

```php
<?php

use StaticPHP\Presentation\Models\Menu\Menu;
use StaticPHP\Presentation\Models\Menu\MenuType;

$menu = new Menu();
$menu->type = MenuType::MAIN_MENU;
$menu->menuList = [
    ['title' => 'Dashboard', 'url' => '%base_url'],
    ['title' => 'Users', 'url' => '%base_url/admin/users', 'active' => fn() => Router::$controller === 'users'],
];

Menu::registerMenu($menu);
```

| Property | Type | Default |
| --- | --- | --- |
| `$type` | `MenuType` | none - **must be assigned** |
| `$menuList` | `array` | `[]` |
| `$preMenuList` | untyped | `''` |
| `$postMenuList` | untyped | `''` |
| `$parentActive` | `bool` | `false` |

`$type` is a typed property with no default. Reading it before it is assigned is an
`Error: Typed property ... must not be accessed before initialization`, and `html()` reads it
to pick the template.

`$preMenuList` and `$postMenuList` are strings or closures taking no arguments; whatever they
produce is passed to the template as `pre_menu_content` and `post_menu_content`.

The constructor takes `$parentActive`, which may be a bool or a closure, and resolves it
immediately:

```php
<?php

public function __construct($parentActive = false)
{
    if (is_callable($parentActive)) {
        $this->parentActive = $parentActive();
    } else {
        $this->parentActive = $parentActive;
    }
}
```

It is only ever passed through to the template as `parent_active`; the class does nothing
else with it. It exists for submenus that need to know whether their parent branch is the
active one.

## Menu items

Every entry in `$menuList` is an array merged over these defaults:

| Key | Default | Resolved |
| --- | --- | --- |
| `title` | `'No Title'` | No |
| `end_icon` | `''` | No |
| `before_icon` | `''` | No |
| `after_icon` | `''` | No |
| `url` | `''` | Placeholders substituted |
| `show` | `true` | Closure called; an exact `false` drops the item |
| `active` | `false` | Closure called, result stored back |
| `nav_class` | `''` | No |
| `link_class` | `''` | No |
| `contents` | `''` | Closure called with the whole item array |

So an item can be as short as `['title' => 'Users', 'url' => '/admin/users']`; the missing
keys are filled in and the template can rely on all ten being present.

Only three keys are resolved. `show` and `active` take a closure with no arguments;
`contents` takes one with the merged item array as its only argument, which is how a badge or
a counter is injected. The seven remaining keys are passed through untouched, so any escaping
is the template's responsibility.

`show` is compared with `!== false`, so a closure returning `null` or `0` keeps the item.
Return a hard `false`.

## Url placeholders

`prepareUrl()` runs `str_replace()` over every item url:

| Placeholder | Replaced with |
| --- | --- |
| `%base_url` | `Router::$base_url` |
| `%module_url` | `Controller::moduleUrl()` |
| `%controller_url` | `Controller::controllerUrl()` |
| `%method_url` | `Controller::methodUrl()` |
| `%module` | `Router::$module` |
| `%controller` | `Router::$controller` |
| `%class` | `Router::$class` |
| `%method` | `Router::$method` |

The three `*_url` entries are full urls built by
[`Router::siteUrl()`](/staticphp-core/core/router/); the four bare ones are the raw route
segments. Substitution is plain string replacement in the order listed, so `%module_url` is
consumed before `%module` can match its prefix.

## Rendering

`html()` resolves the pre and post content, filters and resolves the items, and renders:

```php
<?php

$viewData = [
    'parent_active' => $this->parentActive,
    'pre_menu_content' => $preMenuContent,
    'post_menu_content' => $postMenuContent,
    'menu_items' => $menuItems,
];
return Load::view(["Views/components/menu_type_{$this->type->value}.html"], $viewData, true);
```

The third argument is `true`, so the markup is returned rather than echoed.

:::caution[You have to write the template]
The view path is built from the enum's **integer** value, so the four templates are

```text
Views/components/menu_type_100.html
Views/components/menu_type_200.html
Views/components/menu_type_201.html
Views/components/menu_type_300.html
```

resolved relative to `APP_MODULES_PATH`. This package ships none of them - `src/Core/Views/`
contains only the two error views. Every menu type you use needs a template written in your
application, and it has to render the four `$viewData` keys above.
:::

`Load::view()` renders through Twig when `$config['view_engine']` is set and falls back to
plain PHP includes otherwise, so the `.html` extension is a naming convention rather than a
constraint. See [Load](/staticphp-core/core/load/).

## Registering

Two static helpers put a menu where the view layer can find it.

```php
<?php

public static function registerTwig();
public static function registerMenu(Menu $instance);
```

`registerTwig()` adds a `DisplayMenu` Twig function marked `is_safe: ['html']` that calls
`$instance->html()`, so a template can write:

```twig
{{ DisplayMenu(menu_main) }}
```

It returns early when `Config::get('view_engine')` is empty. Twig is a
[suggested, not required, dependency](/staticphp-core/core/load/) of this package, so on an
application with no template engine there is nothing to register and the call is a no-op.

`registerMenu()` stores the instance in `Config::$items['view_data']`, which
[`Load::view()`](/staticphp-core/core/load/) merges into every view's data:

| Type | Key |
| --- | --- |
| `MenuType::MAIN_MENU` | `menu_main` |
| `MenuType::SUB_MENU` | `menu_submenu` |
| `MenuType::TABS` | `menu_tabs` |

`MenuType::SUB_MENU_NEXT_LEVEL` has no branch, so a menu of that type is silently not
registered. Render it yourself by calling `html()` on it, most often from inside the
`SUB_MENU` template.

## MenuType

`MenuType.php`, an **int**-backed enum with **4** cases - the only int-backed enum in the
presentation module:

| Case | Value |
| --- | --- |
| `MAIN_MENU` | `100` |
| `SUB_MENU` | `200` |
| `SUB_MENU_NEXT_LEVEL` | `201` |
| `TABS` | `300` |

The values are also the template filename suffixes.

## hideMenus()

```php
<?php

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
```

The intent is a bitmask: pass one or more menu types and those menus disappear from the
view data for this request. It does not work, because the values are not powers of two and
their bit patterns overlap:

<!-- captured:menu-flags -->
```text
case                             value binary
MenuType::MAIN_MENU              100   1100100
MenuType::SUB_MENU               200   11001000
MenuType::SUB_MENU_NEXT_LEVEL    201   11001001
MenuType::TABS                   300   100101100

hideMenus() tests each flag with a bitwise AND:
hideMenus(MenuType::MAIN_MENU->value)           hides: MAIN_MENU, SUB_MENU, TABS
hideMenus(MenuType::SUB_MENU->value)            hides: MAIN_MENU, SUB_MENU, TABS
hideMenus(MenuType::SUB_MENU_NEXT_LEVEL->value) hides: MAIN_MENU, SUB_MENU, TABS
hideMenus(MenuType::TABS->value)                hides: MAIN_MENU, SUB_MENU, TABS
```
<!-- /captured:menu-flags -->

Every case shares at least one bit with every other, so **any** argument removes all three
menus. `hideMenus(MenuType::TABS->value)` hides the main menu and the submenu as well.

If you want all menus gone - a print view, a login page - the method does that reliably.
For anything selective, unset the key yourself:

```php
<?php

use StaticPHP\Core\Models\Config;

unset(Config::$items['view_data']['menu_tabs']);
```

Note also that the argument is the enum's `->value`, not the case, and that the method has
no parameter type, so passing the case itself is a `TypeError` on the `&` operator rather
than a helpful error. The package's own
[`BitwiseFlag`](/staticphp-core/utilities/bitwise-flag/) helper is unrelated to this and is
not used here.
