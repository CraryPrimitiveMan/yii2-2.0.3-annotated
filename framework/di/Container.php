<?php
/**
 * @link http://www.yiiframework.com/
 * @copyright Copyright (c) 2008 Yii Software LLC
 * @license http://www.yiiframework.com/license/
 */

namespace yii\di;

use ReflectionClass;
use yii\base\Component;
use yii\base\InvalidConfigException;

/**
 * Container implements a [dependency injection](http://en.wikipedia.org/wiki/Dependency_injection) container.
 *
 * A dependency injection (DI) container is an object that knows how to instantiate and configure objects and
 * all their dependent objects. For more information about DI, please refer to
 * [Martin Fowler's article](http://martinfowler.com/articles/injection.html).
 *
 * Container supports constructor injection as well as property injection.
 *
 * To use Container, you first need to set up the class dependencies by calling [[set()]].
 * You then call [[get()]] to create a new class object. Container will automatically instantiate
 * dependent objects, inject them into the object being created, configure and finally return the newly created object.
 *
 * By default, [[\Yii::$container]] refers to a Container instance which is used by [[\Yii::createObject()]]
 * to create new object instances. You may use this method to replace the `new` operator
 * when creating a new object, which gives you the benefit of automatic dependency resolution and default
 * property configuration.
 *
 * Below is an example of using Container:
 *
 * ```php
 * namespace app\models;
 *
 * use yii\base\Object;
 * use yii\db\Connection;
 * use yii\di\Container;
 *
 * interface UserFinderInterface
 * {
 *     function findUser();
 * }
 *
 * class UserFinder extends Object implements UserFinderInterface
 * {
 *     public $db;
 *
 *     public function __construct(Connection $db, $config = [])
 *     {
 *         $this->db = $db;
 *         parent::__construct($config);
 *     }
 *
 *     public function findUser()
 *     {
 *     }
 * }
 *
 * class UserLister extends Object
 * {
 *     public $finder;
 *
 *     public function __construct(UserFinderInterface $finder, $config = [])
 *     {
 *         $this->finder = $finder;
 *         parent::__construct($config);
 *     }
 * }
 *
 * $container = new Container;
 * $container->set('yii\db\Connection', [
 *     'dsn' => '...',
 * ]);
 * $container->set('app\models\UserFinderInterface', [
 *     'class' => 'app\models\UserFinder',
 * ]);
 * $container->set('userLister', 'app\models\UserLister');
 *
 * $lister = $container->get('userLister');
 *
 * // which is equivalent to:
 *
 * $db = new \yii\db\Connection(['dsn' => '...']);
 * $finder = new UserFinder($db);
 * $lister = new UserLister($finder);
 * ```
 *
 * @property array $definitions The list of the object definitions or the loaded shared objects (type or ID =>
 * definition or instance). This property is read-only.
 *
 * @author Qiang Xue <qiang.xue@gmail.com>
 * @since 2.0
 */
class Container extends Component
{
    /**
     * @var array singleton objects indexed by their types
     * 用于保存单例Singleton对象，以对象类型为键
     */
    private $_singletons = [];
    /**
     * @var array object definitions indexed by their types
     * 用于保存依赖的定义，以对象类型为键
     */
    private $_definitions = [];
    /**
     * @var array constructor parameters indexed by object types
     * 用于保存构造函数的参数，以对象类型为键
     */
    private $_params = [];
    /**
     * @var array cached ReflectionClass objects indexed by class/interface names
     * 用于缓存ReflectionClass对象，以类名或接口名为键
     */
    private $_reflections = [];
    /**
     * @var array cached dependencies indexed by class/interface names. Each class name
     * is associated with a list of constructor parameter types or default values.
     * 用于缓存依赖信息，以类名或接口名为键
     */
    private $_dependencies = [];


