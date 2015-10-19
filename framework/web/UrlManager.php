<?php
/**
 * @link http://www.yiiframework.com/
 * @copyright Copyright (c) 2008 Yii Software LLC
 * @license http://www.yiiframework.com/license/
 */

namespace yii\web;

use Yii;
use yii\base\Component;
use yii\base\InvalidConfigException;
use yii\caching\Cache;

/**
 * UrlManager handles HTTP request parsing and creation of URLs based on a set of rules.
 *
 * UrlManager is configured as an application component in [[\yii\base\Application]] by default.
 * You can access that instance via `Yii::$app->urlManager`.
 *
 * You can modify its configuration by adding an array to your application config under `components`
 * as it is shown in the following example:
 *
 * ~~~
 * 'urlManager' => [
 *     'enablePrettyUrl' => true,
 *     'rules' => [
 *         // your rules go here
 *     ],
 *     // ...
 * ]
 * ~~~
 *
 * @property string $baseUrl The base URL that is used by [[createUrl()]] to prepend to created URLs.
 * @property string $hostInfo The host info (e.g. "http://www.example.com") that is used by
 * [[createAbsoluteUrl()]] to prepend to created URLs.
 * @property string $scriptUrl The entry script URL that is used by [[createUrl()]] to prepend to created
 * URLs.
 *
 * @author Qiang Xue <qiang.xue@gmail.com>
 * @since 2.0
 */
class UrlManager extends Component
{
    /**
     * @var boolean whether to enable pretty URLs. Instead of putting all parameters in the query
     * string part of a URL, pretty URLs allow using path info to represent some of the parameters
     * and can thus produce more user-friendly URLs, such as "/news/Yii-is-released", instead of
     * "/index.php?r=news/view&id=100".
     *
     * 是否开启 pretty URLs
     */
    public $enablePrettyUrl = false;
    /**
     * @var boolean whether to enable strict parsing. If strict parsing is enabled, the incoming
     * requested URL must match at least one of the [[rules]] in order to be treated as a valid request.
     * Otherwise, the path info part of the request will be treated as the requested route.
     * This property is used only when [[enablePrettyUrl]] is true.
     */
    public $enableStrictParsing = false;
    /**
     * @var array the rules for creating and parsing URLs when [[enablePrettyUrl]] is true.
     * This property is used only if [[enablePrettyUrl]] is true. Each element in the array
     * is the configuration array for creating a single URL rule. The configuration will
     * be merged with [[ruleConfig]] first before it is used for creating the rule object.
     *
     * A special shortcut format can be used if a rule only specifies [[UrlRule::pattern|pattern]]
     * and [[UrlRule::route|route]]: `'pattern' => 'route'`. That is, instead of using a configuration
     * array, one can use the key to represent the pattern and the value the corresponding route.
     * For example, `'post/<id:\d+>' => 'post/view'`.
     *
     * For RESTful routing the mentioned shortcut format also allows you to specify the
     * [[UrlRule::verb|HTTP verb]] that the rule should apply for.
     * You can do that  by prepending it to the pattern, separated by space.
     * For example, `'PUT post/<id:\d+>' => 'post/update'`.
     * You may specify multiple verbs by separating them with comma
     * like this: `'POST,PUT post/index' => 'post/create'`.
     * The supported verbs in the shortcut format are: GET, HEAD, POST, PUT, PATCH and DELETE.
     * Note that [[UrlRule::mode|mode]] will be set to PARSING_ONLY when specifying verb in this way
     * so you normally would not specify a verb for normal GET request.
     *
     * Here is an example configuration for RESTful CRUD controller:
     *
     * ~~~php
     * [
     *     'dashboard' => 'site/index',
     *
     *     'POST <controller:\w+>s' => '<controller>/create',
     *     '<controller:\w+>s' => '<controller>/index',
     *
     *     'PUT <controller:\w+>/<id:\d+>'    => '<controller>/update',
     *     'DELETE <controller:\w+>/<id:\d+>' => '<controller>/delete',
     *     '<controller:\w+>/<id:\d+>'        => '<controller>/view',
     * ];
     * ~~~
     *
     * Note that if you modify this property after the UrlManager object is created, make sure
     * you populate the array with rule objects instead of rule configurations.
     *
     * 配置的 rules 存储在这个数组里
     */
    public $rules = [];
    /**
     * @var string the URL suffix used when in 'path' format.
     * For example, ".html" can be used so that the URL looks like pointing to a static HTML page.
     * This property is used only if [[enablePrettyUrl]] is true.
     *
     * 后缀名，只有在 $enablePrettyUrl 是 true 时，才起作用。
     * 如果 pretty url 是 /news/Yii-is-released，$suffix 是 '.html'
     * 则最后的 url 会是 /news/Yii-is-released.html
     */
    public $suffix;
    /**
     * @var boolean whether to show entry script name in the constructed URL. Defaults to true.
     * This property is used only if [[enablePrettyUrl]] is true.
     *
     * 是否展示入口脚本 index.php
     */
    public $showScriptName = true;
    /**
     * @var string the GET parameter name for route. This property is used only if [[enablePrettyUrl]] is false.
     *
     * $enablePrettyUrl 是 false 时，默认的 router 的参数名
     */
    public $routeParam = 'r';
    /**
     * @var Cache|string the cache object or the application component ID of the cache object.
     * Compiled URL rules will be cached through this cache object, if it is available.
     *
     * After the UrlManager object is created, if you want to change this property,
     * you should only assign it with a cache object.
     * Set this property to false if you do not want to cache the URL rules.
     *
     * 用来缓存 route 规则的 component 的名称，默认为cache
     * 不想使用缓存存储 route 规则的话，就将它设置为 false
     */
    public $cache = 'cache';
    /**
     * @var array the default configuration of URL rules. Individual rule configurations
     * specified via [[rules]] will take precedence when the same property of the rule is configured.
     */
    public $ruleConfig = ['class' => 'yii\web\UrlRule'];

