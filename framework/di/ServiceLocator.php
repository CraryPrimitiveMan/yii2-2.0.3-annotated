<?php
/**
 * @link http://www.yiiframework.com/
 * @copyright Copyright (c) 2008 Yii Software LLC
 * @license http://www.yiiframework.com/license/
 */

namespace yii\di;

use Yii;
use Closure;
use yii\base\Component;
use yii\base\InvalidConfigException;

/**
 * ServiceLocator implements a [service locator](http://en.wikipedia.org/wiki/Service_locator_pattern).
 *
 * To use ServiceLocator, you first need to register component IDs with the corresponding component
 * definitions with the locator by calling [[set()]] or [[setComponents()]].
 * You can then call [[get()]] to retrieve a component with the specified ID. The locator will automatically
 * instantiate and configure the component according to the definition.
 *
 * For example,
 *
 * ```php
 * $locator = new \yii\di\ServiceLocator;
 * $locator->setComponents([
 *     'db' => [
 *         'class' => 'yii\db\Connection',
 *         'dsn' => 'sqlite:path/to/file.db',
 *     ],
 *     'cache' => [
 *         'class' => 'yii\caching\DbCache',
 *         'db' => 'db',
 *     ],
 * ]);
 *
 * $db = $locator->get('db');  // or $locator->db
 * $cache = $locator->get('cache');  // or $locator->cache
 * ```
 *
 * Because [[\yii\base\Module]] extends from ServiceLocator, modules and the application are all service locators.
 *
 * @property array $components The list of the component definitions or the loaded component instances (ID =>
 * definition or instance).
 *
 * @author Qiang Xue <qiang.xue@gmail.com>
 * @since 2.0
 */
class ServiceLocator extends Component
{
    /**
     * 存储component的实例，key是component的ID
     * @var array shared component instances indexed by their IDs
     */
    private $_components = [];
    /**
     * 存储component的配置，key是component的ID
     * @var array component definitions indexed by their IDs
     */
    private $_definitions = [];


    /**
     * Getter magic method.
     * This method is overridden to support accessing components like reading properties.
     * @param string $name component or property name
     * @return mixed the named property value
     */
    public function __get($name)
    {
        // 获取component，只要定义中有，就可以拿到
        // 未实例化，get方法会去实例化
        if ($this->has($name)) {
            return $this->get($name);
        } else {
            return parent::__get($name);
        }
    }

    /**
     * Checks if a property value is null.
     * This method overrides the parent implementation by checking if the named component is loaded.
     * @param string $name the property name or the event name
     * @return boolean whether the property value is null
     */
    public function __isset($name)
    {
        // 可以看到，如果用isset去判断是否含有某个component，只能判断已经实例化过的component
        if ($this->has($name, true)) {
            return true;
        } else {
            return parent::__isset($name);
        }
    }

    /**
     * Returns a value indicating whether the locator has the specified component definition or has instantiated the component.
     * This method may return different results depending on the value of `$checkInstance`.
     *
     * - If `$checkInstance` is false (default), the method will return a value indicating whether the locator has the specified
     *   component definition.
     * - If `$checkInstance` is true, the method will return a value indicating whether the locator has
     *   instantiated the specified component.
     *
     * @param string $id component ID (e.g. `db`).
     * @param boolean $checkInstance whether the method should check if the component is shared and instantiated.
     * @return boolean whether the locator has the specified component definition or has instantiated the component.
     * @see set()
     */
    public function has($id, $checkInstance = false)
    {
        // 如果要检查是否有component的实例，就将$checkInstance设置为true
        return $checkInstance ? isset($this->_components[$id]) : isset($this->_definitions[$id]);
    }

    /**
     * Returns the component instance with the specified ID.
     *
     * @param string $id component ID (e.g. `db`).
     * @param boolean $throwException whether to throw an exception if `$id` is not registered with the locator before.
     * @return object|null the component of the specified ID. If `$throwException` is false and `$id`
     * is not registered before, null will be returned.
     * @throws InvalidConfigException if `$id` refers to a nonexistent component ID
     * @see has()
     * @see set()
     */
    public function get($id, $throwException = true)
    {
        if (isset($this->_components[$id])) {
            // 如果存在就直接返回
            return $this->_components[$id];
        }

        if (isset($this->_definitions[$id])) {
            // 存在component的配置
            $definition = $this->_definitions[$id];
            // 是一个object还不是匿名函数，就代表是一个component的实例
            // 继承自Closure代表是一个匿名函数
            if (is_object($definition) && !$definition instanceof Closure) {
                return $this->_components[$id] = $definition;
            } else {
                // 不是component的实例，就根据配置去创建一个
                return $this->_components[$id] = Yii::createObject($definition);
            }
        } elseif ($throwException) {
            // 都不存在并且允许抛异常的话，就抛异常
            throw new InvalidConfigException("Unknown component ID: $id");
        } else {
            return null;
        }
    }