    /**
     * Returns an instance of the requested class.
     *
     * You may provide constructor parameters (`$params`) and object configurations (`$config`)
     * that will be used during the creation of the instance.
     *
     * If the class implements [[\yii\base\Configurable]], the `$config` parameter will be passed as the last
     * parameter to the class constructor; Otherwise, the configuration will be applied *after* the object is
     * instantiated.
     *
     * Note that if the class is declared to be singleton by calling [[setSingleton()]],
     * the same instance of the class will be returned each time this method is called.
     * In this case, the constructor parameters and object configurations will be used
     * only if the class is instantiated the first time.
     *
     * @param string $class the class name or an alias name (e.g. `foo`) that was previously registered via [[set()]]
     * or [[setSingleton()]].
     * @param array $params a list of constructor parameter values. The parameters should be provided in the order
     * they appear in the constructor declaration. If you want to skip some parameters, you should index the remaining
     * ones with the integers that represent their positions in the constructor parameter list.
     * @param array $config a list of name-value pairs that will be used to initialize the object properties.
     * @return object an instance of the requested class.
     * @throws InvalidConfigException if the class cannot be recognized or correspond to an invalid definition
     */
    public function get($class, $params = [], $config = [])
    {
        if (isset($this->_singletons[$class])) {
            // singleton
            // 如果Singleton对象的数组中有，就直接返回
            return $this->_singletons[$class];
        } elseif (!isset($this->_definitions[$class])) {
            // 如果定义的数组中没有，就去构建它
            return $this->build($class, $params, $config);
        }

        $definition = $this->_definitions[$class];

        if (is_callable($definition, true)) {
            // 如果$definition是callable，就先将参数合并，再将其中的Instance实例替换掉
            $params = $this->resolveDependencies($this->mergeParams($class, $params));
            // 调用$definition
            $object = call_user_func($definition, $this, $params, $config);
        } elseif (is_array($definition)) {
            // 如果是一个数组
            $concrete = $definition['class'];
            unset($definition['class']);

            // 合并配置和参数
            $config = array_merge($definition, $config);
            $params = $this->mergeParams($class, $params);

            if ($concrete === $class) {
                $object = $this->build($class, $params, $config);
            } else {
                $object = $this->get($concrete, $params, $config);
            }
        } elseif (is_object($definition)) {
            // 如果$definition是个对象，就直接保存到_singletons中
            return $this->_singletons[$class] = $definition;
        } else {
            throw new InvalidConfigException("Unexpected object definition type: " . gettype($definition));
        }

        if (array_key_exists($class, $this->_singletons)) {
            // singleton
            // 如果_singletons中没有这个类的实例，就保存一份
            $this->_singletons[$class] = $object;
        }

        return $object;
    }

    /**
     * Registers a class definition with this container.
     *
     * For example,
     *
     * ```php
     * // register a class name as is. This can be skipped.
     * $container->set('yii\db\Connection');
     *
     * // register an interface
     * // When a class depends on the interface, the corresponding class
     * // will be instantiated as the dependent object
     * $container->set('yii\mail\MailInterface', 'yii\swiftmailer\Mailer');
     *
     * // register an alias name. You can use $container->get('foo')
     * // to create an instance of Connection
     * $container->set('foo', 'yii\db\Connection');
     *
     * // register a class with configuration. The configuration
     * // will be applied when the class is instantiated by get()
     * $container->set('yii\db\Connection', [
     *     'dsn' => 'mysql:host=127.0.0.1;dbname=demo',
     *     'username' => 'root',
     *     'password' => '',
     *     'charset' => 'utf8',
     * ]);
     *
     * // register an alias name with class configuration
     * // In this case, a "class" element is required to specify the class
     * $container->set('db', [
     *     'class' => 'yii\db\Connection',
     *     'dsn' => 'mysql:host=127.0.0.1;dbname=demo',
     *     'username' => 'root',
     *     'password' => '',
     *     'charset' => 'utf8',
     * ]);
     *
     * // register a PHP callable
     * // The callable will be executed when $container->get('db') is called
     * $container->set('db', function ($container, $params, $config) {
     *     return new \yii\db\Connection($config);
     * });
     * ```
     *
     * If a class definition with the same name already exists, it will be overwritten with the new one.
     * You may use [[has()]] to check if a class definition already exists.
     *
     * @param string $class class name, interface name or alias name
     * @param mixed $definition the definition associated with `$class`. It can be one of the followings:
     *
     * - a PHP callable: The callable will be executed when [[get()]] is invoked. The signature of the callable
     *   should be `function ($container, $params, $config)`, where `$params` stands for the list of constructor
     *   parameters, `$config` the object configuration, and `$container` the container object. The return value
     *   of the callable will be returned by [[get()]] as the object instance requested.
     * - a configuration array: the array contains name-value pairs that will be used to initialize the property
     *   values of the newly created object when [[get()]] is called. The `class` element stands for the
     *   the class of the object to be created. If `class` is not specified, `$class` will be used as the class name.
     * - a string: a class name, an interface name or an alias name.
     * @param array $params the list of constructor parameters. The parameters will be passed to the class
     * constructor when [[get()]] is called.
     * @return static the container itself
     */
    public function set($class, $definition = [], array $params = [])
    {
        // 处理definition，并存入到_definitions中
        $this->_definitions[$class] = $this->normalizeDefinition($class, $definition);
        // 将$params存入_params中
        $this->_params[$class] = $params;
        unset($this->_singletons[$class]);
        return $this;
    }

