<?php

// comment out the following two lines when deployed to production
// 定义 debug 的标记
defined('YII_DEBUG') or define('YII_DEBUG', true);
// 定义环境，有 'dev' 和 'prod' 两种
defined('YII_ENV') or define('YII_ENV', 'dev');

// 引入 vendor 中的 autoload.php 文件，会基于 composer 的机制自动加载类
require(__DIR__ . '/../vendor/autoload.php');
// 引入 Yii 框架的文件 Yii.php
require(__DIR__ . '/../vendor/yiisoft/yii2/Yii.php');

// 引入 web 的 config 文件，并将返回值即配置项放入 $config 变量中
$config = require(__DIR__ . '/../config/web.php');

// new 一个 yii\web\Application 的实例，并执行它的 run 方法
// 用 $config 作为 yii\web\Application 初始化的参数
(new yii\web\Application($config))->run();