    /**
     * Registers a component definition with this locator.
     *
     * For example,
     *
     * ```php
     * // a class name
     * $locator->set('cache', 'yii\caching\FileCache');
     *
     * // a configuration array
     * $locator->set('db', [
     *     'class' => 'yii\db\Connection',
     *     'dsn' => 'mysql:host=127.0.0.1;dbname=demo',
     *     'username' => 'root',
     *     'password' => '',
     *     'charset' => 'utf8',
     * ]);
     *
     * // an anonymous function
     * $locator->set('cache', function ($params) {
     *     return new \yii\caching\FileCache;
     * });
     *
     * // an instance
     * $locator->set('cache', new \yii\caching\FileCache);
     * ```
     *
     * If a component definition with the same ID already exists, it will be overwritten.
     *
     * @param string $id component ID (e.g. `db`).
     * @param mixed $definition the component definition to be registered with this locator.
     * It can be one of the followings:
     *
     * - a class name
     * - a configuration array: the array contains name-value pairs that will be used to
     *   initialize the property values of the newly created object when [[get()]] is called.
     *   The `class` element is required and stands for the the class of the object to be created.
     * - a PHP callable: either an anonymous function or an array representing a class method (e.g. `['Foo', 'bar']`).
     *   The callable will be called by [[get()]] to return an object associated with the specified component ID.
     * - an object: When [[get()]] is called, this object will be returned.
     *
     * @throws InvalidConfigException if the definition is an invalid configuration array
     */
    public function set($id, $definition)
    {
        if ($definition === null) {
            // 如果配置项为空，就将该component移除
            unset($this->_components[$id], $this->_definitions[$id]);
            return;
        }

        // 如果配置项存在，就只移除component的实例
        unset($this->_components[$id]);

        // is_callable — 检测参数是否为合法的可调用结构
        // is_callable的第二个参数如果设置为 TRUE，这个函数仅仅验证$definition可能是函数或方法。
        // 它仅仅拒绝非字符，或者未包含能用于回调函数的有效结构。
        // 有效的应该包含两个元素，第一个是一个对象或者字符，第二个元素是个字符。
        if (is_object($definition) || is_callable($definition, true)) {
            // an object, a class name, or a PHP callable
            $this->_definitions[$id] = $definition;
        } elseif (is_array($definition)) {
            // a configuration array
            if (isset($definition['class'])) {
                // 如果是数组，则必须存在class的定义
                $this->_definitions[$id] = $definition;
            } else {
                throw new InvalidConfigException("The configuration for the \"$id\" component must contain a \"class\" element.");
            }
        } else {
            throw new InvalidConfigException("Unexpected configuration type for the \"$id\" component: " . gettype($definition));
        }
    }

    /**
     * Removes the component from the locator.
     * @param string $id the component ID
     */
    public function clear($id)
    {
        // 清除掉某一个component
        unset($this->_definitions[$id], $this->_components[$id]);
    }

    /**
     * Returns the list of the component definitions or the loaded component instances.
     * @param boolean $returnDefinitions whether to return component definitions instead of the loaded component instances.
     * @return array the list of the component definitions or the loaded component instances (ID => definition or instance).
     */
    public function getComponents($returnDefinitions = true)
    {
        // 返回所有component的定义或者配置
        return $returnDefinitions ? $this->_definitions : $this->_components;
    }

    /**
     * Registers a set of component definitions in this locator.
     *
     * This is the bulk version of [[set()]]. The parameter should be an array
     * whose keys are component IDs and values the corresponding component definitions.
     *
     * For more details on how to specify component IDs and definitions, please refer to [[set()]].
     *
     * If a component definition with the same ID already exists, it will be overwritten.
     *
     * The following is an example for registering two component definitions:
     *
     * ```php
     * [
     *     'db' => [
     *         'class' => 'yii\db\Connection',
     *         'dsn' => 'sqlite:path/to/file.db',
     *     ],
     *     'cache' => [
     *         'class' => 'yii\caching\DbCache',
     *         'db' => 'db',
     *     ],
     * ]
     * ```
     *
     * @param array $components component definitions or instances
     */
    public function setComponents($components)
    {
        // 设置多个component
        foreach ($components as $id => $component) {
            $this->set($id, $component);
        }
    }
}
