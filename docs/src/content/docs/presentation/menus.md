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
| `$preMenuList` | `string\|\Closure` | `''` |
| `$postMenuList` | `string\|\Closure` | `''` |
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

public function __construct(bool|\Closure $parentActive = false)
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
| `type` | `'link'` | No |
| `end_icon` | `''` | No |
| `before_icon` | `''` | No |
| `after_icon` | `''` | No |
| `url` | `''` | Placeholders substituted |
| `show` | `true` | Closure called; any falsy result drops the item |
| `active` | `false` | Closure called, result stored back |
| `nav_class` | `''` | No |
| `link_class` | `''` | No |
| `contents` | `''` | Closure called with the whole item array |
| `children` | `[]` | Each child prepared the same way |

So an item can be as short as `['title' => 'Users', 'url' => '/admin/users']`; the missing
keys are filled in and the template can rely on all twelve being present.

`type` is what a template switches on to tell a link apart from a separator. A divider item
carries nothing else - `['type' => 'divider']` is the whole of it - so without the default
here an ordinary item reached the template with no `type` key at all and the comparison ran
against an undefined index. `firstVisibleMenu()` reads it for the same reason.

Three keys take a closure. `show` and `active` take one with no arguments; `contents` takes
one with the merged item array as its only argument, which is how a badge or a counter is
injected. `url` is rewritten by `prepareUrl()` and `children` is walked recursively. The
seven remaining keys are passed through untouched, so any escaping is the template's
responsibility.

`show` is tested with `empty()`, not `!== false`, so **any** falsy value drops the item -
`false`, `null`, `0`, `''`, `[]`. The read is deliberately defensive: `$item['show'] ?? false`
before the closure call, so an item carrying an explicit `null` is dropped rather than
treated as missing.

```text
show => false  dropped
show => null   dropped
show => 0      dropped
show => ''     dropped
show => []     dropped
show => true   kept
```

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

`html(): string` resolves the pre and post content, filters and resolves the items, and
renders:

```php
<?php

$viewData = [
    'parent_active' => $this->parentActive,
    'pre_menu_content' => $preMenuContent,
    'post_menu_content' => $postMenuContent,
    'menu_items' => $menuItems,
];
return (string) Load::view(["Views/components/menu_type_{$this->type->value}.html"], $viewData, true);
```

The third argument is `true`, so the markup is returned rather than echoed; the cast is there
because `Load::view()` is declared `: string|bool` and only the `$return` branch narrows it.

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

### End to end

Since the template is yours, here is the whole loop with one written for the occasion. A
plain PHP view, because no `view_engine` was configured:

```html
<ul class="nav">
<?php foreach ($menu_items as $item): ?>
    <li class="<?= $item['nav_class'] ?>">
        <a class="<?= $item['link_class'] ?>" href="<?= $item['url'] ?>"><?= $item['title'] ?></a>
        <?= $item['contents'] ?>
    </li>
<?php endforeach; ?>
</ul>
```

The menu, with `Router::$base_url` set to `https://example.test`, `Router::$module` to
`Admin` and `Router::$controller` to `users`:

```php
<?php

$menu = new Menu();
$menu->type = MenuType::MAIN_MENU;
$menu->menuList = [
    ['title' => 'Dashboard', 'url' => '%base_url/'],
    ['title' => 'Users', 'url' => '%base_url/%module/%controller', 'nav_class' => 'active'],
    ['title' => 'Hidden', 'url' => '%base_url/secret', 'show' => fn() => false],
    ['title' => 'Reports', 'url' => '%base_url/reports', 'contents' => fn($item) => '<span class="badge">4</span>'],
    [],
];
```

`$menu->html()` returns:

```html
<ul class="nav">
    <li class="">
        <a class="" href="https://example.test/">Dashboard</a>
            </li>
    <li class="active">
        <a class="" href="https://example.test/Admin/users">Users</a>
            </li>
    <li class="">
        <a class="" href="https://example.test/reports">Reports</a>
        <span class="badge">4</span>    </li>
    <li class="">
        <a class="" href="">No Title</a>
            </li>
</ul>
```

Four things that only show up when you run it:

- The `Hidden` item is gone, because its `show` closure returned `false`. Anything else
  falsy would have done the same.
- The empty `[]` item is still rendered, with every default filled in - hence
  `No Title` and an empty `href`. There is no validation of an item.
