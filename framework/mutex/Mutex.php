<?php
/**
 * @link http://www.yiiframework.com/
 * @copyright Copyright (c) 2008 Yii Software LLC
 * @license http://www.yiiframework.com/license/
 */

namespace yii\mutex;

use Yii;
use yii\base\Component;

/**
 * Mutex 组件允许的并发进程互斥的执行，防止竞争情况
 * Mutex component allows mutual execution of the concurrent processes, preventing "race conditions".
 * This is achieved by using "lock" mechanism. Each possibly concurrent thread cooperates by acquiring
 * the lock before accessing the corresponding data.
 *
 * Usage example:
 *
 * ```
 * if ($mutex->acquire($mutexName)) {
 *     // business logic execution
 * } else {
 *     // execution is blocked!
 * }
 * ```
 *
 * This class is a base one, which should be extended in order to implement actual lock mechanism.
 *
 * @author resurtm <resurtm@gmail.com>
 * @since 2.0
 */
abstract class Mutex extends Component
{
    /**
     * 是否自动释放锁
     * @var boolean whether all locks acquired in this process (i.e. local locks) must be released automagically
     * before finishing script execution. Defaults to true. Setting this property to true means that all locks
     * acquire in this process must be released in any case (regardless any kind of errors or exceptions).
     */
    public $autoRelease = true;

    /**
     * 存储当前 PHP 进程的所有锁名称
     * @var string[] names of the locks acquired in the current PHP process.
     */
    private $_locks = [];


    /**
     * Initializes the mutex component.
     */
    public function init()
    {
        if ($this->autoRelease) {
            // 使用引用
            $locks = &$this->_locks;
            // register_shutdown_function — Register a function for execution on shutdown
            // 注册 shutdown 时，执行的方法，去释放锁
            register_shutdown_function(function () use (&$locks) {
                foreach ($locks as $lock) {
                    $this->release($lock);
                }
            });
        }
    }

    /**
     * 根据名字获取锁，会调用自身的 acquireLock 方法
     * Acquires lock by given name.
     * @param string $name of the lock to be acquired. Must be unique.
     * @param integer $timeout to wait for lock to be released. Defaults to zero meaning that method will return
     * false immediately in case lock was already acquired.
     * @return boolean lock acquiring result.
     */
    public function acquire($name, $timeout = 0)
    {
        // 继承该类的类，只需要写 acquireLock 方法即可
        if ($this->acquireLock($name, $timeout)) {
            // 将名称放入 _locks 数组中
            $this->_locks[] = $name;

            return true;
        } else {
            return false;
        }
    }

    /**
     * 根据名字释放锁，会调用自身的 releaseLock 方法
     * Release acquired lock. This method will return false in case named lock was not found.
     * @param string $name of the lock to be released. This lock must be already created.
     * @return boolean lock release result: false in case named lock was not found..
     */
    public function release($name)
    {
        // 继承该类的类，只需要写 releaseLock 方法即可
        if ($this->releaseLock($name)) {
            // 查找到 _locks 中相应的名称，unset 掉
            $index = array_search($name, $this->_locks);
            if ($index !== false) {
                unset($this->_locks[$index]);
            }

            return true;
        } else {
            return false;
        }
    }

    /**
     * This method should be extended by concrete mutex implementations. Acquires lock by given name.
     * @param string $name of the lock to be acquired.
     * @param integer $timeout to wait for lock to become released.
     * @return boolean acquiring result.
     */
    abstract protected function acquireLock($name, $timeout = 0);

    /**
     * This method should be extended by concrete mutex implementations. Releases lock by given name.
     * @param string $name of the lock to be released.
     * @return boolean release result.
     */
    abstract protected function releaseLock($name);
}