    /**
     * Registers a class definition with this container and marks the class as a singleton class.
     *
     * This method is similar to [[set()]] except that classes registered via this method will only have one
     * instance. Each time [[get()]] is called, the same instance of the specified class will be returned.
     *
     * @param string $class class name, interface name or alias name
     * @param mixed $definition the definition associated with `$class`. See [[set()]] for more details.
     * @param array $params the list of constructor parameters. The parameters will be passed to the class
     * constructor when [[get()]] is called.
     * @return static the container itself
     * @see set()
     */
    public function setSingleton($class, $definition = [], array $params = [])
    {
        // 同上
        $this->_definitions[$class] = $this->normalizeDefinition($class, $definition);
        $this->_params[$class] = $params;
        // 这里是单例，表示这个还会继续用到，所以不用unset，只需赋为空即可
        $this->_singletons[$class] = null;
        return $this;
    }

    /**
     * Returns a value indicating whether the container has the definition of the specified name.
     * @param string $class class name, interface name or alias name
     * @return boolean whether the container has the definition of the specified name..
     * @see set()
     */
    public function has($class)
    {
        // 判断是否已经定义了某个服务或组件
        return isset($this->_definitions[$class]);
    }

    /**
     * Returns a value indicating whether the given name corresponds to a registered singleton.
     * @param string $class class name, interface name or alias name
     * @param boolean $checkInstance whether to check if the singleton has been instantiated.
     * @return boolean whether the given name corresponds to a registered singleton. If `$checkInstance` is true,
     * the method should return a value indicating whether the singleton has been instantiated.
     */
    public function hasSingleton($class, $checkInstance = false)
    {
        // 当 $checkInstance === false 时，用于判断是否已经定义了某个服务或组件
        // 当 $checkInstance === true 时，用于判断是否已经有了某人服务或组件的实例
        return $checkInstance ? isset($this->_singletons[$class]) : array_key_exists($class, $this->_singletons);
    }

    /**
     * Removes the definition for the specified name.
     * @param string $class class name, interface name or alias name
     */
    public function clear($class)
    {
        // 清空相应类的definition和singleton
        unset($this->_definitions[$class], $this->_singletons[$class]);
    }

    /**
     * Normalizes the class definition.
     * @param string $class class name
     * @param string|array|callable $definition the class definition
     * @return array the normalized class definition
     * @throws InvalidConfigException if the definition is invalid.
     */
    protected function normalizeDefinition($class, $definition)
    {
        if (empty($definition)) {
            // 定义为空
            return ['class' => $class];
        } elseif (is_string($definition)) {
            // $definition是字符串，就表明它是类名，$class是简称
            return ['class' => $definition];
        } elseif (is_callable($definition, true) || is_object($definition)) {
            // 是个PHP callable或者是个对象，就直接返回
            return $definition;
        } elseif (is_array($definition)) {
            // 是个数组
            if (!isset($definition['class'])) {
                // 定义中不存在class键，$class必须是带namespace的类名
                if (strpos($class, '\\') !== false) {
                    $definition['class'] = $class;
                } else {
                    throw new InvalidConfigException("A class definition requires a \"class\" member.");
                }
            }
            return $definition;
        } else {
            throw new InvalidConfigException("Unsupported definition type for \"$class\": " . gettype($definition));
        }
    }

    /**
     * Returns the list of the object definitions or the loaded shared objects.
     * @return array the list of the object definitions or the loaded shared objects (type or ID => definition or instance).
     */
    public function getDefinitions()
    {
        return $this->_definitions;
    }