- `%module` expanded to `Admin`, the raw `Router::$module`, not a url segment. Use
  `%module_url` for the url form.
- The `contents` closure's markup lands wherever the template puts it, unescaped.

:::caution[prepareUrl() needs a dispatched request]
`prepareUrl()` builds its replacement array eagerly, calling `Controller::moduleUrl()`,
`controllerUrl()` and `methodUrl()` for **every** item url whether or not it contains those
placeholders. Each one passes a `Router::$*_url` static to `Router::siteUrl()`, which is
typed `string` and those statics start as `null`:

```text
Router::$module_url -> NULL

html(): TypeError: StaticPHP\Core\Models\Router::siteUrl(): Argument #1 ($url) must be of type string, null given, called in /srv/app/src/Core/Controllers/Controller.php on line 65
```

Line 65 is `Controller::moduleUrl()`, which is `Router::siteUrl(Router::$module_url)` against
an untyped static that starts as `null`. `controllerUrl()` is the same shape and fails the
same way once the first one is out of the way. `methodUrl()` is not: it was fixed to
`Router::siteUrl(Router::$method_url ?? '')`.

Inside a dispatched request the router has filled them in and this never comes up. Building
a menu from a script, a test or a CLI command means setting `Router::$module_url` and
`$controller_url` first, even for a menu whose urls are all literal.
:::

`Load::view()` renders through Twig unless `Config::viewEngine()` returns `null` and falls
back to plain PHP includes otherwise, so the `.html` extension is a naming convention rather
than a constraint. See [Load](/staticphp-core/core/load/).

## Groups and submenus

An item with a non-empty `children` key is a group: an entry that opens a submenu instead of,
or as well as, navigating somewhere. `html()` walks the children before the item reaches the
template, and each child goes through the same `prepareItem()` the top level does - defaults
merged, `show` honoured, `active` and `contents` closures called, url placeholders
substituted. The prepared list is written back into `$item['children']`.

A child may also be a whole `Menu` object. That one renders itself with `html()` and its
markup is prepended to the group's `contents`, which is how a `SUB_MENU_NEXT_LEVEL` menu is
nested inside its parent without the parent template knowing anything about it.

The last thing the group loop does is propagate active state upwards:

```php
<?php

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
```

Only one level deep - a child's own `children` are not walked - and only upwards, so a group
that sets `active` itself keeps it whatever its children say.

A `MenuType::SUB_MENU` menu with one group, through a
`Views/components/menu_type_200.html` written for the occasion:

```html
<ul class="submenu">
<?php foreach ($menu_items as $item): ?>
<?php if ($item['type'] === 'divider'): ?>
    <li class="divider"></li>
<?php continue; endif; ?>
    <li class="<?= empty($item['active']) ? '' : 'active' ?>">
        <a href="<?= $item['url'] ?>"><?= $item['title'] ?></a>
<?php if (!empty($item['children'])): ?>
        <ul>
<?php foreach ($item['children'] as $child): ?>
            <li class="<?= empty($child['active']) ? '' : 'active' ?>"><a href="<?= $child['url'] ?>"><?= $child['title'] ?></a></li>
<?php endforeach; ?>
        </ul>
<?php endif; ?>
        <?= $item['contents'] ?>
    </li>
<?php endforeach; ?>
</ul>
```

```php
<?php

$group = new Menu();
$group->type = MenuType::SUB_MENU;
$group->menuList = [
    ['title' => 'Overview', 'url' => '%module_url/overview'],
    [
        'title' => 'Settings',
        'children' => [
            ['title' => 'Profile', 'url' => '%controller_url/profile'],
            ['title' => 'Billing', 'url' => '%controller_url/billing', 'active' => fn() => true],
            ['title' => 'Secret', 'url' => '%controller_url/secret', 'show' => fn() => false],
        ],
    ],
    ['type' => 'divider'],
];
```

```html
<ul class="submenu">
    <li class="">
        <a href="https://example.test/admin/overview">Overview</a>
            </li>
    <li class="active">
        <a href="">Settings</a>
        <ul>
            <li class=""><a href="https://example.test/admin/users/profile">Profile</a></li>
            <li class="active"><a href="https://example.test/admin/users/billing">Billing</a></li>
        </ul>
            </li>
    <li class="divider"></li>
</ul>
```

