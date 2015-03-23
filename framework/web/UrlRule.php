<?php
/**
 * @link http://www.yiiframework.com/
 * @copyright Copyright (c) 2008 Yii Software LLC
 * @license http://www.yiiframework.com/license/
 */

namespace yii\web;

use Yii;
use yii\base\Object;
use yii\base\InvalidConfigException;

/**
 * UrlRule represents a rule used by [[UrlManager]] for parsing and generating URLs.
 *
 * To define your own URL parsing and creation logic you can extend from this class
 * and add it to [[UrlManager::rules]] like this:
 *
 * ~~~
 * 'rules' => [
 *     ['class' => 'MyUrlRule', 'pattern' => '...', 'route' => 'site/index', ...],
 *     // ...
 * ]
 * ~~~
 *
 * rule中class的默认值是yii\web\UrlRule
 *
 * @author Qiang Xue <qiang.xue@gmail.com>
 * @since 2.0
 */
class UrlRule extends Object implements UrlRuleInterface
{
    /**
     * Set [[mode]] with this value to mark that this rule is for URL parsing only
     */
    const PARSING_ONLY = 1;
    /**
     * Set [[mode]] with this value to mark that this rule is for URL creation only
     */
    const CREATION_ONLY = 2;

    /**
     * @var string the name of this rule. If not set, it will use [[pattern]] as the name.
     */
    public $name;
    /**
     * @var string the pattern used to parse and create the path info part of a URL.
     * @see host
     */
    public $pattern;
    /**
     * @var string the pattern used to parse and create the host info part of a URL (e.g. `http://example.com`).
     * @see pattern
     */
    public $host;
    /**
     * @var string the route to the controller action
     */
    public $route;
    /**
     * @var array the default GET parameters (name => value) that this rule provides.
     * When this rule is used to parse the incoming request, the values declared in this property
     * will be injected into $_GET.
     */
    public $defaults = [];
    /**
     * @var string the URL suffix used for this rule.
     * For example, ".html" can be used so that the URL looks like pointing to a static HTML page.
     * If not, the value of [[UrlManager::suffix]] will be used.
     */
    public $suffix;
    /**
     * @var string|array the HTTP verb (e.g. GET, POST, DELETE) that this rule should match.
     * Use array to represent multiple verbs that this rule may match.
     * If this property is not set, the rule can match any verb.
     * Note that this property is only used when parsing a request. It is ignored for URL creation.
     */
    public $verb;
    /**
     * @var integer a value indicating if this rule should be used for both request parsing and URL creation,
     * parsing only, or creation only.
     * If not set or 0, it means the rule is both request parsing and URL creation.
     * If it is [[PARSING_ONLY]], the rule is for request parsing only.
     * If it is [[CREATION_ONLY]], the rule is for URL creation only.
     */
    public $mode;
    /**
     * @var boolean a value indicating if parameters should be url encoded.
     */
    public $encodeParams = true;

    /**
     * @var string the template for generating a new URL. This is derived from [[pattern]] and is used in generating URL.
     */
    private $_template;
    /**
     * @var string the regex for matching the route part. This is used in generating URL.
     */
    private $_routeRule;
    /**
     * @var array list of regex for matching parameters. This is used in generating URL.
     */
    private $_paramRules = [];
    /**
     * @var array list of parameters used in the route.
     * 路由的参数存储数组
     */
    private $_routeParams = [];


