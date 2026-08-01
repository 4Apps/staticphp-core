<?php

namespace StaticPHP\Core\Controllers;

use StaticPHP\Core\Models\Load;
use StaticPHP\Core\Models\Config;
use StaticPHP\Core\Models\Router;

/**
 * StaticPHP's base controller, sets various class variables and offers additional methods.
 */
class Controller
{
    public static ?string $module_url = null;
    public static ?string $controller_url = null;
    public static ?string $method_url = null;


    /**
     *  Constructor - Called on each request.
     */
    public static function construct(?string $class = null, ?string $method = null): void
    {
        // Get full urls to current controller and its method
        self::$module_url = self::moduleUrl();
        self::$method_url = self::methodUrl();
        self::$controller_url = self::controllerUrl();

        // Pass these to the view, too. view_data is a bag inside a bag, so make sure the
        // inner one is an array before reaching into it.
        if (is_array(Config::$items['view_data'] ?? null) === false) {
            Config::$items['view_data'] = [];
        }

        Config::$items['view_data']['current_url'] = Router::$parsed_url;
        Config::$items['view_data']['module_url'] = self::$module_url;
        Config::$items['view_data']['controller_url'] = self::$controller_url;
        Config::$items['view_data']['method_url'] = self::$method_url;

        // Add Router's preferences
        Config::$items['view_data']['module'] = Router::$module;
        Config::$items['view_data']['controller'] = Router::$controller;
        Config::$items['view_data']['class'] = Router::$class;
        Config::$items['view_data']['method'] = Router::$method;

        Config::$items['view_data']['module_url_rel'] = Router::$module_url;
        Config::$items['view_data']['controller_url_rel'] = Router::$controller_url;
        Config::$items['view_data']['method_url_rel'] = Router::$method_url;
    }


    /**
     *  Destructor - Called on each request after data is sent to browser.
     */
    public static function destruct(): void
    {
        // Not implemented
    }

    /**
     * Generates module url
     */
    public static function moduleUrl(): string
    {
        return Router::siteUrl(Router::$module_url);
    }

    /**
     * Generates method url
     */
    public static function methodUrl(): string
    {
        return Router::siteUrl(Router::$method_url ?? '');
    }

    /**
     * Generates controller url
     */
    public static function controllerUrl(): string
    {
        return Router::siteUrl(Router::$controller_url);
    }

    /**
     *  Render a view. This method instead of Load::view() prefixes paths with current module directory.
     */
    /**
     * @param array<int|string, string> $views
     * @param array<mixed, mixed>       $view_data
     */
    public static function render(array $views, array $view_data = []): void
    {
        $views = (array)$views;
        foreach ($views as $key => $item) {
            $views[$key] = Router::$module . "/Views/{$item}";
        }

        Load::view($views, $view_data);
    }


    /**
     *  Write $contents to the output. Arrays are jsonified.
     */
    /**
     * @param string|array<mixed, mixed> $contents
     */
    public static function write(string|array $contents): void
    {
        if (is_array($contents)) {
            echo json_encode($contents);
        } else {
            echo $contents;
        }
    }
}