    /**
     * Creates an instance of the specified class.
     * This method will resolve dependencies of the specified class, instantiate them, and inject
     * them into the new instance of the specified class.
     * @param string $class the class name
     * @param array $params constructor parameters
     * @param array $config configurations to be applied to the new instance
     * @return object the newly created instance of the specified class
     */
    protected function build($class, $params, $config)
    {
        /* @var $reflection ReflectionClass */
        // 先获取类的反射的实例和要依赖的参数（Instance的数组）
        list ($reflection, $dependencies) = $this->getDependencies($class);

        // 用传入的参数去替换掉默认的参数
        foreach ($params as $index => $param) {
            $dependencies[$index] = $param;
        }

        // 将Instance实例转化成相应的id中存储的类名的实例，即参数的真正的实例
        $dependencies = $this->resolveDependencies($dependencies, $reflection);
        if (empty($config)) {
            // ReflectionClass::newInstanceArgs — 从给出的参数创建一个新的类实例
            // 如果$config是空，直接创建一个新的类的实例返回
            return $reflection->newInstanceArgs($dependencies);
        }

        // ReflectionClass::implementsInterface — 检查它是否实现了一个接口（interface）
        // config不为空
        if (!empty($dependencies) && $reflection->implementsInterface('yii\base\Configurable')) {
            // 依赖不为空而且class实现了 yii\base\Configurable 的接口
            // 实现了 yii\base\Configurable 的接口，就意味着类的构造函数的最后一个参数是$config
            // set $config as the last parameter (existing one will be overwritten)
            $dependencies[count($dependencies) - 1] = $config;
            return $reflection->newInstanceArgs($dependencies);
        } else {
            $object = $reflection->newInstanceArgs($dependencies);
            // 循环$config，将$config的键值对赋给相应的类的实例
            foreach ($config as $name => $value) {
                $object->$name = $value;
            }
            return $object;
        }
    }

    /**
     * Merges the user-specified constructor parameters with the ones registered via [[set()]].
     * @param string $class class name, interface name or alias name
     * @param array $params the constructor parameters
     * @return array the merged parameters
     */
    protected function mergeParams($class, $params)
    {
        if (empty($this->_params[$class])) {
            // 如果_params中不存在，就直接返回$params
            return $params;
        } elseif (empty($params)) {
            // 如果_params中存在，且params为空，就返回_params中的内容
            return $this->_params[$class];
        } else {
            // 如果都不为空，就将两者合并起来，以$params为主
            $ps = $this->_params[$class];
            foreach ($params as $index => $value) {
                $ps[$index] = $value;
            }
            return $ps;
        }
    }

    /**
     * Returns the dependencies of the specified class.
     * @param string $class class name, interface name or alias name
     * @return array the dependencies of the specified class.
     */
    protected function getDependencies($class)
    {
        if (isset($this->_reflections[$class])) {
            return [$this->_reflections[$class], $this->_dependencies[$class]];
        }

        $dependencies = [];
        $reflection = new ReflectionClass($class);

        // 获取类的构造方法
        $constructor = $reflection->getConstructor();
        if ($constructor !== null) {
            // 获取构造方法的参数，并将其实例化
            foreach ($constructor->getParameters() as $param) {
                if ($param->isDefaultValueAvailable()) {
                    // 如果有defalut值，就直接将默认值放入依赖中
                    $dependencies[] = $param->getDefaultValue();
                } else {
                    // 否则，就先获取param的ReflectionClass
                    $c = $param->getClass();
                    // 然后通过getName获取类的名称（带namespace），生成一个Instance的实例
                    // Instance中有个id属性，标记了类的名称
                    $dependencies[] = Instance::of($c === null ? null : $c->getName());
                }
            }
        }

        // 将类的反射实例和参数依赖的实例分别缓存到_reflections和_dependencies数组中
        $this->_reflections[$class] = $reflection;
        $this->_dependencies[$class] = $dependencies;

        return [$reflection, $dependencies];
    }

    /**
     * Resolves dependencies by replacing them with the actual object instances.
     * 将Instance的实例替换成参数本身应该的实例
     * @param array $dependencies the dependencies
     * @param ReflectionClass $reflection the class reflection associated with the dependencies
     * @return array the resolved dependencies
     * @throws InvalidConfigException if a dependency cannot be resolved or if a dependency cannot be fulfilled.
     */
    protected function resolveDependencies($dependencies, $reflection = null)
    {
        foreach ($dependencies as $index => $dependency) {
            // 依赖是Instance的实例，才做处理
            if ($dependency instanceof Instance) {
                if ($dependency->id !== null) {
                    // id不为空，就使用get方法去获取它的实例
                    $dependencies[$index] = $this->get($dependency->id);
                } elseif ($reflection !== null) {
                    // 找出缺少的参数名称，以及其所在的类名
                    $name = $reflection->getConstructor()->getParameters()[$index]->getName();
                    $class = $reflection->getName();
                    throw new InvalidConfigException("Missing required parameter \"$name\" when instantiating \"$class\".");
                }
            }
        }
        return $dependencies;
    }
}