The `Settings` group carries `active` although nothing set it, because `Billing` did. Its
`href` is empty - a group with children usually wants no url of its own. `Secret` is gone,
dropped by its own `show` closure at the child level. The divider item survives with nothing
but its `type`, which is what the template switched on to emit `<li class="divider">`.

## firstVisibleMenu()

```php
<?php

public function firstVisibleMenu(): ?array
```

Returns the first item of `$menuList` this request is allowed to open, prepared exactly as
`html()` would prepare it and with its resolved url copied to a `full_url` key. Dividers and
entries with no url are skipped - there is nothing to navigate to - and so is anything whose
`show` says no. It returns `null` when the whole list is unreachable.

It exists for sections whose landing page not every role can see: redirect to whatever this
user's first entry is rather than to a fixed url, and whoever is looking arrives somewhere
they are permitted to be.

```php
<?php

$first = new Menu();
$first->type = MenuType::SUB_MENU;
$first->menuList = [
    ['type' => 'divider'],
    ['title' => 'No url'],
    ['title' => 'Reports', 'url' => '%module_url/reports', 'show' => fn() => false],
    ['title' => 'Billing', 'url' => '%module_url/billing'],
];
```

```text
title: Billing
full_url: https://example.test/admin/billing
```

The first three are skipped in turn - a divider, an entry with no url, an entry the `show`
closure hid - and the fourth is returned. `full_url` is a plain copy of `url`; the method
adds the key rather than a different value, so callers can tell a prepared item apart from a
raw one.

## Registering

Two static helpers put a menu where the view layer can find it.

```php
<?php

public static function registerTwig(): void;
public static function registerMenu(Menu $instance): void;
```

`registerTwig()` adds a `DisplayMenu` Twig function marked `is_safe: ['html']` that calls
`$instance->html()`, so a template can write:

```twig
{{ DisplayMenu(menu_main) }}
```

It returns early when `Config::viewEngine()` is `null`. Twig is a
[suggested, not required, dependency](/staticphp-core/core/load/) of this package, so on an
application with no template engine there is nothing to register and the call is a no-op.

`registerMenu()` stores the instance in `Config::$items['view_data']`, which
[`Load::view()`](/staticphp-core/core/load/) merges into every view's data. It creates that
key first when it is missing or holds something that is not an array, so registering a menu
outside a dispatched request - where `Controller::construct()` would have set it up - works:

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

public static function hideMenus(int $menuFlags): void
{
    if (is_array(Config::$items['view_data'] ?? null) === false) {
        return;
    }

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

It returns early when no view data has been set up at all, so calling it before any menu was
registered is a no-op rather than a warning.

The intent is a bitmask: pass one or more menu types and those menus disappear from the
view data for this request. It does not work, because the values are not powers of two and
their bit patterns overlap - case, value, binary:

```text
MenuType::MAIN_MENU          100   1100100
MenuType::SUB_MENU           200   11001000
MenuType::SUB_MENU_NEXT_LEVEL 201   11001001
MenuType::TABS               300   100101100
```

`hideMenus()` tests each flag with a bitwise AND, so with all three menus registered
beforehand:

```text
hideMenus(MenuType::MAIN_MENU->value) leaves: nothing
hideMenus(MenuType::SUB_MENU->value) leaves: nothing
hideMenus(MenuType::SUB_MENU_NEXT_LEVEL->value) leaves: nothing
hideMenus(MenuType::TABS->value) leaves: nothing
```

Every case shares at least one bit with every other, so **any** argument removes all three
menus. `hideMenus(MenuType::TABS->value)` hides the main menu and the submenu as well.

If you want all menus gone - a print view, a login page - the method does that reliably.
For anything selective, unset the key yourself:

```php
<?php

use StaticPHP\Core\Models\Config;

unset(Config::$items['view_data']['menu_tabs']);
```

Note that the argument is the enum's `->value`, not the case. The parameter is typed `int`,
so passing the case itself fails at the call rather than somewhere inside:

```text
hideMenus(MenuType::TABS): TypeError: StaticPHP\Presentation\Models\Menu\Menu::hideMenus(): Argument #1 ($menuFlags) must be of type int, StaticPHP\Presentation\Models\Menu\MenuType given
```

The package's own [`BitwiseFlag`](/staticphp-core/utilities/bitwise-flag/) helper is
unrelated to this and is not used here.