    private $_baseUrl;
    private $_scriptUrl;
    private $_hostInfo;


    /**
     * Initializes UrlManager.
     */
    public function init()
    {
        parent::init();

        if (!$this->enablePrettyUrl || empty($this->rules)) {
            return;
        }
        if (is_string($this->cache)) {
            // 如果 $cache 是字符串，就将它初始化为 cache component
            $this->cache = Yii::$app->get($this->cache, false);
        }
        if ($this->cache instanceof Cache) {
            // 如果　$cache 继承了 Cache，就说明有 cache component
            // 使用当前的类名称作为缓存的 key 值
            $cacheKey = __CLASS__;
            // 根据 rules 的内容，使用MD5算出其hash的值，用于判断缓存是否失效
            $hash = md5(json_encode($this->rules));
            if (($data = $this->cache->get($cacheKey)) !== false && isset($data[1]) && $data[1] === $hash) {
                // 能够获取到缓存，而且缓存没有失效，就将缓存中的rules保存到该对象的rules属性中
                $this->rules = $data[0];
            } else {
                // 缓存失效，或着未取到相应的缓存，就重新构建rules，并存到缓存中
                $this->rules = $this->buildRules($this->rules);
                $this->cache->set($cacheKey, [$this->rules, $hash]);
            }
        } else {
            // 没有开启cache，就直接构建rules
            $this->rules = $this->buildRules($this->rules);
        }
    }

    /**
     * Adds additional URL rules.
     *
     * This method will call [[buildRules()]] to parse the given rule declarations and then append or insert
     * them to the existing [[rules]].
     *
     * Note that if [[enablePrettyUrl]] is false, this method will do nothing.
     *
     * @param array $rules the new rules to be added. Each array element represents a single rule declaration.
     * Please refer to [[rules]] for the acceptable rule format.
     * @param boolean $append whether to add the new rules by appending them to the end of the existing rules.
     */
    public function addRules($rules, $append = true)
    {
        if (!$this->enablePrettyUrl) {
            return;
        }
        $rules = $this->buildRules($rules);
        if ($append) {
            $this->rules = array_merge($this->rules, $rules);
        } else {
            $this->rules = array_merge($rules, $this->rules);
        }
    }

    /**
     * Builds URL rule objects from the given rule declarations.
     * @param array $rules the rule declarations. Each array element represents a single rule declaration.
     * Please refer to [[rules]] for the acceptable rule formats.
     * @return UrlRuleInterface[] the rule objects built from the given rule declarations
     * @throws InvalidConfigException if a rule declaration is invalid
     */
    protected function buildRules($rules)
    {
        $compiledRules = [];
        // 支持的请求方法
        $verbs = 'GET|HEAD|POST|PUT|PATCH|DELETE|OPTIONS';
        foreach ($rules as $key => $rule) {
            if (is_string($rule)) {
                $rule = ['route' => $rule];
                if (preg_match("/^((?:($verbs),)*($verbs))\\s+(.*)$/", $key, $matches)) {
                    // 匹配rules中相应的请求方法
                    // 正则表达式的分析如下
                    // http://jex.im/regulex/#!embed=false&flags=&re=%5E((%3F%3A(GET%7CHEAD%7CPOST%7CPUT%7CPATCH%7CDELETE%7COPTIONS)%2C)*(GET%7CHEAD%7CPOST%7CPUT%7CPATCH%7CDELETE%7COPTIONS))%5Cs%2B(.*)%24
                    $rule['verb'] = explode(',', $matches[1]);
                    // rules that do not apply for GET requests should not be use to create urls
                    if (!in_array('GET', $rule['verb'])) {
                        $rule['mode'] = UrlRule::PARSING_ONLY;
                    }
                    $key = $matches[4];
                }
                $rule['pattern'] = $key;
                // 假设$rule初始为 PUT，POST <controller:\w+>/<id:\d+>
                // 最终$rule的结构如下
                // [
                //   'route'=>'PUT，POST <controller:\w+>/<id:\d+>'
                //   'verb'=>['PUT','POST'],
                //   'pattern'=>’<controller:\w+>/<id:\d+>
                // ]
            }
            if (is_array($rule)) {
                // 默认创建yii\web\UrlRule对象，也可以自定义相应的UrlRule类
                $rule = Yii::createObject(array_merge($this->ruleConfig, $rule));
            }
            if (!$rule instanceof UrlRuleInterface) {
                throw new InvalidConfigException('URL rule class must implement UrlRuleInterface.');
            }
            $compiledRules[] = $rule;
        }
        return $compiledRules;
    }