    /**
     * Initializes this rule.
     */
    public function init()
    {
        if ($this->pattern === null) {
            throw new InvalidConfigException('UrlRule::pattern must be set.');
        }
        if ($this->route === null) {
            throw new InvalidConfigException('UrlRule::route must be set.');
        }
        if ($this->verb !== null) {
            // 将verb变成数组，并将器内容全部大写
            if (is_array($this->verb)) {
                foreach ($this->verb as $i => $verb) {
                    $this->verb[$i] = strtoupper($verb);
                }
            } else {
                $this->verb = [strtoupper($this->verb)];
            }
        }
        if ($this->name === null) {
            $this->name = $this->pattern;
        }

        $this->pattern = trim($this->pattern, '/');
        $this->route = trim($this->route, '/');

        if ($this->host !== null) {
            // host存在
            $this->host = rtrim($this->host, '/');
            $this->pattern = rtrim($this->host . '/' . $this->pattern, '/');
        } elseif ($this->pattern === '') {
            // pattern为空
            $this->_template = '';
            $this->pattern = '#^$#u';

            return;
        } elseif (($pos = strpos($this->pattern, '://')) !== false) {
            // 存在'://'字符串
            if (($pos2 = strpos($this->pattern, '/', $pos + 3)) !== false) {
                // 找到'://'之后的第一个'/'的位置，并截取之前的字符串作为host
                $this->host = substr($this->pattern, 0, $pos2);
            } else {
                $this->host = $this->pattern;
            }
        } else {
            $this->pattern = '/' . $this->pattern . '/';
        }

        /**
         * $rule的结构如下
         * [
         *     'route'=>'PUT，POST <controller:\w+>/<id>'
         *     'verb'=>['PUT','POST'],
         *     'pattern'=>'<controller:\w+>/<id>'
         * ]
         */
        if (strpos($this->route, '<') !== false && preg_match_all('/<(\w+)>/', $this->route, $matches)) {
            // 匹配不带正则表达式的路由配置，并放入_routeParams中存起来
            // 如上的例子中,$matches[1]=['id']
            foreach ($matches[1] as $name) {
                $this->_routeParams[$name] = "<$name>";
            }
        }

        $tr = [
            '.' => '\\.',
            '*' => '\\*',
            '$' => '\\$',
            '[' => '\\[',
            ']' => '\\]',
            '(' => '\\(',
            ')' => '\\)',
        ];
        $tr2 = [];
        /**
         * 匹配带正则表达式的路由配置
         * PREG_PATTERN_ORDER
         *   结果排序为$matches[0]保存完整模式的所有匹配, $matches[1] 保存第一个子组的所有匹配，以此类推。
         *
         * PREG_SET_ORDER
         *   结果排序为$matches[0]包含第一次匹配得到的所有匹配(包含子组)， $matches[1]是包含第二次匹配到的所有匹配(包含子组)的数组，以此类推。
         *
         * PREG_OFFSET_CAPTURE
         *   如果这个标记被传递，每个发现的匹配返回时会增加它相对目标字符串的偏移量。
         *   注意这会改变matches中的每一个匹配结果字符串元素，使其成为一个第0个元素为匹配结果字符串，第1个元素为 匹配结果字符串在subject中的偏移量。
         *
         * 如果没有给定排序标记，假定设置为PREG_PATTERN_ORDER。
         *
         * 如果$this->pattern是'<controller:\w+>/<id:\d+>'
         * 则$matches为[
         *     [['<controller:\w+>', 0], ['controller', 1], ['\w+', 12]],
         *     [['<id:\d+>', 17], ['id', 18], ['\d+', 21]]
         * ]
         */
        if (preg_match_all('/<(\w+):?([^>]+)?>/', $this->pattern, $matches, PREG_OFFSET_CAPTURE | PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                // 以第一条记录为例
                // $name = 'controller'
                $name = $match[1][0];
                // $pattern = '\w+'
                // 如果正则表达式的匹配值为空，则默认为'[^\/]+'
                $pattern = isset($match[2][0]) ? $match[2][0] : '[^\/]+';
                if (array_key_exists($name, $this->defaults)) {
                    $length = strlen($match[0][0]);
                    $offset = $match[0][1];
                    if ($offset > 1 && $this->pattern[$offset - 1] === '/' && $this->pattern[$offset + $length] === '/') {
                        $tr["/<$name>"] = "(/(?P<$name>$pattern))?";
                    } else {
                        $tr["<$name>"] = "(?P<$name>$pattern)?";
                    }
                } else {
                    // str['<controller>'] = '(?P<controller>\w+)'
                    $tr["<$name>"] = "(?P<$name>$pattern)";
                }
                if (isset($this->_routeParams[$name])) {
                    $tr2["<$name>"] = "(?P<$name>$pattern)";
                } else {
                    $this->_paramRules[$name] = $pattern === '[^\/]+' ? '' : "#^$pattern$#u";
                }
            }
        }

        // 如果$this->pattern是'<controller:\w+>/<id:\d+>'
        // 则$this->_template是'<controller>/<id>'
        $this->_template = preg_replace('/<(\w+):?([^>]+)?>/', '<$1>', $this->pattern);
        // $this->pattern最终是'#^(?P<controller>\w+)/(?P<id>\d+)$#u'
        $this->pattern = '#^' . trim(strtr($this->_template, $tr), '/') . '$#u';

        if (!empty($this->_routeParams)) {
            $this->_routeRule = '#^' . strtr($this->route, $tr2) . '$#u';
        }
    }

