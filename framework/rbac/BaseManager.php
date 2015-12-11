<?php
/**
 * @link http://www.yiiframework.com/
 * @copyright Copyright (c) 2008 Yii Software LLC
 * @license http://www.yiiframework.com/license/
 */

namespace yii\rbac;

use yii\base\Component;
use yii\base\InvalidConfigException;
use yii\base\InvalidParamException;

/**
 * BaseManager is a base class implementing [[ManagerInterface]] for RBAC management.
 *
 * Item  为角色或者权限的基类，其中用字段type来标识
 * Role  为代表角色的类
 * Permission  为代表权限的类
 * Assignment  为代表用户角色或者权限的类
 * Rule  为代表角色或权限能否执行的判定规则表
 *
 * @author Qiang Xue <qiang.xue@gmail.com>
 * @since 2.0
 */
abstract class BaseManager extends Component implements ManagerInterface
{
    /**
     * 默认权限名称的列表，会自动赋给每一个用户
     * @var array a list of role names that are assigned to every user automatically without calling [[assign()]].
     */
    public $defaultRoles = [];


    /**
     * 根据名称获取项，是角色或者权限
     * Returns the named auth item.
     * @param string $name the auth item name.
     * @return Item the auth item corresponding to the specified name. Null is returned if no such item.
     */
    abstract protected function getItem($name);

    /**
     * 根据类型获取所有项，类型是角色或者权限
     * Returns the items of the specified type.
     * @param integer $type the auth item type (either [[Item::TYPE_ROLE]] or [[Item::TYPE_PERMISSION]]
     * @return Item[] the auth items of the specified type.
     */
    abstract protected function getItems($type);

    /**
     * 添加一项到 RBAC 系统中
     * Adds an auth item to the RBAC system.
     * @param Item $item
     * @return boolean whether the auth item is successfully added to the system
     * @throws \Exception if data validation or saving fails (such as the name of the role or permission is not unique)
     */
    abstract protected function addItem($item);

    /**
     * 添加一个 rule 到 RBAC 系统中
     * Adds a rule to the RBAC system.
     * @param Rule $rule
     * @return boolean whether the rule is successfully added to the system
     * @throws \Exception if data validation or saving fails (such as the name of the rule is not unique)
     */
    abstract protected function addRule($rule);

    /**
     * 从 RBAC 系统中删除一项
     * Removes an auth item from the RBAC system.
     * @param Item $item
     * @return boolean whether the role or permission is successfully removed
     * @throws \Exception if data validation or saving fails (such as the name of the role or permission is not unique)
     */
    abstract protected function removeItem($item);

    /**
     * 从 RBAC 系统中删除一个 rule
     * Removes a rule from the RBAC system.
     * @param Rule $rule
     * @return boolean whether the rule is successfully removed
     * @throws \Exception if data validation or saving fails (such as the name of the rule is not unique)
     */
    abstract protected function removeRule($rule);

    /**
     * 在 RBAC 系统中，根据名字更新授权项
     * Updates an auth item in the RBAC system.
     * @param string $name the old name of the auth item
     * @param Item $item
     * @return boolean whether the auth item is successfully updated
     * @throws \Exception if data validation or saving fails (such as the name of the role or permission is not unique)
     */
    abstract protected function updateItem($name, $item);

    /**
     * 在 RBAC 系统中，根据名字更新 rule
     * Updates a rule to the RBAC system.
     * @param string $name the old name of the rule
     * @param Rule $rule
     * @return boolean whether the rule is successfully updated
     * @throws \Exception if data validation or saving fails (such as the name of the rule is not unique)
     */
    abstract protected function updateRule($name, $rule);

    /**
     * @inheritdoc
     */
    public function createRole($name)
    {
        $role = new Role;
        $role->name = $name;
        return $role;
    }

    /**
     * @inheritdoc
     */
    public function createPermission($name)
    {
        $permission = new Permission();
        $permission->name = $name;
        return $permission;
    }

    /**
     * @inheritdoc
     */
    public function add($object)
    {
        if ($object instanceof Item) {
            return $this->addItem($object);
        } elseif ($object instanceof Rule) {
            return $this->addRule($object);
        } else {
            throw new InvalidParamException("Adding unsupported object type.");
        }
    }

    /**
     * @inheritdoc
     */
    public function remove($object)
    {
        if ($object instanceof Item) {
            return $this->removeItem($object);
        } elseif ($object instanceof Rule) {
            return $this->removeRule($object);
        } else {
            throw new InvalidParamException("Removing unsupported object type.");
        }
    }

    /**
     * @inheritdoc
     */
    public function update($name, $object)
    {
        if ($object instanceof Item) {
            return $this->updateItem($name, $object);
        } elseif ($object instanceof Rule) {
            return $this->updateRule($name, $object);
        } else {
            throw new InvalidParamException("Updating unsupported object type.");
        }
    }

    /**
     * @inheritdoc
     */
    public function getRole($name)
    {
        $item = $this->getItem($name);
        return $item instanceof Item && $item->type == Item::TYPE_ROLE ? $item : null;
    }

    /**
     * @inheritdoc
     */
    public function getPermission($name)
    {
        $item = $this->getItem($name);
        return $item instanceof Item && $item->type == Item::TYPE_PERMISSION ? $item : null;
    }

    /**
     * @inheritdoc
     */
    public function getRoles()
    {
        return $this->getItems(Item::TYPE_ROLE);
    }

    /**
     * @inheritdoc
     */
    public function getPermissions()
    {
        return $this->getItems(Item::TYPE_PERMISSION);
    }

    /**
     * Executes the rule associated with the specified auth item.
     *
     * If the item does not specify a rule, this method will return true. Otherwise, it will
     * return the value of [[Rule::execute()]].
     *
     * @param string|integer $user the user ID. This should be either an integer or a string representing
     * the unique identifier of a user. See [[\yii\web\User::id]].
     * @param Item $item the auth item that needs to execute its rule
     * @param array $params parameters passed to [[ManagerInterface::checkAccess()]] and will be passed to the rule
     * @return boolean the return value of [[Rule::execute()]]. If the auth item does not specify a rule, true will be returned.
     * @throws InvalidConfigException if the auth item has an invalid rule.
     */
    protected function executeRule($user, $item, $params)
    {
        if ($item->ruleName === null) {
            return true;
        }
        $rule = $this->getRule($item->ruleName);
        if ($rule instanceof Rule) {
            return $rule->execute($user, $item, $params);
        } else {
            throw new InvalidConfigException("Rule not found: {$item->ruleName}");
        }
    }
}