    /**
     * Parses the user request.
     * @param Request $request the request component
     * @return array|boolean the route and the associated parameters. The latter is always empty
     * if [[enablePrettyUrl]] is false. False is returned if the current request cannot be successfully parsed.
     */
    public function parseRequest($request)
    {
        if ($this->enablePrettyUrl) {
            $pathInfo = $request->getPathInfo();
            /* @var $rule UrlRule */
            foreach ($this->rules as $rule) {
                if (($result = $rule->parseRequest($this, $request)) !== false) {
                    return $result;
                }
            }

            if ($this->enableStrictParsing) {
                return false;
            }

            Yii::trace('No matching URL rules. Using default URL parsing logic.', __METHOD__);

            $suffix = (string) $this->suffix;
            if ($suffix !== '' && $pathInfo !== '') {
                $n = strlen($this->suffix);
                if (substr_compare($pathInfo, $this->suffix, -$n, $n) === 0) {
                    $pathInfo = substr($pathInfo, 0, -$n);
                    if ($pathInfo === '') {
                        // suffix alone is not allowed
                        return false;
                    }
                } else {
                    // suffix doesn't match
                    return false;
                }
            }

            return [$pathInfo, []];
        } else {
            Yii::trace('Pretty URL not enabled. Using default URL parsing logic.', __METHOD__);
            $route = $request->getQueryParam($this->routeParam, '');
            if (is_array($route)) {
                $route = '';
            }

            return [(string) $route, []];
        }
    }

    /**
     * Creates a URL using the given route and query parameters.
     *
     * You may specify the route as a string, e.g., `site/index`. You may also use an array
     * if you want to specify additional query parameters for the URL being created. The
     * array format must be:
     *
     * ```php
     * // generates: /index.php?r=site/index&param1=value1&param2=value2
     * ['site/index', 'param1' => 'value1', 'param2' => 'value2']
     * ```
     *
     * If you want to create a URL with an anchor, you can use the array format with a `#` parameter.
     * For example,
     *
     * ```php
     * // generates: /index.php?r=site/index&param1=value1#name
     * ['site/index', 'param1' => 'value1', '#' => 'name']
     * ```
     *
     * The URL created is a relative one. Use [[createAbsoluteUrl()]] to create an absolute URL.
     *
     * Note that unlike [[\yii\helpers\Url::toRoute()]], this method always treats the given route
     * as an absolute route.
     *
     * @param string|array $params use a string to represent a route (e.g. `site/index`),
     * or an array to represent a route with query parameters (e.g. `['site/index', 'param1' => 'value1']`).
     * @return string the created URL
     */
    public function createUrl($params)
    {
        $params = (array) $params;
        $anchor = isset($params['#']) ? '#' . $params['#'] : '';
        unset($params['#'], $params[$this->routeParam]);

        $route = trim($params[0], '/');
        unset($params[0]);

        $baseUrl = $this->showScriptName || !$this->enablePrettyUrl ? $this->getScriptUrl() : $this->getBaseUrl();

        if ($this->enablePrettyUrl) {
            /* @var $rule UrlRule */
            foreach ($this->rules as $rule) {
                if (($url = $rule->createUrl($this, $route, $params)) !== false) {
                    if (strpos($url, '://') !== false) {
                        if ($baseUrl !== '' && ($pos = strpos($url, '/', 8)) !== false) {
                            return substr($url, 0, $pos) . $baseUrl . substr($url, $pos);
                        } else {
                            return $url . $baseUrl . $anchor;
                        }
                    } else {
                        return "$baseUrl/{$url}{$anchor}";
                    }
                }
            }

            if ($this->suffix !== null) {
                $route .= $this->suffix;
            }
            if (!empty($params) && ($query = http_build_query($params)) !== '') {
                $route .= '?' . $query;
            }

            return "$baseUrl/{$route}{$anchor}";
        } else {
            $url = "$baseUrl?{$this->routeParam}=" . urlencode($route);
            if (!empty($params) && ($query = http_build_query($params)) !== '') {
                $url .= '&' . $query;
            }

            return $url . $anchor;
        }
    }