    /**
     * Parses the given request and returns the corresponding route and parameters.
     * @param UrlManager $manager the URL manager
     * @param Request $request the request component
     * @return array|boolean the parsing result. The route and the parameters are returned as an array.
     * If false, it means this rule cannot be used to parse this path info.
     */
    public function parseRequest($manager, $request)
    {
        if ($this->mode === self::CREATION_ONLY) {
            return false;
        }

        if (!empty($this->verb) && !in_array($request->getMethod(), $this->verb, true)) {
            return false;
        }

        $pathInfo = $request->getPathInfo();
        $suffix = (string) ($this->suffix === null ? $manager->suffix : $this->suffix);
        if ($suffix !== '' && $pathInfo !== '') {
            $n = strlen($suffix);
            if (substr_compare($pathInfo, $suffix, -$n, $n) === 0) {
                $pathInfo = substr($pathInfo, 0, -$n);
                if ($pathInfo === '') {
                    // suffix alone is not allowed
                    return false;
                }
            } else {
                return false;
            }
        }

        if ($this->host !== null) {
            $pathInfo = strtolower($request->getHostInfo()) . ($pathInfo === '' ? '' : '/' . $pathInfo);
        }

        if (!preg_match($this->pattern, $pathInfo, $matches)) {
            return false;
        }
        foreach ($this->defaults as $name => $value) {
            if (!isset($matches[$name]) || $matches[$name] === '') {
                $matches[$name] = $value;
            }
        }
        $params = $this->defaults;
        $tr = [];
        foreach ($matches as $name => $value) {
            if (isset($this->_routeParams[$name])) {
                $tr[$this->_routeParams[$name]] = $value;
                unset($params[$name]);
            } elseif (isset($this->_paramRules[$name])) {
                $params[$name] = $value;
            }
        }
        if ($this->_routeRule !== null) {
            $route = strtr($this->route, $tr);
        } else {
            $route = $this->route;
        }

        Yii::trace("Request parsed with URL rule: {$this->name}", __METHOD__);

        return [$route, $params];
    }

    /**
     * Creates a URL according to the given route and parameters.
     * @param UrlManager $manager the URL manager
     * @param string $route the route. It should not have slashes at the beginning or the end.
     * @param array $params the parameters
     * @return string|boolean the created URL, or false if this rule cannot be used for creating this URL.
     */
    public function createUrl($manager, $route, $params)
    {
        if ($this->mode === self::PARSING_ONLY) {
            return false;
        }

        $tr = [];

        // match the route part first
        if ($route !== $this->route) {
            if ($this->_routeRule !== null && preg_match($this->_routeRule, $route, $matches)) {
                foreach ($this->_routeParams as $name => $token) {
                    if (isset($this->defaults[$name]) && strcmp($this->defaults[$name], $matches[$name]) === 0) {
                        $tr[$token] = '';
                    } else {
                        $tr[$token] = $matches[$name];
                    }
                }
            } else {
                return false;
            }
        }

        // match default params
        // if a default param is not in the route pattern, its value must also be matched
        foreach ($this->defaults as $name => $value) {
            if (isset($this->_routeParams[$name])) {
                continue;
            }
            if (!isset($params[$name])) {
                return false;
            } elseif (strcmp($params[$name], $value) === 0) { // strcmp will do string conversion automatically
                unset($params[$name]);
                if (isset($this->_paramRules[$name])) {
                    $tr["<$name>"] = '';
                }
            } elseif (!isset($this->_paramRules[$name])) {
                return false;
            }
        }

        // match params in the pattern
        foreach ($this->_paramRules as $name => $rule) {
            if (isset($params[$name]) && !is_array($params[$name]) && ($rule === '' || preg_match($rule, $params[$name]))) {
                $tr["<$name>"] = $this->encodeParams ? urlencode($params[$name]) : $params[$name];
                unset($params[$name]);
            } elseif (!isset($this->defaults[$name]) || isset($params[$name])) {
                return false;
            }
        }

        $url = trim(strtr($this->_template, $tr), '/');
        if ($this->host !== null) {
            $pos = strpos($url, '/', 8);
            if ($pos !== false) {
                $url = substr($url, 0, $pos) . preg_replace('#/+#', '/', substr($url, $pos));
            }
        } elseif (strpos($url, '//') !== false) {
            $url = preg_replace('#/+#', '/', $url);
        }

        if ($url !== '') {
            $url .= ($this->suffix === null ? $manager->suffix : $this->suffix);
        }

        if (!empty($params) && ($query = http_build_query($params)) !== '') {
            $url .= '?' . $query;
        }

        return $url;
    }
}