    /**
     * Creates an absolute URL using the given route and query parameters.
     *
     * This method prepends the URL created by [[createUrl()]] with the [[hostInfo]].
     *
     * Note that unlike [[\yii\helpers\Url::toRoute()]], this method always treats the given route
     * as an absolute route.
     *
     * @param string|array $params use a string to represent a route (e.g. `site/index`),
     * or an array to represent a route with query parameters (e.g. `['site/index', 'param1' => 'value1']`).
     * @param string $scheme the scheme to use for the url (either `http` or `https`). If not specified
     * the scheme of the current request will be used.
     * @return string the created URL
     * @see createUrl()
     */
    public function createAbsoluteUrl($params, $scheme = null)
    {
        $params = (array) $params;
        $url = $this->createUrl($params);
        if (strpos($url, '://') === false) {
            $url = $this->getHostInfo() . $url;
        }
        if (is_string($scheme) && ($pos = strpos($url, '://')) !== false) {
            $url = $scheme . substr($url, $pos);
        }

        return $url;
    }

    /**
     * Returns the base URL that is used by [[createUrl()]] to prepend to created URLs.
     * It defaults to [[Request::baseUrl]].
     * This is mainly used when [[enablePrettyUrl]] is true and [[showScriptName]] is false.
     * @return string the base URL that is used by [[createUrl()]] to prepend to created URLs.
     * @throws InvalidConfigException if running in console application and [[baseUrl]] is not configured.
     */
    public function getBaseUrl()
    {
        if ($this->_baseUrl === null) {
            $request = Yii::$app->getRequest();
            if ($request instanceof Request) {
                $this->_baseUrl = $request->getBaseUrl();
            } else {
                throw new InvalidConfigException('Please configure UrlManager::baseUrl correctly as you are running a console application.');
            }
        }

        return $this->_baseUrl;
    }

    /**
     * Sets the base URL that is used by [[createUrl()]] to prepend to created URLs.
     * This is mainly used when [[enablePrettyUrl]] is true and [[showScriptName]] is false.
     * @param string $value the base URL that is used by [[createUrl()]] to prepend to created URLs.
     */
    public function setBaseUrl($value)
    {
        $this->_baseUrl = rtrim($value, '/');
    }

    /**
     * Returns the entry script URL that is used by [[createUrl()]] to prepend to created URLs.
     * It defaults to [[Request::scriptUrl]].
     * This is mainly used when [[enablePrettyUrl]] is false or [[showScriptName]] is true.
     * @return string the entry script URL that is used by [[createUrl()]] to prepend to created URLs.
     * @throws InvalidConfigException if running in console application and [[scriptUrl]] is not configured.
     */
    public function getScriptUrl()
    {
        if ($this->_scriptUrl === null) {
            $request = Yii::$app->getRequest();
            if ($request instanceof Request) {
                $this->_scriptUrl = $request->getScriptUrl();
            } else {
                throw new InvalidConfigException('Please configure UrlManager::scriptUrl correctly as you are running a console application.');
            }
        }

        return $this->_scriptUrl;
    }

    /**
     * Sets the entry script URL that is used by [[createUrl()]] to prepend to created URLs.
     * This is mainly used when [[enablePrettyUrl]] is false or [[showScriptName]] is true.
     * @param string $value the entry script URL that is used by [[createUrl()]] to prepend to created URLs.
     */
    public function setScriptUrl($value)
    {
        $this->_scriptUrl = $value;
    }

    /**
     * Returns the host info that is used by [[createAbsoluteUrl()]] to prepend to created URLs.
     * @return string the host info (e.g. "http://www.example.com") that is used by [[createAbsoluteUrl()]] to prepend to created URLs.
     * @throws InvalidConfigException if running in console application and [[hostInfo]] is not configured.
     */
    public function getHostInfo()
    {
        if ($this->_hostInfo === null) {
            $request = Yii::$app->getRequest();
            if ($request instanceof \yii\web\Request) {
                $this->_hostInfo = $request->getHostInfo();
            } else {
                throw new InvalidConfigException('Please configure UrlManager::hostInfo correctly as you are running a console application.');
            }
        }

        return $this->_hostInfo;
    }

    /**
     * Sets the host info that is used by [[createAbsoluteUrl()]] to prepend to created URLs.
     * @param string $value the host info (e.g. "http://www.example.com") that is used by [[createAbsoluteUrl()]] to prepend to created URLs.
     */
    public function setHostInfo($value)
    {
        $this->_hostInfo = rtrim($value, '/');
    }
}
